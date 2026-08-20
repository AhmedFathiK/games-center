<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\GameController;

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/games', [GameController::class, 'index'])
        ->name('games.index');

    Route::get('/rooms/{room:code}', [RoomController::class, 'show'])
        ->name('rooms.show');

    Route::post('/rooms', [RoomController::class, 'store'])
        ->name('rooms.store');

    Route::post('/rooms/{room}/join', [RoomController::class, 'join'])
        ->name('rooms.join');

    Route::post('/rooms/{room}/start', [RoomController::class, 'start'])
        ->name('rooms.start');
});
