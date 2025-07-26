<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
        SyncLog::log('logTest', 0, 'info', 'Starting sync: ' . $since);

        try {
            $response = Http::pms()->get('bookings', ['updated_at.gt' => $since]);
            usleep($this->rateLimitDelay);

            if ($response->failed()) {
                Log::error("Failed to fetch booking IDs", ['response' => $response->body()]);
                $this->error('Failed to fetch booking IDs');
                SyncLog::log('logTest', 0, 'failed', 'Failed to fetch booking IDs');
                return;
            }

            $bookingIds = collect($response->json('data') ?? [])
                ->map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : (is_numeric($item) ? $item : null))
                ->filter(fn ($id) => is_numeric($id))
                ->unique()
                ->values()
                ->all();

            $this->line("Fetched " . count($bookingIds) . " updated bookings.");
            SyncLog::log('logTest', 0, 'info', 'Fetched ' . count($bookingIds) . ' bookings');

            foreach (array_chunk($bookingIds, 100) as $chunk) {
                $skippedCount = 0;
                $roomsToSync = [];
                $roomTypesToSync = [];
                $guestsToSync = [];
                $bookingsToSync = [];

                foreach ($chunk as $bookingId) {
                    if (Booking::where('id', $bookingId)->exists()) {
                        $skippedCount++;
                        $this->info("⏭️ Booking ID {$bookingId} already exists. Skipping.");
                        SyncLog::log('booking', $bookingId, 'skipped', 'Already exists in DB');
                        continue;
                    }

                    $this->line("\n--- Starting fetch booking ID: {$bookingId} ---");

                    $booking = Cache::remember("booking:{$bookingId}", 3600, function () use ($bookingId) {
                        $response = Http::pms()->get("bookings/{$bookingId}");
                        usleep($this->rateLimitDelay);
                        return $response->successful() ? $response->json() : null;
                    });

                    if (!$booking || empty($booking['id']) || empty($booking['guest_ids']) || !is_array($booking['guest_ids'])) {
                        $this->warn("Skipping booking ID {$bookingId} - invalid/missing guest_ids.");
                        SyncLog::log('booking', $bookingId, 'failed', 'Invalid booking data');
                        continue;
                    }

                    $room = Cache::remember("room:{$booking['room_id']}", 3600, function () use ($booking) {
                        $response = Http::pms()->get("rooms/{$booking['room_id']}");
                        usleep($this->rateLimitDelay);
                        return $response->successful() ? $response->json() : null;
                    });

                    $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);
                    if (!$roomTypeId || empty($room['id'])) {
                        SyncLog::log('booking', $bookingId, 'failed', 'Missing room_type_id or room id');
                        continue;
                    }

                    $roomType = Cache::remember("room_type:{$roomTypeId}", 3600, function () use ($roomTypeId) {
                        $response = Http::pms()->get("room-types/{$roomTypeId}");
                        usleep($this->rateLimitDelay);
                        return $response->successful() ? $response->json() : null;
                    });

                    $roomTypeName = $roomType['name'] ?? 'N/A';
                    $roomTypeDescription = $roomType['description'] ?? 'N/A';

                    $this->line(
                        "📘 Booking ID: {$booking['id']} | External ID: " . ($booking['external_id'] ?? 'N/A') .
                        " | Room ID: {$booking['room_id']} | Guest IDs: " . implode(',', $booking['guest_ids']) . "\n" .
                        "📅 Check-in: " . ($booking['arrival_date'] ?? 'N/A') .
                        " | Check-out: " . ($booking['departure_date'] ?? 'N/A') .
                        " | Status: " . ($booking['status'] ?? 'N/A') .
                        " | Notes: " . ($booking['notes'] ?? 'None')
                    );

                    $this->line("🏨 Room: ID {$room['id']} | Number: {$room['number']} | RoomType: {$roomTypeName} | Floor: {$room['floor']}");

                    if (!empty($roomType['id'])) {
                        $roomTypesToSync[$roomType['id']] = [
                            'id' => $roomType['id'],
                            'name' => $roomType['name'] ?? null,
                            'description' => $roomType['description'] ?? null,
                        ];
                        $this->line("🛏️ RoomType: ID {$roomType['id']} | RoomType Name: {$roomTypeName} | RoomType Description: {$roomTypeDescription}");
                    }

                    $roomsToSync[$room['id']] = [
                        'id' => $room['id'],
                        'number' => $room['number'] ?? null,
                        'floor' => $room['floor'] ?? null,
                        'room_type_id' => $roomTypeId,
                    ];

                    $syncedGuestIds = [];
                    foreach ($booking['guest_ids'] as $guestId) {
                        $guest = Cache::remember("guest:{$guestId}", 3600, function () use ($guestId) {
                            $response = Http::pms()->get("guests/{$guestId}");
                            usleep($this->rateLimitDelay);
                            return $response->successful() ? $response->json() : null;
                        });

                        if (empty($guest['id'])) continue;

                        $guestsToSync[$guest['id']] = [
                            'id' => $guest['id'],
                            'first_name' => $guest['first_name'] ?? null,
                            'last_name' => $guest['last_name'] ?? null,
                            'email' => $guest['email'] ?? null,
                            'phone' => $guest['phone'] ?? null,
                        ];

                        $syncedGuestIds[] = (int) $guest['id'];

                        $this->line("👤 Guest ID: {$guest['id']}, Name: {$guest['first_name']} {$guest['last_name']}");
                    }

                    $expectedGuestIds = array_map('intval', $booking['guest_ids']);
                    sort($expectedGuestIds);
                    sort($syncedGuestIds);

                    if ($expectedGuestIds !== $syncedGuestIds) {
                        SyncLog::log('booking', $bookingId, 'failed', 'Mismatched guest_ids');
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

                Room::bulkUpsert(array_values($roomsToSync), $this);
                RoomType::bulkUpsert(array_values($roomTypesToSync), $this);
                Guest::bulkUpsert(array_values($guestsToSync), $this);
                Booking::bulkUpsert($bookingsToSync, $this);

                $this->line("⏭️ Skipped $skippedCount bookings in this chunk.");
                $this->line("Processed chunk of " . count($chunk) . " bookings.");
            }

            $this->line("✅ Sync complete.");
            SyncLog::log('logTest', 0, 'info', 'Sync complete');
        } catch (\Exception $e) {
            Log::error("Global sync failure", ['error' => $e->getMessage()]);
            $this->error("Sync failed: " . $e->getMessage());
            SyncLog::log('logTest', 0, 'failed', 'Global failure: ' . $e->getMessage());
        }
    }
}
