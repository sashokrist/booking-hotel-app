<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Session;
use App\Jobs\SyncBookingsJob;

class BookingWebController extends Controller
{
    public function index(Request $request)
    {
        $bookings = Booking::with(['room.roomType'])->latest('check_in')->paginate(20);

        // Count records for overview
        $overview = [
            'bookings' => \App\Models\Booking::count(),
            'guests' => \App\Models\Guest::count(),
            'rooms' => \App\Models\Room::count(),
            'roomTypes' => \App\Models\RoomType::count(),
            'rateLimit' => '2/sec',
        ];

        return view('bookings.index', compact('bookings', 'overview'));
    }

    public function sync(Request $request)
    {
        $since = $request->input('since');

        SyncBookingsJob::dispatch($since);

        Session::flash('message', '✅ Booking sync has been queued. It will run shortly.');
        return redirect()->route('bookings.index');
    }
}

