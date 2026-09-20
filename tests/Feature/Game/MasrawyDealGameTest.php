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
        $room = $this->setState($room, [
            'draw_pile' => ['money_1_1'],
            'discard_pile' => ['money_1_2', 'money_1_3'],
        ]);

        $state = (new MasrawyDealGame())->submitAction($room, User::find($p1), ['type' => 'draw']);

        $this->assertCount(2, $state['hands'][$p1]);
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
}