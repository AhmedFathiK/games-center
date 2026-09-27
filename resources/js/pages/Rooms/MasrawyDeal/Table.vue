<script setup lang="ts">
/**
 * Masrawy Deal's in-progress (and finished/cancelled — see Show.vue)
 * table. Every card shown here is rendered by MasrawyCard.vue from
 * room.table.catalog data (CardCatalog::get(), sent by
 * MasrawyDealGame::viewFor()) — this component never guesses at a
 * card's title, color, or value; it only decides layout and which
 * actions are reachable from where.
 *
 * Lives in Rooms/MasrawyDeal/ alongside this game's other own files
 * (Card.vue) — each game gets its own folder under Rooms/, with only
 * the platform-shared views (Show.vue, Mine.vue) at the top level.
 * Mafia's own files live the same way, under Rooms/Mafia/.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import type { FormDataConvertible } from '@inertiajs/core'
import { VueDraggable } from 'vue-draggable-plus'
import type { Room, AuthUser, MasrawyYou, MasrawyTableState, MasrawySeat, CardCatalogEntry } from '@/types/room'
import MasrawyCard from './Card.vue'

const props = defineProps<{
    room: Room
    auth: { user: AuthUser }
    isHost: boolean
}>()

// This component only ever renders for Masrawy Deal rooms (Show.vue's
// game.slug check), so room.you/room.table are always this game's shapes.
const you = computed(() => props.room.you as MasrawyYou | null)
const table = computed(() => props.room.table as MasrawyTableState | null)
const myId = computed(() => props.auth.user.id)
const handOrder = ref<string[]>([])
const handOrderLoaded = ref(false)
const handOrderStorageKey = `masrawy-deal-hand-order:${props.room.id}:${props.auth.user.id}`
const isReorderingHand = ref(false)

function reconcileHandOrder(hand: string[], preferredOrder: string[]): string[] {
    const cardsInHand = new Set(hand)
    const ordered = preferredOrder.filter((id, index) => cardsInHand.has(id) && preferredOrder.indexOf(id) === index)

    return [...ordered, ...hand.filter(id => !ordered.includes(id))]
}

watch(
    () => [...(you.value?.hand ?? [])],
    hand => {
        handOrder.value = reconcileHandOrder(hand, handOrder.value.length > 0 ? handOrder.value : hand)
    },
    { immediate: true },
)

onMounted(() => {
    try {
        const savedOrder: unknown = JSON.parse(window.localStorage.getItem(handOrderStorageKey) ?? '[]')
        if (Array.isArray(savedOrder) && savedOrder.every(id => typeof id === 'string')) {
            handOrder.value = reconcileHandOrder(you.value?.hand ?? [], savedOrder)
        }
    } catch {
        // Sorting still works for this visit when local storage is unavailable.
    }

    handOrderLoaded.value = true
})

watch(handOrder, order => {
    if (!handOrderLoaded.value) return

    try {
        window.localStorage.setItem(handOrderStorageKey, JSON.stringify(order))
    } catch {
        // Keep the in-memory order even when the browser blocks local storage.
    }
})

const topDiscardCardId = computed(() => {
    const pile = table.value?.discard_pile ?? []
    return pile[pile.length - 1] ?? ''
})

const COLORS = [
    'brown', 'light_blue', 'pink', 'orange', 'red',
    'yellow', 'green', 'dark_blue', 'railroad', 'utility',
]

function colorLabel(color: string): string {
    return color
        .split('_')
        .map(w => w[0].toUpperCase() + w.slice(1))
        .join(' ')
}

function entryFor(id: string): CardCatalogEntry | undefined {
    return table.value?.catalog[id]
}

function rentChartFor(id: string): number[] | undefined {
    const entry = entryFor(id)
    return entry?.type === 'property' && entry.color
        ? table.value?.rent_chart[entry.color]
        : undefined
}

function setSizeFor(id: string): number | undefined {
    const entry = entryFor(id)
    return entry?.type === 'property' && entry.color
        ? table.value?.set_size[entry.color]
        : undefined
}

function wildRentChartsFor(id: string): number[][] | undefined {
    const entry = entryFor(id)
    const colors = entry?.type === 'wildcard' && !entry.any_color && entry.colors?.length === 2
        ? entry.colors
        : []
    const charts = colors.map(color => table.value?.rent_chart[color]).filter((chart): chart is number[] => Boolean(chart))

    return colors.length === 2 && charts.length === 2 ? charts : undefined
}

function wildSetSizesFor(id: string): number[] | undefined {
    const entry = entryFor(id)
    const colors = entry?.type === 'wildcard' && !entry.any_color && entry.colors?.length === 2
        ? entry.colors
        : []
    const sizes = colors.map(color => table.value?.set_size[color]).filter((size): size is number => typeof size === 'number')

    return colors.length === 2 && sizes.length === 2 ? sizes : undefined
}

function label(id: string): string {
    return entryFor(id)?.label ?? id
}

function putHandCardAt(cardId: string, targetIndex: number) {
    const order = [...handOrder.value]
    const fromIndex = order.indexOf(cardId)
    if (fromIndex < 0 || targetIndex < 0 || targetIndex >= order.length || fromIndex === targetIndex) return

    const [movedCard] = order.splice(fromIndex, 1)
    order.splice(targetIndex, 0, movedCard)
    handOrder.value = order
}

function moveHandCard(cardId: string, offset: number) {
    const index = handOrder.value.indexOf(cardId)
    putHandCardAt(cardId, index + offset)
}

function toggleHandReordering() {
    isReorderingHand.value = !isReorderingHand.value
    if (isReorderingHand.value) selectedCardId.value = null
}

// --- Roster / seats ----------------------------------------------------

function playerName(id: number | string): string {
    const found = props.room.players.find(p => String(p.id) === String(id))
    return found?.name ?? `Player #${id}`
}

const mySeat = computed<MasrawySeat | undefined>(() =>
    table.value?.players.find(s => s.id === myId.value),
)

const opponents = computed(() => table.value?.players.filter(s => s.id !== myId.value) ?? [])

function seatFor(id: number | string): MasrawySeat | undefined {
    return table.value?.players.find(s => String(s.id) === String(id))
}

function seatBankTotal(seat: MasrawySeat): number {
    return seat.bank.reduce((total, cardId) => total + (entryFor(cardId)?.value ?? 0), 0)
}

// --- Turn / pending state -----------------------------------------------

// Table.vue also renders finished/cancelled Masrawy Deal rooms (see
// Show.vue) since neither has a role-reveal-style screen the way Mafia
// does; every action stays disabled once the game has actually ended.
const gameIsLive = computed(() => props.room.status === 'in_progress')
const isMyTurn = computed(() => gameIsLive.value && table.value?.current_player_id === myId.value)
const pending = computed(() => table.value?.pending ?? null)
const canAct = computed(() => gameIsLive.value && pending.value === null && isMyTurn.value)
const playsLeft = computed(() => 3 - (table.value?.cards_played_this_turn ?? 0))

// Charges I'm currently expected to answer (Just Say No or decline).
const myOpenResponses = computed(() =>
    (you.value?.responding_to ?? []).map(targetId => ({
        targetId,
        charge: pending.value?.charges[String(targetId)] ?? null,
    })),
)

const myJustSayNoCards = computed(() =>
    (you.value?.hand ?? []).filter(id => entryFor(id)?.action === 'just_say_no'),
)

// --- Generic action submission --------------------------------------------

const submitting = ref(false)
const actionError = ref<string | null>(null)

function submit(payload: Record<string, FormDataConvertible>, onSuccess?: () => void) {
    if (submitting.value) return
    submitting.value = true
    actionError.value = null

    router.post(
        `/rooms/${props.room.id}/actions`,
        payload,
        {
            preserveScroll: true,
            onError: errors => {
                actionError.value = Object.values(errors)[0] ?? 'That action was rejected.'
            },
            onSuccess: () => {
                onSuccess?.()
            },
            onFinish: () => {
                submitting.value = false
            },
        },
    )
}

function draw() {
    submit({ type: 'draw' })
}

function endTurn() {
    submit({ type: 'end_turn' }, clearSelection)
}

function playPassGo(cardId: string) {
    submit({ type: 'play_pass_go', card_id: cardId }, clearSelection)
}

function playShisha(cardId: string) {
    submit({ type: 'play_shisha', card_id: cardId }, clearSelection)
}

function playWil3a(cardId: string) {
    submit({ type: 'play_wil3a', card_id: cardId }, clearSelection)
}

function bankCard(cardId: string) {
    submit({ type: 'bank_card', card_id: cardId }, clearSelection)
}

function discard(cardId: string) {
    submit({ type: 'discard', card_id: cardId }, clearSelection)
}

// --- Selected card / contextual play panel --------------------------------

const selectedCardId = ref<string | null>(null)
const selectedEntry = computed(() => (selectedCardId.value ? entryFor(selectedCardId.value) : undefined))
const selectedIsWildRent = computed(() => selectedEntry.value?.type === 'rent' && selectedEntry.value.any_color)
const selectedIsPlainRent = computed(() => selectedEntry.value?.type === 'rent' && !selectedEntry.value.any_color)
const selectedIsElBob = computed(() => selectedEntry.value?.type === 'wildcard' && selectedEntry.value.any_color)
const selectedIsTwoColorWild = computed(() => selectedEntry.value?.type === 'wildcard' && !selectedEntry.value.any_color)

function selectCard(id: string) {
    selectedCardId.value = selectedCardId.value === id ? null : id
    // Reset any in-progress sub-form when switching cards.
    wildcardColor.value = ''
    targetId.value = null
    targetCardId.value = ''
    giveCardId.value = ''
    dealBreakerColor.value = ''
    doubleRentIds.value = []
    rentColor.value = ''
}

function resetTargetSelections() {
    targetCardId.value = ''
    dealBreakerColor.value = ''
    wildcardColor.value = ''
}

function clearSelection() {
    selectedCardId.value = null
}

const wildcardColor = ref('')

function playMoney(id: string) {
    submit({ type: 'play_money', card_id: id }, clearSelection)
}

function playProperty(id: string, color?: string) {
    const payload: Record<string, FormDataConvertible> = { type: 'play_property', card_id: id }
    if (color) payload.color = color
    submit(payload, clearSelection)
}

// --- Targeted-action sub-forms -------------------------------------------

const targetId = ref<number | null>(null)
const targetCardId = ref('')
const giveCardId = ref('')
const dealBreakerColor = ref('')
const doubleRentIds = ref<string[]>([])
const rentColor = ref('')

const targetOpponent = computed(() => (targetId.value !== null ? seatFor(targetId.value) : undefined))

function opponentPropertyCards(seat?: MasrawySeat): { id: string; group: string }[] {
    if (!seat) return []
    const out: { id: string; group: string }[] = []
    for (const [color, group] of Object.entries(seat.properties)) {
        for (const cardId of group.cards) {
            out.push({ id: cardId, group: color })
        }
    }
    return out
}

function opponentCompleteSetColors(seat?: MasrawySeat): string[] {
    if (!seat) return []

    return Object.entries(seat.properties)
        .filter(([color, group]) => group.cards.length >= (table.value?.set_size[color] ?? Number.POSITIVE_INFINITY))
        .map(([color]) => color)
}

const myDoubleRentCards = computed(() =>
    (you.value?.hand ?? []).filter(id => entryFor(id)?.action === 'double_rent'),
)

const myOwnPropertyCards = computed(() => opponentPropertyCards(mySeat.value))
const myOwnColors = computed(() => Object.keys(mySeat.value?.properties ?? {}))

function playDebtCollector(cardId: string) {
    if (targetId.value === null) return
    submit({ type: 'play_debt_collector', card_id: cardId, target_id: targetId.value }, clearSelection)
}

function playBirthday(cardId: string) {
    submit({ type: 'play_birthday', card_id: cardId }, clearSelection)
}

function playRent(cardId: string, isWild: boolean) {
    if (!rentColor.value) return
    const payload: Record<string, FormDataConvertible> = { type: 'play_rent', card_id: cardId, color: rentColor.value }
    if (isWild) {
        if (targetId.value === null) return
        payload.target_id = targetId.value
    }
    if (doubleRentIds.value.length > 0) payload.double_rent_card_ids = doubleRentIds.value
    submit(payload, clearSelection)
}

function playSlyDeal(cardId: string) {
    if (targetId.value === null || !targetCardId.value) return
    const payload: Record<string, FormDataConvertible> = {
        type: 'play_sly_deal',
        card_id: cardId,
        target_id: targetId.value,
        target_card_id: targetCardId.value,
    }
    if (wildcardColor.value) payload.color = wildcardColor.value
    submit(payload, clearSelection)
}

function playForcedDeal(cardId: string) {
    if (targetId.value === null || !targetCardId.value || !giveCardId.value) return
    const payload: Record<string, FormDataConvertible> = {
        type: 'play_forced_deal',
        card_id: cardId,
        target_id: targetId.value,
        target_card_id: targetCardId.value,
        give_card_id: giveCardId.value,
    }
    if (wildcardColor.value) payload.color = wildcardColor.value
    submit(payload, clearSelection)
}

function playDealBreaker(cardId: string) {
    if (targetId.value === null || !dealBreakerColor.value) return
    submit({ type: 'play_deal_breaker', card_id: cardId, target_id: targetId.value, target_color: dealBreakerColor.value }, clearSelection)
}

// --- move_wildcard (free, not a "play") -----------------------------------

const moveWildcardId = ref('')
const moveWildcardColor = ref('')

const myWildcards = computed(() => opponentPropertyCards(mySeat.value).filter(c => entryFor(c.id)?.type === 'wildcard'))

function moveWildcardValidColors(cardId: string): string[] {
    if (!cardId) return []
    const entry = entryFor(cardId)
    if (!entry) return []
    return entry.any_color ? COLORS : (entry.colors ?? [])
}

function moveWildcard() {
    if (!moveWildcardId.value || !moveWildcardColor.value) return
    submit(
        { type: 'move_wildcard', card_id: moveWildcardId.value, color: moveWildcardColor.value },
        () => {
            moveWildcardId.value = ''
            moveWildcardColor.value = ''
        },
    )
}

// --- Responding to a pending action (Just Say No / decline) ---------------

const respondCardByTarget = ref<Record<string, string>>({})

function respondNo(targetIdForCharge: number) {
    const cardId = respondCardByTarget.value[String(targetIdForCharge)]
    if (!cardId) return
    submit({ type: 'respond_no', card_id: cardId, target_id: targetIdForCharge })
}

function decline(targetIdForCharge: number) {
    submit({ type: 'decline', target_id: targetIdForCharge })
}

// --- Paying -------------------------------------------------------------

const paySelection = ref<string[]>([])

const payTotal = computed(() =>
    paySelection.value.reduce((sum, id) => sum + (you.value?.payable_assets?.[id] ?? 0), 0),
)

function togglePayCard(id: string) {
    const i = paySelection.value.indexOf(id)
    if (i === -1) paySelection.value.push(id)
    else paySelection.value.splice(i, 1)
}

function pay() {
    if (paySelection.value.length === 0) return
    submit({ type: 'pay', card_ids: [...paySelection.value] }, () => {
        paySelection.value = []
    })
}

// --- Hand limit discard ---------------------------------------------------

const overHandLimit = computed(() => (you.value?.hand.length ?? 0) > 7)
const canDiscard = computed(() => canAct.value && table.value?.has_drawn_this_turn === true && overHandLimit.value)
</script>

<template>
    <div class="md-root">
        <!-- table/you are only ever null before the game has started, which
             Show.vue's branch guard already keeps this component from
             rendering for — this is a defensive fallback, not an expected
             path, so it stays a plain message rather than a full layout. -->
        <p v-if="!table || !you" class="md-hint">Loading…</p>

        <template v-else>
            <p v-if="room.status === 'finished'" class="md-banner">
                {{ room.winner === String(myId) ? 'You won!' : `${playerName(room.winner ?? '')} won.` }}
            </p>
            <p v-else-if="room.status === 'cancelled'" class="md-banner">
                Room cancelled. Hands are shown below for reference.
            </p>

            <p v-if="actionError" role="alert" class="md-error">{{ actionError }}</p>

            <!-- Turn / draw pile summary -->
            <section class="md-summary">
                <div class="md-summary-row">
                    <span>
                        Turn: <strong>{{ isMyTurn ? 'You' : playerName(table.current_player_id) }}</strong>
                    </span>
                    <span>Plays left this turn: <strong>{{ playsLeft }}</strong></span>
                    <span>Draw pile: <strong>{{ table.draw_pile_count }}</strong></span>
                    <span>Discard pile: <strong>{{ table.discard_pile.length }}</strong></span>
                </div>

                <button
                    v-if="isMyTurn && !table.has_drawn_this_turn && pending === null"
                    class="md-btn md-btn--primary"
                    :disabled="submitting"
                    @click="draw"
                >
                    Draw
                </button>

            </section>

            <!-- Pending action banner -->
            <section v-if="pending" class="md-pending">
                <p class="md-pending-title">
                    {{ playerName(pending.source_id) }} played
                    {{ label(pending.card_id) }}{{ pending.multiplier && pending.multiplier > 1 ? ` (×${pending.multiplier})` : '' }}
                </p>

                <ul class="md-charges">
                    <li v-for="(charge, targetIdKey) in pending.charges" :key="targetIdKey" class="md-charge">
                        <span>{{ playerName(targetIdKey) }}: {{ charge.phase }}</span>
                        <span v-if="charge.owed > 0"> — owes {{ charge.owed }}M</span>
                        <span v-if="charge.outcome"> — {{ charge.outcome }}</span>
                    </li>
                </ul>

                <!-- My response window(s) -->
                <div v-for="entry in myOpenResponses" :key="entry.targetId" class="md-respond">
                    <p>You may respond{{ pending.kind === 'birthday' || pending.kind === 'rent' ? ` (for ${playerName(entry.targetId)})` : '' }}:</p>

                    <select v-model="respondCardByTarget[String(entry.targetId)]" :disabled="myJustSayNoCards.length === 0" aria-label="Choose a Just Say No card">
                        <option value="" disabled>Choose a DA 3AND OMMO... card</option>
                        <option v-for="cardId in myJustSayNoCards" :key="cardId" :value="cardId">
                            {{ label(cardId) }}
                        </option>
                    </select>
                    <button
                        class="md-btn"
                        :disabled="submitting || !respondCardByTarget[String(entry.targetId)]"
                        @click="respondNo(entry.targetId)"
                    >
                        Play It
                    </button>
                    <button class="md-btn md-btn--muted" :disabled="submitting" @click="decline(entry.targetId)">
                        Decline
                    </button>
                </div>

                <!-- Paying -->
                <div v-if="you.owes !== null" class="md-pay">
                    <p>You owe {{ you.owes }}M. Choose cards to pay with (selected: {{ payTotal }}M):</p>
                    <label v-for="(value, cardId) in you.payable_assets ?? {}" :key="cardId" class="md-pay-option">
                        <input
                            type="checkbox"
                            :checked="paySelection.includes(cardId)"
                            @change="togglePayCard(cardId)"
                        />
                        {{ label(cardId) }} ({{ value }}M)
                    </label>
                    <button class="md-btn md-btn--primary" :disabled="submitting || paySelection.length === 0" @click="pay">
                        Pay
                    </button>
                </div>
            </section>

            <!-- My hand -->
            <section class="md-hand">
                <div class="md-hand-header">
                    <h3 class="md-section-title">Your Hand ({{ you.hand.length }}/7)</h3>
                    <div class="md-hand-actions">
                        <button
                            v-if="you.hand.length > 1"
                            class="md-btn md-btn--muted"
                            :aria-pressed="isReorderingHand"
                            @click="toggleHandReordering"
                        >
                            {{ isReorderingHand ? 'Done sorting' : 'Sort hand' }}
                        </button>
                        <button
                            v-if="isMyTurn && table.has_drawn_this_turn && pending === null && !overHandLimit"
                            class="md-btn"
                            :disabled="submitting"
                            @click="endTurn"
                        >
                            End Turn
                        </button>
                    </div>
                </div>

                <p v-if="isReorderingHand" class="md-hint md-hand-sort-hint">
                    Press and hold a card, then drag it to reorder. You can also use the arrows. Your order is saved on this device.
                </p>

                <p v-if="isMyTurn && overHandLimit" class="md-hint">
                    Discard down to 7 cards before ending your turn.
                </p>

                <VueDraggable
                    v-model="handOrder"
                    class="md-card-row md-card-row--hand"
                    :class="{ 'md-card-row--hand-sorting': isReorderingHand }"
                    :disabled="!isReorderingHand"
                    :animation="180"
                    :delay="160"
                    :delay-on-touch-only="true"
                    :touch-start-threshold="5"
                    :fallback-tolerance="5"
                    :force-fallback="true"
                    :fallback-on-body="true"
                    :scroll="true"
                    :scroll-sensitivity="60"
                    :scroll-speed="10"
                    direction="horizontal"
                    ghost-class="md-hand-card--ghost"
                    chosen-class="md-hand-card--chosen"
                    drag-class="md-hand-card--dragging"
                >
                    <div
                        v-for="(cardId, index) in handOrder"
                        :key="cardId"
                        class="md-hand-card"
                    >
                        <button
                            class="md-card-btn"
                            :class="{ 'md-card-btn--selected': selectedCardId === cardId }"
                            :aria-label="`${isReorderingHand ? 'Reorder' : 'Select'} ${label(cardId)}`"
                            :aria-pressed="selectedCardId === cardId"
                            :disabled="!canAct || isReorderingHand"
                            @click="selectCard(cardId)"
                        >
                            <MasrawyCard :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                        </button>
                        <div v-if="isReorderingHand" class="md-hand-sort-controls">
                            <button class="md-btn md-btn--sort" :disabled="index === 0" :aria-label="`Move ${label(cardId)} left from position ${index + 1}`" @click="moveHandCard(cardId, -1)">←</button>
                            <button class="md-btn md-btn--sort" :disabled="index === handOrder.length - 1" :aria-label="`Move ${label(cardId)} right from position ${index + 1}`" @click="moveHandCard(cardId, 1)">→</button>
                        </div>
                    </div>
                </VueDraggable>

                <p v-if="!canAct" class="md-hint">
                    {{ pending ? 'Waiting on a pending action.' : 'Wait for your turn to play a card.' }}
                </p>

                <div v-else-if="selectedEntry" class="md-play-panel">
                    <MasrawyCard
                        :entry="selectedEntry"
                        size="lg"
                        :rent-chart="rentChartFor(selectedEntry.id)"
                        :set-size="setSizeFor(selectedEntry.id)"
                        :wild-rent-charts="wildRentChartsFor(selectedEntry.id)"
                        :wild-set-sizes="wildSetSizesFor(selectedEntry.id)"
                    />

                    <div class="md-play-controls">
                        <!-- Money -->
                        <button v-if="selectedEntry.type === 'money'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playMoney(selectedEntry.id)">
                            Play as Money ({{ selectedEntry.value }}M)
                        </button>

                        <!-- Plain property -->
                        <template v-if="selectedEntry.type === 'property'">
                            <button class="md-btn" :disabled="submitting || playsLeft < 1" @click="playProperty(selectedEntry.id)">
                                Play as Property ({{ colorLabel(selectedEntry.color!) }})
                            </button>
                        </template>

                        <!-- Two-color wildcard -->
                        <template v-if="selectedIsTwoColorWild">
                            <select v-model="wildcardColor" aria-label="Choose the property color">
                                <option value="" disabled>Choose a color</option>
                                <option v-for="c in selectedEntry.colors" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !wildcardColor" @click="playProperty(selectedEntry.id, wildcardColor)">
                                Play as Property
                            </button>
                        </template>

                        <!-- Any-color (EL BOB) wildcard -->
                        <template v-if="selectedIsElBob">
                            <select v-model="wildcardColor" aria-label="Choose the property color">
                                <option value="" disabled>Choose a color</option>
                                <option v-for="c in COLORS" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !wildcardColor" @click="playProperty(selectedEntry.id, wildcardColor)">
                                Play as Property
                            </button>
                        </template>

                        <!-- Bank / discard -->
                        <button
                            v-if="selectedEntry.type === 'action' || selectedEntry.type === 'rent'"
                            class="md-btn"
                            :disabled="submitting || playsLeft < 1"
                            @click="bankCard(selectedEntry.id)"
                        >
                            Bank It (no effect, worth its printed value)
                        </button>

                        <button class="md-btn md-btn--muted" :disabled="submitting || !canDiscard" @click="discard(selectedEntry.id)">
                            Discard
                        </button>

                        <!-- GARAB 7AZAK / Pass Go -->
                        <button v-if="selectedEntry.action === 'pass_go'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playPassGo(selectedEntry.id)">
                            Play Pass Go (draw 2)
                        </button>

                        <!-- SHISHA / WIL3A -->
                        <button v-if="selectedEntry.action === 'house'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playShisha(selectedEntry.id)">
                            Play SHISHA on a complete set
                        </button>
                        <button v-if="selectedEntry.action === 'hotel'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playWil3a(selectedEntry.id)">
                            Play WIL3A on a set with a SHISHA
                        </button>

                        <!-- HAT 5 FI KEES / Debt Collector -->
                        <template v-if="selectedEntry.action === 'debt_collector'">
                            <select v-model="targetId" aria-label="Choose a player to charge">
                                <option :value="null" disabled>Choose a player</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null" @click="playDebtCollector(selectedEntry.id)">
                                Play (charge 5M)
                            </button>
                        </template>

                        <!-- 3ID MILADY YA KELAB / Birthday -->
                        <button v-if="selectedEntry.action === 'birthday'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playBirthday(selectedEntry.id)">
                            Play (2M from everyone)
                        </button>

                        <!-- ELBIS! rent (regular or wild) -->
                        <template v-if="selectedIsPlainRent || selectedIsWildRent">
                            <select v-model="rentColor" aria-label="Choose which property color to charge">
                                <option value="" disabled>Which color to charge</option>
                                <option v-for="c in (selectedIsPlainRent ? selectedEntry.colors : myOwnColors)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <select v-if="selectedIsWildRent" v-model="targetId" aria-label="Choose the player to charge">
                                <option :value="null" disabled>Choose a player</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <fieldset v-if="myDoubleRentCards.length > 0" class="md-fieldset">
                                <legend>ELBIS X 2 (optional, doubles the rent, each costs a play)</legend>
                                <label v-for="c in myDoubleRentCards" :key="c" class="md-pay-option">
                                    <input type="checkbox" :value="c" v-model="doubleRentIds" />
                                    {{ label(c) }}
                                </label>
                            </fieldset>
                            <button
                                class="md-btn"
                                :disabled="submitting || playsLeft < (1 + doubleRentIds.length) || !rentColor || (selectedIsWildRent && targetId === null)"
                                @click="playRent(selectedEntry.id, !!selectedIsWildRent)"
                            >
                                Play Rent
                            </button>
                        </template>

                        <!-- KHOD AMA 2OLAK / Sly Deal -->
                        <template v-if="selectedEntry.action === 'sly_deal'">
                            <select v-model="targetId" aria-label="Choose whose property to take" @change="resetTargetSelections">
                                <option :value="null" disabled>Choose a player</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <p v-if="targetId !== null && opponentPropertyCards(targetOpponent).length === 0" class="md-hint">
                                That player has no properties to take.
                            </p>
                            <select v-model="targetCardId" :disabled="targetId === null" aria-label="Choose the property to take" @change="wildcardColor = ''">
                                <option value="" disabled>Choose a property of theirs</option>
                                <option v-for="c in opponentPropertyCards(targetOpponent)" :key="c.id" :value="c.id">
                                    {{ label(c.id) }} ({{ colorLabel(c.group) }})
                                </option>
                            </select>
                            <select v-if="targetCardId && entryFor(targetCardId)?.type === 'wildcard'" v-model="wildcardColor" aria-label="Choose the taken wildcard's new color">
                                <option value="">Keep current color</option>
                                <option v-for="c in (entryFor(targetCardId)?.any_color ? COLORS : entryFor(targetCardId)?.colors)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null || !targetCardId" @click="playSlyDeal(selectedEntry.id)">
                                Take Property
                            </button>
                        </template>

                        <!-- MA.. TEEGY WANA AGY! / Forced Deal -->
                        <template v-if="selectedEntry.action === 'forced_deal'">
                            <select v-model="targetId" aria-label="Choose whose property to take and replace" @change="resetTargetSelections">
                                <option :value="null" disabled>Choose a player</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <p v-if="targetId !== null && opponentPropertyCards(targetOpponent).length === 0" class="md-hint">
                                That player has no properties to swap.
                            </p>
                            <select v-model="targetCardId" :disabled="targetId === null" aria-label="Choose their property to take" @change="wildcardColor = ''">
                                <option value="" disabled>Choose a property of theirs to take</option>
                                <option v-for="c in opponentPropertyCards(targetOpponent)" :key="c.id" :value="c.id">
                                    {{ label(c.id) }} ({{ colorLabel(c.group) }})
                                </option>
                            </select>
                            <select v-model="giveCardId" aria-label="Choose one of your properties to give">
                                <option value="" disabled>Choose one of your properties to give</option>
                                <option v-for="c in myOwnPropertyCards" :key="c.id" :value="c.id">
                                    {{ label(c.id) }} ({{ colorLabel(c.group) }})
                                </option>
                            </select>
                            <select v-if="targetCardId && entryFor(targetCardId)?.type === 'wildcard'" v-model="wildcardColor" aria-label="Choose the taken wildcard's new color">
                                <option value="">Keep current color</option>
                                <option v-for="c in (entryFor(targetCardId)?.any_color ? COLORS : entryFor(targetCardId)?.colors)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <button
                                class="md-btn"
                                :disabled="submitting || playsLeft < 1 || targetId === null || !targetCardId || !giveCardId"
                                @click="playForcedDeal(selectedEntry.id)"
                            >
                                Swap Properties
                            </button>
                        </template>

                        <!-- HAT wa lamo2akhza EL SHORT! / Deal Breaker -->
                        <template v-if="selectedEntry.action === 'deal_breaker'">
                            <select v-model="targetId" aria-label="Choose whose complete set to take" @change="resetTargetSelections">
                                <option :value="null" disabled>Choose a player</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <p v-if="targetId !== null && opponentCompleteSetColors(targetOpponent).length === 0" class="md-hint">
                                That player has no complete sets to take.
                            </p>
                            <select v-model="dealBreakerColor" :disabled="targetId === null || opponentCompleteSetColors(targetOpponent).length === 0" aria-label="Choose their complete set">
                                <option value="" disabled>Choose one of their complete sets</option>
                                <option v-for="c in opponentCompleteSetColors(targetOpponent)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null || !dealBreakerColor" @click="playDealBreaker(selectedEntry.id)">
                                Take Complete Set
                            </button>
                        </template>
                    </div>
                </div>
            </section>

            <!-- Move a wildcard (free, on your own turn, no pending action) -->
            <section v-if="canAct && myWildcards.length > 0" class="md-panel">
                <h3 class="md-section-title">Move a Wildcard (free)</h3>
                <select v-model="moveWildcardId" aria-label="Choose one of your wildcards to move" @change="moveWildcardColor = ''">
                    <option value="" disabled>Choose one of your wildcards</option>
                    <option v-for="c in myWildcards" :key="c.id" :value="c.id">
                        {{ label(c.id) }} (currently {{ colorLabel(c.group) }})
                    </option>
                </select>
                <select v-model="moveWildcardColor" :disabled="!moveWildcardId" aria-label="Choose the wildcard's new color">
                    <option value="" disabled>New color</option>
                    <option v-for="c in moveWildcardValidColors(moveWildcardId)" :key="c" :value="c">
                        {{ colorLabel(c) }}
                    </option>
                </select>
                <button class="md-btn" :disabled="submitting || !moveWildcardId || !moveWildcardColor" @click="moveWildcard">
                    Move
                </button>
            </section>

            <!-- Players -->
            <section class="md-players">
                <h3 class="md-section-title">Players</h3>
                <details
                    v-for="seat in table.players"
                    :key="seat.id"
                    class="md-seat"
                    :class="{ 'md-seat--turn': seat.id === table.current_player_id }"
                >
                    <summary class="md-seat-summary">
                        <span class="md-seat-name">
                            {{ playerName(seat.id) }}<span v-if="seat.id === myId"> (you)</span>
                            <span v-if="seat.id === table.current_player_id" class="md-seat-turn-tag">— current turn</span>
                        </span>
                        <span class="md-seat-meta">
                            Hand {{ seat.hand_count }} · Bank {{ seatBankTotal(seat) }}M · {{ Object.keys(seat.properties).length }} property groups
                        </span>
                    </summary>

                    <div class="md-seat-content">
                        <p v-if="seat.hand" class="md-seat-hand">Hand: {{ seat.hand_count }} card(s)</p>

                        <div v-if="seat.hand" class="md-card-row">
                            <MasrawyCard v-for="cardId in seat.hand" :key="cardId" :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                        </div>

                        <div v-if="seat.bank.length > 0" class="md-seat-bank-group">
                            <p class="md-group-label">Bank · {{ seatBankTotal(seat) }}M</p>
                            <div class="md-card-row">
                                <MasrawyCard v-for="cardId in seat.bank" :key="cardId" :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                            </div>
                        </div>
                        <p v-else class="md-seat-bank">Bank: empty</p>

                        <div v-if="Object.keys(seat.properties).length > 0" class="md-seat-properties">
                            <div v-for="(group, color) in seat.properties" :key="color" class="md-group">
                                <p class="md-group-label">
                                    {{ colorLabel(String(color)) }}
                                    <span v-if="group.house"> + SHISHA</span>
                                    <span v-if="group.hotel"> + WIL3A</span>
                                </p>
                                <div class="md-card-row">
                                    <MasrawyCard v-for="cardId in group.cards" :key="cardId" :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                                </div>
                            </div>
                        </div>
                    </div>
                </details>
            </section>

            <!-- Discard pile -->
            <section v-if="table.discard_pile.length > 0" class="md-discard">
                <h3 class="md-section-title">Discard Pile</h3>
                <div class="md-discard-stack" :aria-label="`Top card of discard pile; ${table.discard_pile.length} cards in pile`">
                    <MasrawyCard :entry="entryFor(topDiscardCardId)!" :rent-chart="rentChartFor(topDiscardCardId)" :set-size="setSizeFor(topDiscardCardId)" :wild-rent-charts="wildRentChartsFor(topDiscardCardId)" :wild-set-sizes="wildSetSizesFor(topDiscardCardId)" />
                </div>
            </section>




        </template>
    </div>
</template>

<style scoped>
/*
 * NOTE: unlike Mafia's own font import in Show.vue (which loads
 * unconditionally for every room, a known/flagged issue there), this
 * @import only fetches when THIS component actually mounts — i.e. only
 * for Masrawy Deal rooms — since it lives in the game-specific
 * component instead of the shared page. The waiting-room screen (before
 * the game starts, rendered by Show.vue itself) doesn't get these faces
 * loaded yet and falls back to the system font; a small, low-priority
 * gap versus building the full per-game font-gating mechanism the
 * project context doc describes as still deferred.
 */
