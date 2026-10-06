<script setup lang="ts">
/**
 * Masrawy Deal's rounded-rectangle 3D table. Pure presentation: it receives the
 * table state and stands one seat plate per player on a tilted (CSS perspective)
 * plane, with the viewer always at the bottom whatever their seat order.
 * Nothing here decides game rules — tapping a set chip just asks Table.vue to
 * open its existing property-set modal.
 *
 * Everything is sized from one unit, --u (1% of the board's width, measured with
 * a ResizeObserver), so plates, chips and piles shrink with the board instead of
 * keeping fixed pixel sizes. Seats sit in named slots around the rectangle's
 * edge (bottom = viewer, then clockwise); the slot list depends only on the
 * player count, so the same component serves 2 to 10 players.
 *
 * The discard pile's card art comes in through the `discard` slot so card
 * rendering (rent charts, set sizes…) stays in Table.vue.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { MasrawySeat, MasrawyTableState } from '@/types/room'
import { useI18n } from '@/i18n'

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
    playerName: (id: number | string) => string
    colorLabel: (color: string) => string
}>()

const emit = defineEmits<{
    (e: 'open-set', playerId: number, color: string): void
    (e: 'open-player', playerId: number): void
}>()

const { t } = useI18n()

// Same values as Card.vue's COLOR_HEX (that map is private to its SFC), so a
// set chip matches the property cards it summarises.
const COLOR_HEX: Record<string, string> = {
    brown: '#542810',
    light_blue: '#38bdf8',
    pink: '#db2777',
    orange: '#ea580c',
    red: '#dc2626',
    yellow: '#fef08a',
    green: '#15803d',
    dark_blue: '#1d4ed8',
    railroad: '#111111',
    utility: '#a1c1a6',
}

// Light chip colours need dark text to stay readable.
const DARK_TEXT_COLORS = new Set(['yellow', 'utility', 'light_blue'])

const SEAT_HUES = [200, 28, 52, 150, 280, 340, 175, 12, 235, 95]

// Plate width per size tier, in board units (1u = 1% of board width), and the
// font size unit that goes with it.
const TIERS = [
    { max: 4, width: 21, font: 2.4 },
    { max: 6, width: 17, font: 2.0 },
    { max: 8, width: 14, font: 1.75 },
    { max: 10, width: 12, font: 1.55 },
]

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

const playerCount = computed(() => props.table.players.length)
const tier = computed(() => TIERS.findIndex(entry => playerCount.value <= entry.max))
const tierSpec = computed(() => TIERS[tier.value < 0 ? TIERS.length - 1 : tier.value])

type Side = 'top' | 'left' | 'right' | 'bottom'
type Slot = { side: Side; x: number; y: number; podX: number; podY: number }

// The table surface is kept clear for the players' cards: every plate stands
// OUTSIDE the rim (left, right, top, and the viewer below), and each player's
// property sets lie on the felt in a "pod" next to their plate.
// x/y are percentages of the plane; plates anchor at their bottom edge, so
// y = 0 puts a plate right above the top rim and negative/over-100 values
// push it further out. The plane is 74u wide inside a 100u stage, leaving a
// 13u gutter each side for the side plates.
const SIDE_PLATE = 12
const GUTTER_PCT = ((SIDE_PLATE / 2 + 0.8) * 100) / 74
const SIDE_Y: Record<number, number[]> = { 1: [66], 2: [88, 52], 3: [94, 66, 38] }
const TOP_X: Record<number, number[]> = { 1: [50], 2: [34, 66], 3: [24, 50, 76] }
// How the other players split between left / top / right, by player count - 1.
const SPLIT: Record<number, [number, number, number]> = {
    1: [0, 1, 0],
    2: [0, 2, 0],
    3: [1, 1, 1],
    4: [1, 2, 1],
    5: [1, 3, 1],
    6: [2, 2, 2],
    7: [2, 3, 2],
    8: [3, 2, 3],
    9: [3, 3, 3],
}

// Slots for the *other* players, clockwise from the viewer's left hand (the
// viewer sits below the table, centred).
const otherSlots = computed<Slot[]>(() => {
    const [left, top, right] = SPLIT[Math.min(9, Math.max(1, playerCount.value - 1))] ?? [0, 1, 0]
    const slots: Slot[] = []

    // Left side runs bottom → top, then the top row left → right, then the
    // right side top → bottom (clockwise from the viewer).
    ;[...(SIDE_Y[left] ?? [])].forEach(y => slots.push({ side: 'left', x: -GUTTER_PCT, y, podX: 15, podY: y }))
    ;(TOP_X[top] ?? []).forEach(x => slots.push({ side: 'top', x, y: 0, podX: x, podY: 27 }))
    ;[...(SIDE_Y[right] ?? [])].reverse().forEach(y => slots.push({ side: 'right', x: 100 + GUTTER_PCT, y, podX: 85, podY: y }))

    return slots
})

const ME_SLOT: Slot = { side: 'bottom', x: 50, y: 116, podX: 50, podY: 90 }

const seats = computed(() => {
    const players = props.table.players
    const count = players.length
    const myIndex = Math.max(0, players.findIndex(seat => seat.id === props.myId))

    return players.map((seat, index) => {
        const offset = (index - myIndex + count) % count
        const slot = offset === 0 ? ME_SLOT : (otherSlots.value[offset - 1] ?? { side: 'top' as Side, x: 50, y: 0, podX: 50, podY: 27 })

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
                count: group.cards.length,
                size: props.table.set_size[color] ?? group.cards.length,
                complete: isCompleteSet(seat, color),
                house: Boolean(group.house),
                hotel: Boolean(group.hotel),
            })),
        }
    })
})

const stageStyle = computed(() => ({
    '--u': `${unit.value}px`,
    '--pw': tierSpec.value.width,
    '--sw': SIDE_PLATE,
    '--fs': tierSpec.value.font,
    // Discard art is laid out at 114px wide; scale it to 6 board units.
    '--ds': (6 * unit.value) / 114,
}))

function openPlayer(playerId: number) {
    emit('open-player', playerId)
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
    <section
        ref="stageEl"
        class="tb-stage"
        :class="[`tb-stage--tier${tier < 0 ? 3 : tier}`]"
        :style="stageStyle"
    >
        <div class="tb-plane">
            <div class="tb-rim"></div>
            <div class="tb-felt"></div>

            <div class="tb-center">
                <div class="tb-pile" :aria-label="t('Draw pile:') + ' ' + table.draw_pile_count">
                    <span class="tb-card-back tb-card-back--3"></span>
                    <span class="tb-card-back tb-card-back--2"></span>
                    <span class="tb-card-back tb-card-back--1"></span>
                    <strong class="tb-pile-count">{{ table.draw_pile_count }}</strong>
                    <small>{{ t('Draw') }}</small>
                </div>
                <div class="tb-pile tb-pile--discard" :aria-label="t('Discard Pile') + ' ' + table.discard_pile.length">
                    <div v-if="table.discard_pile.length > 0" class="tb-discard">
                        <slot name="discard" />
                    </div>
                    <span v-else class="tb-pile-empty"></span>
                    <small>{{ t('Discard Pile') }}</small>
                </div>
            </div>

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
                    class="tb-set"
                    :class="{ 'tb-set--complete': set.complete }"
                    :style="{ '--set-color': COLOR_HEX[set.color] ?? '#64748b', '--set-text': DARK_TEXT_COLORS.has(set.color) ? '#111827' : '#ffffff' }"
                    :aria-label="t('View :name’s :color Manti2a in detail', { name: playerName(entry.seat.id), color: colorLabel(set.color) })"
                    :title="colorLabel(set.color)"
                    @click="openSet(entry.seat.id, set.color)"
                >
                    <span class="tb-set-count">{{ set.count }}/{{ set.size }}</span>
                    <span v-if="set.house || set.hotel" class="tb-set-mark" aria-hidden="true">{{ set.hotel ? '▲▲' : '▲' }}</span>
                </button>
            </div>

            <div
                v-for="entry in seats"
                :key="entry.seat.id"
                class="tb-seat"
                :class="[`tb-seat--${entry.side}`, { 'tb-seat--me': entry.isMe, 'tb-seat--turn': entry.isTurn, 'tb-seat--winner': entry.isWinner }]"
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
                        <span class="tb-avatar" aria-hidden="true">{{ playerName(entry.seat.id).charAt(0).toUpperCase() }}</span>
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
    --tilt: 45deg;
    /* Height of the stage as a fraction of its width: the 2:1 plane squashed by
       cos(tilt), plus headroom for the top row of standing plates. */
    --tb-k: calc(0.74 / 1.8 * cos(var(--tilt)) + 0.17);
    /* Fit the viewport: Table.vue sets --md-reserve to the height taken by
       everything else on screen (summary, hand dock…). */
    --tb-w: min(calc(100vw - 1rem), 1100px, max(18rem, calc((100dvh - var(--md-reserve, 22rem)) / var(--tb-k))));
    --u: 5px;
    --f: max(9px, calc(var(--u) * var(--fs, 2.4)));
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
    /* 74u wide inside the 100u stage: the gutters hold the side plates; the
       footroom below holds the viewer's plate. */
    left: 13%;
    right: 13%;
    bottom: 6%;
    aspect-ratio: 1.8 / 1;
    transform-style: preserve-3d;
    transform: rotateX(var(--tilt));
    transform-origin: 50% 100%;
}

