<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class Room extends Model
{
    protected $fillable = ['id', 'number', 'floor', 'room_type_id'];

    public $incrementing = false;
    protected $keyType = 'int';

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public static function bulkUpsert(array $rooms, ?Command $console = null): void
    {
        if (!empty($rooms)) {
            try {
                self::upsert(
                    $rooms,
                    ['id'],
                    ['number', 'floor', 'room_type_id']
                );

                $console?->info("Upserted " . count($rooms) . " rooms.");
            } catch (\Throwable $e) {
                $console?->error("Failed to upsert rooms: " . $e->getMessage());
                Log::error("Room bulk upsert failed", ['error' => $e->getMessage()]);
            }
        }
    }
}
