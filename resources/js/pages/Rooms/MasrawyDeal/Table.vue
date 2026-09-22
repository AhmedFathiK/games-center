<script setup lang="ts">
/**
 * Masrawy Deal's in-progress table. Deliberately plain for now — real
 * card art/layout is a follow-up pass once Ahmed's card-studio designs
 * are available; this focuses on making every server action reachable
 * and every server-computed fact (whose turn, who owes what, what's
 * pending) visible. See MasrawyDealGame::viewFor() for where room.you /
 * room.table come from and what privacy rules they already enforce —
 * this component trusts that filtering completely and never assumes
 * anything about hidden information (a hidden hand is simply absent
 * here, not merely unrendered).
 */
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import type { FormDataConvertible } from '@inertiajs/core'
import type { Room, AuthUser, MasrawyYou, MasrawyTableState, MasrawySeat } from '@/types/room'

const props = defineProps<{
    room: Room
    auth: { user: AuthUser }
    isHost: boolean
}>()

// This component only ever renders for Masrawy Deal rooms (Show.vue's
// game.slug check), so room.you/room.table are always this game's shapes.
const you = computed(() => props.room.you as MasrawyYou)
const table = computed(() => props.room.table as MasrawyTableState)
const myId = computed(() => props.auth.user.id)

// --- Card id parsing ---------------------------------------------------
// The frontend never receives a card catalog — every card is just its
// id string, whose shape mirrors CardCatalog's own id scheme. Parsing it
// here (rather than asking the backend) keeps the view payload small and
// keeps CardCatalog as the one place card data is truly defined.
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

function colorsIn(remainder: string): string[] {
    const found: { color: string; index: number }[] = []
    for (const color of COLORS) {
        const m = remainder.match(new RegExp(`(^|_)${color}(_|$)`))
        if (m) found.push({ color, index: m.index ?? 0 })
    }
    return found.sort((a, b) => a.index - b.index).map(f => f.color)
}

const ACTION_LABELS: Record<string, string> = {
    debt_collector: 'HAT 5 FI KEES — Debt Collector (5M from one player)',
    birthday: '3ID MILADY YA KELAB — Birthday (2M from everyone)',
    sly_deal: 'KHOD AMA 2OLAK — Sly Deal',
    forced_deal: 'MA.. TEEGY WANA AGY! — Forced Deal',
    deal_breaker: 'HAT wa lamo2akhza EL SHORT! — Deal Breaker',
    just_say_no: 'DA 3AND OMMO... — Just Say No',
    double_rent: 'ELBIS X 2 — Double The Rent',
    pass_go: 'GARAB 7AZAK — Pass Go (draw 2)',
    house: 'SHISHA (+3M rent on a complete set)',
    hotel: 'WIL3A (+4M rent, needs a SHISHA first)',
}

interface ParsedCard {
    id: string
    category: 'money' | 'property' | 'wildcard' | 'wildcard_any' | 'rent' | 'rent_any' | 'action' | 'unknown'
    amount: number | null
    colors: string[]
    action: string | null
    label: string
}

function parseCard(id: string): ParsedCard {
    const stripIndex = (s: string) => s.replace(/_\d+$/, '')

    if (id.startsWith('money_')) {
        const parts = id.split('_')
        const amount = Number(parts[1])
        return { id, category: 'money', amount, colors: [], action: null, label: `${amount}M` }
    }

    if (id.startsWith('prop_')) {
        const colors = colorsIn(stripIndex(id.slice('prop_'.length)))
        return { id, category: 'property', amount: null, colors, action: null, label: colorLabel(colors[0] ?? '') }
    }

    if (id.startsWith('wild_any_')) {
        return { id, category: 'wildcard_any', amount: null, colors: [], action: null, label: 'EL BOB (any color, worth 0M)' }
    }

    if (id.startsWith('wild_')) {
        const colors = colorsIn(stripIndex(id.slice('wild_'.length)))
        return {
            id,
            category: 'wildcard',
            amount: null,
            colors,
            action: null,
            label: `CART KARBAGA — ${colors.map(colorLabel).join(' / ')} wildcard`,
        }
    }

    if (id.startsWith('rent_any_')) {
        return { id, category: 'rent_any', amount: null, colors: [], action: null, label: 'ELBIS! — Wild Rent (choose one opponent, any of your colors)' }
    }

    if (id.startsWith('rent_')) {
        const colors = colorsIn(stripIndex(id.slice('rent_'.length)))
        return {
            id,
            category: 'rent',
            amount: null,
            colors,
            action: null,
            label: `ELBIS! — Rent (${colors.map(colorLabel).join(' / ')}, everyone pays)`,
        }
    }

    if (id.startsWith('action_')) {
        const action = stripIndex(id.slice('action_'.length))
        return { id, category: 'action', amount: null, colors: [], action, label: ACTION_LABELS[action] ?? action }
    }

    return { id, category: 'unknown', amount: null, colors: [], action: null, label: id }
}

