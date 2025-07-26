<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = ['resource_type', 'resource_id', 'status', 'message'];

     public static function log(string $type, int $id, string $status, ?string $message = null): void
    {
        try {
            self::create([
                'resource_type' => $type,
                'resource_id' => $id,
                'status' => $status,
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Log::error("Failed to write sync log", ['type' => $type, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }
}
