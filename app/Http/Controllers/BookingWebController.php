<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Session;

class BookingWebController extends Controller
{
    public function index(Request $request)
    {
        $bookings = Booking::with(['room.roomType'])->latest('check_in')->paginate(20);
        return view('bookings.index', compact('bookings'));
    }

    public function sync(Request $request)
    {
        $since = $request->input('since');

        $command = $since
            ? "sync:bookings --since=\"{$since}\""
            : "sync:bookings";

        Artisan::call($command);

        Session::flash('message', '✅ Booking sync completed.');

        return redirect()->route('bookings.index');
    }
}

