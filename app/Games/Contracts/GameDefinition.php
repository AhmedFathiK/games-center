<?php

namespace App\Games\Contracts;

use App\Models\Room;

interface GameDefinition
{
    public function minimumPlayers(): int;

    public function maximumPlayers(): int;

    public function hostIsPlayer(): bool;

    public function configurationSchema(): array;

    public function initializeState(Room $room): array;

    /**
     * Game-specific checks beyond player count, run when the host
     * attempts to start the room. Returns an array of error message
     * strings; empty means the game can start.
     */
    public function validateStart(Room $room): array;
}
