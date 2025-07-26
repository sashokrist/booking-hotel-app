<?php

namespace App\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\{Booking, Guest, Room, RoomType, SyncLog};

class BookingSyncService
{
    protected int $delay = 500000; // default 500ms

    public function setDelay(int $delay): static
    {
        $this->delay = $delay;
        return $this;
    }

    public function syncBookings(?string $since, Command $console): void
    {
        $console->line("Syncing bookings updated since: $since");
        SyncLog::log('logTest', 0, 'info', 'Starting sync: ' . $since);

        $report = [];
        $latestReportPath = storage_path('app/reports/latest_booking_sync.csv');

        try {
            $response = Http::pms()->get('bookings', ['updated_at.gt' => $since]);
            usleep($this->delay);

            if ($response->failed()) {
                Log::error("Failed to fetch booking IDs", ['response' => $response->body()]);
                $console->error('Failed to fetch booking IDs');
                SyncLog::log('logTest', 0, 'failed', 'Failed to fetch booking IDs');
                return;
            }

            $bookingIds = collect($response->json('data') ?? [])
                ->map(fn ($item) => is_array($item) ? ($item['id'] ?? null) : (is_numeric($item) ? $item : null))
                ->filter(fn ($id) => is_numeric($id))
                ->unique()
                ->values()
                ->all();

            $console->line("Fetched " . count($bookingIds) . " updated bookings.");
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
                        $console->info("Booking ID {$bookingId} already exists. Skipping.");
                        SyncLog::log('booking', $bookingId, 'skipped', 'Already exists in DB');
                        continue;
                    }

                    $console->line("\n--- Starting fetch booking ID: {$bookingId} ---");

                    $booking = Cache::remember("booking:{$bookingId}", 3600, function () use ($bookingId) {
                        $response = Http::pms()->get("bookings/{$bookingId}");
                        usleep($this->delay);
                        return $response->successful() ? $response->json() : null;
                    });

                    if (!$booking || empty($booking['id']) || empty($booking['guest_ids']) || !is_array($booking['guest_ids'])) {
                        $console->warn("Skipping booking ID {$bookingId} - invalid/missing guest_ids.");
                        SyncLog::log('booking', $bookingId, 'failed', 'Invalid booking data');
                        continue;
                    }

                    $room = Cache::remember("room:{$booking['room_id']}", 3600, function () use ($booking) {
                        $response = Http::pms()->get("rooms/{$booking['room_id']}");
                        usleep($this->delay);
                        return $response->successful() ? $response->json() : null;
                    });

                    $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);
                    if (!$roomTypeId || empty($room['id'])) {
                        SyncLog::log('booking', $bookingId, 'failed', 'Missing room_type_id or room id');
                        continue;
                    }

                    $roomType = Cache::remember("room_type:{$roomTypeId}", 3600, function () use ($roomTypeId) {
                        $response = Http::pms()->get("room-types/{$roomTypeId}");
                        usleep($this->delay);
                        return $response->successful() ? $response->json() : null;
                    });

                    $roomTypeName = $roomType['name'] ?? 'N/A';
                    $roomTypeDescription = $roomType['description'] ?? 'N/A';

                    $console->line("Booking ID: {$booking['id']} | External ID: " . ($booking['external_id'] ?? 'N/A') .
                        " | Room ID: {$booking['room_id']} | Guest IDs: " . implode(',', $booking['guest_ids']));

                    $console->line("Check-in: " . ($booking['arrival_date'] ?? 'N/A') .
                        " | Check-out: " . ($booking['departure_date'] ?? 'N/A') .
                        " | Status: " . ($booking['status'] ?? 'N/A') .
                        " | Notes: " . ($booking['notes'] ?? 'None'));

                    $console->line("Room: ID {$room['id']} | Number: {$room['number']} | RoomType: {$roomTypeName} | Floor: {$room['floor']}");

                    if (!empty($roomType['id'])) {
                        $console->line("RoomType: ID {$roomType['id']} | RoomType Name: {$roomTypeName} | RoomType Description: {$roomTypeDescription}");
                    }

                    $guestNames = [];
                    $syncedGuestIds = [];
                    foreach ($booking['guest_ids'] as $guestId) {
                        $guest = Cache::remember("guest:{$guestId}", 3600, function () use ($guestId) {
                            $response = Http::pms()->get("guests/{$guestId}");
                            usleep($this->delay);
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
                        $guestName = trim(($guest['first_name'] ?? '') . ' ' . ($guest['last_name'] ?? ''));
                        $guestNames[] = $guestName;
                        $console->line("Guest ID: {$guest['id']}, Name: {$guestName}");
                    }

                    $expectedGuestIds = array_map('intval', $booking['guest_ids']);
                    sort($expectedGuestIds);
                    sort($syncedGuestIds);

                    if ($expectedGuestIds !== $syncedGuestIds) {
                        SyncLog::log('booking', $bookingId, 'failed', 'Mismatched guest_ids');
                        continue;
                    }

                    $roomsToSync[$room['id']] = [
                        'id' => $room['id'],
                        'number' => $room['number'] ?? null,
                        'floor' => $room['floor'] ?? null,
                        'room_type_id' => $roomTypeId,
                    ];

                    if (!empty($roomType['id'])) {
                        $roomTypesToSync[$roomType['id']] = [
                            'id' => $roomType['id'],
                            'name' => $roomType['name'] ?? null,
                            'description' => $roomType['description'] ?? null,
                        ];
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

                    $report[] = [
                        'Booking ID' => $booking['id'],
                        'External ID' => $booking['external_id'] ?? '',
                        'Check-in' => $booking['arrival_date'] ?? '',
                        'Check-out' => $booking['departure_date'] ?? '',
                        'Status' => $booking['status'] ?? '',
                        'Room Number' => $room['number'] ?? '',
                        'Room Floor' => $room['floor'] ?? '',
                        'RoomType Name' => $roomTypeName,
                        'RoomType Desc' => $roomTypeDescription,
                        'Guests' => implode('; ', $guestNames),
                    ];

                    $console->line("Prepared booking ID: {$booking['id']}");
                }

                Room::bulkUpsert(array_values($roomsToSync), $console);
                RoomType::bulkUpsert(array_values($roomTypesToSync), $console);
                Guest::bulkUpsert(array_values($guestsToSync), $console);
                Booking::bulkUpsert($bookingsToSync, $console);

                $console->line("Skipped $skippedCount bookings in this chunk.");
                $console->line("Processed chunk of " . count($chunk) . " bookings.");

                // Flush report after every chunk
                if (!empty($report)) {
                    Storage::makeDirectory('reports');
                    $fp = fopen($latestReportPath, 'w');
                    fputcsv($fp, array_keys($report[0]));
                    foreach ($report as $row) {
                        fputcsv($fp, $row);
                    }
                    fclose($fp);
                    Log::info('📁 Partial CSV report saved.', ['file' => $latestReportPath]);
                    $console->info("📁 Partial report saved: storage/app/reports/latest_booking_sync.csv");
                }
            }

            $console->line("✅ Sync complete.");
            SyncLog::log('logTest', 0, 'info', 'Sync complete');
        } catch (\Exception $e) {
            Log::error("Global sync failure", ['error' => $e->getMessage()]);
            $console->error("Sync failed: " . $e->getMessage());
            SyncLog::log('logTest', 0, 'failed', 'Global failure: ' . $e->getMessage());
        }
    }
}