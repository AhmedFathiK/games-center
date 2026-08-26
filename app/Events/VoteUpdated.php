<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoteUpdated implements ShouldBroadcastNow
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
        return 'vote.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'day_votes' => $this->room->game_state['day_votes'] ?? null,
        ];
    }
}
