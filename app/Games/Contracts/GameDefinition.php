<?php

namespace App\Games\Contracts;

use App\Models\Room;
use App\Models\User;

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

    /**
     * Game-specific checks on the submitted configuration at room-creation
     * time, before any players have joined — e.g. a role count that can't
     * exceed the room's chosen max_players. Distinct from validateStart(),
     * which runs later against the room's actual joined player count.
     * Returns an array of error message strings; empty means the
     * configuration is acceptable.
     */
    public function validateRoomConfiguration(array $configuration, int $maxPlayers): array;

    /**
     * Advances the game to its next phase given the room's current
     * game_state. Returns the full updated game_state array to persist.
     */
    public function advancePhase(Room $room): array;

    /**
     * Handles a game-specific player action submitted during play
     * (e.g. a night action). Returns the full updated game_state to persist.
     * Throws InvalidArgumentException for illegal actions.
     */
    public function submitAction(Room $room, User $user, array $payload): array;

    /**
     * Host-driven elimination during the day phase. $targetId may be
     * null (host chooses not to execute anyone this round).
     */
    public function executePlayer(Room $room, ?string $targetId): array;
}
