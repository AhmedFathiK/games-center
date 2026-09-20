<?php

namespace App\Games\MasrawyDeal;

use App\Games\AbstractGame;
use App\Models\Room;
use App\Models\User;

/**
 * Masrawy Deal — Phase 2 of the build: turn logic.
 *
 * Covers a full, playable turn for the "boring but real" cases: draw,
 * play money/property/wildcard cards, bank an action/rent card instead
 * of using its effect, play the three self-contained action cards that
 * need no target and no interrupt (GARAB 7AZAK/Pass Go, SHISHA/House,
 * WIL3A/Hotel), discard down to 7, end turn, and win detection (3
 * complete sets).
 *
 * Deliberately NOT implemented yet — all of it needs the interrupt
 * system (Just Say No) and/or targets another player, so it's Phase
 * 3+: ELBIS!/rent, KHOD AMA 2OLAK/Sly Deal, MA.. TEEGY WANA AGY!/
 * Forced Deal, HAT 5 FI KEES/Debt Collector, 3ID MILADY YA KELAB/
 * Birthday, HAT wa lamo2akhza EL SHORT!/Deal Breaker, DA 3AND OMMO.../
 * Just Say No, ELBIS X 2/Double The Rent.
 *
 * Known Phase 2 simplification: official Monopoly Deal lets a player
 * start a SECOND same-color set once they already have a complete one
 * and acquire more cards of that color. This phase doesn't support
 * that — extra cards of an already-complete color just pile onto the
 * single existing group, which still reads as "complete" (SET_SIZE
 * cards or more) but doesn't grant a second independent set. Flagged
 * to Ahmed; revisit if it matters in practice with 2-5 players.
 *
 * Unlike Mafia, the host IS a player here — Masrawy Deal has no
 * separate "manager" role, everyone dealt in plays their own hand.
 * There's also no host-driven phase transition the way Mafia's
 * night→day is — every action here, including ending your turn, is
 * something the CURRENT PLAYER does, so it all goes through
 * submitAction() rather than advancePhase() (which stays
 * unimplemented, inheriting AbstractGame's no-op default).
 */
class MasrawyDealGame extends AbstractGame
{
    /** Normal per-turn draw, per official rules. */
    private const NORMAL_DRAW_COUNT = 2;

    /** Draw this many instead, if your hand was empty at the start of your turn. */
    private const EMPTY_HAND_DRAW_COUNT = 5;

    /** You may play at most this many cards from your hand per turn. */
    private const MAX_CARDS_PER_TURN = 3;

    /** Must discard down to this many cards before ending your turn. */
    private const HAND_LIMIT = 7;

    /** Complete sets of different colors needed to win. */
    private const SETS_TO_WIN = 3;

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

    public function submitAction(Room $room, User $user, array $payload): array
    {
        $state = $room->game_state;

        if (($state['winner'] ?? null) !== null) {
            throw new \InvalidArgumentException('The game has already ended.');
        }

        $type = $payload['type'] ?? null;

        // Every Phase-2 action is something only the current player can
        // do — there's no equivalent yet of Mafia's "anyone can vote
        // during the day" shape, since nothing here has a target/
        // interrupt step that would involve another player's turn.
        if ((int) ($state['current_player_id'] ?? null) !== (int) $user->id) {
            throw new \InvalidArgumentException('It is not your turn.');
        }

        return match ($type) {
            'draw' => $this->handleDraw($state, $user),
            'play_money' => $this->handlePlayMoney($state, $user, $payload),
            'play_property' => $this->handlePlayProperty($state, $user, $payload),
            'bank_card' => $this->handleBankCard($state, $user, $payload),
            'play_pass_go' => $this->handlePlayPassGo($state, $user, $payload),
            'play_shisha' => $this->handlePlayShisha($state, $user, $payload),
            'play_wil3a' => $this->handlePlayWil3a($state, $user, $payload),
            'discard' => $this->handleDiscard($state, $user, $payload),
            'end_turn' => $this->handleEndTurn($state, $user),
            default => throw new \InvalidArgumentException('Unknown action type.'),
        };
    }

    // --- Draw ---------------------------------------------------------

    protected function handleDraw(array $state, User $user): array
    {
        if ($state['has_drawn_this_turn']) {
            throw new \InvalidArgumentException('You have already drawn this turn.');
        }

        // "Beginning of your turn" is exactly this moment — nothing
        // else can happen before the turn's first draw.
        $handWasEmpty = count($state['hands'][$user->id]) === 0;
        $count = $handWasEmpty ? self::EMPTY_HAND_DRAW_COUNT : self::NORMAL_DRAW_COUNT;

        $state = $this->drawCards($state, (string) $user->id, $count);
        $state['has_drawn_this_turn'] = true;

        return $state;
    }

