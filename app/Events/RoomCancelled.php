<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RoomCancelled implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
        public bool $deleted = false,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rooms.' . $this->room->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'room.cancelled';
    }

    public function broadcastWith(): array
    {
        // 'deleted' true means the room (still in the waiting lobby, no
        // real state to preserve) no longer exists at all — the client
        // must redirect away rather than reload room props that would
        // 404. false means it was marked 'cancelled' and still exists
        // for everyone to review, same as a finished game.
        return [
            'deleted' => $this->deleted,
        ];
    }
}
