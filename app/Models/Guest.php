<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Console\Command;


class Guest extends Model
{
    use HasFactory;

    protected $fillable = ['id', 'first_name', 'last_name', 'email', 'phone'];

    public static function bulkUpsert(array $guests, ?Command $console = null): void
    {
        if (!empty($guests)) {
            self::upsert($guests, ['id'], ['first_name', 'last_name', 'email', 'phone']);
            $console?->info("Upserted " . count($guests) . " guests.");
        }
    }
}
