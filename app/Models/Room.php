<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['id', 'number', 'room_type_id', 'status'];

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
