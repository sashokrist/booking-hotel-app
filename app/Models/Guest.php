<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Guest extends Model
{
    use HasFactory;

    protected $fillable = ['id', 'first_name', 'last_name', 'email', 'phone'];

    public static function bulkUpsert(array $guests, ?Command $console = null): void
    {
        if (!empty($guests)) {
            try {
                self::upsert(
                    $guests,
                    ['id'],
                    ['first_name', 'last_name', 'email', 'phone']
                );

                $console?->info("Upserted " . count($guests) . " guests.");
            } catch (\Throwable $e) {
                $console?->error("Failed to upsert guests: " . $e->getMessage());
                Log::error("Guest bulk upsert failed", ['error' => $e->getMessage()]);
            }
        }
    }
}
