<?php

namespace App\Games\MasrawyDeal;

use App\Games\AbstractGame;
use App\Models\Room;
use App\Models\User;

/**
 * Masrawy Deal — Phase 2 (turn logic) plus Phase 3 in slices
 * (interrupts and targeted actions).
 *
 * Phase 2 covers a full, playable turn for the "boring but real"
 * cases: draw, play money/property/wildcard cards, bank an action/rent
 * card instead of using its effect, play the three self-contained
 * action cards that need no target and no interrupt (GARAB 7AZAK/Pass
 * Go, SHISHA/House, WIL3A/Hotel), discard down to 7, end turn, and win
 * detection (3 complete sets).
 *
 * Phase 3 slice 1 adds the interrupt machinery and its first card:
 * the `pending` sub-state, DA 3AND OMMO.../Just Say No chains, payment,
 * and HAT 5 FI KEES/Debt Collector (see "Pending actions" below).
 * Slice 2 adds 3ID MILADY YA KELAB/Birthday, the first card that
 * charges several players at once — each opponent gets their own
 * independent charge.
 *
 * Slice 3 adds ELBIS!/rent: a regular two-color rent card charges every
 * opponent, a wild rent card charges one chosen opponent, and up to
 * two ELBIS X 2/Double The Rent cards played with it multiply the
 * amount (see handlePlayRent()).
 *
 * Slice 4 adds KHOD AMA 2OLAK/Sly Deal: the first card that moves a
 * property instead of money. It has no payment step — once its Just
 * Say No window closes uncancelled, the steal is applied on the spot.
 *
 * Slice 5 adds MA.. TEEGY WANA AGY!/Forced Deal: a swap of one of your
 * properties for one of an opponent's, applied the same way once its
 * Just Say No window closes uncancelled.
 *
 * Slice 6 adds HAT wa lamo2akhza EL SHORT!/Deal Breaker: take a whole
 * complete set, buildings and all, from an opponent. That completes
 * the Phase 3 card list.
 *
 * Pending actions
 * ---------------
 * A card that targets other players does not resolve on the spot. It
 * parks a `pending` record in game_state and the game freezes for
 * everyone except the people that record is waiting on:
 *
 *   pending = [
 *     'kind'      => 'debt_collector' | 'birthday' | 'rent' | 'sly_deal' | 'forced_deal' | 'deal_breaker',
 *     'source_id' => the player who played the card,
 *     'card_id'   => the card that was played (already in the discard pile),
 *     'charges'   => [ targetId => [
 *         'phase'   => 'responding' | 'paying' | 'done',
 *         'chain'   => [ ['player_id' => ..., 'card_id' => ...], ... ],  // Just Say No plays
 *         'owed'    => amount in $M,
 *         'outcome' => null | 'applied' | 'cancelled',
 *     ] ],
 *   ]
 *
 * Each target has its own independent charge. While `responding`, the
 * responder is the target when the No chain has an even length and the
 * source when it is odd; the responder either plays a Just Say No
 * (`respond_no`, chain grows, responder flips) or `decline`s. Declining
 * ends the chain: an odd chain means the action was cancelled, an even
 * one (including an empty chain) means it goes through — so a No
 * cancelling a No falls out of the parity rule with no special case.
 * A payment-type charge that goes through moves to `paying` and the
 * target chooses what to hand over (`pay`); a steal-type charge (Sly
 * Deal, Forced Deal, Deal Breaker) is applied by the server immediately
 * instead. `pending` clears once every charge is
 * `done`, and the source's turn simply continues.
 *
 * A responder holding no Just Say No card is auto-declined by the
 * server, so nobody is asked to click through a response they cannot
 * make. Played action and Just Say No cards go straight to the discard
 * pile (as in the real game); `pending` only references them, so
 * every card is always in exactly one of the piles, hands, banks or
 * properties.
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
 * something a PLAYER does, so it all goes through submitAction()
 * rather than advancePhase() (which stays unimplemented, inheriting
 * AbstractGame's no-op default).
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

    /** HAT 5 FI KEES/Debt Collector: what the chosen opponent owes, in $M. */
    private const DEBT_COLLECTOR_AMOUNT = 5;

    /** 3ID MILADY YA KELAB/Birthday: what EACH opponent owes, in $M. */
    private const BIRTHDAY_AMOUNT = 2;

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
            // An in-flight action awaiting responses from other
            // players (see "Pending actions" in the class docblock).
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
        $isResponse = in_array($type, ['respond_no', 'decline', 'pay'], true);

        if ($isResponse && ($state['pending'] ?? null) === null) {
            throw new \InvalidArgumentException('There is nothing to respond to.');
        }

        // While an action is waiting on responses the game is frozen
        // for everyone: only the responses below are accepted, and each
        // handler checks that this particular player is the one the
        // action is actually waiting on.
        if (($state['pending'] ?? null) !== null) {
            return match ($type) {
                'respond_no' => $this->handleRespondNo($state, $user, $payload),
                'decline' => $this->handleDecline($state, $user, $payload),
                'pay' => $this->handlePay($state, $user, $payload),
                default => throw new \InvalidArgumentException('Waiting for a response to a played action.'),
            };
        }

        // Every other action is something only the current player can do.
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
            'play_debt_collector' => $this->handlePlayDebtCollector($state, $user, $payload),
            'play_birthday' => $this->handlePlayBirthday($state, $user, $payload),
            'play_rent' => $this->handlePlayRent($state, $user, $payload),
            'play_sly_deal' => $this->handlePlaySlyDeal($state, $user, $payload),
            'play_forced_deal' => $this->handlePlayForcedDeal($state, $user, $payload),
            'play_deal_breaker' => $this->handlePlayDealBreaker($state, $user, $payload),
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

    // --- Targeted actions -------------------------------------------------

    /**
     * HAT 5 FI KEES/Debt Collector: the chosen opponent owes 5M. Goes
     * through the Just Say No window first, like every targeted action.
     */
    protected function handlePlayDebtCollector(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'debt_collector')) {
            throw new \InvalidArgumentException('That is not a HAT 5 FI KEES card.');
        }

        $targetId = $this->resolveOpponent($state, $user, $payload['target_id'] ?? null);

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['discard_pile'][] = $cardId;

        $state['pending'] = [
            'kind' => 'debt_collector',
            'source_id' => (int) $user->id,
            'card_id' => $cardId,
            'charges' => [
                $targetId => $this->newCharge(self::DEBT_COLLECTOR_AMOUNT),
            ],
        ];

        return $this->settlePending($state);
    }

    /**
     * 3ID MILADY YA KELAB/Birthday: EVERY opponent owes 2M. No target to
     * choose. Each opponent gets their own charge with their own Just
     * Say No window — a No from one player cancels only that player's
     * payment, and the charges can be answered in any order.
     */
    protected function handlePlayBirthday(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'birthday')) {
            throw new \InvalidArgumentException('That is not a 3ID MILADY YA KELAB card.');
        }

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['discard_pile'][] = $cardId;

        $charges = [];

        foreach ($state['turn_order'] as $playerId) {
            if ((int) $playerId !== (int) $user->id) {
                $charges[(int) $playerId] = $this->newCharge(self::BIRTHDAY_AMOUNT);
            }
        }

        $state['pending'] = [
            'kind' => 'birthday',
            'source_id' => (int) $user->id,
            'card_id' => $cardId,
            'charges' => $charges,
        ];

        return $this->settlePending($state);
    }

    /**
     * ELBIS!: charge rent on one of your own color groups.
     *
     * Payload: `card_id` (the rent card), `color` (must be one of the
     * card's colors — every color for a wild rent card), optional
     * `double_rent_card_ids` (up to two ELBIS X 2 cards from hand), and
     * `target_id` for a wild rent card only (a regular two-color rent
     * card always charges every opponent, so any target is ignored).
     *
     * The rent is the color's chart value for the number of cards in
     * that group (a wildcard counts as a card, extra cards beyond a full
     * set count as a full set) plus the SHISHA/WIL3A bonus when the set
     * is complete, doubled once per ELBIS X 2. A group of nothing but
     * EL BOB wildcards earns no rent. The rent card and every ELBIS X 2
     * each count as one of the turn's 3 plays, and all of them go to the
     * discard pile at once — so a Just Say No wastes the doubles too.
     */
    protected function handlePlayRent(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if ($card['type'] !== 'rent') {
            throw new \InvalidArgumentException('That is not an ELBIS! card.');
        }

        $color = (string) ($payload['color'] ?? '');

        if (! in_array($color, $card['colors'], true)) {
            throw new \InvalidArgumentException('Choose one of this card\'s colors.');
        }

        $doubleCardIds = $this->resolveDoubleRentCards($state, $user, $payload);
        $multiplier = 2 ** count($doubleCardIds);
        $amount = $this->rentAmount($state, (string) $user->id, $color) * $multiplier;

        if ($card['charges_all']) {
            $targetIds = array_values(array_filter(
                array_map('intval', $state['turn_order']),
                fn (int $playerId): bool => $playerId !== (int) $user->id,
            ));
        } else {
            $targetIds = [$this->resolveOpponent($state, $user, $payload['target_id'] ?? null)];
        }

        foreach ([$cardId, ...$doubleCardIds] as $playedCardId) {
            $state = $this->spendCardFromHand($state, $user, $playedCardId);
            $state['discard_pile'][] = $playedCardId;
        }

        $charges = [];

        foreach ($targetIds as $targetId) {
            $charges[$targetId] = $this->newCharge($amount);
        }

        $state['pending'] = [
            'kind' => 'rent',
            'source_id' => (int) $user->id,
            'card_id' => $cardId,
            'color' => $color,
            'multiplier' => $multiplier,
            'charges' => $charges,
        ];

        return $this->settlePending($state);
    }

    /**
     * KHOD AMA 2OLAK/Sly Deal: take one property from an opponent, as
     * long as it is not part of one of their complete sets.
     *
     * Payload: `card_id`, `target_id` (the opponent), `target_card_id`
     * (the property or wildcard to take) and, only when that card is a
     * wildcard, an optional `color` to place it under (it keeps the
     * color it was sitting in if omitted). A plain property card always
     * goes under its own color. Nothing moves until the Just Say No
     * window closes without a cancellation.
     */
    protected function handlePlaySlyDeal(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'sly_deal')) {
            throw new \InvalidArgumentException('That is not a KHOD AMA 2OLAK card.');
        }

        $targetId = $this->resolveOpponent($state, $user, $payload['target_id'] ?? null);
        $takenCardId = (string) ($payload['target_card_id'] ?? '');
        $takenColor = $this->stealableColor($state, $targetId, $takenCardId);
        $placeColor = $this->resolvePlacementColor($takenCardId, $takenColor, $payload['color'] ?? null);

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['discard_pile'][] = $cardId;

        $state['pending'] = [
            'kind' => 'sly_deal',
            'source_id' => (int) $user->id,
            'card_id' => $cardId,
            'target_card_id' => $takenCardId,
            'color' => $placeColor,
            'charges' => [
                $targetId => $this->newCharge(0),
            ],
        ];

        return $this->settlePending($state);
    }

    /**
     * MA.. TEEGY WANA AGY!/Forced Deal: swap one of your properties for
     * one of an opponent's. Neither card may be part of a complete set.
     *
     * Payload: `card_id`, `target_id`, `target_card_id` (the opponent's
     * card you want), `give_card_id` (your own card they get) and, only
     * when the card you want is a wildcard, an optional `color` to place
     * it under (it keeps its current color if omitted). The card you
     * give keeps whatever color it was sitting in. Nothing moves until
     * the Just Say No window closes without a cancellation.
     */
    protected function handlePlayForcedDeal(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'forced_deal')) {
            throw new \InvalidArgumentException('That is not a MA.. TEEGY WANA AGY! card.');
        }

        $targetId = $this->resolveOpponent($state, $user, $payload['target_id'] ?? null);
        $takenCardId = (string) ($payload['target_card_id'] ?? '');
        $givenCardId = (string) ($payload['give_card_id'] ?? '');

        $takenColor = $this->stealableColor($state, $targetId, $takenCardId);
        $this->stealableColor(
            $state,
            (int) $user->id,
            $givenCardId,
            'Choose one of your own properties to give.',
            'You cannot give up a property from a complete set.',
        );
        $placeColor = $this->resolvePlacementColor($takenCardId, $takenColor, $payload['color'] ?? null);

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['discard_pile'][] = $cardId;

        $state['pending'] = [
            'kind' => 'forced_deal',
            'source_id' => (int) $user->id,
            'card_id' => $cardId,
            'target_card_id' => $takenCardId,
            'give_card_id' => $givenCardId,
            'color' => $placeColor,
            'charges' => [
                $targetId => $this->newCharge(0),
            ],
        ];

        return $this->settlePending($state);
    }

    /**
     * HAT wa lamo2akhza EL SHORT!/Deal Breaker: take an opponent's
     * complete set, along with any SHISHA/WIL3A on it.
     *
     * Payload: `card_id`, `target_id`, and `target_color` (which of
     * their complete sets). Nothing moves until the Just Say No window
     * closes without a cancellation.
     */
    protected function handlePlayDealBreaker(array $state, User $user, array $payload): array
    {
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'deal_breaker')) {
            throw new \InvalidArgumentException('That is not a HAT wa lamo2akhza EL SHORT! card.');
        }

        $targetId = $this->resolveOpponent($state, $user, $payload['target_id'] ?? null);
        $color = (string) ($payload['target_color'] ?? '');

        $this->assertCompleteSet($state, $targetId, $color);

        $state = $this->spendCardFromHand($state, $user, $cardId);
        $state['discard_pile'][] = $cardId;

        $state['pending'] = [
            'kind' => 'deal_breaker',
            'source_id' => (int) $user->id,
            'card_id' => $cardId,
            'color' => $color,
            'charges' => [
                $targetId => $this->newCharge(0),
            ],
        ];

        return $this->settlePending($state);
    }

    protected function assertCompleteSet(array $state, int $ownerId, string $color): void
    {
        $group = $state['properties'][$ownerId][$color] ?? null;

        if ($group === null || ! $this->colorGroupIsComplete($group, $color)) {
            throw new \InvalidArgumentException('Choose one of that player\'s complete sets.');
        }
    }

    /**
     * The color group $cardId currently sits in on $ownerId's table, if
     * it is a property/wildcard that can be taken (i.e. it is in one of
     * their properties and that set is not complete). Throws otherwise.
     */
    protected function stealableColor(
        array $state,
        int $ownerId,
        string $cardId,
        string $missingMessage = 'Choose one of that player\'s properties.',
        string $completeMessage = 'You cannot take a property from a complete set.',
    ): string {
        foreach ($state['properties'][$ownerId] ?? [] as $color => $group) {
            if (! in_array($cardId, $group['cards'], true)) {
                continue;
            }

            if ($this->colorGroupIsComplete($group, $color)) {
                throw new \InvalidArgumentException($completeMessage);
            }

            return $color;
        }

        throw new \InvalidArgumentException($missingMessage);
    }

    /**
     * Where a property taken from another player will be placed: a
     * plain property card always goes under its own color; a wildcard
     * goes under the color asked for (one of its valid colors), or the
     * one it is already sitting in.
     */
    protected function resolvePlacementColor(string $cardId, string $currentColor, mixed $requestedColor): string
    {
        $card = CardCatalog::get($cardId);

        if ($card['type'] === 'property') {
            return $card['color'];
        }

        if ($requestedColor === null || $requestedColor === '') {
            return $currentColor;
        }

        if (! in_array((string) $requestedColor, $card['colors'], true)) {
            throw new \InvalidArgumentException('Choose one of this wildcard\'s valid colors.');
        }

        return (string) $requestedColor;
    }

    /**
     * Rent earned by $userId's group of $color, before any doubling.
     */
    protected function rentAmount(array $state, string $userId, string $color): int
    {
        $group = $state['properties'][$userId][$color] ?? null;

        if ($group === null || $group['cards'] === []) {
            throw new \InvalidArgumentException('You have no properties of that color to charge rent on.');
        }

        $hasRealProperty = false;

        foreach ($group['cards'] as $groupCardId) {
            if (! (CardCatalog::get($groupCardId)['any_color'] ?? false)) {
                $hasRealProperty = true;
                break;
            }
        }

        if (! $hasRealProperty) {
            throw new \InvalidArgumentException('EL BOB wildcards alone cannot earn rent.');
        }

        $count = min(count($group['cards']), CardCatalog::SET_SIZE[$color]);
        $rent = CardCatalog::RENT_CHART[$color][$count - 1];

        if ($this->colorGroupIsComplete($group, $color)) {
            if ($group['house'] !== null) {
                $rent += CardCatalog::HOUSE_RENT_BONUS;
            }

            if ($group['hotel'] !== null) {
                $rent += CardCatalog::HOTEL_RENT_BONUS;
            }
        }

        return $rent;
    }

    /**
     * Validates the ELBIS X 2 cards a rent play asks to use: each must
     * be a distinct ELBIS X 2 in the player's hand.
     *
     * @return array<int, string>
     */
    protected function resolveDoubleRentCards(array $state, User $user, array $payload): array
    {
        $cardIds = $payload['double_rent_card_ids'] ?? [];

        if (! is_array($cardIds)) {
            throw new \InvalidArgumentException('Choose which ELBIS X 2 cards to use.');
        }

        $cardIds = array_map('strval', array_values($cardIds));

        if (count(array_unique($cardIds)) !== count($cardIds)) {
            throw new \InvalidArgumentException('Each ELBIS X 2 card can only be used once.');
        }

        foreach ($cardIds as $doubleCardId) {
            $card = $this->cardInHand($state, $user, $doubleCardId);

            if (! ($card['type'] === 'action' && $card['action'] === 'double_rent')) {
                throw new \InvalidArgumentException('That is not an ELBIS X 2 card.');
            }
        }

        return $cardIds;
    }

    /**
     * Validates a chosen target: must be a different player in this game.
     */
    protected function resolveOpponent(array $state, User $user, mixed $rawTargetId): int
    {
        $targetId = (int) $rawTargetId;

        if ($targetId === (int) $user->id) {
            throw new \InvalidArgumentException('You cannot target yourself.');
        }

        if (! in_array($targetId, array_map('intval', $state['turn_order']), true)) {
            throw new \InvalidArgumentException('Choose another player in this game.');
        }

        return $targetId;
    }

    // --- Interrupts: Just Say No chain and payment --------------------------

    /**
     * DA 3AND OMMO...: cancel the action aimed at you — or, if you are
     * the source and it was just cancelled, cancel that cancellation.
     * Played out of turn, so it never counts toward the 3-cards-per-turn
     * limit and needs no draw first.
     */
    protected function handleRespondNo(array $state, User $user, array $payload): array
    {
        $targetId = $this->respondingChargeTarget($state, $user, $payload);
        $cardId = (string) ($payload['card_id'] ?? '');
        $card = $this->cardInHand($state, $user, $cardId);

        if (! ($card['type'] === 'action' && $card['action'] === 'just_say_no')) {
            throw new \InvalidArgumentException('That is not a DA 3AND OMMO... card.');
        }

        $userId = (string) $user->id;
        $state['hands'][$userId] = $this->removeOneCard($state['hands'][$userId], $cardId);
        $state['discard_pile'][] = $cardId;
        $state['pending']['charges'][$targetId]['chain'][] = [
            'player_id' => (int) $user->id,
            'card_id' => $cardId,
        ];

        return $this->settlePending($state);
    }

    /**
     * The responder chooses not to (or cannot) play a Just Say No. That
     * ends the chain: odd length = the action was cancelled, even
     * length = it goes through.
     */
    protected function handleDecline(array $state, User $user, array $payload): array
    {
        $targetId = $this->respondingChargeTarget($state, $user, $payload);

        $state = $this->finishResponse($state, $targetId);

        return $this->settlePending($state);
    }

    /**
     * Pays a charge that went through. Rules:
     *  - only cards with a value can be paid (an EL BOB wildcard is worth
     *    nothing, so it is never demanded or accepted);
     *  - no change is given, but every card chosen must be needed: the
     *    payment is rejected if dropping its smallest card would still
     *    cover the debt;
     *  - a player who cannot cover the debt must pay everything they own;
     *  - a WIL3A must be paid before the SHISHA underneath it.
     * Money and banked cards land in the source's bank; property cards
     * land in the source's property area under the same color they had
     * (SHISHA/WIL3A, having no color of their own to land in, go to the
     * bank at face value).
     */
    protected function handlePay(array $state, User $user, array $payload): array
    {
        $userId = (int) $user->id;
        $charge = $state['pending']['charges'][$userId] ?? null;

        if ($charge === null || $charge['phase'] !== 'paying') {
            throw new \InvalidArgumentException('You do not owe anything right now.');
        }

        $selected = $payload['card_ids'] ?? null;

        if (! is_array($selected) || $selected === []) {
            throw new \InvalidArgumentException('Choose the cards you are paying with.');
        }

        $selected = array_map('strval', array_values($selected));

        if (count(array_unique($selected)) !== count($selected)) {
            throw new \InvalidArgumentException('Each card can only be used once.');
        }

        $assets = $this->payableAssets($state, $userId);

        foreach ($selected as $cardId) {
            if (! isset($assets[$cardId])) {
                throw new \InvalidArgumentException('One of those cards cannot be used to pay.');
            }
        }

        $owed = $charge['owed'];
        $values = array_map(fn (string $cardId): int => $assets[$cardId], $selected);

        if (array_sum($assets) < $owed) {
            if (count($selected) !== count($assets)) {
                throw new \InvalidArgumentException('You cannot cover this, so you must pay with everything you have.');
            }
        } else {
            if (array_sum($values) < $owed) {
                throw new \InvalidArgumentException('That does not cover what you owe.');
            }

            if (array_sum($values) - min($values) >= $owed) {
                throw new \InvalidArgumentException('You are paying with more cards than needed.');
            }
        }

        foreach ($state['properties'][$userId] ?? [] as $group) {
            if (
                $group['house'] !== null
                && $group['hotel'] !== null
                && in_array($group['house'], $selected, true)
                && ! in_array($group['hotel'], $selected, true)
            ) {
                throw new \InvalidArgumentException('Pay the WIL3A before the SHISHA underneath it.');
            }
        }

        $sourceId = (int) $state['pending']['source_id'];

        foreach ($selected as $cardId) {
            $state = $this->transferPaymentCard($state, $userId, $sourceId, $cardId);
        }

        $state = $this->completeCharge($state, $userId, 'applied');
        $state = $this->endPendingIfWon($state, $sourceId);

        return $this->settlePending($state);
    }

    /**
     * Finds the charge this player is currently expected to answer:
     * their own (as the target) or, if they are the source, the one
     * named by `target_id`. Throws if it is not their move.
     */
    protected function respondingChargeTarget(array $state, User $user, array $payload): int
    {
        $targetId = (int) ($payload['target_id'] ?? $user->id);
        $charge = $state['pending']['charges'][$targetId] ?? null;

        if ($charge === null || $charge['phase'] !== 'responding') {
            throw new \InvalidArgumentException('Nothing is waiting on you.');
        }

        if ($this->responderId($state['pending'], $targetId) !== (int) $user->id) {
            throw new \InvalidArgumentException('It is not your turn to respond.');
        }

        return $targetId;
    }

    /**
     * Who the charge on $targetId is currently waiting on: the target
     * while the No chain has an even length, the source while odd.
     */
    protected function responderId(array $pending, int $targetId): int
    {
        return count($pending['charges'][$targetId]['chain']) % 2 === 0
            ? $targetId
            : (int) $pending['source_id'];
    }

    /**
     * @return array<string, mixed>
     */
    protected function newCharge(int $owed): array
    {
        return [
            'phase' => 'responding',
            'chain' => [],
            'owed' => $owed,
            'outcome' => null,
        ];
    }

    /**
     * Ends a charge's Just Say No window: an odd chain means the
     * action was cancelled; otherwise it goes through. A payment-type
     * action then leaves the target owing payment; a steal-type one is
     * applied right here. (Later slices' steal-type cards join the
     * steal branches.)
     */
    protected function finishResponse(array $state, int $targetId): array
    {
        $chainLength = count($state['pending']['charges'][$targetId]['chain']);

        if ($chainLength % 2 === 1) {
            return $this->completeCharge($state, $targetId, 'cancelled');
        }

        if ($state['pending']['kind'] === 'sly_deal') {
            return $this->applySlyDeal($state, $targetId);
        }

        if ($state['pending']['kind'] === 'forced_deal') {
            return $this->applyForcedDeal($state, $targetId);
        }

        if ($state['pending']['kind'] === 'deal_breaker') {
            return $this->applyDealBreaker($state, $targetId);
        }

        $state['pending']['charges'][$targetId]['phase'] = 'paying';

        return $state;
    }

    protected function applySlyDeal(array $state, int $targetId): array
    {
        $pending = $state['pending'];
        $sourceId = (int) $pending['source_id'];

        $fromColor = $this->stealableColor($state, $targetId, $pending['target_card_id']);
        $state = $this->moveProperty($state, $targetId, $fromColor, $sourceId, $pending['color'], $pending['target_card_id']);
        $state = $this->completeCharge($state, $targetId, 'applied');

        return $this->endPendingIfWon($state, $sourceId);
    }

    protected function applyForcedDeal(array $state, int $targetId): array
    {
        $pending = $state['pending'];
        $sourceId = (int) $pending['source_id'];

        $takenFrom = $this->stealableColor($state, $targetId, $pending['target_card_id']);
        $givenFrom = $this->stealableColor(
            $state,
            $sourceId,
            $pending['give_card_id'],
            'Choose one of your own properties to give.',
            'You cannot give up a property from a complete set.',
        );

        $state = $this->moveProperty($state, $targetId, $takenFrom, $sourceId, $pending['color'], $pending['target_card_id']);
        $state = $this->moveProperty($state, $sourceId, $givenFrom, $targetId, $givenFrom, $pending['give_card_id']);
        $state = $this->completeCharge($state, $targetId, 'applied');

        // Both sides just received a property, so either could have
        // reached a third set; if both did, the player whose turn it is wins.
        return $this->endPendingIfWon($state, $sourceId, $targetId);
    }

    /**
     * Moves the target's whole set to the source. Because a player holds
     * at most one group per color (the Phase 2 simplification), a set
     * taken in a color the source already has is merged into that
     * group; if both groups carry a SHISHA or WIL3A the group can only
     * hold one of each, so the extra building goes to the source's bank
     * at face value rather than vanishing.
     */
    protected function applyDealBreaker(array $state, int $targetId): array
    {
        $pending = $state['pending'];
        $sourceId = (int) $pending['source_id'];
        $color = $pending['color'];

        $this->assertCompleteSet($state, $targetId, $color);

        $set = $state['properties'][$targetId][$color];
        unset($state['properties'][$targetId][$color]);

        foreach ($set['cards'] as $cardId) {
            $state = $this->addToPropertyGroup($state, (string) $sourceId, $color, $cardId);
        }

        foreach (['house', 'hotel'] as $building) {
            if ($set[$building] === null) {
                continue;
            }

            if ($state['properties'][$sourceId][$color][$building] === null) {
                $state['properties'][$sourceId][$color][$building] = $set[$building];
            } else {
                $state['banks'][$sourceId][] = $set[$building];
            }
        }

        $state = $this->completeCharge($state, $targetId, 'applied');

        return $this->endPendingIfWon($state, $sourceId);
    }

    /**
     * After a transfer that gave someone a property: if that completed
     * a third set for any of $playerIds (checked in the order given —
     * the first to qualify wins) the game is over, so the pending
     * action is dropped along with any charges still open on it.
     */
    protected function endPendingIfWon(array $state, int ...$playerIds): array
    {
        foreach ($playerIds as $playerId) {
            $state = $this->checkWinCondition($state, (string) $playerId);

            if (($state['winner'] ?? null) !== null) {
                $state['pending'] = null;

                return $state;
            }
        }

        return $state;
    }

    protected function completeCharge(array $state, int $targetId, string $outcome): array
    {
        $state['pending']['charges'][$targetId]['phase'] = 'done';
        $state['pending']['charges'][$targetId]['outcome'] = $outcome;

        return $state;
    }

    /**
     * Advances every charge that no longer needs a human decision:
     * a responder with no Just Say No card is auto-declined, and a
     * target with nothing payable owes nothing. Repeats until nothing
     * more can move, then clears `pending` once every charge is done.
     */
    protected function settlePending(array $state): array
    {
        if ($state['pending'] === null) {
            return $state;
        }

        do {
            $progressed = false;

            foreach (array_keys($state['pending']['charges']) as $targetId) {
                $targetId = (int) $targetId;
                $phase = $state['pending']['charges'][$targetId]['phase'];

                if (
                    $phase === 'responding'
                    && ! $this->holdsJustSayNo($state, $this->responderId($state['pending'], $targetId))
                ) {
                    $state = $this->finishResponse($state, $targetId);

                    if ($state['pending'] === null) {
                        return $state;
                    }

                    $progressed = true;
                } elseif ($phase === 'paying' && $this->payableAssets($state, $targetId) === []) {
                    $state = $this->completeCharge($state, $targetId, 'applied');
                    $progressed = true;
                }
            }
        } while ($progressed);

        foreach ($state['pending']['charges'] as $charge) {
            if ($charge['phase'] !== 'done') {
                return $state;
            }
        }

        $state['pending'] = null;

        return $state;
    }

    protected function holdsJustSayNo(array $state, int $userId): bool
    {
        foreach ($state['hands'][$userId] ?? [] as $cardId) {
            $card = CardCatalog::get($cardId);

            if ($card['type'] === 'action' && $card['action'] === 'just_say_no') {
                return true;
            }
        }

        return false;
    }

    /**
     * Everything a player could pay with — bank cards, property cards,
     * and any SHISHA/WIL3A on their sets — as cardId => value in $M.
     * Zero-value cards (the EL BOB wildcard) are left out.
     *
     * @return array<string, int>
     */
    protected function payableAssets(array $state, int $userId): array
    {
        $cardIds = $state['banks'][$userId] ?? [];

        foreach ($state['properties'][$userId] ?? [] as $group) {
            array_push($cardIds, ...$group['cards']);

            foreach (['house', 'hotel'] as $building) {
                if ($group[$building] !== null) {
                    $cardIds[] = $group[$building];
                }
            }
        }

        $assets = [];

        foreach ($cardIds as $cardId) {
            $value = CardCatalog::get($cardId)['value'] ?? 0;

            if ($value > 0) {
                $assets[$cardId] = $value;
            }
        }

        return $assets;
    }

    /**
     * Moves one paid card from $fromId to $toId. Bank cards stay bank
     * cards; a property card keeps the color group it was sitting in;
     * a SHISHA/WIL3A comes off its set and goes to the receiver's bank.
     */
    protected function transferPaymentCard(array $state, int $fromId, int $toId, string $cardId): array
    {
        if (in_array($cardId, $state['banks'][$fromId] ?? [], true)) {
            $state['banks'][$fromId] = $this->removeOneCard($state['banks'][$fromId], $cardId);
            $state['banks'][$toId][] = $cardId;

            return $state;
        }

        foreach ($state['properties'][$fromId] ?? [] as $color => $group) {
            if (in_array($cardId, $group['cards'], true)) {
                return $this->moveProperty($state, $fromId, $color, $toId, $color, $cardId);
            }

            if ($group['house'] === $cardId || $group['hotel'] === $cardId) {
                $building = $group['house'] === $cardId ? 'house' : 'hotel';
                $state['properties'][$fromId][$color][$building] = null;
                $state['banks'][$toId][] = $cardId;

                return $this->dropGroupIfEmpty($state, $fromId, $color);
            }
        }

        throw new \InvalidArgumentException('That card is not yours to pay with.');
    }

    /**
     * Moves one property/wildcard card out of $fromId's $fromColor group
     * and into $toId's $toColor group (created if they have none yet),
     * dropping the old group if that leaves it empty.
     */
    protected function moveProperty(array $state, int $fromId, string $fromColor, int $toId, string $toColor, string $cardId): array
    {
        $state['properties'][$fromId][$fromColor]['cards'] = $this->removeOneCard(
            $state['properties'][$fromId][$fromColor]['cards'],
            $cardId,
        );
        $state = $this->addToPropertyGroup($state, (string) $toId, $toColor, $cardId);

        return $this->dropGroupIfEmpty($state, $fromId, $fromColor);
    }

    /**
     * A color group with no cards and no buildings left has nothing to
     * show — remove it so it doesn't linger as an empty shell. (One that
     * still carries a SHISHA/WIL3A is kept: the building stays with the
     * color and starts counting again if the set is completed again.)
     */
    protected function dropGroupIfEmpty(array $state, int $userId, string $color): array
    {
        $group = $state['properties'][$userId][$color];

        if ($group['cards'] === [] && $group['house'] === null && $group['hotel'] === null) {
            unset($state['properties'][$userId][$color]);
        }

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