@import url('https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=EB+Garamond:ital,wght@1,500;1,700&family=Montserrat:wght@400;600;800;900&display=swap');

.md-root {
    margin-top: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.md-error {
    background: var(--rc-surface);
    border: 1px solid var(--rc-primary);
    color: var(--rc-primary);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-size: 0.85rem;
}

.md-banner {
    font-family: var(--rc-font-display);
    font-size: 1.1rem;
    font-weight: 600;
    text-align: center;
}

.md-section-title {
    font-family: var(--rc-font-display);
    font-size: 1rem;
    margin-bottom: 0.5rem;
}

.md-summary,
.md-pending,
.md-players,
.md-discard,
.md-panel,
.md-hand {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

.md-summary-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.85rem;
    margin-bottom: 0.75rem;
}

.md-hint {
    color: var(--rc-text-muted);
    font-size: 0.85rem;
}

.md-pending {
    border-color: var(--rc-primary);
}

.md-pending-title {
    font-weight: 600;
    margin-bottom: 0.5rem;
}

.md-charges {
    list-style: none;
    padding: 0;
    margin: 0 0 0.75rem;
    font-size: 0.85rem;
    color: var(--rc-text-muted);
}

.md-respond,
.md-pay {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px dashed var(--rc-border);
}

.md-pay {
    flex-direction: column;
    align-items: flex-start;
}

.md-pay-option {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.85rem;
}

.md-seat {
    border-top: 1px solid var(--rc-border);
    padding: 0.75rem 0;
    font-size: 0.85rem;
}

.md-seat:first-child {
    border-top: none;
    padding-top: 0;
}

.md-seat--turn {
    font-weight: 600;
}

.md-seat-summary {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.25rem 0.75rem;
    cursor: pointer;
    list-style-position: inside;
}

.md-seat-summary::marker {
    color: var(--rc-primary);
}

.md-seat-name {
    font-weight: 600;
}

.md-seat-meta {
    color: var(--rc-text-muted);
    font-size: 0.78rem;
    font-weight: 400;
}

.md-seat-content {
    padding-top: 0.75rem;
}

.md-hand-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.md-hand-header .md-section-title {
    margin-bottom: 0;
}

.md-hand-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-left: auto;
}

