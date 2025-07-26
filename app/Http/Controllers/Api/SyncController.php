<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\SyncBookingsJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $since = $request->input('since');

        try {
            SyncBookingsJob::dispatch($since);

            return response()->json([
                'message' => 'Booking sync has been queued.',
                'since' => $since
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch SyncBookingsJob', [
                'since' => $since,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to queue booking sync.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
