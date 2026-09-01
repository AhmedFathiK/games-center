<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\GameController;

Route::get('/', function () {
    return Inertia::render('Index');
})->name('home');

require __DIR__ . '/settings.php';
require __DIR__ . '/auth.php';

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [GameController::class, 'index'])
        ->name('home');

    Route::get('/games', [GameController::class, 'index'])
        ->name('games.index');

    Route::post('/rooms/{room}/advance', [RoomController::class, 'advance'])
        ->name('rooms.advance');

    // Room lookups by URL (GET) use the shareable room code.
    Route::get('/rooms/{room:code}', [RoomController::class, 'show'])
        ->name('rooms.show');

    Route::post('/rooms', [RoomController::class, 'store'])
        ->name('rooms.store');

    Route::post('/rooms/{room}/join', [RoomController::class, 'join'])
        ->name('rooms.join');

    Route::post('/rooms/{room}/start', [RoomController::class, 'start'])
        ->name('rooms.start');

    Route::post('/rooms/{room}/actions', [RoomController::class, 'act'])
        ->name('rooms.act');

    Route::post('/rooms/{room}/execute', [RoomController::class, 'execute'])
        ->name('rooms.execute');

    Route::post('/rooms/{room}/leave', [RoomController::class, 'leave'])
        ->name('rooms.leave');

    Route::post('/rooms/find', [RoomController::class, 'find'])
        ->name('rooms.find');

    Route::post('/rooms/{room}/kick/{user}', [RoomController::class, 'kick'])
        ->name('rooms.kick');

    Route::get('/my-rooms', [RoomController::class, 'mine'])
        ->name('rooms.mine');

    Route::post('/rooms/{room}/cancel', [RoomController::class, 'cancel'])
        ->name('rooms.cancel');

    Route::post('/rooms/{room}/heartbeat', [RoomController::class, 'heartbeat'])
        ->name('rooms.heartbeat');
});
