<?php

namespace App\Traits;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HasBulkUpsert
{
    /**
     * @return array
     */
    abstract protected static function getBulkUpsertUpdateColumns(): array;

    public static function bulkUpsert(array $records, ?Command $console = null): void
    {
        if (empty($records)) {
            return;
        }

        $modelName = class_basename(self::class);

        try {
            self::upsert($records, ['id'], static::getBulkUpsertUpdateColumns());
            $console?->info("Upserted " . count($records) . " " . strtolower(Str::plural($modelName)) . ".");
        } catch (\Throwable $e) {
            $console?->error("Failed to upsert " . strtolower(Str::plural($modelName)) . ": " . $e->getMessage());
            Log::error("{$modelName} bulk upsert failed", ['error' => $e->getMessage()]);
        }
    }
}