<script setup lang="ts">
/**
 * Renders one Masrawy Deal card face, styled to match Ahmed's card-studio
 * export (monopoly_deal_card_studio-11.html): black-bordered rounded
 * card, per-color banners, circular "M" value badges, a split-color ring
 * for rent cards, twin banners for wildcards. Every color/value/title
 * shown here comes from the `entry` prop (CardCatalog::get(), sent by
 * MasrawyDealGame::viewFor() as room.table.catalog) — nothing about a
 * specific card is hardcoded here, only the generic per-type layout and
 * the color-name → hex mapping (CardCatalog itself only knows semantic
 * color names, not hex values, so that mapping lives here).
 *
 * Two sizes: 'sm' for hand/board density (used everywhere cards are
 * listed side by side), 'lg' for a single selected/detail card (adds the
 * rent ladder and rules text, which don't fit legibly at 'sm').
 *
 * Scoped out for now, left for Ahmed to add if he wants them (his own
 * card-studio designs are otherwise kept exactly as he built them, not
 * mutated here): the art-deco border patterns, the EL BOB character
 * illustration, and the money card's decorative dashed-border frame.
 * The action-card icons (arrow/cake/flame/hookah) ARE included — small,
 * self-contained SVGs ported directly from the studio.
 */
import type { CardCatalogEntry } from '@/types/room';
import { computed, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        entry: CardCatalogEntry;
        size?: 'sm' | 'lg';
        activeColor?: string;
        rentChart?: number[];
        setSize?: number;
        wildRentCharts?: number[][];
        wildSetSizes?: number[];
    }>(),
    { size: 'sm', activeColor: undefined, rentChart: undefined, setSize: undefined, wildRentCharts: undefined, wildSetSizes: undefined },
);

const COLOR_HEX: Record<string, string> = {
    brown: '#542810',
    light_blue: '#38bdf8',
    pink: '#db2777',
    // Not present in the studio's own presets (no orange preset was
    // exported) — a plausible Monopoly-esque orange, easy to swap for
    // an exact value if Ahmed supplies one.
    orange: '#ea580c',
    red: '#dc2626',
    yellow: '#fef08a',
    green: '#15803d',
    dark_blue: '#1d4ed8',
    railroad: '#111111',
    utility: '#a1c1a6',
};

const EL_BOB_BANNER_COLORS = [
    COLOR_HEX.brown,
    '#7dd3fc',
    COLOR_HEX.pink,
    COLOR_HEX.red,
    COLOR_HEX.yellow,
    '#16a34a',
    COLOR_HEX.dark_blue,
    COLOR_HEX.railroad,
    COLOR_HEX.utility,
];

const MONEY_BG: Record<number, string> = {
    1: '#dbe4ce',
    2: '#d8c8bd',
    3: '#c9d7d2',
    4: '#b8cde3',
    5: '#a899c6',
    10: '#dca743',
};

const ACTION_STYLE: Record<string, { bg: string; symbol: 'none' | 'arrow' | 'cake' | 'flame' | 'hookah' }> = {
    pass_go: { bg: '#fef0b8', symbol: 'arrow' },
    double_rent: { bg: '#fef0b8', symbol: 'none' },
    house: { bg: '#c8e6c9', symbol: 'hookah' },
    hotel: { bg: '#b8cde3', symbol: 'flame' },
    deal_breaker: { bg: '#a899c6', symbol: 'none' },
    just_say_no: { bg: '#b8cde3', symbol: 'none' },
    sly_deal: { bg: '#dbe4ce', symbol: 'none' },
    forced_deal: { bg: '#dbe4ce', symbol: 'none' },
    debt_collector: { bg: '#c8e6c9', symbol: 'none' },
    birthday: { bg: '#d8c8bd', symbol: 'cake' },
};

const RENT_BG = '#e5edd6';

function colorHex(color: string): string {
    return COLOR_HEX[color] ?? '#94a3b8';
}

function colorLabel(color: string): string {
    return color
        .split('_')
        .map((w) => w[0].toUpperCase() + w.slice(1))
        .join(' ');
}

const isLg = computed(() => props.size === 'lg');
const motifId = `mc-art-deco-${useId()}`;
const moneyPatternId = `mc-money-pattern-${useId()}`;
const hookahSilverGradientId = `mc-hookah-silver-${useId()}`;
const hookahGreenGradientId = `mc-hookah-green-${useId()}`;
// The studio's own native card is 340x520 (ratio ≈ 0.6538). 'sm' keeps
// many cards legible side by side; 'lg' is roughly double for a single
// selected-card detail view.
const dims = computed(() => (isLg.value ? { w: 220, h: 337 } : { w: 108, h: 165 }));

function formatValue(value: number): string {
    return `${value}M`;
}

const isRailroadOrUtility = (color?: string) => color === 'railroad' || color === 'utility';

function isUtilityRailroadWildcard(entry: CardCatalogEntry): boolean {
    return entry.type === 'wildcard' && !entry.any_color && entry.colors?.includes('utility') === true && entry.colors.includes('railroad');
}

function hasReferenceWildcardDesign(entry: CardCatalogEntry): boolean {
    return entry.type === 'wildcard' && !entry.any_color && entry.colors?.length === 2;
}

const referenceWildcardColors = computed(() => {
    const colors = props.entry.colors ?? [];

    if (colors.includes('green') && colors.includes('dark_blue')) {
        return ['green', 'dark_blue'];
    }

    if (colors.includes('brown') && colors.includes('light_blue')) {
        return ['brown', 'light_blue'];
    }

    return colors;
});

const isTwoColorCardFlipped = computed(() => {
    if (props.activeColor === undefined) return false;
    if (props.entry.type === 'rent' && !props.entry.any_color) return props.entry.colors?.[1] === props.activeColor;
    return props.entry.type === 'wildcard' && !props.entry.any_color && referenceWildcardColors.value[1] === props.activeColor;
});

const referenceWildcardRentCharts = computed(() =>
    referenceWildcardColors.value.map((color) => {
        const index = props.entry.colors?.indexOf(color) ?? -1;
        return index >= 0 ? props.wildRentCharts?.[index] : undefined;
    }),
);

const referenceWildcardSetSizes = computed(() =>
    referenceWildcardColors.value.map((color) => {
        const index = props.entry.colors?.indexOf(color) ?? -1;
        return index >= 0 ? props.wildSetSizes?.[index] : undefined;
    }),
);
</script>

