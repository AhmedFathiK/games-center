<script setup lang="ts">
/**
 * Masrawy Deal's rounded-rectangle 3D table. Pure presentation: it receives the
 * table state and draws every player's plate OUTSIDE the rim and their
 * property sets as real, stacked cards ON the felt, with the viewer always at
 * the bottom whatever their seat order. Nothing here decides game rules —
 * tapping a set just asks Table.vue to open its existing property-set modal.
 *
 * The geometry is fixed for ten players: the table has nine slots for the
 * other players (plus the viewer's own) and a player always occupies the same
 * area whatever the head-count; fewer players simply leave slots empty (see
 * SUBSETS), so the table and the cards on it never change size.
 *
 * Everything is sized from one unit, --u (1% of the board's width, measured
 * with a ResizeObserver). The draw and discard piles sit in the board's top-left
 * corner, off the table; the discard pile's card art comes in through the
 * `discard` slot so card rendering stays in Table.vue.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { MasrawySeat, MasrawyTableState } from '@/types/room'
import { useI18n } from '@/i18n'
import MasrawyCard from './Card.vue'

const props = defineProps<{
    table: MasrawyTableState
    myId: number
    isMyTurn: boolean
    playsLeft: number
    live: boolean
    // room.winner (a user id as a string) once the game has finished.
    winnerId?: string | null
    // Finished/cancelled rooms reveal every hand; plates then invite a tap.
    revealHands?: boolean
    // True while it's the viewer's turn and they still have to draw.
    canDraw?: boolean
    // Opponent ids that can be tapped as the target of the selected card, or null.
    pickTargets?: number[] | null
    pickedId?: number | null
    playerName: (id: number | string) => string
    colorLabel: (color: string) => string
}>()

const emit = defineEmits<{
    (e: 'open-set', playerId: number, color: string): void
    (e: 'open-player', playerId: number): void
    (e: 'draw'): void
    (e: 'pick-target', playerId: number): void
}>()

const { t } = useI18n()

const SEAT_HUES = [200, 28, 52, 150, 280, 340, 175, 12, 235, 95]

// Card geometry in board units (1u = 1% of the board width). 'sm' cards are
// 108 × 165 px, so a column's scale is CARD_W·u / 108.
const CARD_W = 3.6
const SM_CARD_PX = 108
const SM_RATIO = 165 / 108
const CARD_OVERLAP = 0.27 // fraction of a card's height each next card is offset by
const COLUMNS_PER_ROW = 4

const stageEl = ref<HTMLElement | null>(null)
// Board width / 100, in px. 5 is a sane first paint before we measure.
const unit = ref(5)
let observer: ResizeObserver | null = null

onMounted(() => {
    const el = stageEl.value
    if (!el) return

    const measure = () => {
        const width = el.getBoundingClientRect().width
        if (width > 0) unit.value = Math.round((width / 100) * 100) / 100
    }

    measure()
    if (typeof ResizeObserver !== 'undefined') {
        observer = new ResizeObserver(measure)
        observer.observe(el)
    }
})

onBeforeUnmount(() => observer?.disconnect())

function bankTotal(seat: MasrawySeat): number {
    return seat.bank.reduce((total, cardId) => total + (props.table.catalog[cardId]?.value ?? 0), 0)
}

function isCompleteSet(seat: MasrawySeat, color: string): boolean {
    const group = seat.properties[color]
    if (!group) return false

    const needed = props.table.set_size[color] ?? Number.POSITIVE_INFINITY
    // An all-EL-BOB group can't complete a set on its own (official rule).
    const hasRealCard = group.cards.some(cardId => !props.table.catalog[cardId]?.any_color)

    return group.cards.length >= needed && hasRealCard
}

type Side = 'top' | 'bottom'
type Slot = { side: Side; x: number; y: number; podX: number; podY: number }

// A long table: two rows of five seats, the viewer at the bottom centre. Plates
// stand outside the rim (above the top row, below the bottom row); each
// player's sets lie on the felt beside their plate. Coordinates are percentages
// of the plane (89u wide, ~24u tall on screen); pods anchor at their bottom edge.
const COLS = [11.5, 30.7, 50, 69.3, 88.5]
const TOP_POD_Y = 46
const BOTTOM_POD_Y = 99
const ABOVE = 0
const BELOW = 124

const top = (i: number): Slot => ({ side: 'top', x: COLS[i], y: ABOVE, podX: COLS[i], podY: TOP_POD_Y })
const bottom = (i: number): Slot => ({ side: 'bottom', x: COLS[i], y: BELOW, podX: COLS[i], podY: BOTTOM_POD_Y })

// The nine slots for the other players, clockwise from the viewer's left:
// along the bottom row to the left, across the top row, back down the right.
const SLOTS: Slot[] = [bottom(1), bottom(0), top(0), top(1), top(2), top(3), top(4), bottom(4), bottom(3)]
const ME_SLOT: Slot = bottom(2)

// Which slots a table of N players uses (index = other players). Mirror-
// symmetric, so a small table still looks seated, never lopsided.
const SUBSETS: Record<number, number[]> = {
    1: [4],
    2: [3, 5],
    3: [3, 4, 5],
    4: [0, 3, 5, 8],
    5: [0, 3, 4, 5, 8],
    6: [0, 2, 3, 5, 6, 8],
    7: [0, 2, 3, 4, 5, 6, 8],
    8: [0, 1, 2, 3, 5, 6, 7, 8],
    9: [0, 1, 2, 3, 4, 5, 6, 7, 8],
}

const seats = computed(() => {
    const players = props.table.players
    const count = players.length
    const myIndex = Math.max(0, players.findIndex(seat => seat.id === props.myId))
    const subset = SUBSETS[Math.min(9, Math.max(1, count - 1))] ?? SUBSETS[9]

    return players.map((seat, index) => {
        const offset = (index - myIndex + count) % count
        const slot = offset === 0 ? ME_SLOT : SLOTS[subset[offset - 1] ?? 4]

        return {
            seat,
            offset,
            hue: SEAT_HUES[index % SEAT_HUES.length],
            ...slot,
            isMe: seat.id === props.myId,
            isTurn: props.live && seat.id === props.table.current_player_id,
            isWinner: props.winnerId != null && String(seat.id) === props.winnerId,
            bank: bankTotal(seat),
            sets: Object.entries(seat.properties).map(([color, group]) => ({
                color,
                cards: group.cards,
                complete: isCompleteSet(seat, color),
                house: Boolean(group.house),
                hotel: Boolean(group.hotel),
            })),
        }
    })
})

const stageStyle = computed(() => ({
    '--u': `${unit.value}px`,
    '--cw': CARD_W,
    '--ch': CARD_W * SM_RATIO,
    '--co': CARD_W * SM_RATIO * CARD_OVERLAP,
    '--cs': (CARD_W * unit.value) / SM_CARD_PX,
    '--cols': COLUMNS_PER_ROW,
    // Discard art is laid out at 114px wide; scale it to the pile's 6.6u.
    '--ds': (6.6 * unit.value) / 114,
}))

function columnHeight(count: number): string {
    return `calc(var(--u) * (var(--ch) + ${Math.max(0, count - 1)} * var(--co)))`
}

function isPickable(playerId: number): boolean {
    return props.pickTargets?.includes(playerId) ?? false
}

// With a targeting card selected a tap on a plate chooses that player;
// otherwise it opens their details.
function openPlayer(playerId: number) {
    if (isPickable(playerId)) emit('pick-target', playerId)
    else emit('open-player', playerId)
}

function openSet(playerId: number, color: string) {
    emit('open-set', playerId, color)
}

function seatLabel(entry: (typeof seats.value)[number]): string {
    return t('Hand :count · Bank :bank M · :groups property groups', {
        count: entry.seat.hand_count,
        bank: entry.bank,
        groups: entry.sets.length,
    })
}
</script>

<template>
    <section ref="stageEl" class="tb-stage" :style="stageStyle">
        <!-- Piles live in the corner, off the table, so the felt is free for cards. -->
        <div class="tb-corner">
            <button
                type="button"
                class="tb-pile tb-pile--draw"
                :class="{ 'tb-pile--ready': canDraw }"
                :aria-label="t('Draw pile:') + ' ' + table.draw_pile_count"
                :disabled="!canDraw"
                @click="emit('draw')"
            >
                <span class="tb-card-back tb-card-back--3"></span>
                <span class="tb-card-back tb-card-back--2"></span>
                <span class="tb-card-back tb-card-back--1"></span>
                <strong class="tb-pile-count">{{ table.draw_pile_count }}</strong>
                <small>{{ t('Draw') }}</small>
            </button>
            <div class="tb-pile tb-pile--discard" :aria-label="t('Discard Pile') + ' ' + table.discard_pile.length">
                <div v-if="table.discard_pile.length > 0" class="tb-discard">
                    <slot name="discard" />
                </div>
                <span v-else class="tb-pile-empty"></span>
                <small>{{ t('Discard Pile') }}</small>
            </div>
        </div>

        <div class="tb-plane">
            <div class="tb-rim"></div>
            <div class="tb-felt"></div>

            <div
                v-for="entry in seats"
                :key="`pod-${entry.seat.id}`"
                class="tb-pod"
                :class="`tb-pod--${entry.side}`"
                :style="{ left: `${entry.podX}%`, top: `${entry.podY}%`, '--seat-hue': entry.hue }"
            >
                <button
                    v-for="set in entry.sets"
                    :key="set.color"
                    type="button"
                    class="tb-col"
                    :class="{ 'tb-col--complete': set.complete }"
                    :style="{ height: columnHeight(set.cards.length) }"
                    :aria-label="t('View :name’s :color Manti2a in detail', { name: playerName(entry.seat.id), color: colorLabel(set.color) })"
                    :title="colorLabel(set.color)"
                    @click="openSet(entry.seat.id, set.color)"
                >
                    <span
                        v-for="(cardId, index) in set.cards"
                        :key="cardId"
                        class="tb-card"
                        :style="{ top: `calc(var(--u) * var(--co) * ${index})`, zIndex: index }"
                    >
                        <MasrawyCard
                            v-if="table.catalog[cardId]"
                            :entry="table.catalog[cardId]"
                            :active-color="table.catalog[cardId].type === 'wildcard' ? set.color : undefined"
                        />
                    </span>
                    <span v-if="set.house || set.hotel" class="tb-col-mark" aria-hidden="true">{{ set.hotel ? '▲▲' : '▲' }}</span>
                </button>
            </div>

            <div
                v-for="entry in seats"
                :key="entry.seat.id"
                class="tb-seat"
                :class="[`tb-seat--${entry.side}`, { 'tb-seat--me': entry.isMe, 'tb-seat--turn': entry.isTurn, 'tb-seat--winner': entry.isWinner, 'tb-seat--pick': isPickable(entry.seat.id), 'tb-seat--picked': pickedId === entry.seat.id }]"
                :style="{ left: `${entry.x}%`, top: `${entry.y}%`, '--seat-hue': entry.hue }"
            >
                <!-- The badges live beside the button, not in it: a <button> clips what pokes out of its box. -->
                <div class="tb-plate-wrap">
                    <span v-if="entry.isWinner" class="tb-crown" aria-hidden="true">👑</span>
                    <span v-if="entry.isMe && live && isMyTurn" class="tb-plays">{{ t(':count plays left', { count: playsLeft }) }}</span>
                    <button
                        type="button"
                        class="tb-plate"
                        :aria-label="`${playerName(entry.seat.id)} — ${seatLabel(entry)}`"
                        :title="t('Tap for details')"
                        @click="openPlayer(entry.seat.id)"
                    >
                        <span class="tb-id">
                            <strong class="tb-name">{{ playerName(entry.seat.id) }}</strong>
                            <span class="tb-stats">
                                <span class="tb-bank" :title="t('Bank')">{{ entry.bank }}M</span>
                                <span class="tb-hand" :title="t('Hand cards')">▤ {{ entry.seat.hand_count }}</span>
                            </span>
                        </span>
                        <span v-if="revealHands" class="tb-more" :title="t('View hand')" aria-hidden="true">⌄</span>
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.tb-stage {
    --tilt: 40deg;
    /* Height of the stage as a fraction of its width: the 2.84:1 plane (89u wide)
       squashed by cos(tilt), plus 8.5u headroom and 6.5u footroom for the plates. */
    --tb-k: calc(0.89 / 2.84 * cos(var(--tilt)) + 0.15);
    /* Fit the viewport: Table.vue sets --md-reserve to the height taken by
       everything else on screen (summary, hand dock…). */
    --tb-w: min(calc(100vw - 1rem), 1100px, max(18rem, calc((100dvh - var(--md-reserve, 22rem)) / var(--tb-k))));
    --u: 5px;
    --f: max(9px, calc(var(--u) * 1.7));
    position: relative;
    /* Show.vue keeps its content in a 42rem column; the table breaks out of
       it (margins are relative to that column) so it can use the screen. */
    width: var(--tb-w);
    height: calc(var(--tb-w) * var(--tb-k));
    margin-block: var(--tb-top, 0.25rem) 0;
    margin-inline: calc(50% - var(--tb-w) / 2);
    perspective: calc(var(--tb-w) * 2.2);
    perspective-origin: 50% 20%;
    font-size: var(--f);
}

