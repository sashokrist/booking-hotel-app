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
        $since = $this->option('since') ?? now()->subDay()->toIso8601String();
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
                foreach ($chunk as $bookingId) {
                    DB::beginTransaction();

                    try {
                        $response = Http::pms()->get("bookings/{$bookingId}");
                        usleep($this->rateLimitDelay);

                        $booking = $response->successful() ? $response->json() : null;

                        $this->line("Fetched booking {$bookingId} guest_ids from PMS: [" . implode(', ', $booking['guest_ids']) . "]");
                        $this->line("🔄 Start syncing booking ID: {$bookingId}");

                        if (!$booking || empty($booking['id']) || empty($booking['guest_ids']) || !is_array($booking['guest_ids'])) {
                            DB::rollBack();
                            $this->warn("⚠️ Skipping booking ID {$bookingId} — invalid or missing guest_ids.");
                            $this->logSync('booking', $bookingId, 'failed', 'Invalid or incomplete booking data');
                            continue;
                        }

                        $room = Http::pms()->get("rooms/{$booking['room_id']}")->json();
                        usleep($this->rateLimitDelay);

                        $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);
                        if (!$roomTypeId) {
                            Log::warning("⚠️ Skipping booking ID {$bookingId} due to missing room_type_id", [
                                'room' => $room,
                                'booking' => $booking,
                            ]);
                            DB::rollBack();
                            $this->logSync('booking', $bookingId, 'failed', 'Missing room_type_id');
                            continue;
                        }

                        Room::updateOrCreate(
                            ['id' => $room['id']],
                            [
                                'number' => $room['number'] ?? null,
                                'floor' => $room['floor'] ?? null,
                                'room_type_id' => $roomTypeId,
                            ]
                        );
                        $this->line("   🏨 Room ID: {$room['id']} | Number: {$room['number']} | Floor: {$room['floor']} | Room Type ID: {$roomTypeId}");

                        $roomType = Http::pms()->get("room-types/{$roomTypeId}")->json();
                        usleep($this->rateLimitDelay);

                        RoomType::updateOrCreate(
                            ['id' => $roomType['id']],
                            [
                                'name' => $roomType['name'] ?? null,
                                'description' => $roomType['description'] ?? null,
                            ]
                        );
                       $this->line("   🛏️ RoomType ID: {$roomType['id']} | Name: {$roomType['name']} | Description: " . ($roomType['description'] ?? 'N/A'));

                        $syncedGuestIds = [];

                        foreach ($booking['guest_ids'] as $guestId) {
                            $guestResponse = Http::pms()->get("guests/{$guestId}");
                            usleep($this->rateLimitDelay);

                            if ($guestResponse->failed()) {
                                Log::warning("⚠️ Failed to fetch guest ID {$guestId}", ['response' => $guestResponse->body()]);
                                $this->warn("   ⚠️ Guest ID {$guestId} skipped (fetch failed)");
                                $this->logSync('guest', $guestId, 'failed', 'Failed to fetch guest from PMS');
                                continue;
                            }

                            $guest = $guestResponse->json();

                            if (empty($guest['id'])) {
                                Log::warning("⚠️ Guest missing ID in response", ['guest' => $guest]);
                                $this->warn("   ⚠️ Guest ID {$guestId} skipped (missing ID)");
                                continue;
                            }

                            Guest::updateOrCreate(
                                ['id' => $guest['id']],
                                [
                                    'first_name' => $guest['first_name'] ?? null,
                                    'last_name' => $guest['last_name'] ?? null,
                                    'email' => $guest['email'] ?? null,
                                    'phone' => $guest['phone'] ?? null,
                                ]
                            );

                            $syncedGuestIds[] = (int) $guest['id'];
                            $this->line("   👤 Guest synced: ID {$guest['id']} ({$guest['first_name']} {$guest['last_name']})");
                            $this->logSync('guest', $guest['id'], 'success', "Synced guest {$guest['first_name']} {$guest['last_name']}");
                        }

                        $expectedGuestIds = array_map('intval', $booking['guest_ids']);
                        sort($expectedGuestIds);
                        sort($syncedGuestIds);

                        if ($expectedGuestIds !== $syncedGuestIds) {
                            DB::rollBack();
                            $this->warn("Skipping booking ID {$bookingId} — mismatched guest_ids. Expected: [" . implode(',', $expectedGuestIds) . "] Got: [" . implode(',', $syncedGuestIds) . "]");
                            $this->logSync('booking', $bookingId, 'failed', 'Mismatched guest_ids');
                            continue;
                        }

                        $existing = Booking::find($booking['id']);

                        $existingGuests = is_array($existing?->guest_ids) ? array_map('intval', $existing->guest_ids) : [];
                        $newGuests = array_map('intval', $syncedGuestIds);

                        sort($existingGuests);
                        sort($newGuests);

                        $unchanged = $existing &&
                            $existingGuests === $newGuests &&
                            (int) $existing->room_id === (int) $booking['room_id'] &&
                            $existing->check_in === ($booking['arrival_date'] ?? null) &&
                            $existing->check_out === ($booking['departure_date'] ?? null) &&
                            $existing->status === ($booking['status'] ?? null) &&
                            ($existing->notes ?? '') === ($booking['notes'] ?? '');

                        if ($unchanged) {
                            DB::rollBack();
                            $this->warn("⏭️ Skipped booking ID {$bookingId} — already up-to-date.");
                            $this->logSync('booking', $bookingId, 'skipped', 'Already up-to-date');
                            continue;
                        }

                        $bookingModel = Booking::firstOrNew(['id' => $booking['id']]);

                        $this->line("   → Booking guests: [" . implode(', ', $syncedGuestIds) . "]");

                        $bookingModel->fill([
                            'external_id' => $booking['external_id'] ?? null,
                            'room_id' => $booking['room_id'],
                            'check_in' => $booking['arrival_date'] ?? null,
                            'check_out' => $booking['departure_date'] ?? null,
                            'status' => $booking['status'] ?? null,
                            'notes' => $booking['notes'] ?? null,
                        ]);
                        $bookingModel->guest_ids = array_map('intval', $syncedGuestIds);

                        if ($bookingModel->isDirty()) {
                            $bookingModel->save();
                            DB::commit();
                            Log::info("Booking saved", ['id' => $booking['id']]);
                            $this->line("✅ Synced booking ID: {$booking['id']}");
                            $this->logSync('booking', $booking['id'], 'success', 'Booking synced successfully');
                        } else {
                            DB::rollBack();
                            $this->warn("⏭️ Skipped booking ID {$bookingId} — already up-to-date.");
                            $this->logSync('booking', $bookingId, 'skipped', 'Already up-to-date (no model changes)');
                        }
                    } catch (\Exception $e) {
                        DB::rollBack();
                        Log::error("Error syncing booking ID {$bookingId}", [
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        $this->error("Failed booking ID {$bookingId}: " . $e->getMessage());
                        $this->logSync('booking', $bookingId, 'failed', $e->getMessage());
                    }
                }
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

    protected function logSync($type, $id, $status, $message = null)
    {
        SyncLog::create([
            'resource_type' => $type,
            'resource_id'   => $id,
            'status'        => $status,
            'message'       => $message,
        ]);
    }
}