.md-hand-sort-hint {
    margin: 0.5rem 0 0;
}

.md-seat-turn-tag {
    color: var(--rc-primary);
    font-weight: 400;
    font-size: 0.75rem;
}

.md-seat-hand,
.md-seat-bank {
    color: var(--rc-text-muted);
    margin-bottom: 0.4rem;
}

.md-card-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.5rem;
}

.md-card-row--hand {
    flex-wrap: nowrap;
    overflow-x: auto;
    padding: 0.25rem 0.25rem 0.75rem;
    margin: 0 -0.25rem 0.25rem;
    overscroll-behavior-x: contain;
    scroll-snap-type: x proximity;
    scrollbar-width: thin;
}

.md-card-row--hand-sorting {
    overflow-anchor: none;
    scroll-snap-type: none;
}

.md-hand-card {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    align-items: center;
    gap: 0.4rem;
}

.md-hand-card {
    cursor: auto;
}

.md-card-row--hand-sorting .md-hand-card {
    cursor: grab;
    user-select: none;
    -webkit-user-drag: none;
}

.md-card-row--hand-sorting .md-card-btn {
    pointer-events: none;
}

.md-hand-card--chosen {
    cursor: grabbing;
}

.md-hand-card--dragging,
.md-hand-card--ghost {
    opacity: 0.45;
}

