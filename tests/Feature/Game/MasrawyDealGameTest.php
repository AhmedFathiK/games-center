<?php

namespace Tests\Feature\Games;

use App\Games\MasrawyDeal\CardCatalog;
use App\Games\MasrawyDeal\MasrawyDealGame;
use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasrawyDealGameTest extends TestCase
{
    use RefreshDatabase;

    protected function makeRoom(int $playerCount): Room
    {
        $game = Game::create([
            'name' => 'Masrawy Deal',
            'slug' => 'masrawy-deal',
            'enabled' => true,
        ]);

        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'XYZ789',
            'max_players' => 5,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        // Masrawy Deal's hostIsPlayer() is true, so the host must be
        // attached as a player too, unlike Mafia's makeRoom() helper.
        $room->players()->attach($host->id);

        $otherPlayers = User::factory()->count($playerCount - 1)->create();
        $room->players()->attach($otherPlayers->pluck('id')->all());

        return $room->fresh();
    }

    // --- Card catalog integrity ---------------------------------------

    public function test_catalog_contains_exactly_106_unique_cards(): void
    {
        $cards = CardCatalog::all();

        $this->assertCount(106, $cards);
        $this->assertCount(106, array_unique(array_keys($cards)));
    }

    public function test_catalog_category_counts_match_the_official_breakdown(): void
    {
        $cards = CardCatalog::all();
        $counts = ['money' => 0, 'property' => 0, 'wildcard' => 0, 'rent' => 0, 'action' => 0];

        foreach ($cards as $card) {
            $counts[$card['type']]++;
        }

        $this->assertEquals(20, $counts['money']);
        $this->assertEquals(28, $counts['property']);
        $this->assertEquals(11, $counts['wildcard']);
        $this->assertEquals(13, $counts['rent']);
        $this->assertEquals(34, $counts['action']);
    }

    public function test_catalog_money_total_value_is_57_million(): void
    {
        $total = collect(CardCatalog::all())
            ->where('type', 'money')
            ->sum('value');

        $this->assertEquals(57, $total);
    }

    public function test_money_card_labels_have_no_currency_symbol(): void
    {
        // Confirmed via the card studio: plain "1M"/"2M"/etc., not "$1M".
        $this->assertEquals('1M', CardCatalog::get('money_1_1')['label']);
        $this->assertEquals('10M', CardCatalog::get('money_10_1')['label']);
    }

    public function test_property_card_count_per_color_matches_its_set_size(): void
    {
        $cards = collect(CardCatalog::all())->where('type', 'property');

        foreach (CardCatalog::SET_SIZE as $color => $setSize) {
            $this->assertEquals(
                $setSize,
                $cards->where('color', $color)->count(),
                "Expected {$setSize} standard {$color} property cards."
            );
        }
    }

    public function test_rent_chart_has_an_entry_for_every_color_matching_its_set_size(): void
    {
        foreach (CardCatalog::SET_SIZE as $color => $setSize) {
            $this->assertArrayHasKey($color, CardCatalog::RENT_CHART);
            $this->assertCount($setSize, CardCatalog::RENT_CHART[$color]);
        }
    }

    public function test_get_returns_the_requested_card(): void
    {
        $card = CardCatalog::get('action_deal_breaker_1');

        $this->assertEquals('action', $card['type']);
        $this->assertEquals('deal_breaker', $card['action']);
    }

    public function test_get_throws_for_an_unknown_card_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CardCatalog::get('not_a_real_card');
    }

    // --- Franco-Arabic text (translated so far) -----------------------

    public function test_action_category_label_is_set(): void
    {
        $this->assertEquals('CART SAYTARA', CardCatalog::ACTION_CATEGORY_LABEL);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
     */
    public static function translatedActionCards(): array
    {
        return [
            'deal_breaker' => ['deal_breaker', 'HAT wa lamo2akhza EL SHORT!', 'HAT EL GAMAL BEMA 7AMAL', 5],
            'birthday' => ['birthday', '3ID MILADY YA KELAB', '2 MILLION MALTOOSH min KOL BRINCE', 2],
            'sly_deal' => ['sly_deal', 'KHOD AMA 2OLAK', 'KHOD MANTI2A MIN AY BRINCE', 3],
            'forced_deal' => ['forced_deal', 'MA.. TEEGY WANA AGY!', 'SALIM WESTILIM MANTI2A', 3],
            'debt_collector' => ['debt_collector', 'HAT 5 FI KEES', 'LABES WA7ID YEDIK 5 MILLION MALTOOSH', 3],
            'pass_go' => ['pass_go', 'GARAB 7AZAK', 'ES7AB KARTEIN', 1],
            'just_say_no' => ['just_say_no', 'DA 3AND OMMO...', 'ORFOD AY CART SAYTARA', 4],
            'double_rent' => ['double_rent', 'ELBIS X 2', 'LAZEM CART EL TALBEES 3ASHAN TELABES X 2', 1],
            'house' => ['house', 'SHISHA', '7OT SHISHATK 3ALA MANTI2A KAMLA LABES 3M ZYADA', 3],
            'hotel' => ['hotel', 'WIL3A', 'ZABAT SHISHTAK BEL WIL3A LABES 4M ZYADA', 4],
        ];
    }

    #[DataProvider('translatedActionCards')]
    public function test_translated_action_cards_have_the_correct_label_description_and_value(
        string $action,
        string $expectedLabel,
        string $expectedDescription,
        int $expectedValue,
    ): void {
        $cards = collect(CardCatalog::all())->where('action', $action)->values();

        $this->assertNotEmpty($cards);

        foreach ($cards as $card) {
            $this->assertEquals($expectedLabel, $card['label']);
            $this->assertEquals($expectedDescription, $card['description']);
            $this->assertEquals($expectedValue, $card['value']);
        }
    }

    public function test_no_action_card_has_a_null_description_anymore(): void
    {
        // Every action card is now confirmed — this is the mirror image
        // of the old "still pending" test, kept so a future action card
        // added without a description gets caught immediately.
        foreach (collect(CardCatalog::all())->where('type', 'action') as $card) {
            $this->assertNotNull($card['description'], "{$card['id']} should not be pending translation.");
        }
    }

    public function test_house_and_hotel_are_renamed_to_shisha_and_wil3a(): void
    {
        // The internal 'action' key deliberately stays 'house'/'hotel'
        // (same convention as every other technical identifier) — only
        // the player-facing 'label' changes.
        $shishaCards = collect(CardCatalog::all())->where('action', 'house');
        $wil3aCards = collect(CardCatalog::all())->where('action', 'hotel');

        $this->assertCount(3, $shishaCards);
        $this->assertCount(2, $wil3aCards);

        foreach ($shishaCards as $card) {
            $this->assertEquals('SHISHA', $card['label']);
        }

        foreach ($wil3aCards as $card) {
            $this->assertEquals('WIL3A', $card['label']);
        }
    }

    public function test_regular_rent_cards_charge_everyone_and_bank_for_1m(): void
    {
        $regularRentCards = collect(CardCatalog::all())
            ->where('type', 'rent')
            ->where('any_color', false);

        $this->assertCount(10, $regularRentCards);

        foreach ($regularRentCards as $card) {
            $this->assertEquals('ELBIS!', $card['label']);
            $this->assertEquals('LABES EL KOL EL EIGAR', $card['description']);
            $this->assertTrue($card['charges_all']);
            $this->assertEquals(1, $card['value']);
        }
    }

    public function test_wild_rent_cards_charge_one_player_and_bank_for_3m(): void
    {
        $wildRentCards = collect(CardCatalog::all())
            ->where('type', 'rent')
            ->where('any_color', true);

        $this->assertCount(3, $wildRentCards);

        foreach ($wildRentCards as $card) {
            $this->assertEquals('ELBIS!', $card['label']);
            $this->assertEquals('LABES WA7ID LEWA7DO EL EIGAR', $card['description']);
            $this->assertFalse($card['charges_all']);
            // Deliberately 3M, not the 1M stock Monopoly Deal value —
            // Ahmed's explicit house-rule call, locked in so it doesn't
            // silently drift back to 1M in a later refactor.
            $this->assertEquals(3, $card['value']);
        }
    }

    public function test_every_card_has_a_description_key_even_if_still_null(): void
    {
        foreach (CardCatalog::all() as $card) {
            $this->assertArrayHasKey('description', $card);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<int, string>}>
     */
    public static function translatedPropertyColors(): array
    {
        return [
            'green' => ['green', ['SA7RAWY', '6 OCTOBER', 'SHEIKH ZAYED']],
            'red' => ['red', ['GARDEN CITY', 'MASR EL GEDIDA', 'ZAMALEK']],
            'light_blue' => ['light_blue', ['MOHAMED MAHMOUD', 'MIDDAN EL TAHRIR', 'TAL3AT 7ARB']],
            'brown' => ['brown', ['100 302BA', 'ARD EL LEWA']],
            'railroad' => ['railroad', ['Taftaf Ghamra', 'Taftaf El Bo7ous', 'Taftaf El 3ataba', 'Taftaf Ramsis']],
            'yellow' => ['yellow', ['DOKKI', 'MOHANDESIN', 'HARAM']],
            'dark_blue' => ['dark_blue', ['CAIRO FESTIVAL CITY', '2ATAMEYA HIGHTS']],
            'utility' => ['utility', ['MAYA El dayman ma2too3a', 'KAHRABA El dayman ma2too3a']],
            'orange' => ['orange', ['EL MONTAZA', 'SIDY GABER', 'SAN STEPHANO']],
            'pink' => ['pink', ['EL SHEROU2', 'EL 3OBOOR', 'MAADI']],
        ];
    }

    #[DataProvider('translatedPropertyColors')]
    public function test_translated_property_colors_have_their_individual_titles_in_order(
        string $color,
        array $expectedTitles,
    ): void {
        foreach ($expectedTitles as $i => $expectedTitle) {
            $card = CardCatalog::get('prop_' . $color . '_' . ($i + 1));

            $this->assertEquals($expectedTitle, $card['label']);
        }
    }

    public function test_every_property_color_has_an_individual_title_now(): void
    {
        // All 10 colors are confirmed — colorLabel() is now a dead
        // fallback, unused by any actual card label (properties all
        // have individual titles, wildcards are named EL BOB / Cart
        // Karbaga, kept only for defensive future use).
        foreach (CardCatalog::SET_SIZE as $color => $count) {
            $card = CardCatalog::get('prop_' . $color . '_1');

            $this->assertNotEquals(CardCatalog::colorLabel($color), $card['label']);
        }
    }

    public function test_orange_face_value_is_confirmed_at_2m(): void
    {
        $this->assertEquals(2, CardCatalog::FACE_VALUE['orange']);
    }

    public function test_pink_face_value_is_confirmed_at_2m(): void
    {
        $this->assertEquals(2, CardCatalog::FACE_VALUE['pink']);
    }

    public function test_face_value_has_an_entry_for_every_color(): void
    {
        foreach (CardCatalog::SET_SIZE as $color => $setSize) {
            $this->assertArrayHasKey($color, CardCatalog::FACE_VALUE);
        }
    }

    public function test_every_property_card_carries_its_colors_face_value(): void
    {
        $cards = collect(CardCatalog::all())->where('type', 'property');

        foreach (CardCatalog::FACE_VALUE as $color => $faceValue) {
            foreach ($cards->where('color', $color) as $card) {
                $this->assertEquals($faceValue, $card['value']);
            }
        }
    }

    public function test_multicolor_wildcard_is_named_el_bob(): void
    {
        $cards = collect(CardCatalog::all())->where('any_color', true)->where('type', 'wildcard');

        $this->assertCount(2, $cards);

        foreach ($cards as $card) {
            $this->assertEquals('EL BOB', $card['label']);
            $this->assertEquals('7ot el bob 3ala kol lon ya batista', $card['description']);
        }
    }

    public function test_two_color_property_wildcards_are_named_cart_karbaga(): void
    {
        $cards = collect(CardCatalog::all())->where('any_color', false)->where('type', 'wildcard');

        $this->assertCount(9, $cards);

        foreach ($cards as $card) {
            $this->assertEquals('Cart Karbaga', $card['label']);
        }
    }

    public function test_two_color_wildcards_carry_their_official_face_values(): void
    {
        // Phase 3 needs a value on every wildcard so it can be paid as
        // rent/debt. These are the official Monopoly Deal values, not
        // yet cross-checked against Ahmed's card studio.
        $expected = [
            'wild_dark_blue_green_1' => 4,
            'wild_green_railroad_1' => 4,
            'wild_utility_railroad_1' => 2,
            'wild_light_blue_railroad_1' => 4,
            'wild_light_blue_brown_1' => 1,
            'wild_pink_orange_1' => 2,
            'wild_pink_orange_2' => 2,
            'wild_red_yellow_1' => 3,
            'wild_red_yellow_2' => 3,
        ];

        foreach ($expected as $cardId => $value) {
            $this->assertEquals($value, CardCatalog::get($cardId)['value'], $cardId);
        }
    }

    public function test_multicolor_wildcards_are_worth_nothing(): void
    {
        $this->assertEquals(0, CardCatalog::get('wild_any_1')['value']);
        $this->assertEquals(0, CardCatalog::get('wild_any_2')['value']);
    }

    // --- Game definition -------------------------------------------------

    public function test_host_is_a_player(): void
    {
        $this->assertTrue((new MasrawyDealGame())->hostIsPlayer());
    }

    public function test_minimum_and_maximum_players(): void
    {
        $game = new MasrawyDealGame();

        $this->assertEquals(2, $game->minimumPlayers());
        $this->assertEquals(5, $game->maximumPlayers());
    }

    // --- Dealing / setup ---------------------------------------------------

    public function test_every_player_is_dealt_exactly_5_cards(): void
    {
        $room = $this->makeRoom(4);

        $state = (new MasrawyDealGame())->initializeState($room);

        foreach ($state['hands'] as $hand) {
            $this->assertCount(5, $hand);
        }
    }

    public function test_draw_pile_and_hands_account_for_the_full_deck_with_no_duplicates(): void
    {
        $room = $this->makeRoom(3);

        $state = (new MasrawyDealGame())->initializeState($room);

        $dealtCardIds = collect($state['hands'])->flatten()->all();
        $allCardIds = array_merge($dealtCardIds, $state['draw_pile']);

        $this->assertCount(106, $allCardIds);
        $this->assertCount(106, array_unique($allCardIds));
        $this->assertEqualsCanonicalizing(CardCatalog::deckIds(), $allCardIds);
    }

    public function test_draw_pile_size_matches_player_count(): void
    {
        $room = $this->makeRoom(5);

        $state = (new MasrawyDealGame())->initializeState($room);

        // 106 - (5 cards * 5 players) = 81
        $this->assertCount(81, $state['draw_pile']);
    }

    public function test_turn_order_contains_exactly_the_rooms_players(): void
    {
        $room = $this->makeRoom(4);
        $expectedIds = $room->players()->pluck('users.id')->sort()->values()->all();

        $state = (new MasrawyDealGame())->initializeState($room);
        $actualIds = collect($state['turn_order'])->sort()->values()->all();

        $this->assertEquals($expectedIds, $actualIds);
    }

    public function test_current_player_is_the_first_in_turn_order(): void
    {
        $room = $this->makeRoom(3);

        $state = (new MasrawyDealGame())->initializeState($room);

        $this->assertEquals($state['turn_order'][0], $state['current_player_id']);
    }

    public function test_every_player_starts_with_an_empty_bank_and_properties(): void
    {
        $room = $this->makeRoom(2);

        $state = (new MasrawyDealGame())->initializeState($room);

        foreach ($state['turn_order'] as $userId) {
            $this->assertEquals([], $state['banks'][$userId]);
            $this->assertEquals([], $state['properties'][$userId]);
        }
    }

    public function test_initial_state_has_no_pending_action_and_no_winner(): void
    {
        $room = $this->makeRoom(2);

        $state = (new MasrawyDealGame())->initializeState($room);

        $this->assertNull($state['pending']);
        $this->assertNull($state['winner']);
        $this->assertEquals(0, $state['cards_played_this_turn']);
        $this->assertFalse($state['has_drawn_this_turn']);
    }

    // ==================================================================
    // Phase 2: turn logic
    // ==================================================================

    /**
     * A bare-bones in-progress room: empty hands/banks/properties for
     * every player, turn_order fixed to join order (not shuffled, so
     * tests can rely on it), current player is the first. Individual
     * tests layer on whatever hand/pile/property state they need via
     * setState()/setHand().
     */
    protected function makeInProgressRoom(int $playerCount): Room
    {
        $room = $this->makeRoom($playerCount);
        $playerIds = $room->players()->pluck('users.id')->values()->all();

        $hands = [];
        $banks = [];
        $properties = [];

        foreach ($playerIds as $id) {
            $hands[$id] = [];
            $banks[$id] = [];
            $properties[$id] = [];
        }

        $room->update([
            'status' => 'in_progress',
            'game_state' => [
                'turn_order' => $playerIds,
                'current_player_id' => $playerIds[0],
                'draw_pile' => [],
                'discard_pile' => [],
                'hands' => $hands,
                'banks' => $banks,
                'properties' => $properties,
                'cards_played_this_turn' => 0,
                'has_drawn_this_turn' => false,
                'pending' => null,
                'winner' => null,
            ],
        ]);

        return $room->fresh();
    }

    protected function setState(Room $room, array $changes): Room
    {
        $state = $room->game_state;

        foreach ($changes as $key => $value) {
            $state[$key] = $value;
        }

        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    protected function setHand(Room $room, int $userId, array $cardIds): Room
    {
        $state = $room->game_state;
        $state['hands'][$userId] = $cardIds;
        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    // --- Draw -----------------------------------------------------------

    public function test_draw_gives_two_cards_when_hand_is_not_empty(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['money_1_1']);
        $room = $this->setState($room, ['draw_pile' => ['money_1_2', 'money_1_3', 'money_1_4']]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'draw']);

        $this->assertCount(3, $state['hands'][$p1]);
        $this->assertCount(1, $state['draw_pile']);
        $this->assertTrue($state['has_drawn_this_turn']);
    }

    public function test_draw_gives_five_cards_when_hand_was_empty(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, [
            'draw_pile' => ['money_1_1', 'money_1_2', 'money_1_3', 'money_1_4', 'money_1_5', 'money_1_6'],
        ]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'draw']);

        $this->assertCount(5, $state['hands'][$p1]);
        $this->assertCount(1, $state['draw_pile']);
    }

    public function test_cannot_draw_twice_in_one_turn(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['draw_pile' => ['money_1_1', 'money_1_2', 'money_1_3', 'money_1_4']]);

        $game = new MasrawyDealGame();
        $state = $game->submitAction($room, User::find($p1), ['type' => 'draw']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);
        $game->submitAction($room, User::find($p1), ['type' => 'draw']);
    }

    public function test_draw_reshuffles_discard_pile_when_draw_pile_runs_out(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        // A non-empty starting hand is essential here — an empty hand
        // would trigger the 5-card draw rule instead of the normal
        // 2-card draw this test is actually about.
        $room = $this->setHand($room, $p1, ['money_1_4']);
        $room = $this->setState($room, [
            'draw_pile' => ['money_1_1'],
            'discard_pile' => ['money_1_2', 'money_1_3'],
        ]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'draw']);

        // 1 starting + 2 drawn (1 from draw_pile, then the discard pile
        // reshuffles in and 1 more is drawn from it).
        $this->assertCount(3, $state['hands'][$p1]);
        $this->assertCount(1, $state['draw_pile']);
        $this->assertCount(0, $state['discard_pile']);
    }

    public function test_only_the_current_player_can_act(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p2), ['type' => 'draw']);
    }

    public function test_no_action_allowed_once_the_game_has_a_winner(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['winner' => $p1]);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'draw']);
    }

    // --- Playing money ----------------------------------------------------

    public function test_play_money_moves_the_card_to_the_bank(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['money_1_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_money',
            'card_id' => 'money_1_1',
        ]);

        $this->assertEquals([], $state['hands'][$p1]);
        $this->assertEquals(['money_1_1'], $state['banks'][$p1]);
        $this->assertEquals(1, $state['cards_played_this_turn']);
    }

    public function test_cannot_play_money_before_drawing(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['money_1_1']);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_money',
            'card_id' => 'money_1_1',
        ]);
    }

    public function test_cannot_play_a_property_card_as_money(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['prop_green_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_money',
            'card_id' => 'prop_green_1',
        ]);
    }

    public function test_cannot_play_more_than_three_cards_per_turn(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['money_1_1', 'money_1_2', 'money_1_3', 'money_1_4']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $game = new MasrawyDealGame();

        foreach (['money_1_1', 'money_1_2', 'money_1_3'] as $cardId) {
            $state = $game->submitAction($room, User::find($p1), ['type' => 'play_money', 'card_id' => $cardId]);
            $room->update(['game_state' => $state]);
            $room->refresh();
        }

        $this->expectException(\InvalidArgumentException::class);
        $game->submitAction($room, User::find($p1), ['type' => 'play_money', 'card_id' => 'money_1_4']);
    }

    // --- Playing properties -----------------------------------------------

    public function test_play_property_adds_the_card_to_the_correct_color_group(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['prop_green_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_property',
            'card_id' => 'prop_green_1',
        ]);

        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
    }

    public function test_two_color_wildcard_requires_a_valid_color_choice(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['wild_dark_blue_green_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_property',
            'card_id' => 'wild_dark_blue_green_1',
            'color' => 'red', // not one of this wildcard's two colors
        ]);
    }

    public function test_two_color_wildcard_played_as_one_of_its_valid_colors(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['wild_dark_blue_green_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_property',
            'card_id' => 'wild_dark_blue_green_1',
            'color' => 'green',
        ]);

        $this->assertEquals(['wild_dark_blue_green_1'], $state['properties'][$p1]['green']['cards']);
    }

    public function test_completing_a_third_set_wins_the_game(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];

        // Already own 2 complete sets (brown needs 2, utility needs 2);
        // the third card played this turn completes green (needs 3).
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'properties' => [
                (string) $p1 => [
                    'brown' => ['cards' => ['prop_brown_1', 'prop_brown_2'], 'house' => null, 'hotel' => null],
                    'utility' => ['cards' => ['prop_utility_1', 'prop_utility_2'], 'house' => null, 'hotel' => null],
                    'green' => ['cards' => ['prop_green_1', 'prop_green_2'], 'house' => null, 'hotel' => null],
                ],
            ],
        ]);
        $room = $this->setHand($room, $p1, ['prop_green_3']);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_property',
            'card_id' => 'prop_green_3',
        ]);

        $this->assertEquals((string) $p1, (string) $state['winner']);
    }

    public function test_two_multicolor_wildcards_alone_do_not_complete_a_set(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        // Utility needs only 2 — both from EL BOB (any_color) wildcards.
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'properties' => [
                (string) $p1 => [
                    'utility' => ['cards' => ['wild_any_1'], 'house' => null, 'hotel' => null],
                ],
            ],
        ]);
        $room = $this->setHand($room, $p1, ['wild_any_2']);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_property',
            'card_id' => 'wild_any_2',
            'color' => 'utility',
        ]);

        $this->assertNull($state['winner']);
    }

    // --- Banking action/rent cards -----------------------------------------

    public function test_bank_card_moves_an_action_card_to_the_bank_at_face_value(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['action_deal_breaker_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'bank_card',
            'card_id' => 'action_deal_breaker_1',
        ]);

        $this->assertEquals(['action_deal_breaker_1'], $state['banks'][$p1]);
    }

    public function test_cannot_bank_a_property_card(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['prop_green_1']);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'bank_card',
            'card_id' => 'prop_green_1',
        ]);
    }

    // --- GARAB 7AZAK / Pass Go -----------------------------------------

    public function test_pass_go_discards_itself_and_draws_two(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['action_pass_go_1']);
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'draw_pile' => ['money_1_1', 'money_1_2'],
        ]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_pass_go',
            'card_id' => 'action_pass_go_1',
        ]);

        $this->assertEquals(['money_1_1', 'money_1_2'], $state['hands'][$p1]);
        $this->assertEquals(['action_pass_go_1'], $state['discard_pile']);
    }

    // --- SHISHA / WIL3A -----------------------------------------------

    public function test_shisha_requires_a_complete_set(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'properties' => [
                (string) $p1 => [
                    'brown' => ['cards' => ['prop_brown_1'], 'house' => null, 'hotel' => null], // needs 2
                ],
            ],
        ]);
        $room = $this->setHand($room, $p1, ['action_house_1']);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_shisha',
            'card_id' => 'action_house_1',
            'color' => 'brown',
        ]);
    }

    public function test_shisha_placed_on_a_complete_set(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'properties' => [
                (string) $p1 => [
                    'brown' => ['cards' => ['prop_brown_1', 'prop_brown_2'], 'house' => null, 'hotel' => null],
                ],
            ],
        ]);
        $room = $this->setHand($room, $p1, ['action_house_1']);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_shisha',
            'card_id' => 'action_house_1',
            'color' => 'brown',
        ]);

        $this->assertEquals('action_house_1', $state['properties'][$p1]['brown']['house']);
    }

    public function test_wil3a_requires_a_shisha_first(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'properties' => [
                (string) $p1 => [
                    'brown' => ['cards' => ['prop_brown_1', 'prop_brown_2'], 'house' => null, 'hotel' => null],
                ],
            ],
        ]);
        $room = $this->setHand($room, $p1, ['action_hotel_1']);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_wil3a',
            'card_id' => 'action_hotel_1',
            'color' => 'brown',
        ]);
    }

    public function test_wil3a_placed_after_shisha(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, [
            'has_drawn_this_turn' => true,
            'properties' => [
                (string) $p1 => [
                    'brown' => ['cards' => ['prop_brown_1', 'prop_brown_2'], 'house' => 'action_house_1', 'hotel' => null],
                ],
            ],
        ]);
        $room = $this->setHand($room, $p1, ['action_hotel_1']);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'play_wil3a',
            'card_id' => 'action_hotel_1',
            'color' => 'brown',
        ]);

        $this->assertEquals('action_hotel_1', $state['properties'][$p1]['brown']['hotel']);
    }

    // --- Discard --------------------------------------------------------

    public function test_discard_only_allowed_over_the_hand_limit(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setHand($room, $p1, ['money_1_1', 'money_1_2']); // only 2 cards
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'discard',
            'card_id' => 'money_1_1',
        ]);
    }

    public function test_discard_removes_the_card_and_sends_it_to_the_discard_pile(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $hand = ['money_1_1', 'money_1_2', 'money_1_3', 'money_1_4', 'money_1_5', 'money_1_6', 'money_2_1', 'money_2_2'];
        $room = $this->setHand($room, $p1, $hand);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), [
            'type' => 'discard',
            'card_id' => 'money_2_2',
        ]);

        $this->assertCount(7, $state['hands'][$p1]);
        $this->assertNotContains('money_2_2', $state['hands'][$p1]);
        $this->assertEquals(['money_2_2'], $state['discard_pile']);
    }

    // --- End turn --------------------------------------------------------

    public function test_cannot_end_turn_before_drawing(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'end_turn']);
    }

    public function test_cannot_end_turn_while_over_the_hand_limit(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $hand = ['money_1_1', 'money_1_2', 'money_1_3', 'money_1_4', 'money_1_5', 'money_1_6', 'money_2_1', 'money_2_2'];
        $room = $this->setHand($room, $p1, $hand);
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        $this->expectException(\InvalidArgumentException::class);
        (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'end_turn']);
    }

    public function test_end_turn_advances_to_the_next_player_and_resets_turn_state(): void
    {
        $room = $this->makeInProgressRoom(3);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['has_drawn_this_turn' => true, 'cards_played_this_turn' => 2]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'end_turn']);

        $this->assertEquals($p2, $state['current_player_id']);
        $this->assertFalse($state['has_drawn_this_turn']);
        $this->assertEquals(0, $state['cards_played_this_turn']);
    }

    public function test_end_turn_wraps_around_from_the_last_player_to_the_first(): void
    {
        $room = $this->makeInProgressRoom(3);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['current_player_id' => $p3, 'has_drawn_this_turn' => true]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p3), ['type' => 'end_turn']);

        $this->assertEquals($p1, $state['current_player_id']);
    }

    // ==================================================================
    // Phase 3, slice 1: pending actions, Just Say No, payment,
    // HAT 5 FI KEES (Debt Collector)
    // ==================================================================

    /**
     * Runs one action through the real game and saves the result back
     * onto the room — the round trip through the database (JSON) also
     * catches any array-key type surprises a raw return value would hide.
     */
    protected function act(Room $room, int $userId, array $payload): Room
    {
        $state = (new MasrawyDealGame())->submitAction($room, User::find($userId), $payload);
        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    protected function assertRejected(Room $room, int $userId, array $payload, ?string $messageFragment = null): void
    {
        try {
            (new MasrawyDealGame())->submitAction($room, User::find($userId), $payload);
        } catch (\InvalidArgumentException $e) {
            if ($messageFragment !== null) {
                $this->assertStringContainsString($messageFragment, $e->getMessage());
            }

            return;
        }

        $this->fail('Expected the action to be rejected, but it was accepted.');
    }

    /**
     * Every card id anywhere in the game (piles, hands, banks,
     * properties, buildings), sorted — for asserting that nothing is
     * ever created or destroyed by an action.
     *
     * @return array<int, string>
     */
    protected function allCardIds(Room $room): array
    {
        $state = $room->game_state;
        $ids = array_merge($state['draw_pile'], $state['discard_pile']);

        foreach ($state['hands'] as $hand) {
            $ids = array_merge($ids, $hand);
        }

        foreach ($state['banks'] as $bank) {
            $ids = array_merge($ids, $bank);
        }

        foreach ($state['properties'] as $groups) {
            foreach ($groups as $group) {
                $ids = array_merge($ids, $group['cards']);

                foreach (['house', 'hotel'] as $building) {
                    if ($group[$building] !== null) {
                        $ids[] = $group[$building];
                    }
                }
            }
        }

        sort($ids);

        return $ids;
    }

    protected function group(array $cards, ?string $house = null, ?string $hotel = null): array
    {
        return ['cards' => $cards, 'house' => $house, 'hotel' => $hotel];
    }

    /**
     * An in-progress room where the current player (p1) has already
     * drawn and holds a HAT 5 FI KEES (plus whatever else is passed),
     * and the target (p2) has exactly the given hand/bank/properties.
     * Everything else is empty. Play the card with playDebtCollector().
     */
    protected function debtCollectorRoom(
        array $targetHand = [],
        array $targetBank = [],
        array $targetProperties = [],
        array $sourceHand = [],
        array $sourceProperties = [],
        int $playerCount = 2,
    ): Room {
        $room = $this->makeInProgressRoom($playerCount);
        [$p1, $p2] = $room->game_state['turn_order'];

        $state = $room->game_state;
        $state['has_drawn_this_turn'] = true;
        $state['hands'][$p1] = array_merge(['action_debt_collector_1'], $sourceHand);
        $state['hands'][$p2] = $targetHand;
        $state['banks'][$p2] = $targetBank;
        $state['properties'][$p2] = $targetProperties;
        $state['properties'][$p1] = $sourceProperties;
        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    protected function playDebtCollector(Room $room, int $sourceId, int $targetId): Room
    {
        return $this->act($room, $sourceId, [
            'type' => 'play_debt_collector',
            'card_id' => 'action_debt_collector_1',
            'target_id' => $targetId,
        ]);
    }

    // --- Playing HAT 5 FI KEES ------------------------------------------

    public function test_debt_collector_goes_to_the_discard_pile_counts_as_a_play_and_opens_a_pending_action(): void
    {
        // The target holds a Just Say No, so the charge has to wait for them.
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1'], targetBank: ['money_5_1']);
        [$p1, $p2] = $room->game_state['turn_order'];

        $room = $this->playDebtCollector($room, $p1, $p2);
        $state = $room->game_state;

        $this->assertNotContains('action_debt_collector_1', $state['hands'][$p1]);
        $this->assertContains('action_debt_collector_1', $state['discard_pile']);
        $this->assertEquals(1, $state['cards_played_this_turn']);
        $this->assertEquals('debt_collector', $state['pending']['kind']);
        $this->assertEquals($p1, $state['pending']['source_id']);
        $this->assertEquals('action_debt_collector_1', $state['pending']['card_id']);
        $this->assertCount(1, $state['pending']['charges']);
        $this->assertEquals('responding', $state['pending']['charges'][$p2]['phase']);
        $this->assertEquals(5, $state['pending']['charges'][$p2]['owed']);
        $this->assertEquals([], $state['pending']['charges'][$p2]['chain']);
    }

    public function test_debt_collector_needs_a_draw_first(): void
    {
        $room = $this->debtCollectorRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['has_drawn_this_turn' => false]);

        $this->assertRejected($room, $p1, [
            'type' => 'play_debt_collector',
            'card_id' => 'action_debt_collector_1',
            'target_id' => $p2,
        ], 'Draw before playing');
    }

    public function test_debt_collector_cannot_target_yourself(): void
    {
        $room = $this->debtCollectorRoom();
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, [
            'type' => 'play_debt_collector',
            'card_id' => 'action_debt_collector_1',
            'target_id' => $p1,
        ], 'yourself');
    }

    public function test_debt_collector_needs_a_real_opponent_as_target(): void
    {
        $room = $this->debtCollectorRoom();
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, [
            'type' => 'play_debt_collector',
            'card_id' => 'action_debt_collector_1',
            'target_id' => 999999,
        ], 'another player');

        $this->assertRejected($room, $p1, [
            'type' => 'play_debt_collector',
            'card_id' => 'action_debt_collector_1',
        ], 'another player');
    }

    public function test_debt_collector_rejects_a_card_that_is_not_one(): void
    {
        $room = $this->debtCollectorRoom(sourceHand: ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, [
            'type' => 'play_debt_collector',
            'card_id' => 'money_1_1',
            'target_id' => $p2,
        ], 'HAT 5 FI KEES');
    }

    public function test_debt_collector_counts_toward_the_three_card_limit(): void
    {
        $room = $this->debtCollectorRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['cards_played_this_turn' => 3]);

        $this->assertRejected($room, $p1, [
            'type' => 'play_debt_collector',
            'card_id' => 'action_debt_collector_1',
            'target_id' => $p2,
        ], '3 cards');
    }

    // --- Auto-resolution when nobody can respond ---------------------------

    public function test_target_without_a_just_say_no_goes_straight_to_paying(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_5_1']);
        [$p1, $p2] = $room->game_state['turn_order'];

        $state = $this->playDebtCollector($room, $p1, $p2)->game_state;

        $this->assertEquals('paying', $state['pending']['charges'][$p2]['phase']);
        $this->assertEquals([], $state['pending']['charges'][$p2]['chain']);
    }

    public function test_target_with_nothing_to_pay_owes_nothing_and_pending_clears(): void
    {
        $room = $this->debtCollectorRoom();
        [$p1, $p2] = $room->game_state['turn_order'];

        $state = $this->playDebtCollector($room, $p1, $p2)->game_state;

        $this->assertNull($state['pending']);
        $this->assertContains('action_debt_collector_1', $state['discard_pile']);
    }

    public function test_a_target_holding_only_a_zero_value_wildcard_owes_nothing(): void
    {
        $room = $this->debtCollectorRoom(targetProperties: ['green' => $this->group(['wild_any_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];

        $state = $this->playDebtCollector($room, $p1, $p2)->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['wild_any_1'], $state['properties'][$p2]['green']['cards']);
    }

    // --- The game is frozen while something is pending -----------------------

    public function test_the_source_cannot_do_anything_else_while_an_action_is_pending(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1'], sourceHand: ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1'], 'Waiting');
        $this->assertRejected($room, $p1, ['type' => 'end_turn'], 'Waiting');
        $this->assertRejected($room, $p1, ['type' => 'draw'], 'Waiting');
        $this->assertRejected($room, $p1, ['type' => 'discard', 'card_id' => 'money_1_1'], 'Waiting');
    }

    public function test_only_the_targeted_player_can_pay(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_5_1'], playerCount: 3);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p1, ['type' => 'pay', 'card_ids' => ['money_5_1']], 'owe');
        $this->assertRejected($room, $p3, ['type' => 'pay', 'card_ids' => ['money_5_1']], 'owe');
    }

    public function test_responses_are_rejected_when_nothing_is_pending(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1']);
        [$p1, $p2] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, ['type' => 'decline'], 'nothing to respond to');
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1']], 'nothing to respond to');
        $this->assertRejected($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1'], 'nothing to respond to');
    }

    // --- Just Say No chain ---------------------------------------------------

    public function test_the_target_can_cancel_the_charge_with_a_just_say_no(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1'], targetBank: ['money_5_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $state = $room->game_state;

        // Source holds no Just Say No, so the cancellation stands at once.
        $this->assertNull($state['pending']);
        $this->assertContains('action_just_say_no_1', $state['discard_pile']);
        $this->assertNotContains('action_just_say_no_1', $state['hands'][$p2]);
        $this->assertEquals(['money_5_1'], $state['banks'][$p2]);
        $this->assertEquals([], $state['banks'][$p1]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_source_can_counter_a_no_with_their_own_no(): void
    {
        $room = $this->debtCollectorRoom(
            targetHand: ['action_just_say_no_1'],
            targetBank: ['money_5_1'],
            sourceHand: ['action_just_say_no_2'],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        // The source holds a No, so it is now their move to answer.
        $charge = $room->game_state['pending']['charges'][$p2];
        $this->assertEquals('responding', $charge['phase']);
        $this->assertCount(1, $charge['chain']);
        $this->assertEquals($p2, $charge['chain'][0]['player_id']);

        $room = $this->act($room, $p1, [
            'type' => 'respond_no',
            'card_id' => 'action_just_say_no_2',
            'target_id' => $p2,
        ]);

        // The target has no further No, so the charge now goes through.
        $charge = $room->game_state['pending']['charges'][$p2];
        $this->assertEquals('paying', $charge['phase']);
        $this->assertCount(2, $charge['chain']);
        $this->assertEquals($p1, $charge['chain'][1]['player_id']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_a_no_chain_can_go_three_deep_and_ends_cancelled(): void
    {
        $room = $this->debtCollectorRoom(
            targetHand: ['action_just_say_no_1', 'action_just_say_no_3'],
            targetBank: ['money_5_1'],
            sourceHand: ['action_just_say_no_2'],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_3']);

        $state = $room->game_state;
        $this->assertNull($state['pending']);
        $this->assertEquals(['money_5_1'], $state['banks'][$p2]);

        foreach (['action_just_say_no_1', 'action_just_say_no_2', 'action_just_say_no_3'] as $cardId) {
            $this->assertContains($cardId, $state['discard_pile']);
        }
    }

    public function test_declining_the_no_window_lets_the_charge_through(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1'], targetBank: ['money_5_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $room = $this->act($room, $p2, ['type' => 'decline']);

        $this->assertEquals('paying', $room->game_state['pending']['charges'][$p2]['phase']);
        $this->assertContains('action_just_say_no_1', $room->game_state['hands'][$p2]);
    }

    public function test_the_source_declining_to_counter_leaves_the_cancellation_standing(): void
    {
        $room = $this->debtCollectorRoom(
            targetHand: ['action_just_say_no_1'],
            targetBank: ['money_5_1'],
            sourceHand: ['action_just_say_no_2'],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        $room = $this->act($room, $p1, ['type' => 'decline', 'target_id' => $p2]);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals(['money_5_1'], $room->game_state['banks'][$p2]);
        $this->assertContains('action_just_say_no_2', $room->game_state['hands'][$p1]);
    }

    public function test_respond_no_needs_an_actual_just_say_no_card(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1', 'money_1_1'], targetBank: ['money_5_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p2, ['type' => 'respond_no', 'card_id' => 'money_1_1'], 'DA 3AND OMMO');
        $this->assertRejected($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2'], 'not in your hand');
    }

    public function test_only_the_player_being_waited_on_can_respond(): void
    {
        $room = $this->debtCollectorRoom(
            targetHand: ['action_just_say_no_1'],
            targetBank: ['money_5_1'],
            sourceHand: ['action_just_say_no_2'],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        // First it is the target's move, not the source's.
        $this->assertRejected($room, $p1, [
            'type' => 'respond_no',
            'card_id' => 'action_just_say_no_2',
            'target_id' => $p2,
        ], 'not your turn to respond');
        $this->assertRejected($room, $p1, ['type' => 'decline', 'target_id' => $p2], 'not your turn to respond');

        // After the target's No, it is the source's move, not the target's.
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $this->assertRejected($room, $p2, ['type' => 'decline'], 'not your turn to respond');
    }

    public function test_just_say_no_never_counts_toward_the_three_card_limit(): void
    {
        $room = $this->debtCollectorRoom(
            targetHand: ['action_just_say_no_1'],
            targetBank: ['money_5_1'],
            sourceHand: ['action_just_say_no_2'],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['cards_played_this_turn' => 2]);

        $room = $this->playDebtCollector($room, $p1, $p2);
        $this->assertEquals(3, $room->game_state['cards_played_this_turn']);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);

        // Both Nos were played with the limit already used up.
        $this->assertEquals(3, $room->game_state['cards_played_this_turn']);
        $this->assertEquals('paying', $room->game_state['pending']['charges'][$p2]['phase']);
    }

    // --- Payment ------------------------------------------------------------

    public function test_paying_the_exact_amount_moves_the_cards_to_the_sources_bank(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_2_1', 'money_3_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1', 'money_3_1']]);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals([], $state['banks'][$p2]);
        $this->assertEqualsCanonicalizing(['money_2_1', 'money_3_1'], $state['banks'][$p1]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_overpaying_with_a_single_larger_card_is_fine_and_gives_no_change(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_10_1', 'money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_10_1']]);

        $this->assertEquals(['money_10_1'], $room->game_state['banks'][$p1]);
        $this->assertEquals(['money_1_1'], $room->game_state['banks'][$p2]);
    }

    public function test_paying_too_little_is_rejected_when_you_can_afford_more(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_2_1', 'money_3_1', 'money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1']], 'does not cover');
    }

    public function test_paying_with_more_cards_than_needed_is_rejected(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_5_1', 'money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_5_1', 'money_1_1']], 'more cards than needed');
    }

    public function test_a_player_who_cannot_cover_the_debt_must_pay_everything(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_2_1', 'money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1']], 'everything you have');

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1', 'money_1_1']]);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals([], $room->game_state['banks'][$p2]);
        $this->assertEqualsCanonicalizing(['money_2_1', 'money_1_1'], $room->game_state['banks'][$p1]);
    }

    public function test_you_can_only_pay_with_cards_from_your_bank_or_properties(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['money_10_1'], targetBank: ['money_5_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        // Hand cards, the source's cards and made-up ids are all refused.
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_10_1']], 'cannot be used');
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_5_2']], 'cannot be used');
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['nonsense']], 'cannot be used');
    }

    public function test_the_same_card_cannot_be_listed_twice_and_a_selection_is_required(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_2_1', 'money_3_1', 'money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_3_1', 'money_3_1']], 'only be used once');
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => []], 'Choose the cards');
        $this->assertRejected($room, $p2, ['type' => 'pay'], 'Choose the cards');
    }

    public function test_a_wildcard_with_no_value_cannot_be_used_to_pay(): void
    {
        $room = $this->debtCollectorRoom(
            targetBank: ['money_5_1'],
            targetProperties: ['green' => $this->group(['wild_any_1'])],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['wild_any_1']], 'cannot be used');
    }

    public function test_a_property_payment_lands_in_the_sources_matching_color_group(): void
    {
        $room = $this->debtCollectorRoom(targetProperties: ['green' => $this->group(['prop_green_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $before = $this->allCardIds($room);

        // 4M is short of the 5M owed, so it is paid whole.
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['prop_green_1']]);
        $state = $room->game_state;

        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertArrayNotHasKey('green', $state['properties'][$p2]);
        $this->assertNull($state['pending']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_a_paid_wildcard_keeps_the_color_it_was_sitting_in(): void
    {
        $room = $this->debtCollectorRoom(targetProperties: ['green' => $this->group(['wild_dark_blue_green_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['wild_dark_blue_green_1']]);

        $this->assertEquals(['wild_dark_blue_green_1'], $room->game_state['properties'][$p1]['green']['cards']);
    }

    public function test_receiving_the_last_property_of_a_third_set_wins_the_game(): void
    {
        $room = $this->debtCollectorRoom(
            targetProperties: ['dark_blue' => $this->group(['prop_dark_blue_2'])],
            sourceProperties: [
                'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
                'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
                'dark_blue' => $this->group(['prop_dark_blue_1']),
            ],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['prop_dark_blue_2']]);

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_a_shisha_can_be_paid_and_goes_to_the_receivers_bank(): void
    {
        $greens = ['prop_green_1', 'prop_green_2', 'prop_green_3'];
        $room = $this->debtCollectorRoom(
            targetBank: ['money_2_1'],
            targetProperties: ['green' => $this->group($greens, 'action_house_1')],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $before = $this->allCardIds($room);

        // SHISHA 3M + 2M bank = exactly 5M.
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['action_house_1', 'money_2_1']]);
        $state = $room->game_state;

        $this->assertEqualsCanonicalizing(['action_house_1', 'money_2_1'], $state['banks'][$p1]);
        $this->assertNull($state['properties'][$p2]['green']['house']);
        $this->assertEquals($greens, $state['properties'][$p2]['green']['cards']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_a_wil3a_must_be_paid_before_the_shisha_underneath_it(): void
    {
        $greens = ['prop_green_1', 'prop_green_2', 'prop_green_3'];
        $room = $this->debtCollectorRoom(
            targetBank: ['money_2_1'],
            targetProperties: ['green' => $this->group($greens, 'action_house_1', 'action_hotel_1')],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        // SHISHA + 2M would cover it, but the WIL3A sits on top of it.
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['action_house_1', 'money_2_1']], 'WIL3A');

        // Paying the WIL3A (4M) + 2M works and leaves the SHISHA in place.
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['action_hotel_1', 'money_2_1']]);
        $state = $room->game_state;

        $this->assertContains('action_hotel_1', $state['banks'][$p1]);
        $this->assertNull($state['properties'][$p2]['green']['hotel']);
        $this->assertEquals('action_house_1', $state['properties'][$p2]['green']['house']);
    }

    public function test_paying_a_property_out_of_a_set_leaves_its_shisha_on_that_color(): void
    {
        $greens = ['prop_green_1', 'prop_green_2', 'prop_green_3'];
        $room = $this->debtCollectorRoom(
            targetBank: ['money_1_1'],
            targetProperties: ['green' => $this->group($greens, 'action_house_1')],
        );
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        // Green property 4M + 1M bank = exactly 5M.
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['prop_green_1', 'money_1_1']]);
        $group = $room->game_state['properties'][$p2]['green'];

        $this->assertEquals(['prop_green_2', 'prop_green_3'], $group['cards']);
        $this->assertEquals('action_house_1', $group['house']);
    }

    // --- After the charge ---------------------------------------------------

    public function test_the_source_carries_on_with_their_turn_once_the_charge_resolves(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_5_1'], sourceHand: ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_5_1']]);

        $room = $this->act($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1']);

        $this->assertEquals(2, $room->game_state['cards_played_this_turn']);
        $this->assertEquals($p1, $room->game_state['current_player_id']);

        $room = $this->act($room, $p1, ['type' => 'end_turn']);

        $this->assertEquals($p2, $room->game_state['current_player_id']);
    }

    // ==================================================================
    // Phase 3, slice 2: 3ID MILADY YA KELAB (Birthday)
    // ==================================================================

    /**
     * An in-progress room where the current player (p1) has already
     * drawn and holds a 3ID MILADY YA KELAB (plus whatever else is
     * passed). Everyone else starts empty — give them cards with equip().
     */
    protected function birthdayRoom(int $playerCount = 3, array $sourceHand = [], array $sourceProperties = []): Room
    {
        $room = $this->makeInProgressRoom($playerCount);
        [$p1] = $room->game_state['turn_order'];

        $state = $room->game_state;
        $state['has_drawn_this_turn'] = true;
        $state['hands'][$p1] = array_merge(['action_birthday_1'], $sourceHand);
        $state['properties'][$p1] = $sourceProperties;
        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    /** Sets exactly what one player holds; anything omitted becomes empty. */
    protected function equip(Room $room, int $userId, array $hand = [], array $bank = [], array $properties = []): Room
    {
        $state = $room->game_state;
        $state['hands'][$userId] = $hand;
        $state['banks'][$userId] = $bank;
        $state['properties'][$userId] = $properties;
        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    protected function playBirthday(Room $room, int $sourceId): Room
    {
        return $this->act($room, $sourceId, ['type' => 'play_birthday', 'card_id' => 'action_birthday_1']);
    }

    // --- Playing 3ID MILADY YA KELAB -----------------------------------------

    public function test_birthday_opens_a_charge_for_every_opponent_and_counts_as_one_play(): void
    {
        $room = $this->birthdayRoom(4);
        [$p1, $p2, $p3, $p4] = $room->game_state['turn_order'];

        // Every opponent holds a Just Say No (so no charge auto-settles)
        // and 2M to pay with.
        foreach ([$p2, $p3, $p4] as $i => $target) {
            $room = $this->equip($room, $target, hand: ['action_just_say_no_' . ($i + 1)], bank: ['money_2_' . ($i + 1)]);
        }

        $room = $this->playBirthday($room, $p1);
        $state = $room->game_state;

        $this->assertNotContains('action_birthday_1', $state['hands'][$p1]);
        $this->assertContains('action_birthday_1', $state['discard_pile']);
        $this->assertEquals(1, $state['cards_played_this_turn']);
        $this->assertEquals('birthday', $state['pending']['kind']);
        $this->assertEquals($p1, $state['pending']['source_id']);
        $this->assertCount(3, $state['pending']['charges']);
        $this->assertArrayNotHasKey($p1, $state['pending']['charges']);

        foreach ([$p2, $p3, $p4] as $target) {
            $this->assertEquals('responding', $state['pending']['charges'][$target]['phase']);
            $this->assertEquals(2, $state['pending']['charges'][$target]['owed']);
        }
    }

    public function test_birthday_rejects_a_card_that_is_not_one(): void
    {
        $room = $this->birthdayRoom(2, sourceHand: ['money_1_1']);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, ['type' => 'play_birthday', 'card_id' => 'money_1_1'], '3ID MILADY');
    }

    public function test_birthday_needs_a_draw_first(): void
    {
        $room = $this->birthdayRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['has_drawn_this_turn' => false]);

        $this->assertRejected($room, $p1, ['type' => 'play_birthday', 'card_id' => 'action_birthday_1'], 'Draw before playing');
    }

    public function test_birthday_counts_toward_the_three_card_limit(): void
    {
        $room = $this->birthdayRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['cards_played_this_turn' => 3]);

        $this->assertRejected($room, $p1, ['type' => 'play_birthday', 'card_id' => 'action_birthday_1'], '3 cards');
    }

    // --- Independent charges ----------------------------------------------------

    public function test_each_opponent_is_settled_independently_at_the_start(): void
    {
        $room = $this->birthdayRoom();
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        // p2 has no Just Say No and can pay; p3 holds a Just Say No.
        $room = $this->equip($room, $p2, bank: ['money_2_1']);
        $room = $this->equip($room, $p3, hand: ['action_just_say_no_1'], bank: ['money_2_2']);

        $state = $this->playBirthday($room, $p1)->game_state;

        $this->assertEquals('paying', $state['pending']['charges'][$p2]['phase']);
        $this->assertEquals('responding', $state['pending']['charges'][$p3]['phase']);
    }

    public function test_everyone_pays_and_pending_clears_only_after_the_last_payment(): void
    {
        $room = $this->birthdayRoom();
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_2_1', 'money_1_1']);
        $room = $this->equip($room, $p3, bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1']]);

        $this->assertEquals('done', $room->game_state['pending']['charges'][$p2]['phase']);
        $this->assertEquals('paying', $room->game_state['pending']['charges'][$p3]['phase']);

        $room = $this->act($room, $p3, ['type' => 'pay', 'card_ids' => ['money_2_2']]);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEqualsCanonicalizing(['money_2_1', 'money_2_2'], $state['banks'][$p1]);
        $this->assertEquals(['money_1_1'], $state['banks'][$p2]);
        $this->assertEquals([], $state['banks'][$p3]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_a_player_cannot_pay_twice_or_pay_for_someone_else(): void
    {
        $room = $this->birthdayRoom();
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_2_1', 'money_2_3']);
        $room = $this->equip($room, $p3, bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1']]);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_3']], 'owe');
        $this->assertRejected($room, $p1, ['type' => 'pay', 'card_ids' => ['money_2_2']], 'owe');
    }

    public function test_a_no_from_one_player_cancels_only_that_players_payment(): void
    {
        $room = $this->birthdayRoom();
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_2_1']);
        $room = $this->equip($room, $p3, bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        $charges = $room->game_state['pending']['charges'];
        $this->assertEquals('done', $charges[$p2]['phase']);
        $this->assertEquals('cancelled', $charges[$p2]['outcome']);
        $this->assertEquals('paying', $charges[$p3]['phase']);

        $room = $this->act($room, $p3, ['type' => 'pay', 'card_ids' => ['money_2_2']]);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals(['money_2_1'], $room->game_state['banks'][$p2]);
        $this->assertEquals(['money_2_2'], $room->game_state['banks'][$p1]);
    }

    public function test_one_player_cannot_answer_another_players_no_window(): void
    {
        $room = $this->birthdayRoom();
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_2_1']);
        $room = $this->equip($room, $p3, hand: ['action_just_say_no_2'], bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);

        $this->assertRejected($room, $p3, [
            'type' => 'respond_no',
            'card_id' => 'action_just_say_no_2',
            'target_id' => $p2,
        ], 'not your turn to respond');
        $this->assertRejected($room, $p3, ['type' => 'decline', 'target_id' => $p2], 'not your turn to respond');
    }

    public function test_a_source_with_one_no_can_counter_only_one_of_two_nos(): void
    {
        $room = $this->birthdayRoom(3, sourceHand: ['action_just_say_no_3']);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_2_1']);
        $room = $this->equip($room, $p3, hand: ['action_just_say_no_2'], bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $room = $this->act($room, $p3, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2']);
        $before = $this->allCardIds($room);

        // Both charges now wait on the source, who holds a single No.
        $charges = $room->game_state['pending']['charges'];
        $this->assertEquals('responding', $charges[$p2]['phase']);
        $this->assertEquals('responding', $charges[$p3]['phase']);

        $room = $this->act($room, $p1, [
            'type' => 'respond_no',
            'card_id' => 'action_just_say_no_3',
            'target_id' => $p2,
        ]);

        // p2's payment is back on (their No was countered) ...
        $charges = $room->game_state['pending']['charges'];
        $this->assertEquals('paying', $charges[$p2]['phase']);
        // ... and with the source's No spent, p3's cancellation stands.
        $this->assertEquals('done', $charges[$p3]['phase']);
        $this->assertEquals('cancelled', $charges[$p3]['outcome']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_source_can_choose_not_to_counter_a_no(): void
    {
        $room = $this->birthdayRoom(3, sourceHand: ['action_just_say_no_3']);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_2_1']);
        $room = $this->equip($room, $p3, bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        $room = $this->act($room, $p1, ['type' => 'decline', 'target_id' => $p2]);

        $charges = $room->game_state['pending']['charges'];
        $this->assertEquals('cancelled', $charges[$p2]['outcome']);
        $this->assertEquals('paying', $charges[$p3]['phase']);
        $this->assertContains('action_just_say_no_3', $room->game_state['hands'][$p1]);
    }

    // --- Edge cases -------------------------------------------------------------

    public function test_opponents_with_nothing_to_pay_owe_nothing(): void
    {
        $room = $this->birthdayRoom();
        [$p1] = $room->game_state['turn_order'];

        $state = $this->playBirthday($room, $p1)->game_state;

        $this->assertNull($state['pending']);
        $this->assertContains('action_birthday_1', $state['discard_pile']);
    }

    public function test_a_broke_opponent_is_skipped_while_a_solvent_one_still_pays(): void
    {
        $room = $this->birthdayRoom();
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p3, bank: ['money_2_2']);

        $state = $this->playBirthday($room, $p1)->game_state;

        $this->assertEquals('done', $state['pending']['charges'][$p2]['phase']);
        $this->assertEquals('paying', $state['pending']['charges'][$p3]['phase']);
    }

    public function test_a_player_who_owns_less_than_2m_pays_everything_they_have(): void
    {
        $room = $this->birthdayRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_1_1']);
        $room = $this->playBirthday($room, $p1);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1']]);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals(['money_1_1'], $room->game_state['banks'][$p1]);
    }

    public function test_paying_two_ones_for_a_birthday_is_allowed_but_a_third_card_is_not(): void
    {
        $room = $this->birthdayRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_1_1', 'money_1_2', 'money_1_3']);
        $room = $this->playBirthday($room, $p1);

        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1', 'money_1_2', 'money_1_3']], 'more cards than needed');
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1']], 'does not cover');

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1', 'money_1_2']]);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals(['money_1_3'], $room->game_state['banks'][$p2]);
    }

    public function test_a_payment_that_gives_the_source_a_third_set_ends_the_game_immediately(): void
    {
        $room = $this->birthdayRoom(3, sourceProperties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
        ]);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['dark_blue' => $this->group(['prop_dark_blue_2'])]);
        $room = $this->equip($room, $p3, bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['prop_dark_blue_2']]);

        // p3 has not paid yet, but the game is already over.
        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
        $this->assertRejected($room, $p3, ['type' => 'pay', 'card_ids' => ['money_2_2']], 'already ended');
    }

    public function test_the_source_carries_on_after_every_birthday_charge_resolves(): void
    {
        $room = $this->birthdayRoom(3, sourceHand: ['money_1_1']);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_2_1']);
        $room = $this->equip($room, $p3, bank: ['money_2_2']);
        $room = $this->playBirthday($room, $p1);
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_2_1']]);
        $room = $this->act($room, $p3, ['type' => 'pay', 'card_ids' => ['money_2_2']]);

        $room = $this->act($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1']);
        $room = $this->act($room, $p1, ['type' => 'end_turn']);

        $this->assertEquals($p2, $room->game_state['current_player_id']);
        $this->assertEqualsCanonicalizing(['money_2_1', 'money_2_2', 'money_1_1'], $room->game_state['banks'][$p1]);
    }

    // ==================================================================
    // Phase 3, slice 3: ELBIS! (rent) and ELBIS X 2 (Double The Rent)
    // ==================================================================

    /**
     * An in-progress room where the current player (p1) has already
     * drawn and holds exactly $sourceHand, with exactly
     * $sourceProperties on the table. Everyone else starts empty — give
     * them cards with equip().
     */
    protected function rentRoom(int $playerCount, array $sourceHand, array $sourceProperties = []): Room
    {
        $room = $this->makeInProgressRoom($playerCount);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['has_drawn_this_turn' => true]);

        return $this->equip($room, $p1, hand: $sourceHand, properties: $sourceProperties);
    }

    protected function rentPayload(string $cardId, string $color, array $extra = []): array
    {
        return array_merge(['type' => 'play_rent', 'card_id' => $cardId, 'color' => $color], $extra);
    }

    /** @return array<string, array{0: string, 1: string, 2: array<int, string>, 3: int}> */
    public static function rentAmounts(): array
    {
        return [
            'green, 1 card' => ['rent_dark_blue_green_1', 'green', ['prop_green_1'], 2],
            'green, 2 cards' => ['rent_dark_blue_green_1', 'green', ['prop_green_1', 'prop_green_2'], 4],
            'green, full set' => ['rent_dark_blue_green_1', 'green', ['prop_green_1', 'prop_green_2', 'prop_green_3'], 7],
            'dark blue, 1 card' => ['rent_dark_blue_green_1', 'dark_blue', ['prop_dark_blue_1'], 3],
            'dark blue, full set' => ['rent_dark_blue_green_1', 'dark_blue', ['prop_dark_blue_1', 'prop_dark_blue_2'], 8],
            'brown, 1 card' => ['rent_light_blue_brown_1', 'brown', ['prop_brown_1'], 1],
            'brown, full set' => ['rent_light_blue_brown_1', 'brown', ['prop_brown_1', 'prop_brown_2'], 2],
            'light blue, 2 cards' => ['rent_light_blue_brown_1', 'light_blue', ['prop_light_blue_1', 'prop_light_blue_2'], 2],
            'red, 2 cards' => ['rent_red_yellow_1', 'red', ['prop_red_1', 'prop_red_2'], 3],
            'yellow, full set' => ['rent_red_yellow_1', 'yellow', ['prop_yellow_1', 'prop_yellow_2', 'prop_yellow_3'], 6],
            'pink, full set' => ['rent_pink_orange_1', 'pink', ['prop_pink_1', 'prop_pink_2', 'prop_pink_3'], 4],
            'orange, full set' => ['rent_pink_orange_1', 'orange', ['prop_orange_1', 'prop_orange_2', 'prop_orange_3'], 5],
            'railroad, 2 cards' => ['rent_railroad_utility_1', 'railroad', ['prop_railroad_1', 'prop_railroad_2'], 2],
            'railroad, full set' => ['rent_railroad_utility_1', 'railroad', ['prop_railroad_1', 'prop_railroad_2', 'prop_railroad_3', 'prop_railroad_4'], 4],
            'utility, 1 card' => ['rent_railroad_utility_1', 'utility', ['prop_utility_1'], 1],
            'a wildcard counts as a card' => ['rent_dark_blue_green_1', 'green', ['prop_green_1', 'wild_dark_blue_green_1'], 4],
            'an EL BOB wildcard counts as a card too' => ['rent_dark_blue_green_1', 'green', ['prop_green_1', 'wild_any_1'], 4],
            'cards beyond a full set still pay the full-set rate' => ['rent_dark_blue_green_1', 'green', ['prop_green_1', 'prop_green_2', 'prop_green_3', 'wild_dark_blue_green_1'], 7],
        ];
    }

    #[DataProvider('rentAmounts')]
    public function test_rent_follows_the_color_chart_for_the_number_of_cards_owned(
        string $rentCardId,
        string $color,
        array $groupCards,
        int $expectedRent,
    ): void {
        $room = $this->rentRoom(2, [$rentCardId], [$color => $this->group($groupCards)]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);

        $state = $this->act($room, $p1, $this->rentPayload($rentCardId, $color))->game_state;

        $this->assertEquals($expectedRent, $state['pending']['charges'][$p2]['owed']);
    }

    // --- Buildings ------------------------------------------------------------

    public function test_a_shisha_and_a_wil3a_add_to_a_complete_sets_rent(): void
    {
        $greens = ['prop_green_1', 'prop_green_2', 'prop_green_3'];

        $room = $this->rentRoom(2, ['rent_dark_blue_green_1'], ['green' => $this->group($greens, 'action_house_1')]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);
        $state = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green'))->game_state;

        // 7 + 3
        $this->assertEquals(10, $state['pending']['charges'][$p2]['owed']);
    }

    public function test_a_shisha_and_wil3a_together_add_seven(): void
    {
        $greens = ['prop_green_1', 'prop_green_2', 'prop_green_3'];

        $room = $this->rentRoom(2, ['rent_dark_blue_green_1'], ['green' => $this->group($greens, 'action_house_1', 'action_hotel_1')]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);
        $state = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green'))->game_state;

        // 7 + 3 + 4
        $this->assertEquals(14, $state['pending']['charges'][$p2]['owed']);
    }

    public function test_a_shisha_left_on_a_broken_set_adds_nothing(): void
    {
        // Only 2 of green's 3 cards remain (e.g. one was paid away), so
        // the set is not complete and its SHISHA does not count.
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1'], [
            'green' => $this->group(['prop_green_1', 'prop_green_2'], 'action_house_1'),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);
        $state = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green'))->game_state;

        $this->assertEquals(4, $state['pending']['charges'][$p2]['owed']);
    }

    // --- Doubling ---------------------------------------------------------------

    public function test_one_elbis_x_2_doubles_the_rent_and_costs_a_second_play(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1', 'action_double_rent_1'], [
            'green' => $this->group(['prop_green_1', 'prop_green_2', 'prop_green_3']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);

        $state = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_1'],
        ]))->game_state;

        $this->assertEquals(14, $state['pending']['charges'][$p2]['owed']);
        $this->assertEquals(2, $state['pending']['multiplier']);
        $this->assertEquals(2, $state['cards_played_this_turn']);
        $this->assertEquals([], $state['hands'][$p1]);
        $this->assertContains('rent_dark_blue_green_1', $state['discard_pile']);
        $this->assertContains('action_double_rent_1', $state['discard_pile']);
    }

    public function test_two_elbis_x_2_quadruple_the_rent_and_use_all_three_plays(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1', 'action_double_rent_1', 'action_double_rent_2'], [
            'green' => $this->group(['prop_green_1', 'prop_green_2']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);

        $state = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_1', 'action_double_rent_2'],
        ]))->game_state;

        $this->assertEquals(16, $state['pending']['charges'][$p2]['owed']);
        $this->assertEquals(4, $state['pending']['multiplier']);
        $this->assertEquals(3, $state['cards_played_this_turn']);
    }

    public function test_doubling_is_rejected_when_there_are_not_enough_plays_left(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1', 'action_double_rent_1'], [
            'green' => $this->group(['prop_green_1']),
        ]);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['cards_played_this_turn' => 2]);

        // Rent + ELBIS X 2 = 2 plays, but only 1 is left.
        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_1'],
        ]), '3 cards');

        // The rent alone still fits.
        $state = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green'))->game_state;
        $this->assertEquals(3, $state['cards_played_this_turn']);
    }

    public function test_elbis_x_2_must_be_a_distinct_elbis_x_2_from_your_hand(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1', 'action_double_rent_1', 'money_1_1'], [
            'green' => $this->group(['prop_green_1']),
        ]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_1', 'action_double_rent_1'],
        ]), 'only be used once');

        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['money_1_1'],
        ]), 'ELBIS X 2');

        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_2'],
        ]), 'not in your hand');

        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => 'action_double_rent_1',
        ]), 'Choose which');
    }

    // --- Who is charged ---------------------------------------------------------

    public function test_a_regular_rent_card_charges_every_opponent(): void
    {
        $room = $this->rentRoom(4, ['rent_red_yellow_1'], ['red' => $this->group(['prop_red_1', 'prop_red_2'])]);
        [$p1, $p2, $p3, $p4] = $room->game_state['turn_order'];

        foreach ([$p2, $p3, $p4] as $target) {
            $room = $this->equip($room, $target, bank: ['money_10_1']);
        }

        // Any target_id is ignored: a regular rent card always hits everyone.
        $state = $this->act($room, $p1, $this->rentPayload('rent_red_yellow_1', 'red', ['target_id' => $p2]))->game_state;

        $this->assertEquals('rent', $state['pending']['kind']);
        $this->assertEquals('red', $state['pending']['color']);
        $this->assertEquals(1, $state['pending']['multiplier']);
        $this->assertCount(3, $state['pending']['charges']);
        $this->assertArrayNotHasKey($p1, $state['pending']['charges']);

        foreach ([$p2, $p3, $p4] as $target) {
            $this->assertEquals(3, $state['pending']['charges'][$target]['owed']);
            $this->assertEquals('paying', $state['pending']['charges'][$target]['phase']);
        }

        $this->assertContains('rent_red_yellow_1', $state['discard_pile']);
        $this->assertEquals(1, $state['cards_played_this_turn']);
    }

    public function test_a_wild_rent_card_charges_only_the_chosen_opponent(): void
    {
        $room = $this->rentRoom(3, ['rent_any_1'], ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_5_1']);
        $room = $this->equip($room, $p3, bank: ['money_5_2']);

        $state = $this->act($room, $p1, $this->rentPayload('rent_any_1', 'brown', ['target_id' => $p3]))->game_state;

        $this->assertCount(1, $state['pending']['charges']);
        $this->assertEquals(2, $state['pending']['charges'][$p3]['owed']);
    }

    public function test_a_wild_rent_card_needs_a_valid_opponent_as_target(): void
    {
        $room = $this->rentRoom(3, ['rent_any_1'], ['brown' => $this->group(['prop_brown_1'])]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('rent_any_1', 'brown'), 'another player');
        $this->assertRejected($room, $p1, $this->rentPayload('rent_any_1', 'brown', ['target_id' => $p1]), 'yourself');
        $this->assertRejected($room, $p1, $this->rentPayload('rent_any_1', 'brown', ['target_id' => 999999]), 'another player');
    }

    public function test_a_wild_rent_card_can_charge_any_color_you_own(): void
    {
        $room = $this->rentRoom(2, ['rent_any_1'], ['dark_blue' => $this->group(['prop_dark_blue_1', 'prop_dark_blue_2'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_10_1']);

        $state = $this->act($room, $p1, $this->rentPayload('rent_any_1', 'dark_blue', ['target_id' => $p2]))->game_state;

        $this->assertEquals(8, $state['pending']['charges'][$p2]['owed']);
    }

    // --- Validation -------------------------------------------------------------

    public function test_a_regular_rent_card_only_covers_its_own_two_colors(): void
    {
        $room = $this->rentRoom(2, ['rent_red_yellow_1'], ['green' => $this->group(['prop_green_1'])]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('rent_red_yellow_1', 'green'), 'one of this card');
        $this->assertRejected($room, $p1, $this->rentPayload('rent_red_yellow_1', ''), 'one of this card');
    }

    public function test_you_cannot_charge_rent_on_a_color_you_do_not_own(): void
    {
        $room = $this->rentRoom(2, ['rent_red_yellow_1'], ['red' => $this->group(['prop_red_1'])]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('rent_red_yellow_1', 'yellow'), 'no properties of that color');
    }

    public function test_a_set_of_only_el_bob_wildcards_earns_no_rent(): void
    {
        $room = $this->rentRoom(2, ['rent_any_1'], ['green' => $this->group(['wild_any_1', 'wild_any_2'])]);
        [$p1, $p2] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('rent_any_1', 'green', ['target_id' => $p2]), 'EL BOB');
    }

    public function test_a_color_kept_alive_only_by_a_shisha_earns_no_rent(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1'], ['green' => $this->group([], 'action_house_1')]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green'), 'no properties of that color');
    }

    public function test_rent_rejects_a_card_that_is_not_a_rent_card(): void
    {
        $room = $this->rentRoom(2, ['money_1_1'], ['green' => $this->group(['prop_green_1'])]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->rentPayload('money_1_1', 'green'), 'ELBIS!');
    }

    public function test_rent_needs_a_draw_first(): void
    {
        $room = $this->rentRoom(2, ['rent_red_yellow_1'], ['red' => $this->group(['prop_red_1'])]);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['has_drawn_this_turn' => false]);

        $this->assertRejected($room, $p1, $this->rentPayload('rent_red_yellow_1', 'red'), 'Draw before playing');
    }

    public function test_rent_counts_toward_the_three_card_limit(): void
    {
        $room = $this->rentRoom(2, ['rent_red_yellow_1'], ['red' => $this->group(['prop_red_1'])]);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['cards_played_this_turn' => 3]);

        $this->assertRejected($room, $p1, $this->rentPayload('rent_red_yellow_1', 'red'), '3 cards');
    }

    public function test_a_rejected_rent_play_changes_nothing(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1', 'action_double_rent_1'], ['green' => $this->group(['prop_green_1'])]);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['cards_played_this_turn' => 2]);
        $before = $room->game_state;

        $this->assertRejected($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_1'],
        ]));

        $this->assertEquals($before, $room->fresh()->game_state);
    }

    // --- Interrupts and payment on rent ---------------------------------------------

    public function test_a_no_cancels_the_rent_and_wastes_the_doubles(): void
    {
        $room = $this->rentRoom(2, ['rent_dark_blue_green_1', 'action_double_rent_1'], [
            'green' => $this->group(['prop_green_1', 'prop_green_2', 'prop_green_3']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_10_1']);
        $room = $this->act($room, $p1, $this->rentPayload('rent_dark_blue_green_1', 'green', [
            'double_rent_card_ids' => ['action_double_rent_1'],
        ]));

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['money_10_1'], $state['banks'][$p2]);
        $this->assertEquals([], $state['banks'][$p1]);
        $this->assertContains('action_double_rent_1', $state['discard_pile']);
    }

    public function test_a_no_only_protects_the_player_who_played_it(): void
    {
        $room = $this->rentRoom(3, ['rent_red_yellow_1'], ['red' => $this->group(['prop_red_1', 'prop_red_2'])]);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_5_1']);
        $room = $this->equip($room, $p3, bank: ['money_5_2']);
        $room = $this->act($room, $p1, $this->rentPayload('rent_red_yellow_1', 'red'));
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $room = $this->act($room, $p3, ['type' => 'pay', 'card_ids' => ['money_5_2']]);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['money_5_1'], $state['banks'][$p2]);
        $this->assertEquals(['money_5_2'], $state['banks'][$p1]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_rent_can_be_paid_with_a_mix_of_money_and_property(): void
    {
        $room = $this->rentRoom(2, ['rent_red_yellow_1'], [
            'red' => $this->group(['prop_red_1', 'prop_red_2', 'prop_red_3']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_1_1'], properties: ['brown' => $this->group(['prop_brown_1'])]);
        $room = $this->act($room, $p1, $this->rentPayload('rent_red_yellow_1', 'red'));
        $before = $this->allCardIds($room);

        // Full red set = 6M. p2 owns only 2M in total, so pays everything.
        $this->assertRejected($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1']], 'everything you have');

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_1_1', 'prop_brown_1']]);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['money_1_1'], $state['banks'][$p1]);
        $this->assertEquals(['prop_brown_1'], $state['properties'][$p1]['brown']['cards']);
        $this->assertEquals([], $state['banks'][$p2]);
        $this->assertEquals([], $state['properties'][$p2]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_a_rent_payment_can_give_the_source_a_third_set_and_win(): void
    {
        $room = $this->rentRoom(2, ['rent_any_1'], [
            'red' => $this->group(['prop_red_1', 'prop_red_2', 'prop_red_3']),
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['dark_blue' => $this->group(['prop_dark_blue_2'])]);
        $room = $this->act($room, $p1, $this->rentPayload('rent_any_1', 'red', ['target_id' => $p2]));

        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['prop_dark_blue_2']]);

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_the_source_carries_on_after_rent_resolves_and_the_play_count_holds(): void
    {
        $room = $this->rentRoom(2, ['rent_red_yellow_1', 'action_double_rent_1', 'money_1_1', 'money_1_2'], [
            'red' => $this->group(['prop_red_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_4_1']);
        $room = $this->act($room, $p1, $this->rentPayload('rent_red_yellow_1', 'red', [
            'double_rent_card_ids' => ['action_double_rent_1'],
        ]));

        // 2M doubled = 4M.
        $room = $this->act($room, $p2, ['type' => 'pay', 'card_ids' => ['money_4_1']]);
        $room = $this->act($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1']);

        $this->assertEquals(3, $room->game_state['cards_played_this_turn']);
        $this->assertEquals(['money_4_1', 'money_1_1'], $room->game_state['banks'][$p1]);

        // Rent + ELBIS X 2 + money = 3 plays; a fourth is refused.
        $this->assertRejected($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_2'], '3 cards');
    }

    // ==================================================================
    // Phase 3, slice 4: KHOD AMA 2OLAK (Sly Deal)
    // ==================================================================

    /**
     * A room where the current player (p1) has already drawn and holds
     * a KHOD AMA 2OLAK plus $sourceHand, with $sourceProperties on the
     * table. Give the opponents their cards with equip().
     */
    protected function slyDealRoom(int $playerCount = 2, array $sourceProperties = [], array $sourceHand = []): Room
    {
        return $this->rentRoom($playerCount, array_merge(['action_sly_deal_1'], $sourceHand), $sourceProperties);
    }

    protected function slyDealPayload(int $targetId, string $takenCardId, array $extra = []): array
    {
        return array_merge([
            'type' => 'play_sly_deal',
            'card_id' => 'action_sly_deal_1',
            'target_id' => $targetId,
            'target_card_id' => $takenCardId,
        ], $extra);
    }

    // --- Taking a property --------------------------------------------------------

    public function test_sly_deal_takes_the_chosen_property_when_the_target_cannot_say_no(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1', 'prop_green_2'])]);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'));
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertEquals(['prop_green_2'], $state['properties'][$p2]['green']['cards']);
        $this->assertContains('action_sly_deal_1', $state['discard_pile']);
        $this->assertNotContains('action_sly_deal_1', $state['hands'][$p1]);
        $this->assertEquals(1, $state['cards_played_this_turn']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_sly_deal_adds_to_a_group_the_source_already_has(): void
    {
        $room = $this->slyDealRoom(2, ['green' => $this->group(['prop_green_3'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1', 'prop_green_2'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_2'))->game_state;

        $this->assertEquals(['prop_green_3', 'prop_green_2'], $state['properties'][$p1]['green']['cards']);
    }

    public function test_taking_a_groups_last_card_removes_the_empty_group(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['red' => $this->group(['prop_red_1'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_red_1'))->game_state;

        $this->assertArrayNotHasKey('red', $state['properties'][$p2]);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p1]['red']['cards']);
    }

    public function test_a_shisha_left_on_a_broken_set_stays_behind_when_its_last_card_is_taken(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'], 'action_house_1')]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'))->game_state;

        $this->assertEquals([], $state['properties'][$p2]['green']['cards']);
        $this->assertEquals('action_house_1', $state['properties'][$p2]['green']['house']);
    }

    public function test_sly_deal_can_pick_one_of_several_opponents(): void
    {
        $room = $this->slyDealRoom(3);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['red' => $this->group(['prop_red_1'])]);
        $room = $this->equip($room, $p3, properties: ['yellow' => $this->group(['prop_yellow_1'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p3, 'prop_yellow_1'))->game_state;

        $this->assertEquals(['prop_yellow_1'], $state['properties'][$p1]['yellow']['cards']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p2]['red']['cards']);
    }

    // --- What cannot be taken ------------------------------------------------------

    public function test_a_property_in_a_complete_set_cannot_be_taken(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p2, 'prop_brown_1'), 'complete set');
    }

    public function test_a_set_completed_with_a_wildcard_cannot_be_raided_but_a_lone_el_bob_can(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'utility' => $this->group(['prop_utility_1', 'wild_any_1']),
            'green' => $this->group(['wild_any_2']),
        ]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p2, 'wild_any_1'), 'complete set');

        // A lone EL BOB never completes anything, so that one can be taken.
        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'wild_any_2'))->game_state;
        $this->assertEquals(['wild_any_2'], $state['properties'][$p1]['green']['cards']);
    }

    public function test_only_the_targets_own_properties_can_be_taken(): void
    {
        $room = $this->slyDealRoom(3, ['red' => $this->group(['prop_red_1'])], ['money_1_1']);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['prop_green_1'], bank: ['money_2_1'], properties: [
            'green' => $this->group(['prop_green_2', 'prop_green_3'], 'action_house_1'),
        ]);
        $room = $this->equip($room, $p3, properties: ['yellow' => $this->group(['prop_yellow_1'])]);

        // Own property, another opponent's property, a hand card, a bank
        // card, a building, and a made-up id are all refused.
        foreach (['prop_red_1', 'prop_yellow_1', 'prop_green_1', 'money_2_1', 'action_house_1', 'nonsense', ''] as $cardId) {
            $this->assertRejected($room, $p1, $this->slyDealPayload($p2, $cardId), 'Choose one of');
        }
    }

    public function test_sly_deal_needs_a_valid_opponent(): void
    {
        $room = $this->slyDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p1, 'prop_red_1'), 'yourself');
        $this->assertRejected($room, $p1, $this->slyDealPayload(999999, 'prop_green_1'), 'another player');
        $this->assertRejected($room, $p1, ['type' => 'play_sly_deal', 'card_id' => 'action_sly_deal_1', 'target_card_id' => 'prop_green_1'], 'another player');
    }

    public function test_sly_deal_rejects_a_card_that_is_not_one(): void
    {
        $room = $this->slyDealRoom(2, [], ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p2, 'prop_green_1', ['card_id' => 'money_1_1']), 'KHOD AMA 2OLAK');
    }

    public function test_sly_deal_needs_a_draw_first(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->setState($room, ['has_drawn_this_turn' => false]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'), 'Draw before playing');
    }

    public function test_sly_deal_counts_toward_the_three_card_limit(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->setState($room, ['cards_played_this_turn' => 3]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'), '3 cards');
    }

    // --- Wildcards ------------------------------------------------------------------

    public function test_a_stolen_wildcard_keeps_its_current_color_by_default(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['wild_dark_blue_green_1'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'wild_dark_blue_green_1'))->game_state;

        $this->assertEquals(['wild_dark_blue_green_1'], $state['properties'][$p1]['green']['cards']);
    }

    public function test_a_stolen_wildcard_can_be_placed_under_another_of_its_colors(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['wild_dark_blue_green_1'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'wild_dark_blue_green_1', ['color' => 'dark_blue']))->game_state;

        $this->assertEquals(['wild_dark_blue_green_1'], $state['properties'][$p1]['dark_blue']['cards']);
        $this->assertArrayNotHasKey('green', $state['properties'][$p1]);
    }

    public function test_a_stolen_el_bob_can_be_placed_under_any_color(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['wild_any_1'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'wild_any_1', ['color' => 'railroad']))->game_state;

        $this->assertEquals(['wild_any_1'], $state['properties'][$p1]['railroad']['cards']);
    }

    public function test_a_stolen_wildcard_cannot_be_placed_under_a_color_it_does_not_have(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['wild_dark_blue_green_1'])]);

        $this->assertRejected($room, $p1, $this->slyDealPayload($p2, 'wild_dark_blue_green_1', ['color' => 'red']), 'valid colors');
    }

    public function test_a_plain_property_always_goes_under_its_own_color(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $state = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1', ['color' => 'red']))->game_state;

        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertArrayNotHasKey('red', $state['properties'][$p1]);
    }

    // --- Just Say No ------------------------------------------------------------------

    public function test_the_target_holding_a_no_gets_a_window_before_anything_moves(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);

        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'));
        $state = $room->game_state;

        $this->assertEquals('sly_deal', $state['pending']['kind']);
        $this->assertEquals('prop_green_1', $state['pending']['target_card_id']);
        $this->assertEquals('responding', $state['pending']['charges'][$p2]['phase']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p2]['green']['cards']);
        $this->assertArrayNotHasKey('green', $state['properties'][$p1]);
        $this->assertRejected($room, $p1, ['type' => 'end_turn'], 'Waiting');
    }

    public function test_a_no_saves_the_property(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'));
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p2]['green']['cards']);
        $this->assertContains('action_sly_deal_1', $state['discard_pile']);
        $this->assertContains('action_just_say_no_1', $state['discard_pile']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_source_can_counter_a_no_and_still_take_the_property(): void
    {
        $room = $this->slyDealRoom(2, [], ['action_just_say_no_2']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'));
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);
        $state = $room->game_state;

        // The target holds no further No, so the steal now goes through.
        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertArrayNotHasKey('green', $state['properties'][$p2]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_target_declining_the_no_window_lets_the_steal_through(): void
    {
        $room = $this->slyDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'));

        $room = $this->act($room, $p2, ['type' => 'decline']);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals(['prop_green_1'], $room->game_state['properties'][$p1]['green']['cards']);
        $this->assertContains('action_just_say_no_1', $room->game_state['hands'][$p2]);
    }

    // --- Winning and carrying on ---------------------------------------------------------

    public function test_taking_the_last_property_of_a_third_set_wins_the_game(): void
    {
        $room = $this->slyDealRoom(2, [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['dark_blue' => $this->group(['prop_dark_blue_2'])]);

        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_dark_blue_2'));

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_a_win_after_a_countered_no_also_ends_the_game(): void
    {
        $room = $this->slyDealRoom(2, [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
        ], ['action_just_say_no_2']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['dark_blue' => $this->group(['prop_dark_blue_2'])]);
        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_dark_blue_2'));
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_the_source_carries_on_after_a_sly_deal(): void
    {
        $room = $this->slyDealRoom(2, [], ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->slyDealPayload($p2, 'prop_green_1'));

        $room = $this->act($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1']);
        $room = $this->act($room, $p1, ['type' => 'end_turn']);

        $this->assertEquals($p2, $room->game_state['current_player_id']);
    }

    // ==================================================================
    // Phase 3, slice 5: MA.. TEEGY WANA AGY! (Forced Deal)
    // ==================================================================

    /**
     * A room where the current player (p1) has already drawn and holds
     * a MA.. TEEGY WANA AGY! plus $sourceHand, with $sourceProperties on
     * the table. Give the opponents their cards with equip().
     */
    protected function forcedDealRoom(int $playerCount = 2, array $sourceProperties = [], array $sourceHand = []): Room
    {
        return $this->rentRoom($playerCount, array_merge(['action_forced_deal_1'], $sourceHand), $sourceProperties);
    }

    protected function forcedDealPayload(int $targetId, string $takenCardId, string $givenCardId, array $extra = []): array
    {
        return array_merge([
            'type' => 'play_forced_deal',
            'card_id' => 'action_forced_deal_1',
            'target_id' => $targetId,
            'target_card_id' => $takenCardId,
            'give_card_id' => $givenCardId,
        ], $extra);
    }

    // --- The swap -----------------------------------------------------------------

    public function test_forced_deal_swaps_the_two_properties_when_the_target_cannot_say_no(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1', 'prop_green_2'])]);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'));
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertArrayNotHasKey('red', $state['properties'][$p1]);
        $this->assertEquals(['prop_green_2'], $state['properties'][$p2]['green']['cards']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p2]['red']['cards']);
        $this->assertContains('action_forced_deal_1', $state['discard_pile']);
        $this->assertNotContains('action_forced_deal_1', $state['hands'][$p1]);
        $this->assertEquals(1, $state['cards_played_this_turn']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_a_swap_between_two_groups_of_the_same_color_works(): void
    {
        $room = $this->forcedDealRoom(2, ['green' => $this->group(['prop_green_3'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1', 'prop_green_2'])]);

        $state = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_green_3'))->game_state;

        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertEqualsCanonicalizing(['prop_green_2', 'prop_green_3'], $state['properties'][$p2]['green']['cards']);
    }

    public function test_a_swap_can_pick_one_of_several_opponents(): void
    {
        $room = $this->forcedDealRoom(3, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['yellow' => $this->group(['prop_yellow_1'])]);
        $room = $this->equip($room, $p3, properties: ['pink' => $this->group(['prop_pink_1'])]);

        $state = $this->act($room, $p1, $this->forcedDealPayload($p3, 'prop_pink_1', 'prop_red_1'))->game_state;

        $this->assertEquals(['prop_pink_1'], $state['properties'][$p1]['pink']['cards']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p3]['red']['cards']);
        $this->assertEquals(['prop_yellow_1'], $state['properties'][$p2]['yellow']['cards']);
    }

    // --- What cannot be swapped ------------------------------------------------------

    public function test_the_card_you_want_cannot_come_from_a_complete_set(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_brown_1', 'prop_red_1'), 'take a property from a complete set');
    }

    public function test_the_card_you_give_cannot_come_from_your_own_complete_set(): void
    {
        $room = $this->forcedDealRoom(2, ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_brown_1'), 'give up a property from a complete set');
    }

    public function test_the_card_you_give_must_be_one_of_your_own_properties(): void
    {
        $room = $this->forcedDealRoom(3, ['red' => $this->group(['prop_red_1'], 'action_house_1')], ['prop_yellow_1']);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, bank: ['money_1_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->equip($room, $p3, properties: ['pink' => $this->group(['prop_pink_1'])]);

        // A hand card, the target's own card, another player's card, a
        // building, and a made-up id are all refused (the SHISHA sits on
        // p1's red set but is not one of their property cards).
        foreach (['prop_yellow_1', 'prop_green_1', 'prop_pink_1', 'action_house_1', 'nonsense', ''] as $givenCardId) {
            $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', $givenCardId), 'your own properties');
        }
    }

    public function test_forced_deal_needs_you_to_own_a_property_to_give(): void
    {
        $room = $this->forcedDealRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'), 'your own properties');
    }

    public function test_the_card_you_want_must_be_one_of_the_targets_properties(): void
    {
        $room = $this->forcedDealRoom(3, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['prop_green_1'], bank: ['money_2_1'], properties: [
            'green' => $this->group(['prop_green_2', 'prop_green_3'], 'action_house_1'),
        ]);
        $room = $this->equip($room, $p3, properties: ['yellow' => $this->group(['prop_yellow_1'])]);

        foreach (['prop_red_1', 'prop_yellow_1', 'prop_green_1', 'money_2_1', 'action_house_1', 'nonsense', ''] as $takenCardId) {
            $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, $takenCardId, 'prop_red_1'), 'Choose one of');
        }
    }

    public function test_forced_deal_needs_a_valid_opponent(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p1, 'prop_red_1', 'prop_red_1'), 'yourself');
        $this->assertRejected($room, $p1, $this->forcedDealPayload(999999, 'prop_green_1', 'prop_red_1'), 'another player');
    }

    public function test_forced_deal_rejects_a_card_that_is_not_one(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])], ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1', ['card_id' => 'money_1_1']), 'MA.. TEEGY WANA AGY!');
    }

    public function test_forced_deal_needs_a_draw_first(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->setState($room, ['has_drawn_this_turn' => false]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'), 'Draw before playing');
    }

    public function test_forced_deal_counts_toward_the_three_card_limit(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->setState($room, ['cards_played_this_turn' => 3]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'), '3 cards');
    }

    // --- Wildcards ------------------------------------------------------------------------

    public function test_a_wildcard_you_receive_keeps_its_color_by_default_or_goes_where_you_choose(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['wild_dark_blue_green_1'])]);

        $this->assertRejected($room, $p1, $this->forcedDealPayload($p2, 'wild_dark_blue_green_1', 'prop_red_1', ['color' => 'red']), 'valid colors');

        $state = $this->act($room, $p1, $this->forcedDealPayload($p2, 'wild_dark_blue_green_1', 'prop_red_1', ['color' => 'dark_blue']))->game_state;

        $this->assertEquals(['wild_dark_blue_green_1'], $state['properties'][$p1]['dark_blue']['cards']);
    }

    public function test_a_wildcard_you_give_keeps_the_color_it_was_sitting_in(): void
    {
        $room = $this->forcedDealRoom(2, ['dark_blue' => $this->group(['wild_dark_blue_green_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['red' => $this->group(['prop_red_1'])]);

        $state = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_red_1', 'wild_dark_blue_green_1'))->game_state;

        $this->assertEquals(['wild_dark_blue_green_1'], $state['properties'][$p2]['dark_blue']['cards']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p1]['red']['cards']);
    }

    // --- Just Say No ------------------------------------------------------------------------

    public function test_the_target_holding_a_no_gets_a_window_before_anything_is_swapped(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);

        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'));
        $state = $room->game_state;

        $this->assertEquals('forced_deal', $state['pending']['kind']);
        $this->assertEquals('prop_green_1', $state['pending']['target_card_id']);
        $this->assertEquals('prop_red_1', $state['pending']['give_card_id']);
        $this->assertEquals('responding', $state['pending']['charges'][$p2]['phase']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p1]['red']['cards']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p2]['green']['cards']);
        $this->assertRejected($room, $p1, ['type' => 'end_turn'], 'Waiting');
    }

    public function test_a_no_stops_the_swap(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'));
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p1]['red']['cards']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p2]['green']['cards']);
        $this->assertContains('action_forced_deal_1', $state['discard_pile']);
        $this->assertContains('action_just_say_no_1', $state['discard_pile']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_source_can_counter_a_no_and_the_swap_still_happens(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])], ['action_just_say_no_2']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'));
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertEquals(['prop_red_1'], $state['properties'][$p2]['red']['cards']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_target_declining_the_no_window_lets_the_swap_through(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'));

        $room = $this->act($room, $p2, ['type' => 'decline']);

        $this->assertNull($room->game_state['pending']);
        $this->assertEquals(['prop_green_1'], $room->game_state['properties'][$p1]['green']['cards']);
        $this->assertEquals(['prop_red_1'], $room->game_state['properties'][$p2]['red']['cards']);
    }

    // --- Winning -------------------------------------------------------------------------------

    public function test_a_swap_that_completes_the_sources_third_set_wins(): void
    {
        $room = $this->forcedDealRoom(2, [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
            'red' => $this->group(['prop_red_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['dark_blue' => $this->group(['prop_dark_blue_2'])]);

        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_dark_blue_2', 'prop_red_1'));

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_a_swap_that_completes_only_the_targets_third_set_makes_the_target_win(): void
    {
        $room = $this->forcedDealRoom(2, [
            'dark_blue' => $this->group(['prop_dark_blue_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'dark_blue' => $this->group(['prop_dark_blue_2']),
            'red' => $this->group(['prop_red_1']),
        ]);

        // p1 takes p2's red card and hands over the dark blue that completes p2's set.
        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_red_1', 'prop_dark_blue_1'));

        $this->assertEquals($p2, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_when_both_sides_finish_a_third_set_the_player_whose_turn_it_is_wins(): void
    {
        $room = $this->forcedDealRoom(2, [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'green' => $this->group(['prop_green_1', 'prop_green_2']),
            'red' => $this->group(['prop_red_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'yellow' => $this->group(['prop_yellow_1', 'prop_yellow_2', 'prop_yellow_3']),
            'pink' => $this->group(['prop_pink_1', 'prop_pink_2', 'prop_pink_3']),
            'red' => $this->group(['prop_red_2', 'prop_red_3']),
            'green' => $this->group(['prop_green_3']),
        ]);

        // p1 completes green with p2's green; p2 completes red with p1's red.
        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_3', 'prop_red_1'));

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_the_source_carries_on_after_a_forced_deal(): void
    {
        $room = $this->forcedDealRoom(2, ['red' => $this->group(['prop_red_1'])], ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1'])]);
        $room = $this->act($room, $p1, $this->forcedDealPayload($p2, 'prop_green_1', 'prop_red_1'));

        $room = $this->act($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1']);
        $room = $this->act($room, $p1, ['type' => 'end_turn']);

        $this->assertEquals($p2, $room->game_state['current_player_id']);
    }

    // ==================================================================
    // Phase 3, slice 6: HAT wa lamo2akhza EL SHORT! (Deal Breaker)
    // ==================================================================

    /**
     * A room where the current player (p1) has already drawn and holds
     * a HAT wa lamo2akhza EL SHORT! plus $sourceHand, with
     * $sourceProperties on the table. Give the opponents their cards
     * with equip().
     */
    protected function dealBreakerRoom(int $playerCount = 2, array $sourceProperties = [], array $sourceHand = []): Room
    {
        return $this->rentRoom($playerCount, array_merge(['action_deal_breaker_1'], $sourceHand), $sourceProperties);
    }

    protected function dealBreakerPayload(int $targetId, string $color, array $extra = []): array
    {
        return array_merge([
            'type' => 'play_deal_breaker',
            'card_id' => 'action_deal_breaker_1',
            'target_id' => $targetId,
            'target_color' => $color,
        ], $extra);
    }

    // --- Taking a set ----------------------------------------------------------------

    public function test_deal_breaker_takes_a_whole_complete_set_when_the_target_cannot_say_no(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'red' => $this->group(['prop_red_1']),
        ]);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'brown'));
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_brown_1', 'prop_brown_2'], $state['properties'][$p1]['brown']['cards']);
        $this->assertArrayNotHasKey('brown', $state['properties'][$p2]);
        // Their other properties are untouched.
        $this->assertEquals(['prop_red_1'], $state['properties'][$p2]['red']['cards']);
        $this->assertContains('action_deal_breaker_1', $state['discard_pile']);
        $this->assertNotContains('action_deal_breaker_1', $state['hands'][$p1]);
        $this->assertEquals(1, $state['cards_played_this_turn']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_shisha_and_wil3a_come_with_the_set(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'green' => $this->group(['prop_green_1', 'prop_green_2', 'prop_green_3'], 'action_house_1', 'action_hotel_1'),
        ]);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'green'));
        $group = $room->game_state['properties'][$p1]['green'];

        $this->assertEquals(['prop_green_1', 'prop_green_2', 'prop_green_3'], $group['cards']);
        $this->assertEquals('action_house_1', $group['house']);
        $this->assertEquals('action_hotel_1', $group['hotel']);
        $this->assertArrayNotHasKey('green', $room->game_state['properties'][$p2]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_wildcards_in_the_set_move_with_it(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['utility' => $this->group(['prop_utility_1', 'wild_any_1'])]);

        $state = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'utility'))->game_state;

        $this->assertEquals(['prop_utility_1', 'wild_any_1'], $state['properties'][$p1]['utility']['cards']);
    }

    public function test_deal_breaker_can_pick_one_of_several_opponents(): void
    {
        $room = $this->dealBreakerRoom(3);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        $room = $this->equip($room, $p3, properties: ['utility' => $this->group(['prop_utility_1', 'prop_utility_2'])]);

        $state = $this->act($room, $p1, $this->dealBreakerPayload($p3, 'utility'))->game_state;

        $this->assertArrayHasKey('utility', $state['properties'][$p1]);
        $this->assertArrayHasKey('brown', $state['properties'][$p2]);
    }

    // --- Merging with a color you already have -------------------------------------------

    public function test_a_taken_set_merges_into_a_group_of_the_same_color_you_already_have(): void
    {
        $room = $this->dealBreakerRoom(2, ['green' => $this->group(['prop_green_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'green' => $this->group(['prop_green_2', 'prop_green_3', 'wild_dark_blue_green_1'], 'action_house_1'),
        ]);

        $group = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'green'))->game_state['properties'][$p1]['green'];

        $this->assertEquals(['prop_green_1', 'prop_green_2', 'prop_green_3', 'wild_dark_blue_green_1'], $group['cards']);
        $this->assertEquals('action_house_1', $group['house']);
        $this->assertNull($group['hotel']);
    }

    public function test_a_building_that_has_no_free_slot_after_a_merge_goes_to_the_sources_bank(): void
    {
        $room = $this->dealBreakerRoom(2, [
            'green' => $this->group(['prop_green_1', 'prop_green_2', 'prop_green_3'], 'action_house_1'),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: [
            'green' => $this->group(['wild_dark_blue_green_1', 'wild_green_railroad_1', 'wild_any_1'], 'action_house_2', 'action_hotel_1'),
        ]);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'green'));
        $state = $room->game_state;
        $group = $state['properties'][$p1]['green'];

        // Their SHISHA has nowhere to sit (mine is already there), so it is
        // banked; their WIL3A fills the free slot.
        $this->assertCount(6, $group['cards']);
        $this->assertEquals('action_house_1', $group['house']);
        $this->assertEquals('action_hotel_1', $group['hotel']);
        $this->assertContains('action_house_2', $state['banks'][$p1]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    // --- What cannot be taken ----------------------------------------------------------------

    public function test_an_incomplete_set_cannot_be_taken(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['green' => $this->group(['prop_green_1', 'prop_green_2'])]);

        $this->assertRejected($room, $p1, $this->dealBreakerPayload($p2, 'green'), 'complete sets');
    }

    public function test_a_set_of_only_el_bob_wildcards_is_not_a_complete_set(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['utility' => $this->group(['wild_any_1', 'wild_any_2'])]);

        $this->assertRejected($room, $p1, $this->dealBreakerPayload($p2, 'utility'), 'complete sets');
    }

    public function test_a_color_the_target_does_not_have_or_a_bad_color_is_rejected(): void
    {
        $room = $this->dealBreakerRoom(2, ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['utility' => $this->group(['prop_utility_1', 'prop_utility_2'])]);

        // My own complete set, a color nobody has, and junk are all refused.
        foreach (['brown', 'red', 'nonsense', ''] as $color) {
            $this->assertRejected($room, $p1, $this->dealBreakerPayload($p2, $color), 'complete sets');
        }

        $this->assertRejected($room, $p1, ['type' => 'play_deal_breaker', 'card_id' => 'action_deal_breaker_1', 'target_id' => $p2], 'complete sets');
    }

    public function test_deal_breaker_needs_a_valid_opponent(): void
    {
        $room = $this->dealBreakerRoom(2, ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        [$p1] = $room->game_state['turn_order'];

        $this->assertRejected($room, $p1, $this->dealBreakerPayload($p1, 'brown'), 'yourself');
        $this->assertRejected($room, $p1, $this->dealBreakerPayload(999999, 'brown'), 'another player');
    }

    public function test_deal_breaker_rejects_a_card_that_is_not_one(): void
    {
        $room = $this->dealBreakerRoom(2, [], ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);

        $this->assertRejected($room, $p1, $this->dealBreakerPayload($p2, 'brown', ['card_id' => 'money_1_1']), 'HAT wa lamo2akhza EL SHORT!');
    }

    public function test_deal_breaker_needs_a_draw_first(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        $room = $this->setState($room, ['has_drawn_this_turn' => false]);

        $this->assertRejected($room, $p1, $this->dealBreakerPayload($p2, 'brown'), 'Draw before playing');
    }

    public function test_deal_breaker_counts_toward_the_three_card_limit(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        $room = $this->setState($room, ['cards_played_this_turn' => 3]);

        $this->assertRejected($room, $p1, $this->dealBreakerPayload($p2, 'brown'), '3 cards');
    }

    // --- Just Say No ---------------------------------------------------------------------------

    public function test_the_target_holding_a_no_gets_a_window_before_the_set_moves(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
        ]);

        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'brown'));
        $state = $room->game_state;

        $this->assertEquals('deal_breaker', $state['pending']['kind']);
        $this->assertEquals('brown', $state['pending']['color']);
        $this->assertEquals('responding', $state['pending']['charges'][$p2]['phase']);
        $this->assertArrayHasKey('brown', $state['properties'][$p2]);
        $this->assertArrayNotHasKey('brown', $state['properties'][$p1]);
        $this->assertRejected($room, $p1, ['type' => 'end_turn'], 'Waiting');
    }

    public function test_a_no_saves_the_set(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
        ]);
        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'brown'));
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_brown_1', 'prop_brown_2'], $state['properties'][$p2]['brown']['cards']);
        $this->assertContains('action_deal_breaker_1', $state['discard_pile']);
        $this->assertContains('action_just_say_no_1', $state['discard_pile']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_source_can_counter_a_no_and_still_take_the_set(): void
    {
        $room = $this->dealBreakerRoom(2, [], ['action_just_say_no_2']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
        ]);
        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'brown'));
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);
        $state = $room->game_state;

        $this->assertNull($state['pending']);
        $this->assertEquals(['prop_brown_1', 'prop_brown_2'], $state['properties'][$p1]['brown']['cards']);
        $this->assertArrayNotHasKey('brown', $state['properties'][$p2]);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_the_target_declining_the_no_window_lets_the_set_go(): void
    {
        $room = $this->dealBreakerRoom();
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
        ]);
        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'brown'));

        $room = $this->act($room, $p2, ['type' => 'decline']);

        $this->assertNull($room->game_state['pending']);
        $this->assertArrayHasKey('brown', $room->game_state['properties'][$p1]);
        $this->assertContains('action_just_say_no_1', $room->game_state['hands'][$p2]);
    }

    // --- Winning and carrying on ------------------------------------------------------------------

    public function test_taking_a_set_that_makes_a_third_wins_the_game(): void
    {
        $room = $this->dealBreakerRoom(2, [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['dark_blue' => $this->group(['prop_dark_blue_1', 'prop_dark_blue_2'])]);

        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'dark_blue'));

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_a_set_win_after_a_countered_no_also_ends_the_game(): void
    {
        $room = $this->dealBreakerRoom(2, [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
        ], ['action_just_say_no_2']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], properties: [
            'dark_blue' => $this->group(['prop_dark_blue_1', 'prop_dark_blue_2']),
        ]);
        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'dark_blue'));
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        $room = $this->act($room, $p1, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_2', 'target_id' => $p2]);

        $this->assertEquals($p1, $room->game_state['winner']);
        $this->assertNull($room->game_state['pending']);
    }

    public function test_the_source_carries_on_after_a_deal_breaker(): void
    {
        $room = $this->dealBreakerRoom(2, [], ['money_1_1']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, properties: ['brown' => $this->group(['prop_brown_1', 'prop_brown_2'])]);
        $room = $this->act($room, $p1, $this->dealBreakerPayload($p2, 'brown'));

        $room = $this->act($room, $p1, ['type' => 'play_money', 'card_id' => 'money_1_1']);
        $room = $this->act($room, $p1, ['type' => 'end_turn']);

        $this->assertEquals($p2, $room->game_state['current_player_id']);
    }

    // ==================================================================
    // move_wildcard: rearranging your own wildcards for free
    // ==================================================================

    public function test_a_wildcard_can_be_moved_to_another_of_its_colors(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: [
            'green' => $this->group(['prop_green_1', 'wild_dark_blue_green_1']),
        ]);
        $before = $this->allCardIds($room);

        $room = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'dark_blue']);
        $state = $room->game_state;

        $this->assertEquals(['prop_green_1'], $state['properties'][$p1]['green']['cards']);
        $this->assertEquals(['wild_dark_blue_green_1'], $state['properties'][$p1]['dark_blue']['cards']);
        $this->assertEquals($before, $this->allCardIds($room));
    }

    public function test_moving_a_groups_only_card_removes_the_empty_group_and_can_join_an_existing_one(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: [
            'green' => $this->group(['wild_dark_blue_green_1']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
        ]);

        $state = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'dark_blue'])->game_state;

        $this->assertArrayNotHasKey('green', $state['properties'][$p1]);
        $this->assertEquals(['prop_dark_blue_1', 'wild_dark_blue_green_1'], $state['properties'][$p1]['dark_blue']['cards']);
    }

    public function test_an_el_bob_can_be_moved_to_any_color(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: ['green' => $this->group(['wild_any_1'])]);

        $state = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_any_1', 'color' => 'railroad'])->game_state;

        $this->assertEquals(['wild_any_1'], $state['properties'][$p1]['railroad']['cards']);
    }

    public function test_moving_a_wildcard_is_free_and_needs_no_draw(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: ['green' => $this->group(['wild_dark_blue_green_1'])]);
        $room = $this->setState($room, ['has_drawn_this_turn' => false, 'cards_played_this_turn' => 3]);

        $room = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'dark_blue']);
        $room = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'green']);

        $this->assertEquals(3, $room->game_state['cards_played_this_turn']);
        $this->assertEquals(['wild_dark_blue_green_1'], $room->game_state['properties'][$p1]['green']['cards']);
    }

    public function test_a_wildcard_can_only_go_to_one_of_its_own_colors_and_not_the_one_it_is_in(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: ['green' => $this->group(['wild_dark_blue_green_1'])]);

        $this->assertRejected($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'red'], 'valid colors');
        $this->assertRejected($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => ''], 'valid colors');
        $this->assertRejected($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'green'], 'already in that color');
    }

    public function test_only_wildcards_on_your_own_table_can_be_moved(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, hand: ['wild_red_yellow_1'], bank: ['money_1_1'], properties: [
            'green' => $this->group(['prop_green_1'], 'action_house_1'),
        ]);
        $room = $this->equip($room, $p2, properties: ['pink' => $this->group(['wild_pink_orange_1'])]);

        // A plain property, a hand card, a bank card, a building, the
        // opponent's wildcard, and a made-up id are all refused.
        foreach (['prop_green_1' => 'Only wildcards', 'wild_red_yellow_1' => 'your own properties', 'money_1_1' => 'your own properties', 'action_house_1' => 'your own properties', 'wild_pink_orange_1' => 'your own properties', 'nonsense' => 'your own properties'] as $cardId => $message) {
            $this->assertRejected($room, $p1, ['type' => 'move_wildcard', 'card_id' => $cardId, 'color' => 'orange'], $message);
        }
    }

    public function test_moving_a_wildcard_is_only_allowed_on_your_own_turn_and_not_while_something_is_pending(): void
    {
        $room = $this->rentRoom(2, ['action_debt_collector_1'], ['green' => $this->group(['wild_dark_blue_green_1'])]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['action_just_say_no_1'], bank: ['money_5_1'], properties: [
            'red' => $this->group(['wild_red_yellow_1']),
        ]);
        $move = ['type' => 'move_wildcard', 'card_id' => 'wild_red_yellow_1', 'color' => 'yellow'];

        $this->assertRejected($room, $p2, $move, 'not your turn');

        $room = $this->act($room, $p1, ['type' => 'play_debt_collector', 'card_id' => 'action_debt_collector_1', 'target_id' => $p2]);

        // The game is frozen until the No window closes — even for the target.
        $this->assertRejected($room, $p2, $move, 'Waiting');
        $this->assertRejected($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'dark_blue'], 'Waiting');
    }

    public function test_moving_a_wildcard_out_of_a_set_leaves_its_shisha_on_that_color(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: [
            'green' => $this->group(['prop_green_1', 'prop_green_2', 'wild_dark_blue_green_1'], 'action_house_1'),
        ]);

        $state = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'dark_blue'])->game_state;

        $this->assertEquals(['prop_green_1', 'prop_green_2'], $state['properties'][$p1]['green']['cards']);
        $this->assertEquals('action_house_1', $state['properties'][$p1]['green']['house']);
    }

    public function test_moving_a_wildcard_can_complete_a_third_set_and_win(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, properties: [
            'brown' => $this->group(['prop_brown_1', 'prop_brown_2']),
            'utility' => $this->group(['prop_utility_1', 'prop_utility_2']),
            'dark_blue' => $this->group(['prop_dark_blue_1']),
            'green' => $this->group(['wild_dark_blue_green_1']),
        ]);

        $room = $this->act($room, $p1, ['type' => 'move_wildcard', 'card_id' => 'wild_dark_blue_green_1', 'color' => 'dark_blue']);

        $this->assertEquals($p1, $room->game_state['winner']);
    }

    // ==================================================================
    // What each player is allowed to see (viewFor)
    // ==================================================================

    protected function viewFor(Room $room, int $userId): array
    {
        return (new MasrawyDealGame())->viewFor($room, User::find($userId));
    }

    public function test_the_view_is_empty_before_the_game_starts(): void
    {
        $room = $this->makeRoom(2);
        [$p1] = $room->players()->pluck('users.id')->values()->all();

        $view = $this->viewFor($room, $p1);

        $this->assertNull($view['you']);
        $this->assertNull($view['table']);
    }

    public function test_a_player_sees_their_own_hand_but_only_the_size_of_everyone_elses(): void
    {
        $room = $this->makeInProgressRoom(3);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, hand: ['money_1_1', 'prop_red_1']);
        $room = $this->equip($room, $p2, hand: ['money_2_1', 'prop_green_1', 'rent_any_1']);
        $room = $this->equip($room, $p3, hand: ['action_just_say_no_1']);

        $view = $this->viewFor($room, $p2);

        $this->assertEquals(['money_2_1', 'prop_green_1', 'rent_any_1'], $view['you']['hand']);

        $seats = collect($view['table']['players'])->keyBy('id');
        $this->assertEquals(2, $seats[$p1]['hand_count']);
        $this->assertEquals(3, $seats[$p2]['hand_count']);
        $this->assertEquals(1, $seats[$p3]['hand_count']);

        // Nobody's hand, not even the viewer's own, is repeated on the table.
        foreach ([$p1, $p2, $p3] as $id) {
            $this->assertNull($seats[$id]['hand']);
        }

        $everything = json_encode($view);
        foreach (['money_1_1', 'prop_red_1', 'action_just_say_no_1'] as $othersCard) {
            $this->assertFalse(str_contains($everything, '"' . $othersCard . '"'), "$othersCard leaked");
        }
    }

    public function test_the_draw_pile_is_never_sent_only_its_size(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];
        $room = $this->setState($room, ['draw_pile' => ['prop_pink_3', 'money_10_1', 'rent_any_2']]);

        $view = $this->viewFor($room, $p1);

        $this->assertEquals(3, $view['table']['draw_pile_count']);
        $this->assertArrayNotHasKey('draw_pile', $view['table']);

        $everything = json_encode($view);
        foreach (['prop_pink_3', 'money_10_1', 'rent_any_2'] as $cardId) {
            $this->assertFalse(str_contains($everything, '"' . $cardId . '"'), "$cardId leaked");
        }
    }

    public function test_banks_properties_and_the_discard_pile_are_public(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, bank: ['money_5_1'], properties: ['red' => $this->group(['prop_red_1'])]);
        $room = $this->equip($room, $p2, bank: ['money_2_1']);
        $room = $this->setState($room, ['discard_pile' => ['action_pass_go_1'], 'current_player_id' => $p2, 'has_drawn_this_turn' => true, 'cards_played_this_turn' => 2]);

        $view = $this->viewFor($room, $p2);
        $seats = collect($view['table']['players'])->keyBy('id');

        $this->assertEquals(['money_5_1'], $seats[$p1]['bank']);
        $this->assertEquals(['prop_red_1'], $seats[$p1]['properties']['red']['cards']);
        $this->assertEquals(['money_2_1'], $seats[$p2]['bank']);
        $this->assertEquals(['action_pass_go_1'], $view['table']['discard_pile']);
        $this->assertEquals($p2, $view['table']['current_player_id']);
        $this->assertTrue($view['table']['has_drawn_this_turn']);
        $this->assertEquals(2, $view['table']['cards_played_this_turn']);
    }

    public function test_someone_who_is_not_in_the_game_sees_no_hand(): void
    {
        $room = $this->makeInProgressRoom(2);
        $outsider = User::factory()->create();

        $view = $this->viewFor($room, $outsider->id);

        $this->assertEquals([], $view['you']['hand']);
        $this->assertNull($view['table']['players'][0]['hand']);
    }

    public function test_every_hand_is_revealed_once_the_game_has_a_winner(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p1, hand: ['money_1_1']);
        $room = $this->equip($room, $p2, hand: ['prop_green_1', 'rent_any_1']);
        $room = $this->setState($room, ['winner' => (string) $p1]);

        $seats = collect($this->viewFor($room, $p1)['table']['players'])->keyBy('id');

        $this->assertEquals(['money_1_1'], $seats[$p1]['hand']);
        $this->assertEquals(['prop_green_1', 'rent_any_1'], $seats[$p2]['hand']);
    }

    public function test_every_hand_is_revealed_when_the_room_was_cancelled(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->equip($room, $p2, hand: ['prop_green_1']);
        $room->update(['status' => 'cancelled']);

        $seats = collect($this->viewFor($room->fresh(), $p1)['table']['players'])->keyBy('id');

        $this->assertEquals(['prop_green_1'], $seats[$p2]['hand']);
    }

    // --- What the viewer is being waited on for -------------------------------------------

    public function test_the_view_tells_a_player_when_they_are_the_one_being_waited_on(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1'], targetBank: ['money_5_1'], sourceHand: ['action_just_say_no_2']);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        // First the target may answer; the source is not being waited on.
        $this->assertEquals([$p2], $this->viewFor($room, $p2)['you']['responding_to']);
        $this->assertEquals([], $this->viewFor($room, $p1)['you']['responding_to']);

        // After the target's No, it is the source's turn to answer that charge.
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        $this->assertEquals([$p2], $this->viewFor($room, $p1)['you']['responding_to']);
        $this->assertEquals([], $this->viewFor($room, $p2)['you']['responding_to']);
    }

    public function test_the_view_tells_the_payer_what_they_owe_and_what_they_could_pay_with(): void
    {
        $room = $this->debtCollectorRoom(targetBank: ['money_2_1', 'money_3_1'], targetProperties: [
            'red' => $this->group(['prop_red_1']),
            'utility' => $this->group(['wild_any_1']),
        ]);
        [$p1, $p2] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);

        $payer = $this->viewFor($room, $p2)['you'];
        $other = $this->viewFor($room, $p1)['you'];

        $this->assertEquals(5, $payer['owes']);
        // The EL BOB is worth nothing, so it is not offered as payment.
        $this->assertEquals(['money_2_1' => 2, 'money_3_1' => 3, 'prop_red_1' => 3], $payer['payable_assets']);
        $this->assertNull($other['owes']);
        $this->assertNull($other['payable_assets']);
    }

    public function test_the_pending_action_and_its_no_chain_are_visible_to_everyone(): void
    {
        $room = $this->debtCollectorRoom(targetHand: ['action_just_say_no_1'], targetBank: ['money_5_1'], sourceHand: ['action_just_say_no_2'], playerCount: 3);
        [$p1, $p2, $p3] = $room->game_state['turn_order'];
        $room = $this->playDebtCollector($room, $p1, $p2);
        $room = $this->act($room, $p2, ['type' => 'respond_no', 'card_id' => 'action_just_say_no_1']);

        // Even the player who is not involved sees what is on the table.
        $pending = $this->viewFor($room, $p3)['table']['pending'];

        $this->assertEquals('debt_collector', $pending['kind']);
        $this->assertEquals($p1, $pending['source_id']);
        $this->assertEquals($p2, $pending['charges'][$p2]['chain'][0]['player_id']);
        $this->assertEquals('action_just_say_no_1', $pending['charges'][$p2]['chain'][0]['card_id']);
    }

    public function test_there_is_no_pending_action_in_the_view_when_nothing_is_pending(): void
    {
        $room = $this->makeInProgressRoom(2);
        [$p1] = $room->game_state['turn_order'];

        $view = $this->viewFor($room, $p1);

        $this->assertNull($view['table']['pending']);
        $this->assertEquals([], $view['you']['responding_to']);
        $this->assertNull($view['you']['owes']);
    }
}