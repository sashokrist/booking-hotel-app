<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\SyncBookingsJob;

class SyncController extends Controller
{
   public function run(Request $request)
    {
        $since = $request->input('since');

        SyncBookingsJob::dispatch($since);

        return response()->json([
            'message' => '✅ Booking sync has been queued.',
            'since' => $since
        ]);
    }

}
