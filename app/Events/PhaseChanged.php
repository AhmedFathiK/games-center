<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PhaseChanged implements ShouldBroadcastNow
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
        return 'phase.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'phase' => $this->room->game_state['phase'] ?? null,
            'round' => $this->room->game_state['round'] ?? null,
            'night_step' => $this->room->game_state['night_step'] ?? null,
        ];
    }
}
