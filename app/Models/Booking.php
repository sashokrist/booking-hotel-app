<?php

namespace App\Models;

use App\Traits\HasBulkUpsert;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory, HasBulkUpsert;
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

    /**
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function guests()
    {
        return Guest::whereIn('id', $this->guest_ids ?? [])->get();
    }

    /**
     * @return array
     */
    protected static function getBulkUpsertUpdateColumns(): array
    {
        return ['external_id', 'room_id', 'check_in', 'check_out', 'status', 'notes', 'guest_ids'];
    }
}