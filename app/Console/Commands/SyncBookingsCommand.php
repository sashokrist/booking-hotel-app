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

            foreach (array_chunk($bookingIds, 100) as $chunk) {
                $skippedCount = 0;
                $roomsToSync = [];
                $roomTypesToSync = [];
                $guestsToSync = [];
                $bookingsToSync = [];
                $guestCache = [];

                foreach ($chunk as $bookingId) {

                    if (Booking::where('id', $bookingId)->exists()) {
                         $skippedCount++;
                        $this->info("⏭️ Booking ID {$bookingId} already exists. Skipping.");
                        $this->logSync('booking', $bookingId, 'skipped', 'Already exists in DB');
                        continue;
                    }

                    $this->line("\n--- Starting fetch booking ID: {$bookingId} ---");

                    $response = Http::pms()->get("bookings/{$bookingId}");
                    usleep($this->rateLimitDelay);
                    $booking = $response->successful() ? $response->json() : null;

                    if (!$booking || empty($booking['id']) || empty($booking['guest_ids']) || !is_array($booking['guest_ids'])) {
                        $this->warn("Skipping booking ID {$bookingId} - invalid/missing guest_ids.");
                        $this->logSync('booking', $bookingId, 'failed', 'Invalid booking data');
                        continue;
                    }

                    $room = Http::pms()->get("rooms/{$booking['room_id']}")->json();
                    usleep($this->rateLimitDelay);
                    $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);
                    if (!$roomTypeId || empty($room['id'])) {
                        $this->logSync('booking', $bookingId, 'failed', 'Missing room_type_id or room id');
                        continue;
                    }

                    $roomsToSync[$room['id']] = [
                        'id' => $room['id'],
                        'number' => $room['number'] ?? null,
                        'floor' => $room['floor'] ?? null,
                        'room_type_id' => $roomTypeId,
                    ];

                    $roomType = Http::pms()->get("room-types/{$roomTypeId}")->json();
                    usleep($this->rateLimitDelay);

                    if (!empty($roomType['id'])) {
                        $roomTypesToSync[$roomType['id']] = [
                            'id' => $roomType['id'],
                            'name' => $roomType['name'] ?? null,
                            'description' => $roomType['description'] ?? null,
                        ];
                    }

                    $syncedGuestIds = [];
                    foreach ($booking['guest_ids'] as $guestId) {
                        if (isset($guestCache[$guestId])) {
                            $guest = $guestCache[$guestId];
                        } else {
                            $guestResponse = Http::pms()->get("guests/{$guestId}");
                            usleep($this->rateLimitDelay);
                            if ($guestResponse->failed()) continue;
                            $guest = $guestResponse->json();
                            $guestCache[$guestId] = $guest;
                        }

                        if (empty($guest['id'])) continue;

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
                        $this->logSync('booking', $bookingId, 'failed', 'Mismatched guest_ids');
                        continue;
                    }

                    $bookingsToSync[] = [
                        'id' => $booking['id'],
                        'external_id' => $booking['external_id'] ?? null,
                        'room_id' => $booking['room_id'],
                        'check_in' => $booking['arrival_date'] ?? null,
                        'check_out' => $booking['departure_date'] ?? null,
                        'status' => $booking['status'] ?? null,
                        'notes' => $booking['notes'] ?? null,
                        'guest_ids' => json_encode($syncedGuestIds),
                    ];

                    $this->line("✅ Prepared booking ID: {$booking['id']}");
                }

                $this->bulkUpsertRooms(array_values($roomsToSync));
                $this->bulkUpsertRoomTypes(array_values($roomTypesToSync));
                $this->bulkUpsertGuests(array_values($guestsToSync));
                $this->bulkUpsertBookings($bookingsToSync);

                $this->line("⏭️ Skipped $skippedCount bookings in this chunk.");
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
            Room::upsert($rooms, ['id'], ['number', 'floor', 'room_type_id']);
            $this->info("🏨 Upserted " . count($rooms) . " rooms.");
        }
    }

    protected function bulkUpsertRoomTypes(array $roomTypes)
    {
        if (!empty($roomTypes)) {
            RoomType::upsert($roomTypes, ['id'], ['name', 'description']);
            $this->info("🛏️ Upserted " . count($roomTypes) . " room types.");
        }
    }

    protected function bulkUpsertGuests(array $guests)
    {
        if (!empty($guests)) {
            Guest::upsert($guests, ['id'], ['first_name', 'last_name', 'email', 'phone']);
            $this->info("👤 Upserted " . count($guests) . " guests.");
        }
    }

    protected function bulkUpsertBookings(array $bookings)
    {
        if (!empty($bookings)) {
            Booking::upsert($bookings, ['id'], ['external_id', 'room_id', 'check_in', 'check_out', 'status', 'notes', 'guest_ids']);
            $this->info("📘 Upserted " . count($bookings) . " bookings.");
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