.md-hand-sort-controls {
    display: flex;
    gap: 0.4rem;
}

.md-hand-sort-controls .md-btn {
    min-width: 44px;
    min-height: 44px;
    padding: 0.4rem;
    font-size: 1.1rem;
}

.md-discard-stack {
    position: relative;
    isolation: isolate;
    width: 114px;
    height: 171px;
    margin: 0 0.375rem 0.375rem 0;
}

.md-discard-stack::before,
.md-discard-stack::after {
    content: '';
    position: absolute;
    inset: 0;
    width: 108px;
    height: 165px;
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    background: var(--rc-surface-alt);
    box-shadow: 0 2px 4px rgb(0 0 0 / 12%);
}

.md-discard-stack::before {
    transform: translate(6px, 6px);
    z-index: -2;
}

.md-discard-stack::after {
    transform: translate(3px, 3px);
    z-index: -1;
}

.md-group {
    margin-bottom: 0.5rem;
}

.md-group-label {
    font-size: 0.8rem;
    margin-bottom: 0.3rem;
    color: var(--rc-text-muted);
}

.md-card-btn {
    flex: 0 0 auto;
    border: none;
    background: transparent;
    padding: 0;
    cursor: pointer;
    border-radius: 8px;
    transition: transform 0.1s ease;
    scroll-snap-align: start;
}

