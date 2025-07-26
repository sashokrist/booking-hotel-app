<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BookingWebController;


Route::get('/bookings', [BookingWebController::class, 'index'])->name('bookings.index');
Route::post('/bookings/sync', [BookingWebController::class, 'sync'])->name('bookings.sync');

Route::get('/', function () {
    return view('welcome');
});
