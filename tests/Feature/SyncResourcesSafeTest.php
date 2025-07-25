<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class SyncResourcesSafeTest extends TestCase
{
    /** 
     * This safe test verifies syncing of room, room_type, and guest without affecting real data.
     */
    public function test_sync_related_resources_safe()
    {
        // Fake API responses for known safe IDs
        Http::fake([
            'https://api.pms.donatix.info/api/bookings*' => Http::response(['data' => [1001]]),
            'https://api.pms.donatix.info/api/bookings/1001' => Http::response([
                'id' => 1001,
                'external_id' => 'EXT-BKG-1001',
                'room_id' => 201,
                'guest_ids' => [401],
                'arrival_date' => '2025-07-30',
                'departure_date' => '2025-08-02',
                'status' => 'confirmed',
                'notes' => 'Testing full sync path',
            ]),
            'https://api.pms.donatix.info/api/rooms/201' => Http::response([
                'id' => 201,
                'number' => '201',
                'floor' => 2,
                'room_type_id' => 301,
            ]),
            'https://api.pms.donatix.info/api/room-types/301' => Http::response([
                'id' => 301,
                'name' => 'Deluxe',
                'description' => 'Deluxe King Size Room',
            ]),
            'https://api.pms.donatix.info/api/guests/401' => Http::response([
                'id' => 401,
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '123456789',
            ]),
        ]);

        // Trigger the command
        Artisan::call('sync:bookings', ['--since' => '2025-07-20']);

        // Assert each resource was saved correctly
        $this->assertDatabaseHas('bookings', [
            'id' => 1001,
            'external_id' => 'EXT-BKG-1001',
            'status' => 'confirmed',
        ]);

        $this->assertDatabaseHas('rooms', [
            'id' => 201,
            'number' => '201',
            'floor' => 2,
        ]);

        $this->assertDatabaseHas('room_types', [
            'id' => 301,
        ]);

        $this->assertDatabaseHas('guests', [
            'id' => 401,
            'first_name' => 'John',
            'email' => 'john@example.com',
        ]);
    }
}
