<?php

use App\Models\Room;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('rooms.{room}', function ($user, Room $room) {
    return $room->host_id === $user->id
        || $room->players()->where('users.id', $user->id)->exists();
});