.md-card-btn:hover:not(:disabled) {
    transform: translateY(-2px);
}

.md-card-btn:disabled {
    cursor: default;
}

.md-card-btn--selected {
    outline: 2px solid var(--rc-primary);
    outline-offset: 2px;
    border-radius: 8px;
}

.md-play-panel {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 1rem;
    padding-top: 0.75rem;
    border-top: 1px dashed var(--rc-border);
}

.md-play-controls {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
    min-width: 200px;
}

.md-play-controls select {
    flex: 1 1 100%;
    min-width: 0;
    max-width: 100%;
}

.md-respond select,
.md-panel select {
    min-width: 0;
    max-width: 100%;
    min-height: 44px;
}

.md-fieldset {
    border: 1px solid var(--rc-border);
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
}

.md-btn {
    border-radius: 6px;
    padding: 0.5rem 1rem;
    min-height: 44px;
    font-size: 0.85rem;
    border: 1px solid var(--rc-border);
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    cursor: pointer;
    transition: opacity 0.15s ease;
}

.md-btn:hover:not(:disabled) {
    opacity: 0.85;
}

.md-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.md-btn--primary {
    background: var(--rc-primary);
    border-color: var(--rc-primary);
    color: #fff;
}

.md-btn--muted {
    background: transparent;
}

select,
input[type='checkbox'] {
    font-size: 0.85rem;
}

@media (max-width: 600px) {
    .md-root {
        gap: 1rem;
    }

    .md-summary,
    .md-pending,
    .md-players,
    .md-discard,
    .md-panel,
    .md-hand {
        padding: 0.875rem;
    }

    .md-play-panel {
        gap: 0.75rem;
    }

    .md-play-controls {
        min-width: 0;
        width: 100%;
    }

    .md-play-controls .md-btn {
        flex: 1 1 auto;
    }
}
</style>
