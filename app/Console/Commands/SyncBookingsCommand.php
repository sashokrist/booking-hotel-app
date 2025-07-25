<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Booking;
use App\Models\Guest;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\SyncLog;

class SyncBookingsCommand extends Command
{
    protected $signature = 'sync:bookings {--since=}';
    protected $description = 'Sync bookings, guests, rooms, and room types from PMS API';
    protected $rateLimitDelay = 500000; // 500ms

    public function handle()
    {
        $since = $this->option('since');
        $this->line("Syncing bookings updated since: $since");
        $this->logSync('logTest', 0, 'info', 'Starting sync: ' . $since);

        try {
            $response = Http::pms()->get('bookings', ['updated_at.gt' => $since]);
            usleep($this->rateLimitDelay);

            if ($response->failed()) {
                Log::error("Failed to fetch booking IDs", ['response' => $response->body()]);
                $this->error('Failed to fetch booking IDs');
                $this->logSync('logTest', 0, 'failed', 'Failed to fetch booking IDs');
                return;
            }

            $bookingIds = collect($response->json('data') ?? [])
                ->map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : (is_numeric($item) ? $item : null))
                ->filter(fn ($id) => is_numeric($id))
                ->unique()
                ->values()
                ->all();

            $this->line("Fetched " . count($bookingIds) . " updated bookings.");
            $this->logSync('logTest', 0, 'info', 'Fetched ' . count($bookingIds) . ' bookings');

            $roomsToSync = [];
            $roomTypesToSync = [];
            $guestsToSync = [];

            foreach (array_chunk($bookingIds, 100) as $chunk) {
                foreach ($chunk as $bookingId) {
                    $this->line("\n--- Starting fetch booking ID: {$bookingId} ---");
                    DB::beginTransaction();

                    try {
                        $response = Http::pms()->get("bookings/{$bookingId}");
                        usleep($this->rateLimitDelay);
                        $booking = $response->successful() ? $response->json() : null;

                        if (!$booking || empty($booking['id']) || empty($booking['guest_ids']) || !is_array($booking['guest_ids'])) {
                            DB::rollBack();
                            $this->warn("Skipping booking ID {$bookingId} - invalid/missing guest_ids.");
                            $this->logSync('booking', $bookingId, 'failed', 'Invalid booking data');
                            continue;
                        }

                        $room = Http::pms()->get("rooms/{$booking['room_id']}")->json();
                        usleep($this->rateLimitDelay);
                        $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);
                        if (!$roomTypeId) {
                            DB::rollBack();
                            $this->logSync('booking', $bookingId, 'failed', 'Missing room_type_id');
                            continue;
                        }

                        $this->line("Room: ID {$room['id']}, Number: {$room['number']}, RoomType: {$roomTypeId}, Floor: {$room['floor']}");

                        $roomsToSync[$room['id']] = [
                            'id' => $room['id'],
                            'number' => $room['number'] ?? null,
                            'floor' => $room['floor'] ?? null,
                            'room_type_id' => $roomTypeId,
                        ];

                        $roomType = Http::pms()->get("room-types/{$roomTypeId}")->json();
                        usleep($this->rateLimitDelay);

                        $this->line("RoomType: ID {$roomType['id']}, Name: {$roomType['name']}, Description: {$roomType['description']}");

                        $roomTypesToSync[$roomType['id']] = [
                            'id' => $roomType['id'],
                            'name' => $roomType['name'] ?? null,
                            'description' => $roomType['description'] ?? null,
                        ];

                        $syncedGuestIds = [];
                        foreach ($booking['guest_ids'] as $guestId) {
                            $guestResponse = Http::pms()->get("guests/{$guestId}");
                            usleep($this->rateLimitDelay);
                            if ($guestResponse->failed()) continue;

                            $guest = $guestResponse->json();
                            if (empty($guest['id'])) continue;

                            $this->line("Guest: ID {$guest['id']}, First Name: {$guest['first_name']}, Last Name: {$guest['last_name']}");

                            $guestsToSync[$guest['id']] = [
                                'id' => $guest['id'],
                                'first_name' => $guest['first_name'] ?? null,
                                'last_name' => $guest['last_name'] ?? null,
                                'email' => $guest['email'] ?? null,
                                'phone' => $guest['phone'] ?? null,
                            ];

                            $syncedGuestIds[] = (int) $guest['id'];
                        }

                        $expectedGuestIds = array_map('intval', $booking['guest_ids']);
                        sort($expectedGuestIds);
                        sort($syncedGuestIds);

                        if ($expectedGuestIds !== $syncedGuestIds) {
                            DB::rollBack();
                            $this->logSync('booking', $bookingId, 'failed', 'Mismatched guest_ids');
                            continue;
                        }

                        $existing = Booking::find($booking['id']);
                        $existingGuests = is_array($existing?->guest_ids) ? array_map('intval', $existing->guest_ids) : [];
                        sort($existingGuests);
                        if (Booking::find($bookingId)) {
                            $this->line("⏭️ Skipped booking ID {$bookingId} — already exists.");
                            $this->info("ℹ️  Booking ID {$bookingId} is already stored. Skipping fetch and sync.");
                            $this->logSync('booking', $bookingId, 'skipped', 'Already exists in DB');
                            continue;
                        }


                        $unchanged = $existing &&
                            $existingGuests === $syncedGuestIds &&
                            (int) $existing->room_id === (int) $booking['room_id'] &&
                            $existing->check_in === ($booking['arrival_date'] ?? null) &&
                            $existing->check_out === ($booking['departure_date'] ?? null) &&
                            $existing->status === ($booking['status'] ?? null) &&
                            ($existing->notes ?? '') === ($booking['notes'] ?? '');

                        if ($unchanged) {
                            DB::rollBack();
                            $this->logSync('booking', $bookingId, 'skipped', 'Already up-to-date');
                            continue;
                        }

                        $bookingModel = Booking::firstOrNew(['id' => $booking['id']]);
                        $bookingModel->fill([
                            'external_id' => $booking['external_id'] ?? null,
                            'room_id' => $booking['room_id'],
                            'check_in' => $booking['arrival_date'] ?? null,
                            'check_out' => $booking['departure_date'] ?? null,
                            'status' => $booking['status'] ?? null,
                            'notes' => $booking['notes'] ?? null,
                        ]);
                        $bookingModel->guest_ids = $syncedGuestIds;

                        $bookingModel->save();
                        DB::commit();
                        $this->line("✅ Synced booking ID: {$booking['id']}");
                        $this->logSync('booking', $booking['id'], 'success', 'Booking synced successfully');
                        $this->line("--- Finished fetch booking ID: {$bookingId} ---\n");
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error("Error syncing booking ID {$bookingId}", ['error' => $e->getMessage()]);
                        $this->error("Failed booking ID {$bookingId}: " . $e->getMessage());
                        $this->logSync('booking', $bookingId, 'failed', $e->getMessage());
                    }
                }

                $this->bulkUpsertRooms($roomsToSync);
                $this->bulkUpsertRoomTypes($roomTypesToSync);
                $this->bulkUpsertGuests($guestsToSync);
                $roomsToSync = [];
                $roomTypesToSync = [];
                $guestsToSync = [];
                $this->line("Processed chunk of " . count($chunk) . " bookings.");
            }

