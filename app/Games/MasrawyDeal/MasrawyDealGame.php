<?php

namespace App\Games\MasrawyDeal;

use App\Games\AbstractGame;
use App\Models\Room;

/**
 * Masrawy Deal — Phase 1 of the build.
 *
 * Masrawy Deal is our reskin of Monopoly Deal: identical ruleset, card
 * counts, and card values (see CardCatalog), our own name and card
 * text. This phase covers only room setup and dealing:
 * minimum/maximum players, host participation, and
 * initializeState()'s shuffle + deal. Turn logic (draw, play,
 * rent/action resolution, Just Say No interrupts, win detection) is
 * deliberately NOT implemented yet — submitAction()/advancePhase()/
 * executePlayer() still fall through to AbstractGame's
 * not-yet-implemented defaults. That's the next phase, built and
 * tested the same incremental way MafiaGame was.
 *
 * Unlike Mafia, the host IS a player here — Masrawy Deal has no
 * separate "manager" role, everyone dealt in plays their own hand.
 */
class MasrawyDealGame extends AbstractGame
{
    /** Matches Monopoly Deal's official cap; below 2 there's no one to trade/steal with. */
    public function minimumPlayers(): int
    {
        return 2;
    }

    /** Matches Monopoly Deal's official cap. */
    public function maximumPlayers(): int
    {
        return 5;
    }

    public function hostIsPlayer(): bool
    {
        return true;
    }

    /**
     * Shuffles the full 106-card deck, deals 5 cards to each player one
     * at a time in turn order (matching how a physical deck is actually
     * dealt), and sets up the empty per-player hand/bank/property
     * structures the rest of the game will build on.
     *
     * Turn order itself is also shuffled here — Mafia shuffles role
     * assignment but keeps the room's join order for turns; Monopoly
     * Deal has no equivalent "join order is fine" turn concept, so who
     * goes first has to be randomized explicitly.
     */
    public function initializeState(Room $room): array
    {
        $playerIds = $room->players()->pluck('users.id')->shuffle()->values()->all();

        $deck = CardCatalog::deckIds();
        shuffle($deck);

        $hands = [];
        $banks = [];
        $properties = [];

        foreach ($playerIds as $userId) {
            $hands[$userId] = [];
            $banks[$userId] = [];
            $properties[$userId] = [];
        }

        for ($round = 0; $round < 5; $round++) {
            foreach ($playerIds as $userId) {
                $hands[$userId][] = array_shift($deck);
            }
        }

        return [
            'turn_order' => $playerIds,
            'current_player_id' => $playerIds[0],
            'draw_pile' => $deck,
            'discard_pile' => [],
            'hands' => $hands,
            'banks' => $banks,
            'properties' => $properties,
            'cards_played_this_turn' => 0,
            'has_drawn_this_turn' => false,
            // Placeholder for the next phase: describes an in-flight
            // rent/action/interrupt awaiting a response from someone
            // other than the current player.
            'pending' => null,
            'winner' => null,
        ];
    }
}
