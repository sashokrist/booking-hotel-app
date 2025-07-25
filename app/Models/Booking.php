<?php

namespace App\Console\Commands;

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    public function getGuestIdsAttribute($value)
    {
        return json_decode($value, true);
    }
}
