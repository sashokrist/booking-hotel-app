<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class SyncBookingsCommandTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function testSyncBookingsCommandRuns()
{
    $this->artisan('sync:bookings --since=2025-07-20')
         ->assertExitCode(0);
}

}
