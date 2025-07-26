<?php

namespace App\Services;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\{Booking, Guest, Room, RoomType, SyncLog};
use App\Traits\BookingSyncHelpers;

class BookingSyncService
{
    use BookingSyncHelpers;

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
            $bookingIds = $this->fetchUpdatedBookingIds($since);

            if (empty($bookingIds)) {
                $console->error('Failed to fetch booking IDs or no updated bookings found.');
                SyncLog::log('logTest', 0, 'failed', 'No booking IDs fetched.');
                return;
            }

            $console->line("Fetched " . count($bookingIds) . " updated bookings.");
            SyncLog::log('logTest', 0, 'info', 'Fetched ' . count($bookingIds) . ' bookings');

            foreach (array_chunk($bookingIds, 100) as $chunk) {
                $skippedCount = 0;
                $roomsToSync = [];
                $roomTypesToSync = [];
                $guestsToSync = [];
                $bookingsToSync = [];

                foreach ($chunk as $bookingId) {
                    if ($this->bookingExists($bookingId)) {
                        $skippedCount++;
                        $console->info("Booking ID {$bookingId} already exists. Skipping.");
                        SyncLog::log('booking', $bookingId, 'skipped', 'Already exists in DB');
                        continue;
                    }

                    $console->line("\n--- Starting fetch booking ID: {$bookingId} ---");

                    $booking = $this->fetchBooking($bookingId);

                    if (!$booking || empty($booking['id']) || empty($booking['guest_ids']) || !is_array($booking['guest_ids'])) {
                        $console->warn("Skipping booking ID {$bookingId} - invalid/missing guest_ids.");
                        SyncLog::log('booking', $bookingId, 'failed', 'Invalid booking data');
                        continue;
                    }

                    $room = $this->fetchRoom($booking);

                    $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);
                    if (!$roomTypeId || empty($room['id'])) {
                        SyncLog::log('booking', $bookingId, 'failed', 'Missing room_type_id or room id');
                        continue;
                    }

                    $roomType = $this->fetchRoomType($roomTypeId);

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

                    $guestData = $this->fetchGuests($booking['guest_ids'], $console);
                    $guestsToSync = array_merge($guestsToSync, $guestData['guestsToSync']);
                    $guestNames = $guestData['guestNames'];
                    $syncedGuestIds = $guestData['syncedGuestIds'];

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

                if (!empty($report)) {
                    $this->writeCsvReport($report, $latestReportPath, $console);
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

    private function writeCsvReport(array $report, string $path, Command $console): void
    {
        Storage::makeDirectory('reports');
        $fp = fopen($path, 'w');
        fputcsv($fp, array_keys($report[0]));
        foreach ($report as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);
        Log::info('📁 Partial CSV report saved.', ['file' => $path]);
        $console->info("📁 Partial report saved: storage/app/reports/latest_booking_sync.csv");
    }
}
