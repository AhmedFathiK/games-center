<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NightActionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Room $room,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rooms.' . $this->room->id . '.mafia'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'night-action.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'mafia' => $this->room->game_state['night_actions']['mafia'] ?? null,
        ];
    }
}