.tb-plane {
    position: absolute;
    /* 89u wide: an 11u strip on the left holds the piles; the footroom below
       holds the bottom row of plates. */
    left: 11%;
    right: 0;
    bottom: 6.5%;
    aspect-ratio: 2.84 / 1;
    transform-style: preserve-3d;
    transform: rotateX(var(--tilt));
    transform-origin: 50% 100%;
}

.tb-rim,
.tb-felt {
    position: absolute;
    border-radius: 4%/14%;
}

/* Outer rim with a stacked-layer edge so the table reads as a thick slab. */
.tb-rim {
    inset: 0;
    background: linear-gradient(180deg, #3b4458, #1b2230);
    transform-style: preserve-3d;
    box-shadow: 0 0 0 calc(var(--u) * 0.3) rgb(255 255 255 / 8%);
}

.tb-rim::before,
.tb-rim::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
}

.tb-rim::before {
    background: #141a26;
    transform: translateZ(calc(var(--u) * -1.2));
}

.tb-rim::after {
    background: #0b1019;
    transform: translateZ(calc(var(--u) * -2.4));
    box-shadow: 0 calc(var(--u) * 4) calc(var(--u) * 7) rgb(0 0 0 / 55%);
}

.tb-felt {
    inset: 5% 1.2%;
    border-radius: 3.6%/12.5%;
    background: radial-gradient(ellipse at 50% 45%, #1f6b4a, #0f3d2b 75%);
    box-shadow: inset 0 0 calc(var(--u) * 4) rgb(0 0 0 / 55%);
}

/* Everything that stands on the table is counter-rotated about its base so
   it stays upright and readable while the surface is tilted. */
.tb-pod,
.tb-seat {
    position: absolute;
    transform-style: flat;
    transform-origin: 50% 100%;
}

/* ---- Pods: each player's property sets, as real stacked cards ---------- */
.tb-pod {
    box-sizing: border-box;
    width: max-content;
    max-width: calc(var(--u) * (var(--cols) * (var(--cw) + 0.4) + 0.4));
    min-height: 0;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: center;
    gap: calc(var(--u) * 0.4);
    padding: calc(var(--u) * 0.4);
    border-radius: calc(var(--u) * 0.8);
    background: hsl(var(--seat-hue) 45% 18% / 45%);
    transform: translate(-50%, -100%) rotateX(calc(var(--tilt) * -1));
    z-index: 1;
}

.tb-pod:empty {
    display: none;
}

.tb-col {
    position: relative;
    flex: 0 0 auto;
    width: calc(var(--u) * var(--cw));
    padding: 0;
    border: 0;
    border-radius: calc(var(--u) * 0.3);
    background: none;
    cursor: pointer;
}

/* The card art is 108 × 165 px; each one is scaled down to the column width and
   offset so every card's header strip shows. */
.tb-card {
    position: absolute;
    left: 0;
    width: calc(var(--u) * var(--cw));
    height: calc(var(--u) * var(--ch));
    pointer-events: none;
}

.tb-card :deep(.mc-card) {
    position: absolute;
    top: 0;
    left: 0;
    transform: scale(var(--cs));
    transform-origin: top left;
    transition: none;
}

.tb-card :deep(.mc-card--flipped) {
    transform: translate(calc(100% * var(--cs)), calc(100% * var(--cs))) rotate(180deg) scale(var(--cs));
}

.tb-col:hover,
.tb-col:focus-visible {
    outline: 2px solid var(--rc-primary, #f59e0b);
    outline-offset: 1px;
}

.tb-col--complete {
    box-shadow: 0 0 0 max(1.5px, calc(var(--u) * 0.3)) #fde047, 0 0 calc(var(--u) * 1.2) rgb(253 224 71 / 55%);
}

.tb-col-mark {
    position: absolute;
    right: 0;
    bottom: calc(100% + 1px);
    font-size: 0.7em;
    line-height: 1;
    color: #fde047;
    text-shadow: 0 1px 2px rgb(0 0 0 / 80%);
}

/* ---- Plates: outside the rim ------------------------------------------- */
.tb-seat {
    width: calc(var(--u) * 14);
    display: flex;
    flex-direction: column;
    align-items: center;
    transform: translate(-50%, -100%) rotateX(calc(var(--tilt) * -1));
    z-index: 2;
}

.tb-seat--turn {
    z-index: 3;
}

.tb-plate-wrap {
    position: relative;
    width: 100%;
}

.tb-plate {
    position: relative;
    box-sizing: border-box;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.1em;
    padding: 0.3em 0.45em;
    border-radius: 1em;
    font: inherit;
    text-align: center;
    color: #f8fafc;
    background: linear-gradient(180deg, hsl(var(--seat-hue) 60% 42%), hsl(var(--seat-hue) 62% 26%));
    border: max(1.5px, 0.18em) solid rgb(255 255 255 / 55%);
    box-shadow: 0 calc(var(--u) * 0.6) calc(var(--u) * 1.4) rgb(0 0 0 / 45%);
    cursor: pointer;
}

.tb-seat--turn .tb-plate {
    border-color: var(--rc-primary, #f59e0b);
    box-shadow: 0 0 0 calc(var(--u) * 0.4) rgb(245 158 11 / 45%), 0 calc(var(--u) * 0.6) calc(var(--u) * 1.8) rgb(0 0 0 / 55%);
    animation: tb-turn-pulse 1.8s ease-in-out infinite;
}

@keyframes tb-turn-pulse {
    50% {
        box-shadow: 0 0 0 calc(var(--u) * 0.9) rgb(245 158 11 / 15%), 0 calc(var(--u) * 0.6) calc(var(--u) * 1.8) rgb(0 0 0 / 55%);
    }
}

.tb-plate:hover,
.tb-plate:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 2px;
}

.tb-crown {
    position: absolute;
    top: -1.5em;
    left: 50%;
    transform: translateX(-50%);
    font-size: 1.6em;
    line-height: 1;
    color: #fde047;
    text-shadow: 0 2px 4px rgb(0 0 0 / 70%);
}

.tb-seat--winner .tb-plate {
    border-color: #fde047;
    box-shadow: 0 0 0 calc(var(--u) * 0.4) rgb(253 224 71 / 45%), 0 calc(var(--u) * 0.6) calc(var(--u) * 1.8) rgb(0 0 0 / 55%);
}

.tb-id {
    min-width: 0;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    line-height: 1.15;
}

.tb-name {
    display: block;
    max-width: 100%;
    overflow: hidden;
    font-size: 1em;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.tb-stats {
    display: flex;
    justify-content: center;
    white-space: nowrap;
    gap: 0.45em;
    font-size: 0.85em;
    opacity: 0.92;
}

.tb-bank {
    font-weight: 800;
}

.tb-more {
    font-size: 1em;
    line-height: 0.8;
    opacity: 0.8;
}

.tb-plays {
    position: absolute;
    left: 50%;
    bottom: calc(100% + 0.25em);
    transform: translateX(-50%);
    padding: 0.1em 0.6em;
    border-radius: 999px;
    font-size: 0.85em;
    font-weight: 700;
    white-space: nowrap;
    color: #0f172a;
    background: var(--rc-primary, #f59e0b);
}

/* ---- Corner piles ------------------------------------------------------- */
.tb-corner {
    position: absolute;
    top: 8.5%;
    left: 0;
    z-index: 4;
    width: calc(var(--u) * 10);
    display: flex;
    flex-direction: column;
    gap: calc(var(--u) * 0.8);
    align-items: center;
}

.tb-pile {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #f8fafc;
    font-size: max(8px, calc(var(--u) * 1.4));
    text-shadow: 0 1px 3px rgb(0 0 0 / 80%);
}

/* The draw pile is a stack of backs the size of one table card, a touch bigger. */
.tb-pile--draw {
    padding: 0;
    border: 0;
    background: none;
    font-family: inherit;
    cursor: default;
}

.tb-pile--ready {
    cursor: pointer;
}

.tb-pile--ready .tb-card-back--1 {
    animation: tb-draw-ready 1.4s ease-in-out infinite;
}

@keyframes tb-draw-ready {
    50% {
        box-shadow: 0 0 0 calc(var(--u) * 0.8) rgb(245 158 11 / 55%), 0 2px 4px rgb(0 0 0 / 40%);
    }
}

/* Plates that can be picked as a card's target glow; the chosen one is solid. */
.tb-seat--pick .tb-plate {
    border-color: #fde047;
    animation: tb-pick 1.2s ease-in-out infinite;
}

.tb-seat--picked .tb-plate {
    border-color: #fde047;
    background: linear-gradient(180deg, #ca8a04, #854d0e);
    animation: none;
}

@keyframes tb-pick {
    50% {
        box-shadow: 0 0 0 calc(var(--u) * 0.9) rgb(253 224 71 / 40%), 0 calc(var(--u) * 0.6) calc(var(--u) * 1.8) rgb(0 0 0 / 55%);
    }
}

.tb-pile:not(.tb-pile--discard) {
    width: calc(var(--u) * 7.4);
    padding-top: calc(var(--u) * 10.6);
}

.tb-card-back {
    position: absolute;
    top: 0;
    left: 0;
    width: calc(var(--u) * 6.6);
    height: calc(var(--u) * 10);
    box-sizing: border-box;
    border-radius: calc(var(--u) * 0.9);
    border: max(1.5px, calc(var(--u) * 0.3)) solid #fef3c7;
    background: repeating-linear-gradient(45deg, #b45309 0 6px, #92400e 6px 12px);
    box-shadow: 0 2px 4px rgb(0 0 0 / 40%);
}

.tb-card-back--2 {
    transform: translate(calc(var(--u) * 0.4), calc(var(--u) * 0.4));
}

.tb-card-back--3 {
    transform: translate(calc(var(--u) * 0.8), calc(var(--u) * 0.8));
}

.tb-pile-count {
    position: absolute;
    top: calc(var(--u) * 3.2);
    left: 0;
    width: calc(var(--u) * 6.6);
    text-align: center;
    font: 900 max(11px, calc(var(--u) * 2.4)) var(--rc-font-display, serif);
}

.tb-pile--discard {
    min-width: calc(var(--u) * 6.6);
}

.tb-discard {
    position: relative;
    width: calc(var(--u) * 6.6);
    height: calc(var(--u) * 10);
    margin-bottom: calc(var(--u) * 0.6);
}

/* The slot renders Table.vue's full-size discard stack (114 × 171); --ds
   scales it down to the pile's card size. */
.tb-discard :deep(.md-discard-stack) {
    position: absolute;
    top: 0;
    left: 0;
    width: 114px;
    margin: 0;
    transform: scale(var(--ds, 0.5));
    transform-origin: top left;
}

.tb-pile-empty {
    box-sizing: border-box;
    width: calc(var(--u) * 6.6);
    height: calc(var(--u) * 10);
    margin-bottom: calc(var(--u) * 0.6);
    border: 2px dashed rgb(255 255 255 / 45%);
    border-radius: calc(var(--u) * 0.9);
}

@media (prefers-reduced-motion: reduce) {
    .tb-seat--turn .tb-plate,
    .tb-seat--pick .tb-plate,
    .tb-pile--ready .tb-card-back--1 {
        animation: none;
    }
}
</style>
