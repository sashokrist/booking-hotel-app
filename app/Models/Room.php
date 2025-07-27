<?php

namespace App\Models;

use App\Traits\HasBulkUpsert;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory, HasBulkUpsert;
    protected $fillable = ['id', 'number', 'floor', 'room_type_id'];

    public $incrementing = false;
    protected $keyType = 'int';

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return array
     */
    protected static function getBulkUpsertUpdateColumns(): array
    {
        return ['number', 'floor', 'room_type_id'];
    }
}
