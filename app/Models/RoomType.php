<?php

namespace App\Models;

use App\Traits\HasBulkUpsert;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use HasFactory, HasBulkUpsert;

    protected $fillable = ['id', 'name', 'description'];

    public $incrementing = false;
    protected $keyType = 'int';

    /**
     * Get the columns that should be updated during a bulk upsert.
     *
     * @return array
     */
    protected static function getBulkUpsertUpdateColumns(): array
    {
        return ['name', 'description'];
    }
}
