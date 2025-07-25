<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;

class SyncBookingsCommandTest extends TestCase
{
    
    public function testSyncBookingsCommandRuns()
    {
    
        Http::fake(); 

        $this->artisan('sync:bookings --since=2025-07-20')
             ->assertExitCode(0);

        $this->assertTrue(true, 'Command executed successfully');
    }
}
