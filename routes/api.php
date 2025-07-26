<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SyncController;

Route::post('/sync-bookings', [SyncController::class, 'run']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