    /**
     * Draws $count cards for $userId, reshuffling the discard pile into
     * the draw pile if it runs out mid-draw. If both piles are ever
     * genuinely empty, the player just gets fewer cards than requested
     * rather than the game erroring out — matches official rules (no
     * penalty, you simply can't draw what doesn't exist).
     */
    protected function drawCards(array $state, string $userId, int $count): array
    {
        for ($i = 0; $i < $count; $i++) {
            if (empty($state['draw_pile'])) {
                if (empty($state['discard_pile'])) {
                    break;
                }

                $state['draw_pile'] = $state['discard_pile'];
                shuffle($state['draw_pile']);
                $state['discard_pile'] = [];
            }

            $state['hands'][$userId][] = array_shift($state['draw_pile']);
        }

        return $state;
    }

    // --- Playing cards --------------------------------------------------

    protected function handlePlayMoney(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if ($card['type'] !== 'money') {
            throw new \InvalidArgumentException('That is not a money card.');
        }

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['banks'][(string) $user->id][] = $cardId;

        return $state;
    }

    protected function handlePlayProperty(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! in_array($card['type'], ['property', 'wildcard'], true)) {
            throw new \InvalidArgumentException('That card cannot be played as a property.');
        }

        $color = $this->resolvePropertyColor($card, $payload);

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state = $this->addToPropertyGroup($state, (string) $user->id, $color, $cardId);
        $state = $this->checkWinCondition($state, (string) $user->id);