            $this->line("✅ Sync complete.");
            $this->logSync('logTest', 0, 'info', 'Sync complete');
        } catch (\Exception $e) {
            Log::error("Global sync failure", ['error' => $e->getMessage()]);
            $this->error("Sync failed: " . $e->getMessage());
            $this->logSync('logTest', 0, 'failed', 'Global failure: ' . $e->getMessage());
        }
    }

    protected function bulkUpsertRooms(array $rooms)
    {
        if (!empty($rooms)) {
            Room::upsert(array_values($rooms), ['id'], ['number', 'floor', 'room_type_id']);
            $this->info("🏨 Upserted " . count($rooms) . " rooms.");
        }
    }

    protected function bulkUpsertRoomTypes(array $roomTypes)
    {
        if (!empty($roomTypes)) {
            RoomType::upsert(array_values($roomTypes), ['id'], ['name', 'description']);
            $this->info("🛏️ Upserted " . count($roomTypes) . " room types.");
        }
    }

    protected function bulkUpsertGuests(array $guests)
    {
        if (!empty($guests)) {
            Guest::upsert(array_values($guests), ['id'], ['first_name', 'last_name', 'email', 'phone']);
            $this->info("👤 Upserted " . count($guests) . " guests.");
        }
    }

    protected function logSync($type, $id, $status, $message = null)
    {
        SyncLog::create([
            'resource_type' => $type,
            'resource_id' => $id,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
