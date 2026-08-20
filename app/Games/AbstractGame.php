<?php

namespace App\Games;

use App\Games\Contracts\GameDefinition;
use App\Models\Room;

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
}