<template>
    <div
        class="mc-card"
        :class="{ 'mc-card--flipped': isTwoColorCardFlipped, 'mc-card--detail': isLg }"
        :style="{ width: dims.w + 'px', height: dims.h + 'px', fontSize: (isLg ? 1 : 0.62) + 'rem' }"
    >
        <!-- Money -->
        <div v-if="entry.type === 'money'" class="mc-face mc-money-reference" :style="{ '--mc-money-bg': MONEY_BG[entry.value] ?? '#e2e8f0' }">
            <div class="mc-money-badge mc-money-badge--top">
                <span class="mc-money-badge-inner"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></span>
            </div>
            <div class="mc-money-badge mc-money-badge--bottom">
                <span class="mc-money-badge-inner"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></span>
            </div>

            <div class="mc-money-frame">
                <svg class="mc-money-pattern" viewBox="0 0 310 490" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="moneyPatternId" width="12" height="12" patternUnits="userSpaceOnUse">
                            <rect width="12" height="12" :fill="`var(--mc-money-bg)`" />
                            <path d="M 0 6 L 6 0 L 12 6 L 6 12 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <circle cx="6" cy="6" r="2" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="3" y="3" width="304" height="484" fill="none" stroke="#111111" stroke-width="1.5" />
                    <rect x="8" y="8" width="294" height="14" :fill="`url(#${moneyPatternId})`" stroke="#111111" stroke-width="1" />
                    <rect x="8" y="468" width="294" height="14" :fill="`url(#${moneyPatternId})`" stroke="#111111" stroke-width="1" />
                    <rect x="8" y="8" width="14" height="474" :fill="`url(#${moneyPatternId})`" stroke="#111111" stroke-width="1" />
                    <rect x="288" y="8" width="14" height="474" :fill="`url(#${moneyPatternId})`" stroke="#111111" stroke-width="1" />
                    <rect x="25" y="25" width="260" height="440" fill="none" stroke="#111111" stroke-width="1.2" />
                </svg>

                <div class="mc-money-emblem">
                    <div class="mc-money-emblem-inner">
                        <span class="mc-money-center-value" :class="{ 'mc-money-center-value--double': entry.value === 10 }"
                            ><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></span
                        >
                        <span class="mc-money-center-caption">MALTOOSH</span>
                    </div>
                </div>
            </div>

            <div class="mc-money-copyright">© 1935, 2008 HASBRO.</div>
        </div>

        <!-- Property -->
        <div
            v-else-if="entry.type === 'property' && isRailroadOrUtility(entry.color)"
            class="mc-face mc-property-reference mc-service-property-reference"
        >
            <div class="mc-studio-badge mc-service-property-reference-badge"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-property-reference-banner mc-service-property-reference-banner" :style="{ background: colorHex(entry.color) }">
                <span>KHADAMAT ENSHERA7</span>
                <h3>{{ entry.label }}</h3>
            </div>
            <div class="mc-property-reference-body">
                <div class="mc-property-reference-elbis">ELBIS</div>
                <ul v-if="rentChart" class="mc-property-reference-ladder">
                    <li v-for="(rent, i) in rentChart" :key="i" class="mc-property-reference-row">
                        <div class="mc-property-reference-cards">
                            <div
                                v-for="cardIndex in i + 1"
                                :key="cardIndex"
                                class="mc-property-reference-mini-card"
                                :style="{
                                    left: `${(i + 1 - cardIndex) * 1.18}cqi`,
                                    top: `${(i + 1 - cardIndex) * 0.59}cqi`,
                                    zIndex: cardIndex,
                                    '--property-color': colorHex(entry.color),
                                }"
                            >
                                <span />
                                <strong>{{ cardIndex === i + 1 ? cardIndex : '' }}</strong>
                            </div>
                        </div>
                        <div class="mc-property-reference-leader">
                            <span v-if="i === (setSize ?? rentChart.length) - 1" class="mc-property-reference-full-set">KHADAMAT ENSHERA7 KAMLA</span>
                        </div>
                        <div class="mc-property-reference-rent"><span class="mc-studio-mark">M</span>{{ rent }}<sub>M</sub></div>
                    </li>
                </ul>
            </div>
        </div>

        <div v-else-if="entry.type === 'property' && !isRailroadOrUtility(entry.color)" class="mc-face mc-property-reference">
            <div class="mc-studio-badge mc-property-reference-badge"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-property-reference-banner" :style="{ background: colorHex(entry.color!) }">
                <h3>{{ entry.label }}</h3>
            </div>
            <div class="mc-property-reference-body">
                <div class="mc-property-reference-elbis">ELBIS</div>
                <ul v-if="rentChart" class="mc-property-reference-ladder">
                    <li v-for="(rent, i) in rentChart" :key="i" class="mc-property-reference-row">
                        <div class="mc-property-reference-cards">
                            <div
                                v-for="cardIndex in i + 1"
                                :key="cardIndex"
                                class="mc-property-reference-mini-card"
                                :style="{
                                    left: `${(i + 1 - cardIndex) * 1.18}cqi`,
                                    top: `${(i + 1 - cardIndex) * 0.59}cqi`,
                                    zIndex: cardIndex,
                                    '--property-color': colorHex(entry.color!),
                                }"
                            >
                                <span />
                                <strong>{{ cardIndex === i + 1 ? cardIndex : '' }}</strong>
                            </div>
                        </div>
                        <div class="mc-property-reference-leader">
                            <span v-if="i === (setSize ?? rentChart.length) - 1" class="mc-property-reference-full-set">MANTI2A KAMLA</span>
                        </div>
                        <div class="mc-property-reference-rent"><span class="mc-studio-mark">M</span>{{ rent }}<sub>M</sub></div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Service properties keep their existing specialized banner. -->
        <div v-else-if="entry.type === 'property'" class="mc-face mc-property">
            <div class="mc-badge mc-badge--corner">{{ formatValue(entry.value) }}</div>
            <div class="mc-property-banner" :style="{ background: colorHex(entry.color!) }">
                <span v-if="isRailroadOrUtility(entry.color)" class="mc-property-kicker">KHADAMAT ENSHERA7</span>
                <h3 class="mc-property-title">{{ entry.label }}</h3>
            </div>
            <div class="mc-property-body">
                <span class="mc-elbis-label">ELBIS</span>
                <ul v-if="isLg && rentChart" class="mc-rent-ladder">
                    <li v-for="(rent, i) in rentChart" :key="i" class="mc-rent-row">
                        <span class="mc-rent-count">{{ i + 1 }}×</span>
                        <span class="mc-rent-dots"></span>
                        <span class="mc-rent-amount">{{ formatValue(rent) }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Two-color wild card -->
        <div
            v-else-if="hasReferenceWildcardDesign(entry)"
            class="mc-face mc-double-wild-reference"
            :class="{ 'mc-double-wild-reference--service': referenceWildcardColors.some((color) => isRailroadOrUtility(color)) }"
        >
            <div class="mc-studio-badge mc-double-wild-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-double-wild-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-double-wild-banner" :style="{ background: colorHex(referenceWildcardColors[0]) }">
                <div class="mc-double-wild-banner-copy">
                    <b v-if="activeColor === referenceWildcardColors[0]" class="mc-double-wild-active">ACTIVE COLOR</b>
                    <span>{{ isRailroadOrUtility(referenceWildcardColors[0]) ? 'KHADAMAT ENSHERA7' : 'MANTI2A' }}</span>
                    <strong>CART KARBAGA</strong>
                    <em>(Use card either way up.)</em>
                </div>
                <div v-if="isUtilityRailroadWildcard(entry)" class="mc-double-wild-service-icons" aria-hidden="true">
                    <svg
                        width="20"
                        height="22"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#111111"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M9 18h6m-4 3h2M12 2a7 7 0 0 0-7 7c0 3 2 5 3 7h8c1-2 3-4 3-7a7 7 0 0 0-7-7z" fill="#facc15" />
                    </svg>
                    <svg
                        width="22"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="#111111"
                        stroke-width="2.2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <path d="M4 10h12a2 2 0 0 0 2-2V6h-4M18 12v6a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-6m6-6v6" />
                    </svg>
                </div>
            </div>

            <div class="mc-double-wild-body">
                <section class="mc-double-wild-panel">
                    <h3>ELBIS</h3>
                    <ul v-if="referenceWildcardRentCharts[0]" class="mc-double-wild-ladder">
                        <li v-for="(rent, i) in referenceWildcardRentCharts[0]" :key="i" class="mc-double-wild-row">
                            <div class="mc-double-wild-cards">
                                <div
                                    v-for="cardIndex in i + 1"
                                    :key="cardIndex"
                                    class="mc-double-wild-mini-card"
                                    :style="{
                                        left: `${(i + 1 - cardIndex) * 1.18}cqi`,
                                        top: `${(i + 1 - cardIndex) * 0.59}cqi`,
                                        zIndex: cardIndex,
                                        '--property-color': colorHex(referenceWildcardColors[0]),
                                    }"
                                >
                                    <span />
                                    <strong>{{ cardIndex === i + 1 ? cardIndex : '' }}</strong>
                                </div>
                            </div>
                            <div class="mc-double-wild-leader">
                                <span v-if="i === (referenceWildcardSetSizes[0] ?? referenceWildcardRentCharts[0].length) - 1">{{
                                    isRailroadOrUtility(referenceWildcardColors[0]) ? 'KHADAMAT ENSHERA7 KAMLA' : 'MANTI2A KAMLA'
                                }}</span>
                            </div>
                            <div class="mc-double-wild-rent"><span class="mc-studio-mark">M</span>{{ rent }}<sub>M</sub></div>
                        </li>
                    </ul>
                </section>

                <section class="mc-double-wild-panel mc-double-wild-panel--bottom">
                    <h3>ELBIS</h3>
                    <ul v-if="referenceWildcardRentCharts[1]" class="mc-double-wild-ladder">
                        <li v-for="(rent, i) in referenceWildcardRentCharts[1]" :key="i" class="mc-double-wild-row mc-double-wild-row--bottom">
                            <div class="mc-double-wild-rent"><span class="mc-studio-mark">M</span>{{ rent }}<sub>M</sub></div>
                            <div class="mc-double-wild-leader">
                                <span v-if="i === (referenceWildcardSetSizes[1] ?? referenceWildcardRentCharts[1].length) - 1">{{
                                    isRailroadOrUtility(referenceWildcardColors[1]) ? 'KHADAMAT ENSHERA7 KAMLA' : 'MANTI2A KAMLA'
                                }}</span>
                            </div>
                            <div class="mc-double-wild-cards">
                                <div
                                    v-for="cardIndex in i + 1"
                                    :key="cardIndex"
                                    class="mc-double-wild-mini-card"
                                    :style="{
                                        left: `${(i + 1 - cardIndex) * 1.18}cqi`,
                                        top: `${(i + 1 - cardIndex) * 0.59}cqi`,
                                        zIndex: cardIndex,
                                        '--property-color': colorHex(referenceWildcardColors[1]),
                                    }"
                                >
                                    <span />
                                    <strong>{{ cardIndex === i + 1 ? cardIndex : '' }}</strong>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>

            <div class="mc-double-wild-banner mc-double-wild-banner--bottom" :style="{ background: colorHex(referenceWildcardColors[1]) }">
                <div class="mc-double-wild-banner-copy">
                    <b v-if="activeColor === referenceWildcardColors[1]" class="mc-double-wild-active">ACTIVE COLOR</b>
                    <span>{{ isRailroadOrUtility(referenceWildcardColors[1]) ? 'KHADAMAT ENSHERA7' : 'MANTI2A' }}</span>
                    <strong>CART KARBAGA</strong>
                    <em>(Use card either way up.)</em>
                </div>
                <div v-if="isUtilityRailroadWildcard(entry)" class="mc-double-wild-service-icons" aria-hidden="true">
                    <svg width="26" height="22" viewBox="0 0 24 24" fill="#ffffff">
                        <path
                            d="M4 15.5V14a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v1.5M6 18a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm15 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zM3 8l2-4h14l2 4v5H3V8z"
                        />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Wild cards with service colors retain their existing design. -->
        <div v-else-if="entry.type === 'wildcard' && !entry.any_color" class="mc-face mc-wild">
            <div class="mc-badge mc-badge--corner">{{ formatValue(entry.value) }}</div>
            <div class="mc-wild-banner" :style="{ background: colorHex(entry.colors![0]) }">
                <span class="mc-wild-kicker">{{ isRailroadOrUtility(entry.colors![0]) ? 'KHADAMAT' : colorLabel(entry.colors![0]) }}</span>
                <span class="mc-wild-name">CART KARBAGA</span>
            </div>
            <div class="mc-wild-mid">{{ colorLabel(entry.colors![0]) }} / {{ colorLabel(entry.colors![1]) }}</div>
            <div class="mc-wild-banner mc-wild-banner--bottom" :style="{ background: colorHex(entry.colors![1]) }">
                <span class="mc-wild-kicker">{{ isRailroadOrUtility(entry.colors![1]) ? 'KHADAMAT' : colorLabel(entry.colors![1]) }}</span>
                <span class="mc-wild-name">CART KARBAGA</span>
            </div>
        </div>

        <!-- EL BOB (any-color wildcard) -->
        <div v-else-if="entry.type === 'wildcard' && entry.any_color" class="mc-face mc-elbob">
            <div class="mc-elbob-frame">
                <div class="mc-elbob-header">
                    <div class="mc-elbob-stripe" aria-hidden="true">
                        <span v-for="color in EL_BOB_BANNER_COLORS" :key="color" :style="{ background: color }" />
                    </div>
                    <h3 class="mc-elbob-title">EL BOB</h3>
                    <div class="mc-elbob-stripe" aria-hidden="true">
                        <span v-for="color in EL_BOB_BANNER_COLORS" :key="color" :style="{ background: color }" />
                    </div>
                </div>

                <div class="mc-elbob-art" aria-hidden="true">
                    <svg viewBox="0 0 180 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <ellipse cx="90" cy="208" rx="55" ry="5" fill="#111111" opacity="0.12" />
                        <path
                            d="M112 196 C114 190 134 190 144 196 C148 200 140 206 122 206 C114 206 110 200 112 196Z"
                            fill="#422517"
                            stroke="#111111"
                            stroke-width="2.2"
                            stroke-linejoin="round"
                        />
                        <path
                            d="M40 196 C38 190 58 190 70 196 C72 200 68 206 50 206 C42 206 38 200 40 196Z"
                            fill="#422517"
                            stroke="#111111"
                            stroke-width="2.2"
                            stroke-linejoin="round"
                        />
                        <path
                            d="M68 96 C58 115 50 150 44 196 C72 201 112 201 132 196 C126 150 118 115 110 96Z"
                            fill="#5c3826"
                            stroke="#111111"
                            stroke-width="2.5"
                            stroke-linejoin="round"
                        />
                        <path d="M88 96L86 130M82 96L88 104L94 96" stroke="#111111" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                        <path
                            d="M62 138C72 148 82 145 88 138M118 142C108 152 98 148 92 140"
                            stroke="#3d2316"
                            stroke-width="2"
                            stroke-linecap="round"
                        />
                        <path
                            d="M110 96C124 104 134 118 128 132C122 138 114 136 112 126C112 116 108 106 110 96ZM68 96C52 104 42 118 48 130C54 134 60 130 62 120C62 112 66 102 68 96Z"
                            fill="#5c3826"
                            stroke="#111111"
                            stroke-width="2.5"
                            stroke-linejoin="round"
                        />
                        <path
                            d="M46 122L24 116C20 114 18 120 22 124L44 130ZM24 116C15 113 12 117 14 122C18 126 26 126 30 122ZM28 116C29 110 34 108 36 112C36 115 32 118 28 118Z"
                            fill="#fbc4ab"
                            stroke="#111111"
                            stroke-width="2"
                            stroke-linejoin="round"
                        />
                        <path d="M76 90V96H100V90Z" fill="#fbc4ab" stroke="#111111" stroke-width="2" />
                        <ellipse cx="56" cy="72" rx="5" ry="8" fill="#fbc4ab" stroke="#111111" stroke-width="2" />
                        <ellipse cx="120" cy="72" rx="5" ry="8" fill="#fbc4ab" stroke="#111111" stroke-width="2" />
                        <path
                            d="M58 72C56 50 68 40 88 40C108 40 120 50 118 72C118 86 106 94 88 94C70 94 58 86 58 72Z"
                            fill="#fbc4ab"
                            stroke="#111111"
                            stroke-width="2.5"
                            stroke-linejoin="round"
                        />
                        <path
                            d="M64 46C62 20 70 8 88 8C106 8 114 20 112 46C100 50 76 50 64 46Z"
                            fill="#5c3826"
                            stroke="#111111"
                            stroke-width="2.5"
                            stroke-linejoin="round"
                        />
                        <path d="M64 46C76 51 100 51 112 46" stroke="#3d2316" stroke-width="2.2" />
                        <path d="M68 56C73 53 79 55 80 57M96 57C97 55 103 53 108 56" stroke="#111111" stroke-width="2.2" stroke-linecap="round" />
                        <ellipse cx="74" cy="63" rx="3" ry="4.5" fill="#111111" />
                        <ellipse cx="102" cy="63" rx="3" ry="4.5" fill="#111111" />
                        <circle cx="73" cy="61" r="1" fill="#ffffff" />
                        <circle cx="101" cy="61" r="1" fill="#ffffff" />
                        <ellipse cx="88" cy="66" rx="6" ry="5" fill="#fbc4ab" stroke="#111111" stroke-width="2" />
                        <path
                            d="M88 71C80 66 62 64 50 73C44 78 48 84 56 82C66 80 78 78 88 83C98 78 110 80 120 82C128 84 132 78 126 73C114 64 96 66 88 71Z"
                            fill="#ffffff"
                            stroke="#111111"
                            stroke-width="2.5"
                            stroke-linejoin="round"
                        />
                    </svg>
                </div>

                <div class="mc-elbob-footer">
                    <p class="mc-elbob-desc">{{ entry.description }}</p>
                    <span class="mc-elbob-index">30</span>
                </div>
            </div>
        </div>

        <!-- Rent -->
        <div
            v-else-if="entry.type === 'rent'"
            class="mc-face mc-studio mc-rent-reference"
            :style="{ '--mc-studio-bg': RENT_BG, '--mc-yellow': COLOR_HEX.yellow }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" :fill="RENT_BG" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header">
                    <span class="mc-category">CART SAYTARA</span>
                </div>

                <div class="mc-studio-center">
                    <div class="mc-rent-reference-orbit">
                        <template v-if="entry.any_color">
                            <div class="mc-rent-ring-rainbow"></div>
                        </template>
                        <template v-else>
                            <div class="mc-rent-ring-half" :style="{ background: colorHex(entry.colors![0]) }"></div>
                            <div class="mc-rent-ring-half" :style="{ background: colorHex(entry.colors![1]) }"></div>
                        </template>
                        <div class="mc-rent-reference-medallion">
                            <h3>{{ entry.label }}</h3>
                        </div>
                    </div>
                </div>

                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Forced Deal, Just Say No, and Deal Breaker -->
        <div
            v-else-if="entry.type === 'action' && ['forced_deal', 'just_say_no', 'deal_breaker'].includes(entry.action ?? '')"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE[entry.action ?? '']?.bg ?? '#dbe4ce' }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" :fill="ACTION_STYLE[entry.action ?? '']?.bg ?? '#dbe4ce'" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header"><span class="mc-category">CART SAYTARA</span></div>
                <div class="mc-studio-center">
                    <div class="mc-garab-medallion">
                        <h3 class="mc-garab-title">{{ entry.label }}</h3>
                    </div>
                </div>
                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Birthday -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'birthday'"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE.birthday.bg }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" :fill="ACTION_STYLE.birthday.bg" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header"><span class="mc-category">CART SAYTARA</span></div>
                <div class="mc-studio-center">
                    <div class="mc-garab-medallion mc-birthday-medallion">
                        <h3 class="mc-garab-title">{{ entry.label }}</h3>
                        <svg class="mc-birthday-cake" viewBox="0 0 80 50" fill="none" aria-hidden="true">
                            <ellipse cx="40" cy="46" rx="34" ry="3" fill="#ffffff" stroke="#111111" stroke-width="2.2" />
                            <path d="M14 34 C14 43 66 43 66 34 L66 25 C66 25 14 25 14 25 Z" fill="#ffffff" stroke="#111111" stroke-width="2.2" />
                            <path d="M14 31 C20 35 26 35 32 31 C38 35 44 35 50 31 C56 35 62 31 66 31" stroke="#111111" stroke-width="2" fill="none" />
                            <path d="M22 25 C22 31 58 31 58 25 L58 17 L22 17 Z" fill="#ffffff" stroke="#111111" stroke-width="2.2" />
                            <path
                                d="M22 22 C27 25 32 25 37 22 C42 25 47 25 52 22 C56 25 58 22 58 22"
                                stroke="#111111"
                                stroke-width="1.8"
                                fill="none"
                            />
                            <line x1="32" y1="17" x2="32" y2="8" stroke="#111111" stroke-width="2.2" stroke-linecap="round" />
                            <ellipse cx="32" cy="5" rx="1.6" ry="2.6" fill="#111111" />
                            <line x1="40" y1="17" x2="40" y2="6" stroke="#111111" stroke-width="2.2" stroke-linecap="round" />
                            <ellipse cx="40" cy="3" rx="1.6" ry="2.6" fill="#111111" />
                            <line x1="48" y1="17" x2="48" y2="8" stroke="#111111" stroke-width="2.2" stroke-linecap="round" />
                            <ellipse cx="48" cy="5" rx="1.6" ry="2.6" fill="#111111" />
                        </svg>
                    </div>
                </div>
                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Debt collector -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'debt_collector'"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE.debt_collector.bg }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" :fill="ACTION_STYLE.debt_collector.bg" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header"><span class="mc-category">CART SAYTARA</span></div>
                <div class="mc-studio-center">
                    <div class="mc-garab-medallion">
                        <h3 class="mc-garab-title">{{ entry.label }}</h3>
                    </div>
                </div>
                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Double rent -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'double_rent'"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE.double_rent.bg }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" :fill="ACTION_STYLE.double_rent.bg" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header"><span class="mc-category">CART SAYTARA</span></div>
                <div class="mc-studio-center">
                    <div class="mc-garab-medallion">
                        <h3 class="mc-garab-title">{{ entry.label }}</h3>
                    </div>
                </div>
                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Shisha -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'house'"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE.house.bg }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" :fill="ACTION_STYLE.house.bg" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header"><span class="mc-category">CART SAYTARA</span></div>
                <div class="mc-studio-center">
                    <div class="mc-garab-medallion mc-house-medallion">
                        <svg class="mc-house-hookah" viewBox="0 0 120 120" fill="none" aria-hidden="true">
                            <defs>
                                <linearGradient :id="hookahSilverGradientId" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" stop-color="#ffffff" />
                                    <stop offset="50%" stop-color="#94a3b8" />
                                    <stop offset="100%" stop-color="#475569" />
                                </linearGradient>
                                <linearGradient :id="hookahGreenGradientId" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#15803d" />
                                    <stop offset="50%" stop-color="#22c55e" />
                                    <stop offset="100%" stop-color="#166534" />
                                </linearGradient>
                            </defs>
                            <path
                                d="M 52 65 C 70 55 102 58 102 75 C 102 92 82 98 70 85 C 60 75 75 66 90 70"
                                fill="none"
                                stroke="#22c55e"
                                stroke-width="5"
                                stroke-linecap="round"
                            />
                            <path
                                d="M 52 65 C 70 55 102 58 102 75 C 102 92 82 98 70 85 C 60 75 75 66 90 70"
                                fill="none"
                                stroke="#111111"
                                stroke-width="1.6"
                                stroke-linecap="round"
                            />
                            <path d="M 46 10 C 46 4 58 4 58 10 L 56 16 L 48 16 Z" fill="#16a34a" stroke="#111111" stroke-width="1.8" />
                            <ellipse cx="52" cy="17" rx="22" ry="6" :fill="`url(#${hookahSilverGradientId})`" stroke="#111111" stroke-width="2" />
                            <path d="M 48 20 L 56 20 L 56 60 L 48 60 Z" fill="#15803d" stroke="#111111" stroke-width="1.8" />
                            <line
                                v-for="y in [24, 29, 34, 39, 44, 49, 54]"
                                :key="y"
                                x1="47"
                                :y1="y"
                                x2="57"
                                :y2="y"
                                stroke="#111111"
                                stroke-width="1.5"
                            />
                            <path
                                d="M 48 60 C 40 72 22 92 22 106 C 22 112 82 112 82 106 C 82 92 64 72 56 60 Z"
                                :fill="`url(#${hookahGreenGradientId})`"
                                stroke="#111111"
                                stroke-width="2.2"
                                stroke-linejoin="round"
                            />
                            <path d="M 36 90 L 52 112 M 44 80 L 64 112 M 52 72 L 72 104" stroke="#166534" stroke-width="1.4" opacity="0.8" />
                        </svg>
                        <h3 class="mc-garab-title mc-house-title">{{ entry.label }}</h3>
                    </div>
                </div>
                <div class="mc-studio-footer">
                    <p class="mc-studio-description mc-house-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Sly Deal -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'sly_deal'"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE[entry.action]?.bg ?? '#dbe4ce' }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" fill="#dbe4ce" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header">
                    <span class="mc-category">CART SAYTARA</span>
                </div>

                <div class="mc-studio-center">
                    <div class="mc-garab-medallion">
                        <h3 class="mc-garab-title">{{ entry.label }}</h3>
                    </div>
                </div>

                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Garab 7azak / Pass Go -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'pass_go'"
            class="mc-face mc-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE[entry.action]?.bg ?? '#e2e8f0' }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" fill="#fef0b8" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header">
                    <span class="mc-category">CART SAYTARA</span>
                </div>

                <div class="mc-studio-center">
                    <div class="mc-garab-medallion">
                        <h3 class="mc-garab-title">{{ entry.label }}</h3>
                        <svg class="mc-garab-arrow" viewBox="0 0 120 30" fill="none" aria-hidden="true">
                            <path
                                d="M 5 15 L 35 2 L 35 9 L 105 9 L 115 2 L 115 28 L 105 21 L 35 21 L 35 28 Z"
                                fill="#cc1111"
                                stroke="#111111"
                                stroke-width="2.5"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </div>
                </div>

                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Hotel / Wil3a -->
        <div
            v-else-if="entry.type === 'action' && entry.action === 'hotel'"
            class="mc-face mc-studio mc-hotel-studio"
            :style="{ '--mc-studio-bg': ACTION_STYLE[entry.action]?.bg ?? '#b8cde3' }"
        >
            <div class="mc-studio-badge mc-studio-badge--top"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>
            <div class="mc-studio-badge mc-studio-badge--bottom"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></div>

            <div class="mc-studio-inset">
                <svg class="mc-studio-frame" viewBox="0 0 310 488" preserveAspectRatio="none" aria-hidden="true">
                    <defs>
                        <pattern :id="motifId" width="10" height="10" patternUnits="userSpaceOnUse">
                            <rect width="10" height="10" fill="#b8cde3" />
                            <path d="M5 0 L10 5 L5 10 L0 5 Z" fill="none" stroke="#111111" stroke-width="1" />
                            <path d="M5 2.5 L7.5 5 L5 7.5 L2.5 5 Z" fill="#111111" />
                        </pattern>
                    </defs>
                    <rect x="2" y="2" width="306" height="484" fill="none" stroke="#111111" stroke-width="2" />
                    <rect x="3" y="3" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="473" width="304" height="12" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="3" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="295" y="3" width="12" height="482" :fill="`url(#${motifId})`" stroke="#111111" stroke-width="0.8" />
                    <rect x="15" y="15" width="280" height="458" fill="none" stroke="#111111" stroke-width="1.5" />
                </svg>

                <div class="mc-studio-header">
                    <span class="mc-category">CART SAYTARA</span>
                </div>

                <div class="mc-studio-center">
                    <div class="mc-garab-medallion mc-hotel-medallion">
                        <svg class="mc-hotel-flame" viewBox="0 0 100 100" fill="none" aria-hidden="true">
                            <path
                                d="M50 5 C50 5 78 30 78 62 C78 78 66 90 50 90 C34 90 22 78 22 62 C22 38 42 18 50 5 Z"
                                fill="#d9383a"
                                stroke="#111111"
                                stroke-width="2.5"
                                stroke-linejoin="round"
                            />
                            <path d="M50 26 C50 26 66 42 66 64 C66 74 58 82 50 82 C42 82 34 74 34 64 C34 50 44 36 50 26 Z" fill="#ff7d3b" />
                            <path d="M50 48 C50 48 57 56 57 68 C57 73 53 77 50 77 C47 77 43 73 43 68 C43 60 47 54 50 48 Z" fill="#ffcf43" />
                        </svg>
                        <h3 class="mc-garab-title mc-hotel-title">{{ entry.label }}</h3>
                    </div>
                </div>

                <div class="mc-studio-footer">
                    <p class="mc-studio-description mc-hotel-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Other actions -->
        <div v-else-if="entry.type === 'action'" class="mc-face mc-action" :style="{ background: ACTION_STYLE[entry.action ?? '']?.bg ?? '#e2e8f0' }">
            <div class="mc-badge mc-badge--corner">{{ formatValue(entry.value) }}</div>
            <span class="mc-category">CART SAYTARA</span>
            <h3 class="mc-action-title">{{ entry.label }}</h3>

            <svg v-if="ACTION_STYLE[entry.action ?? '']?.symbol === 'arrow'" class="mc-action-icon" viewBox="0 0 120 30" fill="none">
                <path
                    d="M 5 15 L 35 2 L 35 9 L 105 9 L 115 2 L 115 28 L 105 21 L 35 21 L 35 28 Z"
                    fill="#cc1111"
                    stroke="#111111"
                    stroke-width="2.5"
                    stroke-linejoin="round"
                />
            </svg>
            <svg v-else-if="ACTION_STYLE[entry.action ?? '']?.symbol === 'cake'" class="mc-action-icon" viewBox="0 0 80 50" fill="none">
                <ellipse cx="40" cy="46" rx="34" ry="3" fill="#ffffff" stroke="#111111" stroke-width="2.2" />
                <path d="M14 34 C14 43 66 43 66 34 L66 25 C66 25 14 25 14 25 Z" fill="#ffffff" stroke="#111111" stroke-width="2.2" />
                <path d="M22 25 C22 31 58 31 58 25 L58 17 L22 17 Z" fill="#ffffff" stroke="#111111" stroke-width="2.2" />
                <line x1="32" y1="17" x2="32" y2="8" stroke="#111111" stroke-width="2.2" stroke-linecap="round" />
                <ellipse cx="32" cy="5" rx="1.6" ry="2.6" fill="#111111" />
                <line x1="48" y1="17" x2="48" y2="8" stroke="#111111" stroke-width="2.2" stroke-linecap="round" />
                <ellipse cx="48" cy="5" rx="1.6" ry="2.6" fill="#111111" />
            </svg>
            <svg v-else-if="ACTION_STYLE[entry.action ?? '']?.symbol === 'flame'" class="mc-action-icon" viewBox="0 0 100 100" fill="none">
                <path
                    d="M50 5 C50 5 78 30 78 62 C78 78 66 90 50 90 C34 90 22 78 22 62 C22 38 42 18 50 5 Z"
                    fill="#d9383a"
                    stroke="#111111"
                    stroke-width="2.5"
                    stroke-linejoin="round"
                />
                <path d="M50 26 C50 26 66 42 66 64 C66 74 58 82 50 82 C42 82 34 74 34 64 C34 50 44 36 50 26 Z" fill="#ff7d3b" />
                <path d="M50 48 C50 48 57 56 57 68 C57 73 53 77 50 77 C47 77 43 73 43 68 C43 60 47 54 50 48 Z" fill="#ffcf43" />
            </svg>
            <svg v-else-if="ACTION_STYLE[entry.action ?? '']?.symbol === 'hookah'" class="mc-action-icon" viewBox="0 0 120 120" fill="none">
                <path
                    d="M 52 65 C 70 55 102 58 102 75 C 102 92 82 98 70 85 C 60 75 75 66 90 70"
                    fill="none"
                    stroke="#22c55e"
                    stroke-width="5"
                    stroke-linecap="round"
                />
                <ellipse cx="52" cy="17" rx="22" ry="6" fill="#94a3b8" stroke="#111111" stroke-width="2" />
                <path d="M 48 20 L 56 20 L 56 60 L 48 60 Z" fill="#15803d" stroke="#111111" stroke-width="1.8" />
                <path
                    d="M 48 60 C 40 72 22 92 22 106 C 22 112 82 112 82 106 C 82 92 64 72 56 60 Z"
                    fill="#22c55e"
                    stroke="#111111"
                    stroke-width="2.2"
                    stroke-linejoin="round"
                />
            </svg>

            <p v-if="isLg" class="mc-action-desc">{{ entry.description }}</p>
        </div>
    </div>