function shortLabel(id: string): string {
    const c = parseCard(id)
    return c.label
}

// --- Roster / seats ----------------------------------------------------

function playerName(id: number | string): string {
    const found = props.room.players.find(p => String(p.id) === String(id))
    return found?.name ?? `Player #${id}`
}

const mySeat = computed<MasrawySeat | undefined>(() =>
    table.value.players.find(s => s.id === myId.value),
)

const opponents = computed(() => table.value.players.filter(s => s.id !== myId.value))

function seatFor(id: number | string): MasrawySeat | undefined {
    return table.value.players.find(s => String(s.id) === String(id))
}

// --- Turn / pending state -----------------------------------------------

// Table.vue also renders finished/cancelled Masrawy Deal rooms (see
// Show.vue) since neither has a role-reveal-style screen the way Mafia
// does; every action stays disabled once the game has actually ended.
const gameIsLive = computed(() => props.room.status === 'in_progress')
const isMyTurn = computed(() => gameIsLive.value && table.value.current_player_id === myId.value)
const pending = computed(() => table.value.pending)
const canAct = computed(() => gameIsLive.value && pending.value === null && isMyTurn.value)
const playsLeft = computed(() => 3 - table.value.cards_played_this_turn)

// Charges I'm currently expected to answer (Just Say No or decline).
const myOpenResponses = computed(() =>
    you.value.responding_to.map(targetId => ({
        targetId,
        charge: pending.value?.charges[String(targetId)] ?? null,
    })),
)

const myJustSayNoCards = computed(() =>
    you.value.hand.filter(id => parseCard(id).action === 'just_say_no'),
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
    submit({ type: 'end_turn' })
}

function playPassGo(cardId: string) {
    submit({ type: 'play_pass_go', card_id: cardId })
}

function playShisha(cardId: string) {
    submit({ type: 'play_shisha', card_id: cardId })
}

function playWil3a(cardId: string) {
    submit({ type: 'play_wil3a', card_id: cardId })
}

function bankCard(cardId: string) {
    submit({ type: 'bank_card', card_id: cardId })
}

function discard(cardId: string) {
    submit({ type: 'discard', card_id: cardId })
}

// --- Selected card / contextual play panel --------------------------------

const selectedCardId = ref<string | null>(null)
const selectedCard = computed(() => (selectedCardId.value ? parseCard(selectedCardId.value) : null))

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
    // We don't know each color's SET_SIZE on the frontend, so this can't
    // be narrowed to "complete" sets client-side — every color the
    // opponent has any properties in is offered, and the server is the
    // real judge of which ones are actually complete.
    return seat ? Object.keys(seat.properties) : []
}

