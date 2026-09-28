<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JustSayNoCountered implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $recipientId,
        public int $roomId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.' . $this->recipientId)];
    }

    public function broadcastAs(): string
    {
        return 'masrawy.just_say_no_countered';
    }

    public function broadcastWith(): array
    {
        return ['room_id' => $this->roomId];
    }
}
