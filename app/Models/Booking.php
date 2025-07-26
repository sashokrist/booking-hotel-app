<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;


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
        $ids = json_decode($this->attributes['guest_ids'] ?? '[]', true);
        return Guest::whereIn('id', is_array($ids) ? $ids : [])->get();
    }

    public function guestCollection(): Collection
    {
        $ids = $this->guest_ids ?? [];
        return Guest::whereIn('id', $ids)->get();
    }
    

    public function getGuestIdsAttribute($value)
    {
        return json_decode($value, true);
    }

    public static function bulkUpsert(array $bookings, ?Command $console = null): void
    {
        if (!empty($bookings)) {
            self::upsert($bookings, ['id'], ['external_id', 'room_id', 'check_in', 'check_out', 'status', 'notes', 'guest_ids']);
            $console?->info("Upserted " . count($bookings) . " bookings.");
        }
    }
}