</template>

<style scoped>
.mc-card {
    flex-shrink: 0;
    font-family: 'Montserrat', sans-serif;
    color: #111111;
    container-type: inline-size;
    transition: transform 180ms ease;
}

.mc-card--flipped {
    transform: rotate(180deg);
}

.mc-card--detail {
    transition: none;
}

.mc-face {
    width: 100%;
    height: 100%;
    border: 2px solid #111111;
    border-radius: 8px;
    box-sizing: border-box;
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

.mc-badge {
    position: absolute;
    width: 2.2em;
    height: 2.2em;
    border-radius: 50%;
    border: 1.6px solid #111111;
    background: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    z-index: 5;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

.mc-badge--corner {
    top: 0.35em;
    left: 0.35em;
}

/* Money */
.mc-money-reference {
    align-items: stretch;
    padding: 3.53cqi;
    border: 0.88cqi solid #111111;
    border-radius: 3.53cqi;
    background: var(--mc-money-bg, #e2e8f0);
}

.mc-money-badge {
    position: absolute;
    z-index: 40;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 15.29cqi;
    height: 15.29cqi;
    box-sizing: border-box;
    border: 0.74cqi solid #111111;
    border-radius: 50%;
    background: var(--mc-money-bg, #e2e8f0);
    box-shadow: 0 0.59cqi 1.18cqi rgba(0, 0, 0, 0.1);
}

.mc-money-badge--top {
    top: 3.53cqi;
    left: 3.53cqi;
}

.mc-money-badge--bottom {
    right: 3.53cqi;
    bottom: 3.53cqi;
}

.mc-money-badge-inner {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 84.62%;
    height: 84.62%;
    box-sizing: border-box;
    border: 0.44cqi solid #111111;
    border-radius: 50%;
    font-family: 'Montserrat', sans-serif;
    font-size: 4.12cqi;
    font-weight: 900;
    letter-spacing: -0.15cqi;
    line-height: 1;
    white-space: nowrap;
    transform: rotate(90deg);
}

.mc-money-badge-inner sub,
.mc-money-center-value sub {
    position: relative;
    bottom: -0.05em;
    font-size: 0.65em;
}

.mc-money-frame {
    position: relative;
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    min-height: 0;
    overflow: hidden;
    border: 0.74cqi solid #111111;
    border-radius: 1.18cqi;
    background: var(--mc-money-bg, #e2e8f0);
}

.mc-money-pattern {
    position: absolute;
    inset: 0;
    z-index: 2;
    width: 100%;
    height: 100%;
    pointer-events: none;
}

.mc-money-emblem {
    z-index: 20;
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    width: 68.24cqi;
    height: 68.24cqi;
    box-sizing: border-box;
    padding: 1.76cqi;
    border: 1.47cqi solid #111111;
    border-radius: 50%;
    background: var(--mc-money-bg, #e2e8f0);
    box-shadow: 0 1.18cqi 3.53cqi rgba(0, 0, 0, 0.15);
}

.mc-money-emblem-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
    padding: 2.94cqi;
    border: 0.59cqi solid #111111;
    border-radius: 50%;
    transform: rotate(90deg);
}

.mc-money-center-value {
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 14.12cqi;
    font-weight: 900;
    letter-spacing: -0.44cqi;
    line-height: 1;
    white-space: nowrap;
}

.mc-money-center-value--double {
    font-size: 11.76cqi;
}

.mc-money-center-caption {
    margin-top: 1.47cqi;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 3.82cqi;
    font-weight: 900;
    letter-spacing: 0.74cqi;
    text-transform: uppercase;
    white-space: nowrap;
}

.mc-money-copyright {
    position: absolute;
    bottom: 0.88cqi;
    z-index: 50;
    width: 100%;
    color: #444444;
    font-family: sans-serif;
    font-size: 2.35cqi;
    text-align: center;
}

/* Property */
.mc-property-banner {
    width: 100%;
    padding: 0.5em 0.5em 0.5em 2.4em;
    box-sizing: border-box;
    text-align: center;
    border-bottom: 2px solid #111111;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 3em;
}

.mc-property-kicker {
    font-weight: 900;
    font-size: 0.55em;
    color: #ffffff;
    text-transform: uppercase;
}

.mc-property-title {
    font-weight: 900;
    font-size: 0.85em;
    color: #ffffff;
    text-transform: uppercase;
    margin: 0;
    line-height: 1.15;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.mc-property-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.4em;
    gap: 0.4em;
}

.mc-elbis-label {
    font-weight: 900;
    font-size: 0.75em;
    align-self: flex-end;
}

.mc-rent-ladder {
    list-style: none;
    margin: 0;
    padding: 0;
    width: 100%;
    font-size: 0.75em;
}

.mc-rent-row {
    display: flex;
    align-items: center;
    gap: 0.4em;
    margin-bottom: 0.4em;
}

.mc-rent-count {
    font-weight: 700;
    width: 1.6em;
}

.mc-rent-dots {
    flex: 1;
    border-bottom: 2px dotted #111111;
    height: 0.5em;
}

.mc-rent-amount {
    font-weight: 900;
}

.mc-property-reference {
    display: flex;
    border: 0.88cqi solid #111111;
    border-radius: 3.53cqi;
    background: #e5edd6;
}

.mc-property-reference-badge {
    top: 2.35cqi;
    left: 2.35cqi;
}

.mc-service-property-reference-badge {
    top: 2.35cqi;
    left: 2.35cqi;
    transform: none;
}

.mc-property-reference-banner {
    z-index: 10;
    display: flex;
    flex: 0 0 32.35cqi;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: 100%;
    padding: 2.94cqi 3.53cqi 2.94cqi 20cqi;
    border-bottom: 0.88cqi solid #111111;
    text-align: center;
}

.mc-property-reference-banner h3 {
    margin: 0;
    color: #ffffff;
    font-family: 'Montserrat', sans-serif;
    font-size: 5.29cqi;
    font-weight: 900;
    line-height: 1.15;
    text-shadow: 0 0.59cqi 1.18cqi rgba(0, 0, 0, 0.3);
    text-transform: uppercase;
}

.mc-service-property-reference-banner {
    flex-direction: column;
}

.mc-service-property-reference-banner > span {
    margin-bottom: 0.59cqi;
    color: #ffffff;
    font-family: 'Montserrat', sans-serif;
    font-size: 2.94cqi;
    font-weight: 900;
    letter-spacing: 0.15cqi;
    text-transform: uppercase;
}

.mc-property-reference-body {
    z-index: 10;
    display: flex;
    flex: 1;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    width: 100%;
    min-height: 0;
    padding: 4.7cqi 5.88cqi;
}

.mc-property-reference-elbis {
    align-self: stretch;
    margin: 0 0 4.7cqi;
    padding: 0 0.59cqi;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 4.41cqi;
    font-weight: 900;
    text-align: right;
}

.mc-property-reference-ladder {
    width: 100%;
    margin: 0;
    padding: 0;
    list-style: none;
}

.mc-property-reference-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-bottom: 5.29cqi;
}

.mc-property-reference-cards {
    position: relative;
    flex: 0 0 14.7cqi;
    height: 13.53cqi;
}

.mc-property-reference-mini-card {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 1;
    display: flex;
    flex-direction: column;
    width: 8.82cqi;
    height: 12.35cqi;
    overflow: hidden;
    border: 0.44cqi solid #111111;
    border-radius: 0.88cqi;
    background: #ffffff;
    box-shadow: 0.29cqi 0.44cqi 0.88cqi rgba(0, 0, 0, 0.18);
}

.mc-property-reference-mini-card span {
    flex: 0 0 28.6%;
    box-sizing: border-box;
    border-bottom: 0.35cqi solid #111111;
    background: var(--property-color);
}

.mc-property-reference-mini-card strong {
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 5.29cqi;
    font-weight: 900;
}

.mc-property-reference-leader {
    position: relative;
    flex: 1;
    height: 1em;
    margin: 0 2.35cqi;
    border-bottom: 0.88cqi dotted #111111;
}

.mc-property-reference-full-set {
    position: absolute;
    top: 50%;
    left: 50%;
    padding: 0 1.76cqi;
    background: #e5edd6;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 3.24cqi;
    font-weight: 900;
    letter-spacing: 0.15cqi;
    line-height: 1.1;
    text-align: center;
    transform: translate(-50%, -10%);
}

.mc-property-reference-rent {
    min-width: 19.1cqi;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 6.47cqi;
    font-weight: 900;
    text-align: right;
    white-space: nowrap;
}

.mc-property-reference-rent sub {
    position: relative;
    bottom: -0.05em;
    font-size: 0.65em;
}

.mc-double-wild-reference {
    display: flex;
    border: 0.88cqi solid #111111;
    border-radius: 3.53cqi;
    background: #ffffff;
}

.mc-double-wild-reference--service .mc-double-wild-banner--bottom .mc-double-wild-banner-copy {
    color: #ffffff;
}

.mc-double-wild-reference--service .mc-double-wild-banner--bottom .mc-double-wild-banner-copy em {
    color: #e2e8f0;
}

.mc-double-wild-service-icons {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 1.18cqi;
}

.mc-double-wild-service-icons svg {
    width: 6.47cqi;
    height: 6.47cqi;
}

.mc-double-wild-service-icons svg:last-child {
    width: 7.65cqi;
}

.mc-double-wild-badge--top {
    top: 1.76cqi;
    left: 1.76cqi;
    z-index: 40;
}

.mc-double-wild-badge--bottom {
    right: 1.76cqi;
    bottom: 1.76cqi;
    z-index: 40;
    transform: rotate(180deg);
}

.mc-double-wild-banner {
    z-index: 20;
    display: flex;
    flex: 0 0 20cqi;
    align-items: center;
    justify-content: space-between;
    box-sizing: border-box;
    width: 100%;
    padding: 1.18cqi 18.24cqi 1.18cqi 17.65cqi;
    border-bottom: 0.59cqi solid #111111;
}

.mc-double-wild-banner--bottom {
    border-top: 0.59cqi solid #111111;
    border-bottom: 0;
    transform: rotate(180deg);
}

.mc-double-wild-banner-copy {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    color: #111111;
    white-space: nowrap;
}

.mc-double-wild-banner-copy span {
    font-family: 'Montserrat', sans-serif;
    font-size: 3.24cqi;
    font-weight: 900;
    letter-spacing: 0.15cqi;
    text-transform: uppercase;
}

.mc-double-wild-banner-copy strong {
    margin-top: 0.29cqi;
    font-family: 'Montserrat', sans-serif;
    font-size: 4.41cqi;
    font-weight: 900;
    line-height: 1.1;
    text-transform: uppercase;
}

.mc-double-wild-banner-copy em {
    margin-top: 0.29cqi;
    color: #222222;
    font-family: 'EB Garamond', serif;
    font-size: 2.65cqi;
    font-style: italic;
}

.mc-double-wild-active {
    margin-bottom: 0.2cqi;
    padding: 0.15cqi 0.45cqi;
    border: 0.3cqi solid #111111;
    border-radius: 1cqi;
    background: #ffffff;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 2.45cqi;
    font-style: normal;
    font-weight: 900;
    line-height: 1;
}

.mc-double-wild-body {
    z-index: 10;
    display: grid;
    flex: 1;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: center;
    gap: 2.35cqi;
    min-height: 0;
    padding: 1.18cqi 2.94cqi;
    overflow: hidden;
}

.mc-double-wild-panel {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    width: 100%;
    height: 100%;
    min-width: 0;
}

.mc-double-wild-panel--bottom {
    transform: rotate(180deg);
}

.mc-double-wild-panel h3 {
    width: 100%;
    margin: 0 0 0.59cqi;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 4.12cqi;
    font-weight: 900;
    text-align: right;
}

.mc-double-wild-ladder {
    width: 100%;
    margin: 0;
    padding: 0;
    list-style: none;
}

.mc-double-wild-row {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    width: 100%;
    min-width: 0;
    margin-bottom: 0.88cqi;
}

.mc-double-wild-row--bottom {
    justify-content: flex-start;
}

.mc-double-wild-cards {
    position: relative;
    flex: 0 0 10.6cqi;
    height: 13.53cqi;
}

.mc-double-wild-mini-card {
    position: absolute;
    top: 0;
    left: 0;
    z-index: 1;
    display: flex;
    flex-direction: column;
    width: 8.82cqi;
    height: 12.35cqi;
    overflow: hidden;
    border: 0.44cqi solid #111111;
    border-radius: 0.88cqi;
    background: #ffffff;
    box-shadow: 0.29cqi 0.44cqi 0.88cqi rgba(0, 0, 0, 0.18);
}

.mc-double-wild-mini-card span {
    flex: 0 0 28.6%;
    box-sizing: border-box;
    border-bottom: 0.35cqi solid #111111;
    background: var(--property-color);
}

.mc-double-wild-mini-card strong {
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 5.29cqi;
    font-weight: 900;
}

.mc-double-wild-leader {
    position: relative;
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    min-width: 0;
    height: 1em;
    margin: 0 1.18cqi;
}

.mc-double-wild-leader::before {
    position: absolute;
    right: 0;
    left: 0;
    border-bottom: 0.44cqi dotted #111111;
    content: '';
}

.mc-double-wild-leader span {
    z-index: 1;
    padding: 0 0.59cqi;
    background: #ffffff;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 2.5cqi;
    font-weight: 900;
    line-height: 1.1;
    text-align: center;
}

.mc-double-wild-rent {
    flex-shrink: 0;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 3.24cqi;
    font-weight: 900;
    text-align: right;
    white-space: nowrap;
}

.mc-double-wild-rent sub {
    position: relative;
    bottom: -0.05em;
    font-size: 0.65em;
}

/* Wildcard */
.mc-wild-banner {
    width: 100%;
    padding: 0.4em 1.9em;
    box-sizing: border-box;
    border-bottom: 1.5px solid #111111;
    display: flex;
    flex-direction: column;
}

.mc-wild-banner--bottom {
    border-bottom: none;
    border-top: 1.5px solid #111111;
    transform: rotate(180deg);
}

.mc-wild-kicker {
    font-weight: 900;
    font-size: 0.5em;
    text-transform: uppercase;
}

.mc-wild-name {
    font-weight: 900;
    font-size: 0.7em;
    text-transform: uppercase;
}

.mc-wild-mid {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.65em;
    text-align: center;
    padding: 0.3em;
}

/* EL BOB */
.mc-elbob {
    display: block;
    padding: 2.94cqi;
    border: 0.88cqi solid #111111;
    border-radius: 3.53cqi;
    background: #ffffff;
}

.mc-elbob-frame {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
    padding-bottom: 2.35cqi;
    border: 0.59cqi solid #111111;
    background: #ffffff;
}

.mc-elbob-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
}

.mc-elbob-title {
    width: 100%;
    box-sizing: border-box;
    padding: 1.76cqi 0;
    border-bottom: 0.59cqi solid #111111;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-weight: 900;
    font-size: 5.88cqi;
    letter-spacing: 0.29cqi;
    margin: 0;
    text-align: center;
    text-transform: uppercase;
}

.mc-elbob-stripe {
    display: flex;
    width: 100%;
    height: 4.71cqi;
    box-sizing: border-box;
    overflow: hidden;
    border-top: 0.59cqi solid #111111;
    border-bottom: 0.59cqi solid #111111;
}

.mc-elbob-stripe span {
    flex: 1;
}

.mc-elbob-art {
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    width: 100%;
    min-height: 0;
    margin: 1.18cqi 0;
}

.mc-elbob-art svg {
    display: block;
    width: 45.59cqi;
    max-height: 100%;
}

.mc-elbob-footer {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 100%;
    box-sizing: border-box;
    padding: 0 3.53cqi;
}

.mc-elbob-desc {
    margin: 0 0 1.76cqi;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 4.41cqi;
    font-weight: 800;
    line-height: 1.25;
    text-align: center;
}

.mc-elbob-index {
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 3.24cqi;
    font-weight: 700;
}

.mc-action-desc {
    font-family: 'EB Garamond', serif;
    font-weight: 700;
    font-style: italic;
    font-size: 0.7em;
    text-align: center;
    line-height: 1.25;
    padding: 0 0.4em 0.4em;
    margin: 0;
}

/* Rent */
.mc-category {
    text-align: center;
    font-weight: 900;
    font-size: 0.6em;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    padding-top: 0.5em;
}

.mc-rent-ring-half {
    flex: 1;
}

.mc-rent-ring-rainbow {
    flex: 1;
    background: conic-gradient(#dc2626, #ea580c, #d97706, var(--mc-yellow, #fef08a), #65a30d, #16a34a, #0d9488, #0284c7, #2563eb, #9333ea, #dc2626);
}

/* Action */
.mc-action {
    align-items: center;
    text-align: center;
    padding-bottom: 0.4em;
}

.mc-action-title {
    font-weight: 900;
    font-size: 0.8em;
    text-transform: uppercase;
    margin: 0.3em 0.3em 0;
    line-height: 1.15;
}

.mc-action-icon {
    width: 2.6em;
    height: auto;
    margin-top: 0.3em;
}

/* Shared card-studio frame used by Garab 7azak and Rent cards. */
.mc-studio {
    display: block;
    border: 0.88cqi solid #111111;
    border-radius: 3.53cqi;
    padding: 1.76cqi;
    background: var(--mc-studio-bg, #fef0b8);
}

.mc-studio-inset {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    height: 100%;
    box-sizing: border-box;
    overflow: hidden;
    padding: 4.52cqi;
    border: 0.59cqi solid #111111;
    border-radius: 1.76cqi;
    background: var(--mc-studio-bg, #fef0b8);
}

.mc-studio-frame {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 2;
    pointer-events: none;
}

.mc-studio-badge {
    position: absolute;
    z-index: 30;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 14.12cqi;
    aspect-ratio: 1;
    box-sizing: border-box;
    border: 0.74cqi solid #111111;
    border-radius: 50%;
    background: #ffffff;
    box-shadow: 0 0.59cqi 1.18cqi rgba(0, 0, 0, 0.15);
    font-family: 'Montserrat', sans-serif;
    font-size: 4.41cqi;
    font-weight: 900;
    letter-spacing: -0.15cqi;
    line-height: 1;
    transform: rotate(-12deg);
}

.mc-studio-badge::before {
    position: absolute;
    inset: 8.33%;
    border: 0.44cqi solid #111111;
    border-radius: 50%;
    content: '';
}

.mc-studio-badge sub {
    position: relative;
    bottom: -0.05em;
    font-size: 0.65em;
    line-height: 1;
}

.mc-studio-mark {
    position: relative;
    display: inline-block;
    line-height: 1;
}

.mc-studio-mark::before,
.mc-studio-mark::after {
    position: absolute;
    right: -0.08em;
    left: -0.08em;
    height: 0.09em;
    border-radius: 1px;
    background: currentColor;
    content: '';
}

.mc-studio-mark::before {
    top: 36%;
}

.mc-studio-mark::after {
    top: 56%;
}

.mc-studio-badge--top {
    top: 2.94cqi;
    left: 2.94cqi;
}

.mc-studio-badge--bottom {
    right: 2.94cqi;
    bottom: 2.94cqi;
}

.mc-studio-header,
.mc-studio-footer {
    z-index: 10;
    display: flex;
    flex: 1;
    align-items: center;
    justify-content: center;
    width: 100%;
}

.mc-studio-header .mc-category {
    padding: 0;
    font-size: 5cqi;
    letter-spacing: 0.35cqi;
}

.mc-studio-center {
    z-index: 10;
    display: flex;
    flex: 0 0 39%;
    align-items: center;
    justify-content: center;
    width: 61.3%;
}

.mc-garab-medallion {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 96%;
    aspect-ratio: 1;
    box-sizing: border-box;
    padding: 3.24cqi;
    border: 1.76cqi solid #111111;
    border-radius: 50%;
    background: #ffffff;
    box-shadow: 0 1.18cqi 2.94cqi rgba(0, 0, 0, 0.15);
    text-align: center;
}

.mc-garab-title {
    margin: 0;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 6.47cqi;
    font-weight: 900;
    line-height: 1.1;
    text-transform: uppercase;
    overflow-wrap: anywhere;
}

.mc-garab-arrow {
    display: block;
    width: 32.35%;
    height: auto;
    margin-top: 2.35cqi;
    flex-shrink: 0;
}

.mc-hotel-flame {
    display: block;
    flex: 0 0 auto;
    width: 20cqi;
    height: 20cqi;
    margin-bottom: 1.76cqi;
}

.mc-hotel-title {
    font-size: 4.41cqi;
}

.mc-hotel-description {
    max-width: 45%;
}

.mc-house-hookah {
    display: block;
    flex: 0 0 auto;
    width: 21.76cqi;
    height: 21.76cqi;
    margin-bottom: 1.76cqi;
}

.mc-house-title {
    font-size: 4.41cqi;
}

.mc-house-description {
    max-width: 78%;
}

.mc-birthday-cake {
    display: block;
    flex: 0 0 auto;
    width: 20cqi;
    height: auto;
    margin-top: 1.76cqi;
}

.mc-rent-reference-orbit {
    position: relative;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    width: 82%;
    aspect-ratio: 1;
    overflow: hidden;
    border: 1.76cqi solid #111111;
    border-radius: 50%;
}

.mc-rent-reference-medallion {
    position: absolute;
    inset: 12.2%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    padding: 3cqi;
    border-radius: 50%;
    background: #ffffff;
    box-shadow: 0 1.18cqi 2.94cqi rgba(0, 0, 0, 0.15);
    text-align: center;
}

.mc-rent-reference-medallion h3 {
    margin: 0;
    color: #111111;
    font-family: 'Montserrat', sans-serif;
    font-size: 6.47cqi;
    font-weight: 900;
    line-height: 1.1;
    text-transform: uppercase;
    overflow-wrap: anywhere;
}

.mc-studio-description {
    margin: 0;
    color: #111111;
    font-family: 'EB Garamond', serif;
    font-size: 4.41cqi;
    font-weight: 700;
    line-height: 1.25;
    text-align: center;
}
</style>
