<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'id',
        'room_id',
        'guest_ids',
        'check_in',
        'check_out',
        'status',
    ];

    public $incrementing = false;
    protected $keyType = 'int';

    protected $casts = [
        'guest_ids' => 'array',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
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
