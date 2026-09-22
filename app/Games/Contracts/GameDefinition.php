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

    /**
     * The events to broadcast after submitAction() succeeded and its
     * result has been saved to the room. The platform never inspects
     * the payload itself, so a game decides here which of its own
     * events (and channels) an action should notify. Returns broadcast-
     * able event instances; the default is one generic, data-free
     * GameStateChanged on the room channel.
     *
     * @return array<int, object>
     */
    public function eventsAfterAction(Room $room, array $payload): array;

    /**
     * The game-specific part of what the room page shows $viewer: keys
     * merged into the `room` Inertia prop next to the platform's own
     * (id, code, status, winner, players, ...), which always win on a
     * name clash. This is the ONE place private state is filtered —
     * anything returned here is sent to $viewer's browser, so a hand,
     * role or draw pile must only appear if $viewer may see it.
     *
     * Called on every room page load, including before the game has
     * started (game_state is null then), so implementations must handle
     * a null state.
     *
     * @return array<string, mixed>
     */
    public function viewFor(Room $room, User $viewer): array;

    /**
     * Extra game-specific attributes for one entry of the room's player
     * roster (e.g. Mafia's `alive`), merged into that player's
     * id/name. Also called before the game starts.
     *
     * @return array<string, mixed>
     */
    public function playerAttributes(Room $room, User $player): array;
}