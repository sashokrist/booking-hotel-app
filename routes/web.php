<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookingWebController;
use Illuminate\Support\Facades\Storage;

Route::get('/reports/latest-booking-sync', function () {
    $files = Storage::files('reports');
    $latest = collect($files)->sortDesc()->first();

    if (!$latest) {
        abort(404, 'No report found.');
    }

    return Storage::download($latest);
});


Route::get('/bookings', [BookingWebController::class, 'index'])->name('bookings.index');
Route::post('/bookings/sync', [BookingWebController::class, 'sync'])->name('bookings.sync');

Route::get('/', function () {
    return view('welcome');
});
