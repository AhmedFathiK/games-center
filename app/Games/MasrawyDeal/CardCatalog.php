<?php

namespace App\Games\MasrawyDeal;

/**
 * The complete, static 106-card deck definition for Masrawy Deal, our
 * reskin of Monopoly Deal (same ruleset/card counts/values, our own
 * name and card text — the 4 physical "Quick Start Rules" cards are
 * omitted, they have no digital equivalent). Every card has a
 * permanent, unique string id used throughout game_state (hands,
 * banks, properties, piles) instead of ever storing card *objects* —
 * game_state must stay JSON-serializable plain arrays, the same
 * convention MafiaGame already relies on.
 *
 * Card counts and bank values are sourced from Monopoly Deal's
 * official card text and cross-checked across multiple independent
 * rules references for internal consistency (every category total
 * reconciles to the documented 20 money / 34 action / 13 rent / 28
 * property / 11 wildcard = 106 breakdown). The RENT_CHART values are
 * the ones consistently cited across every reference checked, but —
 * unlike everything else here — no single authoritative numeric table
 * was found during research, so it's worth a spot-check against a
 * physical or digital deck if one is available.
 *
 * Every card also carries a `description` (nullable — populated only
 * once Ahmed has supplied Franco-Arabic text for that card; still
 * `null` for anything pending translation). `label` is the card's
 * name; `description` is its rules text, shown to explain what the
 * card actually does.
 *
 * Property cards ALSO carry a `value` — unlike money/action/rent
 * cards, this isn't a bankable amount (property cards can never be
 * put in the bank), it's the printed face value used when a player
 * pays a rent/debt with property instead of cash. Rent and wildcard
 * cards deliberately do NOT bake a color name into `label` — e.g. a
 * rent card's label is just "ELBIS!" regardless of which color pair
 * it covers, with the actual color(s) staying in the structured
 * `color`/`colors` field. Property cards, by contrast, DO need an
 * individual `label` per card — Masrawy Deal properties are named
 * after real places (e.g. the three green cards are SA7RAWY / 6
 * OCTOBER / SHEIKH ZAYED, not "Green" ×3), so `colorLabel()` below is
 * only a *fallback* label for colors Ahmed hasn't supplied individual
 * property titles for yet, not the source of truth once they exist.
 *
 * Any `label`/`description` still in plain English below is a
 * placeholder pending Ahmed's Franco-Arabic text — `id`, `type`,
 * `color`/`colors`, and `action` are internal identifiers the rest of
 * the game logic depends on and must stay as-is regardless of
 * translation.
 */
class CardCatalog
{
    public const COLORS = [
        'brown', 'light_blue', 'pink', 'orange', 'red',
        'yellow', 'green', 'dark_blue', 'railroad', 'utility',
    ];

    /** Number of property cards (standard + wildcard) needed for a complete set, per color. */
    public const SET_SIZE = [
        'brown' => 2,
        'light_blue' => 3,
        'pink' => 3,
        'orange' => 3,
        'red' => 3,
        'yellow' => 3,
        'green' => 3,
        'dark_blue' => 2,
        'railroad' => 4,
        'utility' => 2,
    ];

    /**
     * Rent (in $M) for owning 1..N cards of that color, N = SET_SIZE[$color].
     * Index 0 = rent for owning exactly 1 card of that color.
     *
     * Independently corroborated for brown/light_blue/red/yellow/green/
     * dark_blue/railroad/utility via Ahmed's own card-studio presets
     * (exact rent numbers matched on every one of those 8 colors) —
     * pink and orange are still just the researched stock values,
     * unconfirmed either way.
     */
    public const RENT_CHART = [
        'brown' => [1, 2],
        'light_blue' => [1, 2, 3],
        'pink' => [1, 2, 4],
        'orange' => [1, 3, 5],
        'red' => [2, 3, 6],
        'yellow' => [2, 4, 6],
        'green' => [2, 4, 7],
        'dark_blue' => [3, 8],
        'railroad' => [1, 2, 3, 4],
        'utility' => [1, 2],
    ];

