<?php

use App\Games\MasrawyDeal\CardCatalog;
use App\Http\Controllers\GameController;
use App\Http\Controllers\RoomController;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Index');
})->name('home');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';

Route::middleware(['auth', 'verified'])->group(function () {
    // Temporary visual index for reviewing the full Masrawy Deal deck.
    $renderMasrawyDealCardGallery = static function () {
        return Inertia::render('Rooms/MasrawyDeal/CardGallery', [
            'catalog' => CardCatalog::all(),
            'rentChart' => CardCatalog::RENT_CHART,
            'setSize' => CardCatalog::SET_SIZE,
        ]);
    };

    Route::get('/masrawy-deal/card-gallery', $renderMasrawyDealCardGallery)
        ->name('masrawy-deal.card-gallery');
    Route::get('/rooms/MasrawyDeal/CardGallery', $renderMasrawyDealCardGallery)
        ->name('masrawy-deal.card-gallery.preview');

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

    Route::get('/test-email', function () {
        try {
            Mail::raw(
                'Testing Hostinger SMTP connection from Laravel web route!',
                function ($message) {
                    $message->to('e.elghamed@gmail.com')
                        ->subject('Laravel Web SMTP Test');
                },
            );

            return 'Email sent successfully! Check your inbox and spam folder.';
        } catch (Throwable $exception) {
            report($exception);

            return response('Email failed. Check the Laravel logs for details.', 500);
        }
    })
        ->middleware('throttle:5,1')
        ->name('test-email');
});
