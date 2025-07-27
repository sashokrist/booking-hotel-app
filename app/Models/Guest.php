<?php

namespace App\Models;

use App\Traits\HasBulkUpsert;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Guest extends Model
{
    use HasFactory, HasBulkUpsert;

    protected $fillable = ['id', 'first_name', 'last_name', 'email', 'phone'];

    public $incrementing = false;
    protected $keyType = 'int';

    /**
     * @return array
     */
    protected static function getBulkUpsertUpdateColumns(): array
    {
        return ['first_name', 'last_name', 'email', 'phone'];
    }
}