    /**
     * Printed face value (in $M) of every property card of that color
     * — used when a player pays a rent/debt with property instead of
     * cash, never for banking. Confirmed via the card studio for
     * brown/light_blue/red/yellow/green/dark_blue/railroad/utility;
     * pink/orange are still an unconfirmed placeholder guess (the
     * commonly-cited stock Monopoly Deal value).
     */
    public const FACE_VALUE = [
        'brown' => 1,
        'light_blue' => 1,
        'pink' => 2, // PENDING — unconfirmed guess
        'orange' => 2, // PENDING — unconfirmed guess
        'red' => 3,
        'yellow' => 3,
        'green' => 4,
        'dark_blue' => 4,
        'railroad' => 2,
        'utility' => 2,
    ];

    /**
     * SHISHA rent bonus (Masrawy Deal's reskin of the official "House"
     * card).
     */
    public const HOUSE_RENT_BONUS = 3;

    /**
     * WIL3A rent bonus (Masrawy Deal's reskin of the official "Hotel"
     * card). A WIL3A may only be placed on a complete property set that
     * already has a SHISHA on it — that ordering constraint is a
     * turn-logic invariant (Phase 2+), not per-card catalog data, so it
     * isn't encoded as a field here, just documented at the source.
     */
    public const HOTEL_RENT_BONUS = 4;

