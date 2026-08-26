<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HostNightActionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rooms.' . $this->room->id . '.host'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'night-action.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'night_actions' => $this->room->game_state['night_actions'] ?? null,
        ];
    }
}
