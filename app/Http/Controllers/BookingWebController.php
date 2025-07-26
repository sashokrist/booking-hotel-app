<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use App\Jobs\SyncBookingsJob;

class BookingWebController extends Controller
{
    public function index(Request $request)
    {
        try {
            return DB::transaction(function () {
                $bookings = Booking::with(['room.roomType'])->latest('check_in')->paginate(20);

                $overview = [
                    'bookings' => \App\Models\Booking::count(),
                    'guests' => \App\Models\Guest::count(),
                    'rooms' => \App\Models\Room::count(),
                    'roomTypes' => \App\Models\RoomType::count(),
                    'rateLimit' => '2/sec',
                ];

                return view('bookings.index', compact('bookings', 'overview'));
            });
        } catch (\Throwable $e) {
            Log::error('Failed to load bookings page', ['error' => $e->getMessage()]);
            abort(500, 'An error occurred while loading bookings.');
        }
    }

    public function sync(Request $request)
    {
        try {
            $since = $request->input('since');
            SyncBookingsJob::dispatch($since);

            Session::flash('message', '✅ Booking sync has been queued. It will run shortly.');
        } catch (\Throwable $e) {
            Log::error('Failed to queue sync job', ['error' => $e->getMessage()]);
            Session::flash('error', 'Failed to queue sync job.');
        }

        return redirect()->route('bookings.index');
    }
}