    /**
     * Generic Franco-Arabic label for the "Action Card" category itself
     * — used by the frontend as a badge/header on any type=action card
     * (e.g. alongside that card's own specific name), not the name of
     * any single card.
     */
    public const ACTION_CATEGORY_LABEL = 'CART SAYTARA';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, array<string, mixed>> card id => card definition
     */
    public static function all(): array
    {
        return self::$cache ??= array_merge(
            self::moneyCards(),
            self::propertyCards(),
            self::wildcardCards(),
            self::rentCards(),
            self::actionCards(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(string $cardId): array
    {
        $cards = self::all();

        if (! isset($cards[$cardId])) {
            throw new \InvalidArgumentException("Unknown card id: {$cardId}");
        }

        return $cards[$cardId];
    }

    /** @return array<int, string> every card id in the deck, unshuffled */
    public static function deckIds(): array
    {
        return array_keys(self::all());
    }

    // PENDING: Franco-Arabic color names not yet supplied. This is the
    // single place to update once they are — every property, wildcard,
    // and rent card label derives its color text from here.
    public static function colorLabel(string $color): string
    {
        return match ($color) {
            'brown' => 'Brown',
            'light_blue' => 'Light Blue',
            'pink' => 'Pink',
            'orange' => 'Orange',
            'red' => 'Red',
            'yellow' => 'Yellow',
            'green' => 'Green',
            'dark_blue' => 'Dark Blue',
            'railroad' => 'Railroad',
            'utility' => 'Utility',
            default => ucfirst(str_replace('_', ' ', $color)),
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function moneyCards(): array
    {
        $denominations = [1 => 6, 2 => 5, 3 => 3, 4 => 3, 5 => 2, 10 => 1];
        $cards = [];

        foreach ($denominations as $value => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $id = "money_{$value}_{$i}";
                $cards[$id] = [
                    'id' => $id,
                    'type' => 'money',
                    // PENDING: Ahmed hasn't said whether money cards
                    // should be relabeled (e.g. "X Melyon") or keep
                    // this numeric format — ask before assuming.
                    'label' => "\${$value}M",
                    'description' => null,
                    'value' => $value,
                ];
            }
        }

        return $cards;
    }

    /**
     * Individual Franco-Arabic property titles, in card order, per
     * color — sourced from Ahmed's card-studio presets. A color absent
     * here (pink, orange — still pending) falls back to colorLabel()
     * in propertyCards() below.
     *
     * @return array<string, array<int, string>>
     */
    private static function propertyTitles(): array
    {
        return [
            'green' => ['SA7RAWY', '6 OCTOBER', 'SHEIKH ZAYED'],
            'red' => ['GARDEN CITY', 'MASR EL GEDIDA', 'ZAMALEK'],
            'light_blue' => ['MOHAMED MAHMOUD', 'MIDDAN EL TAHRIR', 'TAL3AT 7ARB'],
            'brown' => ['100 302BA', 'ARD EL LEWA'],
            'railroad' => ['Taftaf Ghamra', 'Taftaf El Bo7ous', 'Taftaf El 3ataba', 'Taftaf Ramsis'],
            'yellow' => ['DOKKI', 'MOHANDESIN', 'HARAM'],
            'dark_blue' => ['CAIRO FESTIVAL CITY', '2ATAMEYA HIGHTS'],
            'utility' => ['MAYA El dayman ma2too3a', 'KAHRABA El dayman ma2too3a'],
            // pink, orange: PENDING — not yet supplied.
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function propertyCards(): array
    {
        $titles = self::propertyTitles();
        $cards = [];

        foreach (self::SET_SIZE as $color => $count) {
            $colorTitles = $titles[$color] ?? [];

            for ($i = 1; $i <= $count; $i++) {
                $id = "prop_{$color}_{$i}";
                $cards[$id] = [
                    'id' => $id,
                    'type' => 'property',
                    // PENDING (pink, orange): falls back to the
                    // (still-English) generic colorLabel() until
                    // Ahmed supplies individual titles for that color.
                    'label' => $colorTitles[$i - 1] ?? self::colorLabel($color),
                    'description' => null,
                    'color' => $color,
                    'value' => self::FACE_VALUE[$color],
                ];
            }
        }

        return $cards;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function wildcardCards(): array
    {
        // [colorA, colorB, count]
        $pairs = [
            ['dark_blue', 'green', 1],
            ['green', 'railroad', 1],
            ['utility', 'railroad', 1],
            ['light_blue', 'railroad', 1],
            ['light_blue', 'brown', 1],
            ['pink', 'orange', 2],
            ['red', 'yellow', 2],
        ];

        $cards = [];

        foreach ($pairs as [$colorA, $colorB, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $id = "wild_{$colorA}_{$colorB}_{$i}";
                $cards[$id] = [
                    'id' => $id,
                    'type' => 'wildcard',
                    // PENDING: no Franco-Arabic name given yet for the
                    // "property wildcard" concept itself — currently
                    // just the two color names, same placeholder
                    // pattern as property cards above.
                    'label' => self::colorLabel($colorA) . ' / ' . self::colorLabel($colorB),
                    'description' => null,
                    'colors' => [$colorA, $colorB],
                    'any_color' => false,
                ];
            }
        }

        // The 2 "10-color" multicolor wildcards: no monetary value, can
        // represent any color, but per Hasbro cannot single-handedly
        // complete a set (at least one standard property of that color
        // must also be present) and cannot be charged rent on their own.
        for ($i = 1; $i <= 2; $i++) {
            $id = "wild_any_{$i}";
            $cards[$id] = [
                'id' => $id,
                'type' => 'wildcard',
                'label' => 'EL BOB',
                'description' => '7ot el bob 3ala kol lon ya batista',
                'colors' => self::COLORS,
                'any_color' => true,
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function rentCards(): array
    {
        $pairs = [
            ['dark_blue', 'green'],
            ['red', 'yellow'],
            ['pink', 'orange'],
            ['light_blue', 'brown'],
            ['railroad', 'utility'],
        ];

        $cards = [];

        foreach ($pairs as [$colorA, $colorB]) {
            for ($i = 1; $i <= 2; $i++) {
                $id = "rent_{$colorA}_{$colorB}_{$i}";
                $cards[$id] = [
                    'id' => $id,
                    'type' => 'rent',
                    'label' => 'ELBIS!',
                    'description' => 'LABES EL KOL EL EIGAR',
                    'colors' => [$colorA, $colorB],
                    'any_color' => false,
                    'value' => 1,
                    // Two-color rent cards charge EVERY other player.
                    'charges_all' => true,
                ];
            }
        }

        for ($i = 1; $i <= 3; $i++) {
            $id = "rent_any_{$i}";
            $cards[$id] = [
                'id' => $id,
                'type' => 'rent',
                // Same base action as regular rent ("ELBIS!"), but wild
                // rent only targets one player instead of everyone, and
                // Ahmed priced its bank value higher (3M vs 1M) —
                // deliberate, not the stock Monopoly Deal value.
                'label' => 'ELBIS!',
                'description' => 'LABES WA7ID LEWA7DO EL EIGAR',
                'colors' => self::COLORS,
                'any_color' => true,
                'value' => 3,
                // Wild rent only charges a single chosen player.
                'charges_all' => false,
            ];
        }

        return $cards;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function actionCards(): array
    {
        // PENDING marks the ones still waiting on Franco-Arabic text —
        // deal_breaker and debt_collector still need everything;
        // double_rent, house, and hotel now have their label (Ahmed
        // renamed house/hotel to SHISHA/WIL3A) but still need a
        // description. Every 'value' below that hasn't been explicitly
        // confirmed by Ahmed is still just my original researched
        // assumption (matching stock Monopoly Deal bank values).
        //
        // NOTE: the 'house'/'hotel' action keys themselves stay as
        // internal English identifiers on purpose (same convention as
        // every other card's id/type/color/action) — only the 'label'
        // shown to players changes to SHISHA/WIL3A.
        $definitions = [
            'deal_breaker' => ['count' => 2, 'value' => 5, 'label' => 'Deal Breaker', 'description' => null], // PENDING
            'just_say_no' => ['count' => 3, 'value' => 4, 'label' => 'DA 3AND OMMO...', 'description' => 'ORFOD AY CART SAYTARA'],
            'pass_go' => ['count' => 10, 'value' => 1, 'label' => 'GARAB 7AZAK', 'description' => 'ES7AB KARTEIN'],
            'forced_deal' => ['count' => 3, 'value' => 3, 'label' => 'MA.. TEEGY WANA AGY!', 'description' => 'SALIM WESTILIM MANTI2A'],
            'sly_deal' => ['count' => 3, 'value' => 3, 'label' => 'KHOD AMA 2OLAK', 'description' => 'KHOD MANTI2A MIN AY BRINCE (MATKONSH MIN MAGMO3A KAMLA)'],
            'debt_collector' => ['count' => 3, 'value' => 3, 'label' => 'Debt Collector', 'description' => null], // PENDING
            'birthday' => ['count' => 3, 'value' => 2, 'label' => 'EID MILADY YA KELAB', 'description' => 'KHOD 2 MILLION MALTOOSH MIN KOL BRINCE'],
            'double_rent' => ['count' => 2, 'value' => 1, 'label' => 'Double The Rent', 'description' => null], // PENDING
            // SHISHA = Masrawy Deal's reskin of the official "House"
            // card. Rent bonus unchanged (see HOUSE_RENT_BONUS).
            'house' => ['count' => 3, 'value' => 3, 'label' => 'SHISHA', 'description' => null], // description PENDING
            // WIL3A = Masrawy Deal's reskin of the official "Hotel"
            // card. Can only be placed on a set that already has a
            // SHISHA on it — see HOTEL_RENT_BONUS's doc comment.
            'hotel' => ['count' => 2, 'value' => 4, 'label' => 'WIL3A', 'description' => null], // description PENDING
        ];

        $cards = [];

        foreach ($definitions as $action => $def) {
            for ($i = 1; $i <= $def['count']; $i++) {
                $id = "action_{$action}_{$i}";
                $cards[$id] = [
                    'id' => $id,
                    'type' => 'action',
                    'action' => $action,
                    'label' => $def['label'],
                    'description' => $def['description'],
                    'value' => $def['value'],
                ];
            }
        }

        return $cards;
    }
}