const myDoubleRentCards = computed(() =>
    you.value.hand.filter(id => parseCard(id).action === 'double_rent'),
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

const myWildcards = computed(() => opponentPropertyCards(mySeat.value).filter(c => {
    const parsed = parseCard(c.id)
    return parsed.category === 'wildcard' || parsed.category === 'wildcard_any'
}))

function moveWildcardValidColors(cardId: string): string[] {
    if (!cardId) return []
    const parsed = parseCard(cardId)
    return parsed.category === 'wildcard_any' ? COLORS : parsed.colors
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
    paySelection.value.reduce((sum, id) => sum + (you.value.payable_assets?.[id] ?? 0), 0),
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

const overHandLimit = computed(() => you.value.hand.length > 7)
</script>

<template>
    <div class="md-root">
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

            <button
                v-if="isMyTurn && table.has_drawn_this_turn && pending === null && !overHandLimit"
                class="md-btn"
                :disabled="submitting"
                @click="endTurn"
            >
                End Turn
            </button>

            <p v-if="isMyTurn && overHandLimit" class="md-hint">
                You're over the 7-card hand limit — discard down to 7 before ending your turn.
            </p>
        </section>

        <!-- Pending action banner -->
        <section v-if="pending" class="md-pending">
            <p class="md-pending-title">
                {{ playerName(pending.source_id) }} played
                {{ ACTION_LABELS[pending.kind] ?? pending.kind }}{{ pending.multiplier && pending.multiplier > 1 ? ` (×${pending.multiplier})` : '' }}
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

                <select v-model="respondCardByTarget[String(entry.targetId)]" :disabled="myJustSayNoCards.length === 0">
                    <option value="" disabled>Choose a DA 3AND OMMO... card</option>
                    <option v-for="cardId in myJustSayNoCards" :key="cardId" :value="cardId">
                        {{ shortLabel(cardId) }}
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
                    {{ shortLabel(cardId) }} ({{ value }}M)
                </label>
                <button class="md-btn md-btn--primary" :disabled="submitting || paySelection.length === 0" @click="pay">
                    Pay
                </button>
            </div>
        </section>

        <!-- Opponents -->
        <section class="md-players">
            <h3 class="md-section-title">Players</h3>
            <div v-for="seat in table.players" :key="seat.id" class="md-seat" :class="{ 'md-seat--me': seat.id === myId, 'md-seat--turn': seat.id === table.current_player_id }">
                <p class="md-seat-name">
                    {{ playerName(seat.id) }}<span v-if="seat.id === myId"> (you)</span>
                    <span v-if="seat.id === table.current_player_id" class="md-seat-turn-tag">— current turn</span>
                </p>
                <p class="md-seat-hand">Hand: {{ seat.hand_count }} card(s)<span v-if="seat.hand"> — {{ seat.hand.map(shortLabel).join(', ') }}</span></p>
                <p class="md-seat-bank">Bank: {{ seat.bank.length === 0 ? 'empty' : seat.bank.map(shortLabel).join(', ') }}</p>
                <div v-if="Object.keys(seat.properties).length > 0" class="md-seat-properties">
                    <div v-for="(group, color) in seat.properties" :key="color" class="md-group">
                        <strong>{{ colorLabel(String(color)) }}:</strong>
                        {{ group.cards.map(shortLabel).join(', ') || '(no properties, building only)' }}
                        <span v-if="group.house"> + SHISHA</span>
                        <span v-if="group.hotel"> + WIL3A</span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Discard pile -->
        <section v-if="table.discard_pile.length > 0" class="md-discard">
            <h3 class="md-section-title">Discard Pile</h3>
            <p>{{ table.discard_pile.map(shortLabel).join(', ') }}</p>
        </section>

        <!-- Move a wildcard (free, on your own turn, no pending action) -->
        <section v-if="canAct && myWildcards.length > 0" class="md-panel">
            <h3 class="md-section-title">Move a Wildcard (free)</h3>
            <select v-model="moveWildcardId">
                <option value="" disabled>Choose one of your wildcards</option>
                <option v-for="c in myWildcards" :key="c.id" :value="c.id">
                    {{ shortLabel(c.id) }} (currently {{ colorLabel(c.group) }})
                </option>
            </select>
            <select v-model="moveWildcardColor" :disabled="!moveWildcardId">
                <option value="" disabled>New color</option>
                <option v-for="c in moveWildcardValidColors(moveWildcardId)" :key="c" :value="c">
                    {{ colorLabel(c) }}
                </option>
            </select>
            <button class="md-btn" :disabled="submitting || !moveWildcardId || !moveWildcardColor" @click="moveWildcard">
                Move
            </button>
        </section>

        <!-- My hand -->
        <section class="md-hand">
            <h3 class="md-section-title">Your Hand ({{ you.hand.length }})</h3>

            <div class="md-hand-cards">
                <button
                    v-for="cardId in you.hand"
                    :key="cardId"
                    class="md-card"
                    :class="{ 'md-card--selected': selectedCardId === cardId }"
                    @click="selectCard(cardId)"
                >
                    {{ shortLabel(cardId) }}
                </button>
            </div>

            <p v-if="!canAct" class="md-hint">
                {{ pending ? 'Waiting on a pending action.' : 'Wait for your turn to play a card.' }}
            </p>

            <div v-else-if="selectedCard" class="md-play-panel">
                <p class="md-play-title">{{ selectedCard.label }}</p>

                <!-- Money -->
                <button v-if="selectedCard.category === 'money'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playMoney(selectedCard.id)">
                    Play as Money ({{ selectedCard.amount }}M)
                </button>

                <!-- Plain property -->
                <template v-if="selectedCard.category === 'property'">
                    <button class="md-btn" :disabled="submitting || playsLeft < 1" @click="playProperty(selectedCard.id)">
                        Play as Property ({{ colorLabel(selectedCard.colors[0]) }})
                    </button>
                </template>

                <!-- Two-color wildcard -->
                <template v-if="selectedCard.category === 'wildcard'">
                    <select v-model="wildcardColor">
                        <option value="" disabled>Choose a color</option>
                        <option v-for="c in selectedCard.colors" :key="c" :value="c">{{ colorLabel(c) }}</option>
                    </select>
                    <button class="md-btn" :disabled="submitting || playsLeft < 1 || !wildcardColor" @click="playProperty(selectedCard.id, wildcardColor)">
                        Play as Property
                    </button>
                </template>

                <!-- Any-color (EL BOB) wildcard -->
                <template v-if="selectedCard.category === 'wildcard_any'">
                    <select v-model="wildcardColor">
                        <option value="" disabled>Choose a color</option>
                        <option v-for="c in COLORS" :key="c" :value="c">{{ colorLabel(c) }}</option>
                    </select>
                    <button class="md-btn" :disabled="submitting || playsLeft < 1 || !wildcardColor" @click="playProperty(selectedCard.id, wildcardColor)">
                        Play as Property
                    </button>
                </template>

                <!-- Bank / discard, available for money-ineligible cards too -->
                <button
                    v-if="selectedCard.category === 'action' || selectedCard.category === 'rent' || selectedCard.category === 'rent_any'"
                    class="md-btn"
                    :disabled="submitting || playsLeft < 1"
                    @click="bankCard(selectedCard.id)"
                >
                    Bank It (no effect, worth its printed value)
                </button>

                <button class="md-btn md-btn--muted" :disabled="submitting" @click="discard(selectedCard.id)">
                    Discard
                </button>

                <!-- GARAB 7AZAK / Pass Go -->
                <button v-if="selectedCard.action === 'pass_go'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playPassGo(selectedCard.id)">
                    Play Pass Go (draw 2)
                </button>

                <!-- SHISHA / WIL3A -->
                <button v-if="selectedCard.action === 'house'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playShisha(selectedCard.id)">
                    Play SHISHA on a complete set
                </button>
                <button v-if="selectedCard.action === 'hotel'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playWil3a(selectedCard.id)">
                    Play WIL3A on a set with a SHISHA
                </button>

                <!-- HAT 5 FI KEES / Debt Collector -->
                <template v-if="selectedCard.action === 'debt_collector'">
                    <select v-model="targetId">
                        <option :value="null" disabled>Choose a player</option>
                        <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                    </select>
                    <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null" @click="playDebtCollector(selectedCard.id)">
                        Play (charge 5M)
                    </button>
                </template>

                <!-- 3ID MILADY YA KELAB / Birthday -->
                <button v-if="selectedCard.action === 'birthday'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playBirthday(selectedCard.id)">
                    Play (2M from everyone)
                </button>

                <!-- ELBIS! rent (regular or wild) -->
                <template v-if="selectedCard.category === 'rent' || selectedCard.category === 'rent_any'">
                    <select v-model="rentColor">
                        <option value="" disabled>Which color to charge</option>
                        <option v-for="c in (selectedCard.category === 'rent' ? selectedCard.colors : myOwnColors)" :key="c" :value="c">
                            {{ colorLabel(c) }}
                        </option>
                    </select>
                    <select v-if="selectedCard.category === 'rent_any'" v-model="targetId">
                        <option :value="null" disabled>Choose a player</option>
                        <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                    </select>
                    <fieldset v-if="myDoubleRentCards.length > 0" class="md-fieldset">
                        <legend>ELBIS X 2 (optional, doubles the rent, each costs a play)</legend>
                        <label v-for="c in myDoubleRentCards" :key="c" class="md-pay-option">
                            <input type="checkbox" :value="c" v-model="doubleRentIds" />
                            {{ shortLabel(c) }}
                        </label>
                    </fieldset>
                    <button
                        class="md-btn"
                        :disabled="submitting || playsLeft < (1 + doubleRentIds.length) || !rentColor || (selectedCard.category === 'rent_any' && targetId === null)"
                        @click="playRent(selectedCard.id, selectedCard.category === 'rent_any')"
                    >
                        Play Rent
                    </button>
                </template>

                <!-- KHOD AMA 2OLAK / Sly Deal -->
                <template v-if="selectedCard.action === 'sly_deal'">
                    <select v-model="targetId">
                        <option :value="null" disabled>Choose a player</option>
                        <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                    </select>
                    <select v-model="targetCardId" :disabled="targetId === null">
                        <option value="" disabled>Choose a property of theirs</option>
                        <option v-for="c in opponentPropertyCards(targetOpponent)" :key="c.id" :value="c.id">
                            {{ shortLabel(c.id) }} ({{ colorLabel(c.group) }})
                        </option>
                    </select>
                    <select v-if="targetCardId && parseCard(targetCardId).category !== 'property'" v-model="wildcardColor">
                        <option value="">Keep current color</option>
                        <option v-for="c in (parseCard(targetCardId).category === 'wildcard_any' ? COLORS : parseCard(targetCardId).colors)" :key="c" :value="c">
                            {{ colorLabel(c) }}
                        </option>
                    </select>
                    <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null || !targetCardId" @click="playSlyDeal(selectedCard.id)">
                        Play
                    </button>
                </template>

                <!-- MA.. TEEGY WANA AGY! / Forced Deal -->
                <template v-if="selectedCard.action === 'forced_deal'">
                    <select v-model="targetId">
                        <option :value="null" disabled>Choose a player</option>
                        <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                    </select>
                    <select v-model="targetCardId" :disabled="targetId === null">
                        <option value="" disabled>Choose a property of theirs to take</option>
                        <option v-for="c in opponentPropertyCards(targetOpponent)" :key="c.id" :value="c.id">
                            {{ shortLabel(c.id) }} ({{ colorLabel(c.group) }})
                        </option>
                    </select>
                    <select v-model="giveCardId">
                        <option value="" disabled>Choose one of your properties to give</option>
                        <option v-for="c in myOwnPropertyCards" :key="c.id" :value="c.id">
                            {{ shortLabel(c.id) }} ({{ colorLabel(c.group) }})
                        </option>
                    </select>
                    <select v-if="targetCardId && parseCard(targetCardId).category !== 'property'" v-model="wildcardColor">
                        <option value="">Keep current color</option>
                        <option v-for="c in (parseCard(targetCardId).category === 'wildcard_any' ? COLORS : parseCard(targetCardId).colors)" :key="c" :value="c">
                            {{ colorLabel(c) }}
                        </option>
                    </select>
                    <button
                        class="md-btn"
                        :disabled="submitting || playsLeft < 1 || targetId === null || !targetCardId || !giveCardId"
                        @click="playForcedDeal(selectedCard.id)"
                    >
                        Play
                    </button>
                </template>

                <!-- HAT wa lamo2akhza EL SHORT! / Deal Breaker -->
                <template v-if="selectedCard.action === 'deal_breaker'">
                    <select v-model="targetId">
                        <option :value="null" disabled>Choose a player</option>
                        <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                    </select>
                    <select v-model="dealBreakerColor" :disabled="targetId === null">
                        <option value="" disabled>Choose one of their complete sets</option>
                        <option v-for="c in opponentCompleteSetColors(targetOpponent)" :key="c" :value="c">
                            {{ colorLabel(c) }}
                        </option>
                    </select>
                    <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null || !dealBreakerColor" @click="playDealBreaker(selectedCard.id)">
                        Play
                    </button>
                </template>
            </div>
        </section>
    </div>
</template>

<style scoped>
/* Deliberately plain — colors/fonts inherited via the CSS custom
   properties Show.vue sets on its root, same as every other phase
   component, but Masrawy Deal has no theme entry yet (see
   gameThemes.ts), so these resolve to defaultTheme's plain values. */

.md-root {
    margin-top: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.md-banner {
    font-family: var(--rc-font-display);
    font-size: 1.1rem;
    font-weight: 600;
    text-align: center;
}

.md-error {
    background: var(--rc-surface);
    border: 1px solid var(--rc-primary);
    color: var(--rc-primary);
    border-radius: 8px;
    padding: 0.75rem 1rem;
    font-size: 0.85rem;
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

.md-seat-name {
    margin-bottom: 0.25rem;
}

.md-seat-turn-tag {
    color: var(--rc-primary);
    font-weight: 400;
    font-size: 0.75rem;
}

.md-seat-hand,
.md-seat-bank {
    color: var(--rc-text-muted);
    margin-bottom: 0.15rem;
}

.md-group {
    font-size: 0.85rem;
}

.md-hand-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.md-card {
    border: 1px solid var(--rc-border);
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
    font-size: 0.8rem;
    cursor: pointer;
    text-align: left;
}

.md-card--selected {
    border-color: var(--rc-primary);
    background: var(--rc-surface);
}

.md-play-panel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    padding-top: 0.75rem;
    border-top: 1px dashed var(--rc-border);
}

.md-play-title {
    width: 100%;
    font-weight: 600;
    margin-bottom: 0.25rem;
}

.md-fieldset {
    border: 1px solid var(--rc-border);
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
}

.md-btn {
    border-radius: 6px;
    padding: 0.5rem 1rem;
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
</style>