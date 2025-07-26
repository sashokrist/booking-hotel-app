<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;


class Room extends Model
{
    protected $fillable = ['id', 'number', 'floor', 'room_type_id'];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public static function bulkUpsert(array $rooms, ?Command $console = null): void
    {
        if (!empty($rooms)) {
            self::upsert($rooms, ['id'], ['number', 'floor', 'room_type_id']);
            $console?->info("Upserted " . count($rooms) . " rooms.");
        }
    }
}
