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
    }>(),
    { size: 'sm', rentChart: undefined, setSize: undefined },
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
// The studio's own native card is 340x520 (ratio ≈ 0.6538). 'sm' keeps
// many cards legible side by side; 'lg' is roughly double for a single
// selected-card detail view.
const dims = computed(() => (isLg.value ? { w: 220, h: 337 } : { w: 108, h: 165 }))

function formatValue(value: number): string {
    return `${value}M`
}

const isRailroadOrUtility = (color?: string) => color === 'railroad' || color === 'utility'
</script>

<template>
    <div
        class="mc-card"
        :style="{ width: dims.w + 'px', height: dims.h + 'px', fontSize: (isLg ? 1 : 0.62) + 'rem' }"
    >
        <!-- Money -->
        <div v-if="entry.type === 'money'" class="mc-face mc-money" :style="{ background: MONEY_BG[entry.value] ?? '#e2e8f0' }">
            <span class="mc-money-value">{{ formatValue(entry.value) }}</span>
            <span class="mc-money-caption">MALTOOSH</span>
        </div>

        <!-- Property -->
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

        <!-- Two-color wildcard -->
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
.mc-money {
    align-items: center;
    justify-content: center;
    gap: 0.3em;
}

.mc-money-value {
    font-weight: 900;
    font-size: 2.4em;
    letter-spacing: -0.03em;
}

.mc-money-caption {
    font-weight: 900;
    font-size: 0.75em;
    letter-spacing: 0.15em;
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
