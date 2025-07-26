<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\BookingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncBookingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected ?string $since;

    public function __construct(?string $since = null)
    {
        $this->since = $since;
    }

    public function handle(BookingSyncService $syncService): void
    {
        try {
            Log::info('📦 Running SyncBookingsJob with since = ' . $this->since);

            $console = new class extends Command {
                public function __construct() { parent::__construct(); }
                public function line($string, $style = null, $verbosity = null) { echo $string . PHP_EOL; }
                public function info($string, $verbosity = null) { echo $string . PHP_EOL; }
                public function warn($string, $verbosity = null) { echo "[WARN] $string" . PHP_EOL; }
                public function error($string, $verbosity = null) { echo "[ERROR] $string" . PHP_EOL; }
            };

            $syncService->setDelay(500000)->syncBookings($this->since, $console);

            Log::info('✅ Background sync finished successfully.', ['since' => $this->since]);

        } catch (\Throwable $e) {
            Log::error('SyncBookingsJob failed.', [
                'since' => $this->since,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
