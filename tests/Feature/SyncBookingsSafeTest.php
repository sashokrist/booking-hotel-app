<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class SyncBookingsSafeTest extends TestCase
{
    public function test_sync_bookings_safe_run()
    {
        Http::fake([
            'https://api.pms.donatix.info/api/bookings*' => Http::response([
                'data' => [1001],
            ], 200),

            'https://api.pms.donatix.info/api/bookings/1001' => Http::response([
                'id' => 1001,
                'external_id' => 'ABC123',
                'room_id' => 201,
                'guest_ids' => [401],
                'arrival_date' => '2025-07-20',
                'departure_date' => '2025-07-22',
                'status' => 'confirmed',
                'notes' => 'Safe test booking',
            ], 200),

            'https://api.pms.donatix.info/api/rooms/201' => Http::response([
                'id' => 201,
                'number' => '201',
                'floor' => 2,
                'room_type_id' => 301,
            ], 200),

            'https://api.pms.donatix.info/api/room-types/301' => Http::response([
                'id' => 301,
                'name' => 'Deluxe Room',
                'description' => 'Comfortable deluxe room',
            ], 200),

            'https://api.pms.donatix.info/api/guests/401' => Http::response([
                'id' => 401,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '0000000000',
            ], 200),
        ]);

        Artisan::call('sync:bookings', ['--since' => '2025-07-20']);

        $this->assertDatabaseHas('bookings', [
            'id' => 1001,
            'external_id' => 'EXT-BKG-1001',
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('guests', [
            'id' => 401,
            'first_name' => 'John',
        ]);
    }
}
