<?php

namespace App\Games;

use App\Events\GameStateChanged;
use App\Games\Contracts\GameDefinition;
use App\Models\Room;
use App\Models\User;

abstract class AbstractGame implements GameDefinition
{
    public function configurationSchema(): array
    {
        return [];
    }

    public function initializeState(Room $room): array
    {
        return [];
    }

    public function validateStart(Room $room): array
    {
        return [];
    }

    public function validateRoomConfiguration(array $configuration, int $maxPlayers): array
    {
        return [];
    }

    public function advancePhase(Room $room): array
    {
        return $room->game_state ?? [];
    }

    public function submitAction(Room $room, User $user, array $payload): array
    {
        throw new \RuntimeException('This game does not support player actions.');
    }

    public function executePlayer(Room $room, ?string $targetId): array
    {
        throw new \RuntimeException('This game does not support execution.');
    }

    public function eventsAfterAction(Room $room, array $payload): array
    {
        return [new GameStateChanged($room)];
    }

    public function viewFor(Room $room, User $viewer): array
    {
        return [];
    }

    public function playerAttributes(Room $room, User $player): array
    {
        return [];
    }
}