.tb-rim,
.tb-felt {
    position: absolute;
    border-radius: 9%/18%;
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
    inset: 3.2% 1.8%;
    border-radius: 8%/16%;
    background: radial-gradient(ellipse at 50% 45%, #1f6b4a, #0f3d2b 75%);
    box-shadow: inset 0 0 calc(var(--u) * 4) rgb(0 0 0 / 55%);
}

/* Everything that stands on the table is counter-rotated about its base so
   it stays upright and readable while the surface is tilted. */
.tb-center,
.tb-pod,
.tb-seat {
    position: absolute;
    transform-style: flat;
    transform-origin: 50% 100%;
}

.tb-center {
    left: 50%;
    top: 67%;
    display: flex;
    gap: calc(var(--u) * 2.2);
    align-items: flex-end;
    transform: translate(-50%, -100%) rotateX(calc(var(--tilt) * -1));
}

.tb-seat {
    width: calc(var(--u) * var(--pw));
    display: flex;
    flex-direction: column;
    gap: calc(var(--u) * 0.5);
    align-items: center;
    transform: translate(-50%, -100%) rotateX(calc(var(--tilt) * -1));
    z-index: 1;
}

.tb-seat--turn {
    z-index: 2;
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
    align-items: center;
    gap: 0.35em;
    padding: 0.3em 0.6em 0.3em 0.3em;
    border-radius: 999px;
    font: inherit;
    text-align: start;
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

.tb-more {
    flex: 0 0 auto;
    margin-inline-start: auto;
    font-size: 1.1em;
    line-height: 1;
    opacity: 0.8;
}

.tb-avatar {
    flex: 0 0 auto;
    width: 2.1em;
    height: 2.1em;
    display: grid;
    place-items: center;
    border-radius: 50%;
    font-family: var(--rc-font-display, serif);
    font-weight: 800;
    background: rgb(0 0 0 / 30%);
    border: max(1px, 0.12em) solid rgb(255 255 255 / 70%);
}

.tb-id {
    min-width: 0;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
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
    white-space: nowrap;
    gap: 0.45em;
    font-size: 0.85em;
    opacity: 0.92;
}

.tb-bank {
    font-weight: 800;
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

/* Property sets lie on the felt beside their owner's plate. */
.tb-pod {
    width: max-content;
    max-width: calc(var(--u) * 21);
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.2em;
    box-sizing: border-box;
    padding: 0.25em;
    border-radius: 0.6em;
    background: hsl(var(--seat-hue) 45% 18% / 55%);
    border: 1px solid hsl(var(--seat-hue) 60% 55% / 70%);
    transform: translate(-50%, -100%) rotateX(calc(var(--tilt) * -1));
    z-index: 1;
}

.tb-pod--top {
    max-width: calc(var(--u) * 23);
}

.tb-pod:empty {
    display: none;
}

/* Side plates are narrow columns in the gutter: name over stats. */
.tb-seat--left,
.tb-seat--right {
    width: calc(var(--u) * var(--sw, 12));
}

.tb-seat--left .tb-plate,
.tb-seat--right .tb-plate {
    flex-direction: column;
    gap: 0.1em;
    padding: 0.3em 0.4em;
    border-radius: 1em;
    text-align: center;
}

.tb-seat--left .tb-avatar,
.tb-seat--right .tb-avatar,
.tb-seat--left .tb-more,
.tb-seat--right .tb-more {
    display: none;
}

.tb-seat--left .tb-id,
.tb-seat--right .tb-id {
    width: 100%;
    flex: 0 0 auto;
    align-items: center;
}

.tb-seat--left .tb-stats,
.tb-seat--right .tb-stats {
    justify-content: center;
}

.tb-set {
    display: inline-flex;
    align-items: center;
    gap: 0.2em;
    min-width: 2.3em;
    padding: 0.12em 0.4em;
    border: max(1px, 0.14em) solid rgb(255 255 255 / 70%);
    border-radius: 0.35em;
    font: 800 0.8em var(--rc-font-body, sans-serif);
    color: var(--set-text, #fff);
    background: var(--set-color);
    box-shadow: 0 2px 5px rgb(0 0 0 / 45%);
    cursor: pointer;
}

.tb-set:hover,
.tb-set:focus-visible {
    outline: 2px solid var(--rc-primary, #f59e0b);
    outline-offset: 1px;
}

.tb-set--complete {
    border-color: #fde047;
    box-shadow: 0 0 0 2px rgb(253 224 71 / 50%), 0 2px 5px rgb(0 0 0 / 45%);
}

.tb-set-mark {
    font-size: 0.7em;
}

/* Dense tables: drop the decorative bits first, keep name + bank + sets. */
.tb-stage--tier2 .tb-avatar,
.tb-stage--tier3 .tb-avatar,
.tb-stage--tier3 .tb-more {
    display: none;
}

.tb-stage--tier3 .tb-hand {
    display: none;
}

.tb-stage--tier3 .tb-set {
    min-width: 0;
    padding: 0.05em 0.25em;
    font-size: 0.72em;
}

.tb-pile {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    color: #f8fafc;
    font-size: max(8px, calc(var(--u) * 1.5));
    text-shadow: 0 1px 3px rgb(0 0 0 / 80%);
}

/* Both piles are 6u × 9u cards. */
.tb-pile:not(.tb-pile--discard) {
    width: calc(var(--u) * 7);
    padding-top: calc(var(--u) * 9.9);
}

.tb-card-back {
    position: absolute;
    top: 0;
    left: 0;
    width: calc(var(--u) * 6);
    height: calc(var(--u) * 9);
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
    top: calc(var(--u) * 2.8);
    left: 0;
    width: calc(var(--u) * 6);
    text-align: center;
    font: 900 max(11px, calc(var(--u) * 2.6)) var(--rc-font-display, serif);
}

.tb-pile--discard {
    min-width: calc(var(--u) * 6);
}

.tb-discard {
    position: relative;
    width: calc(var(--u) * 6);
    height: calc(var(--u) * 9);
    margin-bottom: calc(var(--u) * 0.9);
}

/* The slot renders Table.vue's full-size discard stack (114 × 171); --ds
   scales it down to the board's card size. */
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
    width: calc(var(--u) * 6);
    height: calc(var(--u) * 9);
    margin-bottom: calc(var(--u) * 0.9);
    border: 2px dashed rgb(255 255 255 / 45%);
    border-radius: calc(var(--u) * 0.9);
}

@media (prefers-reduced-motion: reduce) {
    .tb-seat--turn .tb-plate {
        animation: none;
    }
}
</style>
