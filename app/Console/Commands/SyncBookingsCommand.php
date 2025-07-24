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

class SyncBookingsCommand extends Command
{
    protected $signature = 'sync:bookings {--since=}';
    protected $description = 'Sync bookings, guests, rooms, and room types from PMS API';
    protected $rateLimitDelay = 500000; // 500ms = 2 requests per second

    public function handle()
    {
        $since = $this->option('since') ?? now()->subDay()->toIso8601String();
        $this->info("Syncing bookings updated since: $since");

        try {
            $response = Http::pms()->get('bookings', [
                'updated_at.gt' => $since,
            ]);
            usleep($this->rateLimitDelay);

            if ($response->failed()) {
                Log::error("❌ Failed to fetch booking IDs", ['response' => $response->body()]);
                $this->error('Failed to fetch booking IDs');
                return;
            }

            $bookingIds = $response->json('data') ?? [];
        $this->info("Fetched " . count($bookingIds) . " updated bookings.");

        $chunkSize = 100; // adjust as needed

        foreach (array_chunk($bookingIds, $chunkSize) as $chunk) {
            foreach ($chunk as $bookingId) {
                if (!is_numeric($bookingId)) {
                    Log::warning("⚠️ Skipping invalid booking ID", ['value' => $bookingId]);
                    continue;
                }

                DB::beginTransaction();

                try {
                    $booking = Http::pms()->get("bookings/{$bookingId}")->json();
                    usleep($this->rateLimitDelay);

                    if (empty($booking['id']) || empty($booking['room_id'])) {
                        throw new \Exception("Invalid booking data for ID: $bookingId");
                    }

                    // Fetch room
                    $room = Http::pms()->get("rooms/{$booking['room_id']}")->json();
                    usleep($this->rateLimitDelay);

                    // Fallback for missing room_type_id
                    $roomTypeId = $room['room_type_id'] ?? ($booking['room_type_id'] ?? null);

                    if (!$roomTypeId) {
                        Log::warning("⚠️ Skipping booking ID {$bookingId} due to missing room_type_id", [
                            'room' => $room,
                            'booking' => $booking
                        ]);
                        DB::rollBack();
                        continue;
                    }

                    Room::updateOrCreate(
                        ['id' => $room['id']],
                        [
                            'number'        => $room['number'] ?? null,
                            'room_type_id'  => $roomTypeId,
                            'status'        => $room['status'] ?? null,
                        ]
                    );

                    // Fetch room type
                    $roomType = Http::pms()->get("room-types/{$roomTypeId}")->json();
                    usleep($this->rateLimitDelay);

                    RoomType::updateOrCreate(
                        ['id' => $roomType['id']],
                        [
                            'name'        => $roomType['name'] ?? null,
                            'description' => $roomType['description'] ?? null,
                            'capacity'    => $roomType['capacity'] ?? null,
                            'price'       => $roomType['price'] ?? null,
                        ]
                    );

                    // Sync guests
                    foreach ($booking['guest_ids'] ?? [] as $guestId) {
                        $guest = Http::pms()->get("guests/{$guestId}")->json();
                        usleep($this->rateLimitDelay);

                        Guest::updateOrCreate(
                            ['id' => $guest['id']],
                            [
                                'first_name' => $guest['first_name'] ?? null,
                                'last_name'  => $guest['last_name'] ?? null,
                                'email'      => $guest['email'] ?? null,
                                'phone'      => $guest['phone'] ?? null,
                            ]
                        );
                    }

                    // Store booking
                    $bookingModel = Booking::updateOrCreate(
                        ['id' => $booking['id']],
                        [
                            'room_id'   => $booking['room_id'],
                            'check_in'  => $booking['check_in'] ?? null,
                            'check_out' => $booking['check_out'] ?? null,
                            'status'    => $booking['status'] ?? null,
                        ]
                    );

                    $bookingModel->guest_ids = json_encode($booking['guest_ids'] ?? []);
                    $bookingModel->save();

                    DB::commit();
                    Log::info("✅ Booking saved", ['id' => $booking['id']]);
                    $this->line("✅ Synced booking ID: {$booking['id']}");
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error("❌ Error syncing booking ID {$bookingId}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    $this->error("❌ Failed booking ID {$bookingId}: " . $e->getMessage());
                }
            }

            $this->info("✅ Sync complete.");
        }
        } catch (\Exception $e) {
            Log::error("❌ Global sync failure", ['error' => $e->getMessage()]);
            $this->error("Sync failed: " . $e->getMessage());
        }

         $this->info("✅ Processed chunk of " . count($chunk) . " bookings.");
    }
}
