<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class Booking extends Model
{
    protected $fillable = [
        'id',
        'external_id',
        'room_id',
        'guest_ids',
        'check_in',
        'check_out',
        'status',
        'notes',
    ];

    public $incrementing = false;
    protected $keyType = 'int';

    protected $casts = [
        'guest_ids' => 'array',
        'check_in' => 'date',
        'check_out' => 'date',
    ];

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guests()
    {
        return Guest::whereIn('id', $this->guest_ids ?? [])->get();
    }

    public static function bulkUpsert(array $bookings, ?Command $console = null): void
    {
        if (!empty($bookings)) {
            try {
                self::upsert(
                    $bookings,
                    ['id'],
                    ['external_id', 'room_id', 'check_in', 'check_out', 'status', 'notes', 'guest_ids']
                );

                $console?->info("Upserted " . count($bookings) . " bookings.");
            } catch (\Throwable $e) {
                $console?->error("Failed to upsert bookings: " . $e->getMessage());
                Log::error("Booking bulk upsert failed", ['error' => $e->getMessage()]);
            }
        }
    }
}