<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\BookingSyncService;

class SyncBookingsCommand extends Command
{
    protected $signature = 'sync:bookings {--since=}';
    protected $description = 'Sync bookings, guests, rooms, and room types from PMS API';
    protected $rateLimitDelay = 500000; // 500ms

    public function __construct(protected BookingSyncService $syncService)
    {
        parent::__construct();
        $this->syncService->setDelay($this->rateLimitDelay);
    }

    public function handle(): void
    {
        $since = $this->option('since');

        $this->line("🔄 Syncing bookings updated since: " . ($since ?? 'all time'));

        try {
            $this->syncService->syncBookings($since, $this);
        } catch (\Exception $e) {
            Log::error("Global sync failure", ['error' => $e->getMessage()]);
            $this->error("Sync failed: " . $e->getMessage());
        }
    }
}
