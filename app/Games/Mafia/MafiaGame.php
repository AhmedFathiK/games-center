<?php

namespace App\Games\Mafia;

use App\Games\AbstractGame;
use App\Models\Room;

class MafiaGame extends AbstractGame
{
    public function minimumPlayers(): int
    {
        return 5;
    }

    public function maximumPlayers(): int
    {
        return 20;
    }

    public function hostIsPlayer(): bool
    {
        return false;
    }

    public function configurationSchema(): array
    {
        return [
            'mafia_count' => [
                'type' => 'integer',
                'label' => 'Number of Mafia',
                'min' => 1,
            ],
            'doctor' => [
                'type' => 'boolean',
                'label' => 'Doctor',
            ],
            'detective' => [
                'type' => 'boolean',
                'label' => 'Detective',
            ],
        ];
    }

    public function validateStart(Room $room): array
    {
        $errors = [];

        $playerCount = $room->players()->count();
        $mafiaCount = (int) ($room->configuration['mafia_count'] ?? 0);
        $doctor = (bool) ($room->configuration['doctor'] ?? false);
        $detective = (bool) ($room->configuration['detective'] ?? false);

        if ($mafiaCount < 1) {
            $errors[] = 'At least one Mafia member is required.';
        }

        if ($mafiaCount >= $playerCount) {
            $errors[] = 'There must be at least one non-Mafia player.';
        }

        $specialRoleCount = $mafiaCount + ($doctor ? 1 : 0) + ($detective ? 1 : 0);

        if ($specialRoleCount > $playerCount) {
            $errors[] = 'There are too many special roles for the number of players.';
        }

        return $errors;
    }

    public function initializeState(Room $room): array
    {
        $playerIds = $room->players()->pluck('users.id')->shuffle()->values();

        $mafiaCount = (int) ($room->configuration['mafia_count'] ?? 0);
        $doctor = (bool) ($room->configuration['doctor'] ?? false);
        $detective = (bool) ($room->configuration['detective'] ?? false);

        $roles = [];
        $cursor = 0;

        foreach ($playerIds->slice($cursor, $mafiaCount) as $id) {
            $roles[$id] = 'mafia';
        }
        $cursor += $mafiaCount;

        if ($doctor) {
            $roles[$playerIds[$cursor]] = 'doctor';
            $cursor++;
        }

        if ($detective) {
            $roles[$playerIds[$cursor]] = 'detective';
            $cursor++;
        }

        foreach ($playerIds->slice($cursor) as $id) {
            $roles[$id] = 'civilian';
        }

        return [
            'phase' => 'night',
            'round' => 1,
            'roles' => $roles,
            'alive' => $playerIds->mapWithKeys(fn($id) => [$id => true])->all(),
            'winner' => null,
        ];
    }
}
