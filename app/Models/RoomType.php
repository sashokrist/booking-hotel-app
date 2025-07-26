<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;


class RoomType extends Model
{
    use HasFactory;

    protected $fillable = ['id', 'name', 'description', 'capacity', 'price'];

    public static function bulkUpsert(array $roomTypes, ?Command $console = null): void
    {
        if (!empty($roomTypes)) {
            self::upsert($roomTypes, ['id'], ['name', 'description']);
            $console?->info("Upserted " . count($roomTypes) . " room types.");
        }
    }
}
