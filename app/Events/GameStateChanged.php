<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * The generic "something in this game changed, reload your view" signal
 * for games that don't need their own event types. It carries no game
 * data on purpose: the room channel reaches every participant, so
 * anything private (a hand, a role) must never travel in a broadcast —
 * each client re-fetches its own filtered view of the room instead.
 */
class GameStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rooms.' . $this->room->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'game.state_changed';
    }

    public function broadcastWith(): array
    {
        return [
            'status' => $this->room->status,
        ];
    }
}