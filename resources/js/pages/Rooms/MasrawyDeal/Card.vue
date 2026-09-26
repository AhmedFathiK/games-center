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
import { computed, useId } from 'vue'
import type { CardCatalogEntry } from '@/types/room'

const props = withDefaults(
    defineProps<{
        entry: CardCatalogEntry
        size?: 'sm' | 'lg'
        rentChart?: number[]
        setSize?: number
        wildRentCharts?: number[][]
        wildSetSizes?: number[]
    }>(),
    { size: 'sm', rentChart: undefined, setSize: undefined, wildRentCharts: undefined, wildSetSizes: undefined },
)

const COLOR_HEX: Record<string, string> = {
    brown: '#542810',
    light_blue: '#38bdf8',
    pink: '#db2777',
    // Not present in the studio's own presets (no orange preset was
    // exported) — a plausible Monopoly-esque orange, easy to swap for
    // an exact value if Ahmed supplies one.
    orange: '#ea580c',
    red: '#dc2626',
    yellow: '#ca8a04',
    green: '#15803d',
    dark_blue: '#1d4ed8',
    railroad: '#111111',
    utility: '#a1c1a6',
}

const MONEY_BG: Record<number, string> = {
    1: '#dbe4ce',
    2: '#d8c8bd',
    3: '#c9d7d2',
    4: '#b8cde3',
    5: '#a899c6',
    10: '#dca743',
}

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
}

const RENT_BG = '#e5edd6'

function colorHex(color: string): string {
    return COLOR_HEX[color] ?? '#94a3b8'
}

function colorLabel(color: string): string {
    return color
        .split('_')
        .map(w => w[0].toUpperCase() + w.slice(1))
        .join(' ')
}

const isLg = computed(() => props.size === 'lg')
const motifId = `mc-art-deco-${useId()}`
const moneyPatternId = `mc-money-pattern-${useId()}`
// The studio's own native card is 340x520 (ratio ≈ 0.6538). 'sm' keeps
// many cards legible side by side; 'lg' is roughly double for a single
// selected-card detail view.
const dims = computed(() => (isLg.value ? { w: 220, h: 337 } : { w: 108, h: 165 }))

function formatValue(value: number): string {
    return `${value}M`
}

const isRailroadOrUtility = (color?: string) => color === 'railroad' || color === 'utility'

function isPropertyWildcard(entry: CardCatalogEntry): boolean {
    return entry.type === 'wildcard'
        && !entry.any_color
        && Boolean(entry.colors?.length)
        && !entry.colors!.some(color => isRailroadOrUtility(color))
}

function isUtilityRailroadWildcard(entry: CardCatalogEntry): boolean {
    return entry.type === 'wildcard'
        && !entry.any_color
        && entry.colors?.includes('utility') === true
        && entry.colors.includes('railroad')
}

function hasReferenceWildcardDesign(entry: CardCatalogEntry): boolean {
    return isPropertyWildcard(entry) || isUtilityRailroadWildcard(entry)
}
</script>

