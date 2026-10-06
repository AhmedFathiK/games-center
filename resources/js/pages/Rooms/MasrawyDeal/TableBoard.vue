<script setup lang="ts">
/**
 * Masrawy Deal's round 3D table. Pure presentation: it receives the table
 * state and draws one sector per seat on a tilted (CSS perspective) plane,
 * with the viewer always seated at the bottom whatever their seat order.
 * Nothing here decides game rules — tapping a set chip just asks Table.vue
 * to open its existing property-set modal.
 *
 * Seat geometry depends only on the player count (sector = 360° / players),
 * so the same component serves 2 players today and more later; past
 * COMPACT_FROM players the seat plates shrink to dots-and-counts.
 *
 * The discard pile's card art comes in through the `discard` slot so card
 * rendering (rent charts, set sizes…) stays in Table.vue.
 */
import { computed } from 'vue'
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
const COMPACT_FROM = 7
const DENSE_FROM = 5
// Seat anchors sit on an ellipse inside the plane (percent of its box).
const RADIUS_X = 38
const RADIUS_Y = 36

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
const compact = computed(() => playerCount.value >= COMPACT_FROM)
// From 5 players up phones get the small plates too (desktop keeps full size).
const dense = computed(() => playerCount.value >= DENSE_FROM)

const seats = computed(() => {
    const players = props.table.players
    const count = players.length
    const myIndex = Math.max(0, players.findIndex(seat => seat.id === props.myId))

    return players.map((seat, index) => {
        const offset = (index - myIndex + count) % count
        // 90° = bottom of the screen; turn order runs clockwise from there.
        const radians = ((90 + (offset * 360) / count) * Math.PI) / 180

        return {
            seat,
            offset,
            hue: SEAT_HUES[index % SEAT_HUES.length],
            x: 50 + RADIUS_X * Math.cos(radians),
            y: 50 + RADIUS_Y * Math.sin(radians),
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

// One coloured wedge per seat, listed in seat-offset order so wedge k is the
// k-th seat clockwise from the viewer. The viewer's wedge is centred on the
// bottom (conic 180°), hence the half-wedge start offset.
const ringBackground = computed(() => {
    const count = playerCount.value
    if (count === 0) return 'none'

    const width = 360 / count
    const byOffset = [...seats.value].sort((a, b) => a.offset - b.offset)
    const stops = byOffset.map((entry, k) => {
        const light = entry.isTurn ? 50 : 33
        const from = k * width
        const to = (k + 1) * width

        return `hsl(${entry.hue} 55% ${light}%) ${from + 0.6}deg ${to - 0.6}deg, #0b1220 ${to - 0.6}deg ${to + 0.6}deg`
    })

    return `conic-gradient(from ${180 - width / 2}deg, ${stops.join(', ')})`
})

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
    <section class="tb-stage" :class="{ 'tb-stage--compact': compact, 'tb-stage--dense': dense }" :style="{ '--tb-players': playerCount }">
        <div class="tb-plane">
            <div class="tb-rim"></div>
            <div class="tb-ring" :style="{ background: ringBackground }"></div>
            <div class="tb-hub"></div>

            <div class="tb-center">
                <div class="tb-pile" :aria-label="t('Draw pile:') + ' ' + table.draw_pile_count">
                    <span class="tb-pile-back tb-pile-back--3"></span>
                    <span class="tb-pile-back tb-pile-back--2"></span>
                    <span class="tb-pile-back tb-pile-back--1"></span>
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
                :key="entry.seat.id"
                class="tb-seat"
                :class="{ 'tb-seat--me': entry.isMe, 'tb-seat--turn': entry.isTurn, 'tb-seat--winner': entry.isWinner }"
                :style="{ left: `${entry.x}%`, top: `${entry.y}%`, '--seat-hue': entry.hue }"
            >
                <!-- The badges live beside the button, not in it: a <button> clips what pokes out of its box. -->
                <div class="tb-plate-wrap">
                <span v-if="entry.isWinner" class="tb-crown" aria-hidden="true">♛</span>
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
                    <span class="tb-more" aria-hidden="true">⌄</span>
                </button>
                </div>
                <small v-if="revealHands" class="tb-reveal">{{ t('View hand') }}</small>

                <div v-if="entry.sets.length" class="tb-sets">
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
            </div>
        </div>
    </section>
</template>

<style scoped>
.tb-stage {
    --tilt: 54deg;
    --tb-plate-w: 150px;
    /* Fit the viewport: Table.vue sets --md-reserve to the height taken by
       everything else on screen (summary, hand dock…). */
    --tb-w: min(calc(100vw - 2rem), 1120px, max(26rem, calc((100dvh - var(--md-reserve, 34rem)) * 100 / 47)));
    position: relative;
    /* Room for the top seat's plate, which stands above the table's rim. */
    margin-top: var(--tb-top, 1rem);
    /* Show.vue keeps its content in a 42rem column; the table breaks out of
       it (margins are relative to that column) so it can use the screen. */
    width: var(--tb-w);
    margin-inline: calc(50% - var(--tb-w) / 2);
    /* The plane is 16:11 and gets foreshortened by cos(tilt); reserve that
       height plus headroom for the standing seat plates. */
    aspect-ratio: 100 / 47;
    perspective: 1400px;
    perspective-origin: 50% 20%;
}

.tb-stage--compact {
    --tb-plate-w: 104px;
}

.tb-plane {
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    aspect-ratio: 16 / 11;
    transform-style: preserve-3d;
    transform: rotateX(var(--tilt));
    transform-origin: 50% 100%;
}

.tb-rim,
.tb-ring,
.tb-hub {
    position: absolute;
    border-radius: 50%;
}

/* Outer rim with a stacked-layer edge so the table reads as a thick disc. */
.tb-rim {
    inset: 3% 2%;
    background: radial-gradient(ellipse at 50% 40%, #3b4458, #1b2230);
    transform-style: preserve-3d;
    box-shadow: 0 0 0 3px rgb(255 255 255 / 8%);
}

.tb-rim::before,
.tb-rim::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
}

.tb-rim::before {
    background: #141a26;
    transform: translateZ(-14px);
}

.tb-rim::after {
    background: #0b1019;
    transform: translateZ(-28px);
    box-shadow: 0 40px 70px rgb(0 0 0 / 55%);
}

.tb-ring {
    inset: 8% 7%;
    box-shadow: inset 0 0 40px rgb(0 0 0 / 45%);
}

.tb-hub {
    inset: 31% 30%;
    background: radial-gradient(ellipse at 50% 40%, #7a5a3a, #3f2d1e);
    box-shadow: 0 0 0 4px rgb(0 0 0 / 35%), inset 0 0 24px rgb(0 0 0 / 45%);
}

/* Everything that stands on the table is counter-rotated about its base so
   it stays upright and readable while the surface is tilted. */
.tb-center,
.tb-seat {
    position: absolute;
    transform-style: flat;
}

.tb-center {
    left: 50%;
    top: 50%;
    display: flex;
    gap: 1.1rem;
    align-items: flex-end;
    transform: translate(-50%, -62%) rotateX(calc(var(--tilt) * -1));
    transform-origin: 50% 100%;
}

.tb-seat {
    width: var(--tb-plate-w);
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    align-items: center;
    transform: translate(-50%, -100%) rotateX(calc(var(--tilt) * -1));
    transform-origin: 50% 100%;
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
    font: inherit;
    text-align: start;
    cursor: pointer;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.65rem 0.3rem 0.4rem;
    border-radius: 999px;
    color: #f8fafc;
    background: linear-gradient(180deg, hsl(var(--seat-hue) 60% 42%), hsl(var(--seat-hue) 62% 26%));
    border: 2px solid rgb(255 255 255 / 55%);
    box-shadow: 0 6px 14px rgb(0 0 0 / 45%);
}

.tb-seat--turn .tb-plate {
    border-color: var(--rc-primary, #f59e0b);
    box-shadow: 0 0 0 3px rgb(245 158 11 / 45%), 0 6px 18px rgb(0 0 0 / 55%);
    animation: tb-turn-pulse 1.8s ease-in-out infinite;
}

@keyframes tb-turn-pulse {
    50% {
        box-shadow: 0 0 0 6px rgb(245 158 11 / 15%), 0 6px 18px rgb(0 0 0 / 55%);
    }
}

.tb-plate:hover,
.tb-plate:focus-visible {
    outline: 2px solid #fff;
    outline-offset: 2px;
}

.tb-more {
    flex: 0 0 auto;
    margin-inline-start: auto;
    padding-inline-start: 0.2rem;
    font-size: 0.9rem;
    line-height: 1;
    opacity: 0.75;
}

.tb-crown {
    position: absolute;
    top: -1.6rem;
    left: 50%;
    transform: translateX(-50%);
    font-size: 1.9rem;
    line-height: 1;
    color: #fde047;
    text-shadow: 0 2px 4px rgb(0 0 0 / 70%);
}

.tb-seat--winner .tb-plate {
    border-color: #fde047;
    box-shadow: 0 0 0 3px rgb(253 224 71 / 45%), 0 6px 18px rgb(0 0 0 / 55%);
}

.tb-reveal {
    order: 3;
    padding: 0.05rem 0.5rem;
    border-radius: 999px;
    font-size: 0.62rem;
    font-weight: 700;
    color: #e2e8f0;
    background: rgb(15 23 42 / 70%);
}

.tb-avatar {
    flex: 0 0 auto;
    width: 2rem;
    height: 2rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    font-family: var(--rc-font-display, serif);
    font-weight: 800;
    background: rgb(0 0 0 / 30%);
    border: 2px solid rgb(255 255 255 / 70%);
}

.tb-id {
    min-width: 0;
    display: flex;
    flex-direction: column;
    line-height: 1.15;
}

.tb-name {
    overflow: hidden;
    font-size: 0.85rem;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.tb-stats {
    display: flex;
    white-space: nowrap;
    gap: 0.45rem;
    font-size: 0.72rem;
    opacity: 0.92;
}

.tb-bank {
    font-weight: 800;
}

.tb-plays {
    position: absolute;
    left: 50%;
    bottom: calc(100% + 0.25rem);
    transform: translateX(-50%);
    padding: 0.1rem 0.55rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    white-space: nowrap;
    color: #0f172a;
    background: var(--rc-primary, #f59e0b);
}

.tb-sets {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 0.2rem;
}

.tb-set {
    display: inline-flex;
    align-items: center;
    gap: 0.2rem;
    min-width: 2.1rem;
    padding: 0.15rem 0.4rem;
    border: 2px solid rgb(255 255 255 / 70%);
    border-radius: 6px;
    font: 800 0.7rem var(--rc-font-body, sans-serif);
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
    font-size: 0.55rem;
}

.tb-stage--compact .tb-name {
    font-size: 0.72rem;
}

.tb-stage--compact .tb-avatar {
    width: 1.6rem;
    height: 1.6rem;
    font-size: 0.75rem;
}

.tb-stage--compact .tb-hand {
    display: none;
}

.tb-pile {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    align-items: center;
    color: #f8fafc;
    font-size: 0.65rem;
    text-shadow: 0 1px 3px rgb(0 0 0 / 80%);
}

.tb-pile-back {
    position: absolute;
    top: 0;
    left: 0;
    width: 48px;
    height: 70px;
    border-radius: 6px;
    border: 2px solid #fef3c7;
    background: repeating-linear-gradient(45deg, #b45309 0 6px, #92400e 6px 12px);
    box-shadow: 0 2px 4px rgb(0 0 0 / 40%);
}

.tb-pile-back--2 {
    transform: translate(3px, 3px);
}

.tb-pile-back--3 {
    transform: translate(6px, 6px);
}

.tb-pile:not(.tb-pile--discard) {
    width: 54px;
    padding-top: 78px;
}

.tb-pile-count {
    position: absolute;
    top: 22px;
    left: 0;
    width: 48px;
    text-align: center;
    font: 900 1.2rem var(--rc-font-display, serif);
}

.tb-pile--discard {
    min-width: 58px;
}

.tb-discard {
    width: 58px;
    height: 87px;
}

/* The slot renders Table.vue's full-size discard stack (114 × 171); scale it
   down to fit the hub. */
.tb-discard :deep(.md-discard-stack) {
    margin: 0;
    transform: scale(0.5);
    transform-origin: top left;
}

.tb-pile-empty {
    width: 48px;
    height: 70px;
    border: 2px dashed rgb(255 255 255 / 45%);
    border-radius: 6px;
}

@media (max-width: 640px) {
    .tb-stage {
        --tilt: 40deg;
        --tb-plate-w: 112px;
        aspect-ratio: 100 / 78;
    }

    .tb-stage--compact,
    .tb-stage--dense {
        --tb-plate-w: 84px;
    }

    .tb-stage--dense {
        aspect-ratio: 100 / 92;
    }

    .tb-stage--dense .tb-hand,
    .tb-stage--dense .tb-avatar {
        display: none;
    }

    .tb-plane {
        aspect-ratio: 1 / 1;
    }

    .tb-hub {
        inset: 27% 31% 39%;
    }

    .tb-name {
        font-size: 0.72rem;
    }

    .tb-stats {
        font-size: 0.64rem;
    }

    .tb-center {
        gap: 0.6rem;
        top: 44%;
        transform: translate(-50%, -62%) rotateX(calc(var(--tilt) * -1)) scale(0.8);
    }
}

/* Phones held sideways: the board is sized from the available height so the
   whole table fits without scrolling. Comes after the narrow-width rules so
   it wins on small landscape screens too. */
@media (orientation: landscape) and (max-height: 560px) {
    .tb-stage {
        --tilt: 46deg;
        --tb-plate-w: 132px;
        --tb-w: min(calc(100vw - 1rem), 1120px, calc((100dvh - 6rem - var(--tb-top, 2.4rem)) * 100 / 47));
        margin-top: var(--tb-top, 2.4rem);
        aspect-ratio: 100 / 47;
    }

    .tb-stage--compact,
    .tb-stage--dense {
        --tb-plate-w: 92px;
    }

    .tb-plane {
        aspect-ratio: 16 / 11;
    }

    .tb-hub {
        inset: 31% 30%;
    }

    .tb-center {
        top: 45%;
        gap: 0.9rem;
        transform: translate(-50%, -62%) rotateX(calc(var(--tilt) * -1));
    }

    .tb-stage--dense .tb-hand {
        display: none;
    }

    .tb-stage--dense .tb-avatar,
    .tb-stage--dense .tb-more {
        display: none;
    }

    .tb-reveal {
        display: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .tb-seat--turn .tb-plate {
        animation: none;
    }
}
</style>