        return $state;
    }

    /**
     * A property card's color is implied by the card itself. A
     * two-color wildcard needs the player to pick one of its two
     * colors; a multicolor (any) wildcard needs a pick from all 10.
     */
    protected function resolvePropertyColor(array $card, array $payload): string
    {
        if ($card['type'] === 'property') {
            return $card['color'];
        }

        $color = (string) ($payload['color'] ?? '');

        if (! in_array($color, $card['colors'], true)) {
            throw new \InvalidArgumentException('Choose one of this wildcard\'s valid colors.');
        }

        return $color;
    }

    protected function addToPropertyGroup(array $state, string $userId, string $color, string $cardId): array
    {
        // 'house'/'hotel' hold the actual SHISHA/WIL3A card id once
        // placed (null = not placed) rather than a bare boolean, so
        // those cards stay traceable/conserved instead of vanishing
        // into a flag the moment they're played.
        $state['properties'][$userId][$color] ??= ['cards' => [], 'house' => null, 'hotel' => null];
        $state['properties'][$userId][$color]['cards'][] = $cardId;

        return $state;
    }

    /**
     * Banking a card means playing it into your OWN bank at face
     * value instead of using its effect — official rule, available for
     * any action or rent card. Money cards only ever go to the bank via
     * handlePlayMoney() (no "instead of its effect" choice to make);
     * property/wildcard cards can never be banked at all.
     */
    protected function handleBankCard(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! in_array($card['type'], ['action', 'rent'], true)) {
            throw new \InvalidArgumentException('Only action or rent cards can be banked instead of played.');
        }

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['banks'][(string) $user->id][] = $cardId;

        return $state;
    }

    protected function handlePlayPassGo(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'pass_go')) {
            throw new \InvalidArgumentException('That is not a GARAB 7AZAK card.');
        }

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['discard_pile'][] = $cardId;
        $state = $this->drawCards($state, (string) $user->id, self::NORMAL_DRAW_COUNT);

        return $state;
    }

    protected function handlePlayShisha(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'house')) {
            throw new \InvalidArgumentException('That is not a SHISHA card.');
        }

        $color = (string) ($payload['color'] ?? '');
        $group = $state['properties'][(string) $user->id][$color] ?? null;

        if ($group === null || ! $this->colorGroupIsComplete($group, $color)) {
            throw new \InvalidArgumentException('SHISHA can only be placed on one of your own complete sets.');
        }

        if ($group['house'] !== null) {
            throw new \InvalidArgumentException('That set already has a SHISHA on it.');
        }

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['properties'][(string) $user->id][$color]['house'] = $cardId;

        return $state;
    }

    protected function handlePlayWil3a(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'hotel')) {
            throw new \InvalidArgumentException('That is not a WIL3A card.');
        }

        $color = (string) ($payload['color'] ?? '');
        $group = $state['properties'][(string) $user->id][$color] ?? null;

        if ($group === null || ! $this->colorGroupIsComplete($group, $color)) {
            throw new \InvalidArgumentException('WIL3A can only be placed on one of your own complete sets.');
        }

        if ($group['house'] === null) {
            throw new \InvalidArgumentException('WIL3A can only be placed on a set that already has a SHISHA.');
        }

        if ($group['hotel'] !== null) {
            throw new \InvalidArgumentException('That set already has a WIL3A on it.');
        }

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['properties'][(string) $user->id][$color]['hotel'] = $cardId;

        return $state;
    }

    // --- Discard / end turn ---------------------------------------------

    protected function handleDiscard(array $state, User $user, array $payload): array
    {
        if (! $state['has_drawn_this_turn']) {
            throw new \InvalidArgumentException('Draw before discarding.');
        }

        $userId = (string) $user->id;

        if (count($state['hands'][$userId]) <= self::HAND_LIMIT) {
            throw new \InvalidArgumentException('You can only discard when you have more than 7 cards.');
        }

        $cardId = (string) ($payload['card_id'] ?? '');
        $this->cardInHand($state, $user, $cardId);

        $state['hands'][$userId] = $this->removeOneCard($state['hands'][$userId], $cardId);
        $state['discard_pile'][] = $cardId;

        return $state;
    }

    protected function handleEndTurn(array $state, User $user): array
    {
        $userId = (string) $user->id;

        if (! $state['has_drawn_this_turn']) {
            throw new \InvalidArgumentException('Draw before ending your turn.');
        }

        if (count($state['hands'][$userId]) > self::HAND_LIMIT) {
            throw new \InvalidArgumentException('Discard down to 7 cards before ending your turn.');
        }

        $order = $state['turn_order'];
        $currentIndex = array_search((int) $userId, array_map('intval', $order), true);
        $nextIndex = ($currentIndex + 1) % count($order);

        $state['current_player_id'] = $order[$nextIndex];
        $state['has_drawn_this_turn'] = false;
        $state['cards_played_this_turn'] = 0;

        return $state;
    }

    // --- Shared helpers ---------------------------------------------------

    /**
     * Looks up $cardId in $user's hand and returns its catalog
     * definition, or throws if they don't actually hold it.
     */
    protected function cardInHand(array $state, User $user, string $cardId): array
    {
        if ($cardId === '' || ! in_array($cardId, $state['hands'][(string) $user->id] ?? [], true)) {
            throw new \InvalidArgumentException('That card is not in your hand.');
        }

        return CardCatalog::get($cardId);
    }

    /**
     * Common bookkeeping for any card leaving the hand to be played
     * (for money, property, or an action/rent card's effect, or
     * banked) — enforces the has-drawn and 3-per-turn rules, removes
     * the card from hand, and counts it against the turn's play limit.
     * Does NOT decide where the card ends up; callers do that after.
     */
    protected function spendCardFromHand(array $state, User $user, string $cardId): array
    {
        if (! $state['has_drawn_this_turn']) {
            throw new \InvalidArgumentException('Draw before playing a card.');
        }

        if ($state['cards_played_this_turn'] >= self::MAX_CARDS_PER_TURN) {
            throw new \InvalidArgumentException('You can only play 3 cards per turn.');
        }

        $userId = (string) $user->id;
        $state['hands'][$userId] = $this->removeOneCard($state['hands'][$userId], $cardId);
        $state['cards_played_this_turn']++;

        return $state;
    }

    /**
     * @param  array<int, string>  $cardIds
     * @return array<int, string>
     */
    protected function removeOneCard(array $cardIds, string $cardId): array
    {
        $index = array_search($cardId, $cardIds, true);

        if ($index === false) {
            throw new \InvalidArgumentException('That card is not in your hand.');
        }

        unset($cardIds[$index]);

        return array_values($cardIds);
    }

    /**
     * A color group is complete once it has enough cards for that
     * color's set size — UNLESS every card in it is a multicolor (any)
     * wildcard, which per official rules cannot complete a set alone.
     */
    protected function colorGroupIsComplete(array $group, string $color): bool
    {
        $cards = $group['cards'];

        if (count($cards) < CardCatalog::SET_SIZE[$color]) {
            return false;
        }

        foreach ($cards as $cardId) {
            // Plain property cards have no 'any_color' key at all
            // (only wildcards do) — treat that as "not any_color" too.
            if (! (CardCatalog::get($cardId)['any_color'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    protected function checkWinCondition(array $state, string $userId): array
    {
        $completeSets = 0;

        foreach ($state['properties'][$userId] ?? [] as $color => $group) {
            if ($this->colorGroupIsComplete($group, $color)) {
                $completeSets++;
            }
        }

        if ($completeSets >= self::SETS_TO_WIN) {
            $state['winner'] = $userId;
        }

        return $state;
    }
}