<template>
    <div
        class="mc-card"
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
                        <span class="mc-money-center-value" :class="{ 'mc-money-center-value--double': entry.value === 10 }"><span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub></span>
                        <span class="mc-money-center-caption">MALTOOSH</span>
                    </div>
                </div>
            </div>

            <div class="mc-money-copyright">© 1935, 2008 HASBRO.</div>
        </div>

        <!-- Property -->
        <div v-else-if="entry.type === 'property' && !isRailroadOrUtility(entry.color)" class="mc-face mc-property-reference">
            <div class="mc-studio-badge mc-property-reference-badge">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>
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

        <!-- Two-property wild card -->
        <div v-else-if="hasReferenceWildcardDesign(entry)" class="mc-face mc-double-wild-reference" :class="{ 'mc-double-wild-reference--service': isUtilityRailroadWildcard(entry) }">
            <div class="mc-studio-badge mc-double-wild-badge--top">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>
            <div class="mc-studio-badge mc-double-wild-badge--bottom">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>

            <div class="mc-double-wild-banner" :style="{ background: colorHex(entry.colors![0]) }">
                <div class="mc-double-wild-banner-copy">
                    <span>{{ isUtilityRailroadWildcard(entry) ? 'KHADAMAT ENSHERA7' : 'MANTI2A' }}</span>
                    <strong>CART KARBAGA</strong>
                    <em>(Use card either way up.)</em>
                </div>
                <div v-if="isUtilityRailroadWildcard(entry)" class="mc-double-wild-service-icons" aria-hidden="true">
                    <svg width="20" height="22" viewBox="0 0 24 24" fill="none" stroke="#111111" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 18h6m-4 3h2M12 2a7 7 0 0 0-7 7c0 3 2 5 3 7h8c1-2 3-4 3-7a7 7 0 0 0-7-7z" fill="#facc15" />
                    </svg>
                    <svg width="22" height="20" viewBox="0 0 24 24" fill="none" stroke="#111111" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 10h12a2 2 0 0 0 2-2V6h-4M18 12v6a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-6m6-6v6" />
                    </svg>
                </div>
            </div>

            <div class="mc-double-wild-body">
                <section class="mc-double-wild-panel">
                    <h3>ELBIS</h3>
                    <ul v-if="wildRentCharts?.[0]" class="mc-double-wild-ladder">
                        <li v-for="(rent, i) in wildRentCharts[0]" :key="i" class="mc-double-wild-row">
                            <div class="mc-double-wild-cards">
                                <div
                                    v-for="cardIndex in i + 1"
                                    :key="cardIndex"
                                    class="mc-double-wild-mini-card"
                                    :style="{
                                        left: `${(i + 1 - cardIndex) * 1.18}cqi`,
                                        top: `${(i + 1 - cardIndex) * 0.59}cqi`,
                                        zIndex: cardIndex,
                                        '--property-color': colorHex(entry.colors![0]),
                                    }"
                                >
                                    <span />
                                    <strong>{{ cardIndex === i + 1 ? cardIndex : '' }}</strong>
                                </div>
                            </div>
                            <div class="mc-double-wild-leader">
                                <span v-if="i === (wildSetSizes?.[0] ?? wildRentCharts[0].length) - 1">{{ isUtilityRailroadWildcard(entry) ? 'KHADAMAT ENSHERA7 KAMLA' : 'MANTI2A KAMLA' }}</span>
                            </div>
                            <div class="mc-double-wild-rent"><span class="mc-studio-mark">M</span>{{ rent }}<sub>M</sub></div>
                        </li>
                    </ul>
                </section>

                <section class="mc-double-wild-panel mc-double-wild-panel--bottom">
                    <h3>ELBIS</h3>
                    <ul v-if="wildRentCharts?.[1]" class="mc-double-wild-ladder">
                        <li v-for="(rent, i) in wildRentCharts[1]" :key="i" class="mc-double-wild-row mc-double-wild-row--bottom">
                            <div class="mc-double-wild-rent"><span class="mc-studio-mark">M</span>{{ rent }}<sub>M</sub></div>
                            <div class="mc-double-wild-leader">
                                <span v-if="i === (wildSetSizes?.[1] ?? wildRentCharts[1].length) - 1">{{ isUtilityRailroadWildcard(entry) ? 'KHADAMAT ENSHERA7 KAMLA' : 'MANTI2A KAMLA' }}</span>
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
                                        '--property-color': colorHex(entry.colors![1]),
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

            <div class="mc-double-wild-banner mc-double-wild-banner--bottom" :style="{ background: colorHex(entry.colors![1]) }">
                <div class="mc-double-wild-banner-copy">
                    <span>{{ isUtilityRailroadWildcard(entry) ? 'KHADAMAT ENSHERA7' : 'MANTI2A' }}</span>
                    <strong>CART KARBAGA</strong>
                    <em>(Use card either way up.)</em>
                </div>
                <div v-if="isUtilityRailroadWildcard(entry)" class="mc-double-wild-service-icons" aria-hidden="true">
                    <svg width="26" height="22" viewBox="0 0 24 24" fill="#ffffff">
                        <path d="M4 15.5V14a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v1.5M6 18a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zm15 0a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0zM3 8l2-4h14l2 4v5H3V8z" />
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
            <h3 class="mc-elbob-title">EL BOB</h3>
            <div class="mc-elbob-stripe">
                <span v-for="c in ['#dc2626', '#ea580c', '#ca8a04', '#16a34a', '#0284c7', '#9333ea']" :key="c" :style="{ background: c }" />
            </div>
            <p v-if="isLg" class="mc-elbob-desc">{{ entry.description }}</p>
        </div>

        <!-- Rent -->
        <div v-else-if="entry.type === 'rent'" class="mc-face mc-studio mc-rent-reference" :style="{ '--mc-studio-bg': RENT_BG }">
            <div class="mc-studio-badge mc-studio-badge--top">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>
            <div class="mc-studio-badge mc-studio-badge--bottom">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>

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

        <!-- Sly Deal -->
        <div v-else-if="entry.type === 'action' && entry.action === 'sly_deal'" class="mc-face mc-studio" :style="{ '--mc-studio-bg': ACTION_STYLE[entry.action]?.bg ?? '#dbe4ce' }">
            <div class="mc-studio-badge mc-studio-badge--top">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>
            <div class="mc-studio-badge mc-studio-badge--bottom">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>

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
        <div v-else-if="entry.type === 'action' && entry.action === 'pass_go'" class="mc-face mc-studio" :style="{ '--mc-studio-bg': ACTION_STYLE[entry.action]?.bg ?? '#e2e8f0' }">
            <div class="mc-studio-badge mc-studio-badge--top">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>
            <div class="mc-studio-badge mc-studio-badge--bottom">
                <span class="mc-studio-mark">M</span>{{ entry.value }}<sub>M</sub>
            </div>

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
                            <path d="M 5 15 L 35 2 L 35 9 L 105 9 L 115 2 L 115 28 L 105 21 L 35 21 L 35 28 Z" fill="#cc1111" stroke="#111111" stroke-width="2.5" stroke-linejoin="round" />
                        </svg>
                    </div>
                </div>

                <div class="mc-studio-footer">
                    <p class="mc-studio-description">{{ entry.description }}</p>
                </div>
            </div>
        </div>

        <!-- Other actions -->
        <div v-else-if="entry.type === 'action'" class="mc-face mc-action" :style="{ background: ACTION_STYLE[entry.action ?? '']?.bg ?? '#e2e8f0' }">
            <div class="mc-badge mc-badge--corner">{{ formatValue(entry.value) }}</div>
            <span class="mc-category">CART SAYTARA</span>
            <h3 class="mc-action-title">{{ entry.label }}</h3>

            <svg v-if="ACTION_STYLE[entry.action ?? '']?.symbol === 'arrow'" class="mc-action-icon" viewBox="0 0 120 30" fill="none">
                <path d="M 5 15 L 35 2 L 35 9 L 105 9 L 115 2 L 115 28 L 105 21 L 35 21 L 35 28 Z" fill="#cc1111" stroke="#111111" stroke-width="2.5" stroke-linejoin="round" />
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
                <path d="M50 5 C50 5 78 30 78 62 C78 78 66 90 50 90 C34 90 22 78 22 62 C22 38 42 18 50 5 Z" fill="#d9383a" stroke="#111111" stroke-width="2.5" stroke-linejoin="round" />
                <path d="M50 26 C50 26 66 42 66 64 C66 74 58 82 50 82 C42 82 34 74 34 64 C34 50 44 36 50 26 Z" fill="#ff7d3b" />
                <path d="M50 48 C50 48 57 56 57 68 C57 73 53 77 50 77 C47 77 43 73 43 68 C43 60 47 54 50 48 Z" fill="#ffcf43" />
            </svg>
            <svg v-else-if="ACTION_STYLE[entry.action ?? '']?.symbol === 'hookah'" class="mc-action-icon" viewBox="0 0 120 120" fill="none">
                <path d="M 52 65 C 70 55 102 58 102 75 C 102 92 82 98 70 85 C 60 75 75 66 90 70" fill="none" stroke="#22c55e" stroke-width="5" stroke-linecap="round" />
                <ellipse cx="52" cy="17" rx="22" ry="6" fill="#94a3b8" stroke="#111111" stroke-width="2" />
                <path d="M 48 20 L 56 20 L 56 60 L 48 60 Z" fill="#15803d" stroke="#111111" stroke-width="1.8" />
                <path d="M 48 60 C 40 72 22 92 22 106 C 22 112 82 112 82 106 C 82 92 64 72 56 60 Z" fill="#22c55e" stroke="#111111" stroke-width="2.2" stroke-linejoin="round" />
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
    white-space: nowrap;
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
    align-items: center;
    justify-content: center;
    gap: 0.5em;
    background: #ffffff;
    padding: 0.5em;
}

.mc-elbob-title {
    font-weight: 900;
    font-size: 1.1em;
    margin: 0;
    text-transform: uppercase;
}

.mc-elbob-stripe {
    display: flex;
    width: 80%;
    height: 0.5em;
    border-radius: 4px;
    overflow: hidden;
    border: 1px solid #111111;
}

.mc-elbob-stripe span {
    flex: 1;
}

.mc-elbob-desc,
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
    background: conic-gradient(#dc2626, #ea580c, #d97706, #ca8a04, #65a30d, #16a34a, #0d9488, #0284c7, #2563eb, #9333ea, #dc2626);
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
