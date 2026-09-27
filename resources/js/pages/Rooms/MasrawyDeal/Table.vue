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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import type { FormDataConvertible } from '@inertiajs/core'
import { VueDraggable } from 'vue-draggable-plus'
import type { Room, AuthUser, MasrawyYou, MasrawyTableState, MasrawySeat, CardCatalogEntry, MasrawyActivity } from '@/types/room'
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
const flippedCardIds = ref<string[]>([])
const flippedCardsLoaded = ref(false)
const flippedCardsStorageKey = `masrawy-deal-flipped-cards:${props.room.id}:${props.auth.user.id}`
const isReorderingHand = ref(false)
const isHandCollapsed = ref(false)

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

    try {
        const savedOrientations: unknown = JSON.parse(window.localStorage.getItem(flippedCardsStorageKey) ?? '[]')
        if (Array.isArray(savedOrientations) && savedOrientations.every(id => typeof id === 'string')) {
            flippedCardIds.value = savedOrientations
        }
    } catch {
        // Hand card orientation stays in memory when local storage is unavailable.
    }

    handOrderLoaded.value = true
    flippedCardsLoaded.value = true
})

watch(handOrder, order => {
    if (!handOrderLoaded.value) return

    try {
        window.localStorage.setItem(handOrderStorageKey, JSON.stringify(order))
    } catch {
        // Keep the in-memory order even when the browser blocks local storage.
    }
})

watch(flippedCardIds, ids => {
    if (!flippedCardsLoaded.value) return

    try {
        window.localStorage.setItem(flippedCardsStorageKey, JSON.stringify(ids))
    } catch {
        // Keep the in-memory orientation when local storage is unavailable.
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

function orientationColorsForCard(id: string): string[] {
    const entry = entryFor(id)
    const colors = entry?.colors ?? []
    if (entry?.type === 'wildcard') {
        if (colors.includes('green') && colors.includes('dark_blue')) return ['green', 'dark_blue']
        if (colors.includes('brown') && colors.includes('light_blue')) return ['brown', 'light_blue']
    }

    return colors
}

function activeColorForCard(id: string): string | undefined {
    const colors = orientationColorsForCard(id)
    if (colors.length !== 2) return undefined
    return colors[flippedCardIds.value.includes(id) ? 1 : 0]
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

const nextPlayerId = computed(() => {
    const players = table.value?.players ?? []
    const currentIndex = players.findIndex(seat => seat.id === table.value?.current_player_id)
    return currentIndex < 0 || players.length < 2 ? null : players[(currentIndex + 1) % players.length]?.id ?? null
})

function seatFor(id: number | string): MasrawySeat | undefined {
    return table.value?.players.find(s => String(s.id) === String(id))
}

const selectedPropertySet = ref<{ playerId: number; color: string } | null>(null)
const selectedPropertySetSeat = computed(() =>
    selectedPropertySet.value ? seatFor(selectedPropertySet.value.playerId) : undefined,
)
const selectedPropertySetGroup = computed(() => {
    if (!selectedPropertySet.value || !selectedPropertySetSeat.value) return undefined
    return selectedPropertySetSeat.value.properties[selectedPropertySet.value.color]
})
const selectedPropertySetCardIds = computed(() => {
    const group = selectedPropertySetGroup.value
    if (!group) return []
    return [...group.cards, ...(group.house ? [group.house] : []), ...(group.hotel ? [group.hotel] : [])]
})

function openPropertySet(playerId: number, color: string) {
    selectedPropertySet.value = { playerId, color }
}

function closePropertySet() {
    selectedPropertySet.value = null
}

const recentActivity = computed(() => [...(table.value?.recent_activity ?? [])].slice(-4).reverse())
const currentTurnSeat = computed(() => table.value?.players.find((seat) => seat.id === table.value?.current_player_id));
const currentTurnAttentionKey = computed(() => `${table.value?.turn_number ?? 0}:${table.value?.current_player_id ?? ''}`)
const dismissedMyTurnAttentionKey = ref<string | null>(null)
const showingPreviousTurn = ref(false)
let previousTurnTimer: ReturnType<typeof setTimeout> | null = null
const previousTurnSnapshot = ref<{ playerId: number; activity: MasrawyActivity[] } | null>(null)
const previousTurnActivity = computed(() => previousTurnSnapshot.value?.activity ?? [])
const previousTurnPlayerId = computed(() => previousTurnSnapshot.value?.playerId ?? null)
const turnViewSeat = computed(() =>
    showingPreviousTurn.value && previousTurnPlayerId.value !== null
        ? seatFor(previousTurnPlayerId.value)
        : currentTurnSeat.value,
)
const showMyTurnAttention = computed(
    () => gameIsLive.value && !showingPreviousTurn.value && isMyTurn.value && dismissedMyTurnAttentionKey.value !== currentTurnAttentionKey.value,
)
const currentTurnActivity = computed(() =>
    (table.value?.turn_activity ?? []).filter((event) => event.player_id === table.value?.current_player_id),
);
const turnViewActivity = computed(() =>
    showingPreviousTurn.value ? previousTurnActivity.value : currentTurnActivity.value,
)
const currentTurnMoveCardIds = computed(
    () =>
        new Set(
            turnViewActivity.value.flatMap((event) => [
                ...(event.card_id ? [event.card_id] : []),
                ...event.card_ids,
                ...(event.target_card_id ? [event.target_card_id] : []),
                ...(event.give_card_id ? [event.give_card_id] : []),
                ...event.double_rent_card_ids,
            ]),
        ),
);
const dismissedTurnPlayerId = ref<number | null>(null);
const paymentReceivedEvents = ref<MasrawyActivity[]>([])
const latestSeenActivityId = ref(Math.max(0, ...(table.value?.recent_activity ?? []).map((event) => event.id)))
const celebrationPieces = Array.from({ length: 28 }, (_, index) => index)
const winnerModalDismissed = ref(false)
const showWinnerModal = computed(() =>
    props.room.status === 'finished' && props.room.winner !== null && !winnerModalDismissed.value,
)

watch(
    () => table.value?.recent_activity.map((event) => event.id) ?? [],
    (activityIds) => {
        const unseenIds = activityIds.filter((id) => id > latestSeenActivityId.value)
        if (activityIds.length) latestSeenActivityId.value = Math.max(latestSeenActivityId.value, ...activityIds)
        if (unseenIds.length === 0) return

        const newPaymentsToMe = (table.value?.recent_activity ?? []).filter(
            (event) => unseenIds.includes(event.id) && event.type === 'pay' && event.target_id === myId.value,
        )
        if (newPaymentsToMe.length) paymentReceivedEvents.value = newPaymentsToMe
    },
)

const showTurnModal = computed(
    () =>
        gameIsLive.value &&
        (showingPreviousTurn.value || !isMyTurn.value) &&
        pending.value === null &&
        turnViewSeat.value !== undefined &&
        dismissedTurnPlayerId.value !== turnViewSeat.value.id,
);

watch(
    () => table.value,
    (nextTable, previousTable) => {
        if (!nextTable || !previousTable || nextTable.turn_number === previousTable.turn_number) return

        previousTurnSnapshot.value = {
            playerId: previousTable.current_player_id,
            activity: previousTable.turn_activity.filter((event) => event.player_id === previousTable.current_player_id),
        }
        dismissedTurnPlayerId.value = null
        showingPreviousTurn.value = true
        if (previousTurnTimer !== null) clearTimeout(previousTurnTimer)
        previousTurnTimer = setTimeout(() => {
            showingPreviousTurn.value = false
            previousTurnTimer = null
        }, 5000)
    },
);

function dismissTurnModal() {
    dismissedTurnPlayerId.value = turnViewSeat.value?.id ?? null;
}

function dismissMyTurnAttention() {
    dismissedMyTurnAttentionKey.value = currentTurnAttentionKey.value
}

function dismissPaymentReceipt() {
    paymentReceivedEvents.value = []
}

function activityCardLabels(event: MasrawyActivity): string[] {
    const ids = [event.card_id, ...event.card_ids, event.target_card_id, event.give_card_id, ...event.double_rent_card_ids]
    return [...new Set(ids.filter((id): id is string => Boolean(id)))].map(id => label(id))
}

function activityDescription(event: MasrawyActivity): string {
    const cardName = event.card_id ? label(event.card_id) : ''
    const target = event.target_id !== null ? playerName(event.target_id) : ''
    const targetCardName = event.target_card_id ? label(event.target_card_id) : ''
    const giveCardName = event.give_card_id ? label(event.give_card_id) : ''
    const color = event.color ? colorLabel(event.color) : ''

    switch (event.type) {
        case 'play_money': return `banked ${cardName}`
        case 'play_property': return `played ${cardName} into ${color}`
        case 'bank_card': return `banked ${cardName} as money (${entryFor(event.card_id ?? '')?.value ?? 0}M)`
        case 'play_pass_go': return `played ${cardName} and drew 2 cards`
        case 'play_shisha': return `added SHISHA to the ${color} set`
        case 'play_wil3a': return `added WIL3A to the ${color} set`
        case 'play_debt_collector': return `played ${cardName} against ${target}`
        case 'play_birthday': return `played ${cardName} against everyone`
        case 'play_rent': return `played ${cardName} for ${color} rent${target ? ` against ${target}` : ''}${event.double_rent_card_ids.length ? ' (doubled)' : ''}`
        case 'play_sly_deal': return `played ${cardName} and took ${targetCardName} from ${target}`
        case 'play_forced_deal': return `played ${cardName} and swapped ${targetCardName} for ${giveCardName}`
        case 'play_deal_breaker': return `played ${cardName} and took the ${colorLabel(event.target_color ?? '')} set from ${target}`
        case 'move_wildcard': return `moved ${cardName} to ${color}`
        case 'discard': return `discarded ${cardName}`
        case 'respond_no': return `played ${cardName} to stop an action`
        case 'decline': return `declined the charge from ${target}`
        case 'pay': return `paid ${event.card_ids.map(id => label(id)).join(', ')}${target ? ` to ${target}` : ''}`
        case 'draw': return 'drew cards'
        default: return 'made a move'
    }
}

const payableOptions = computed(() => {
    const assets = Object.entries(you.value?.payable_assets ?? {}).map(([id, value]) => {
        const inBank = mySeat.value?.bank.includes(id) ?? false
        const group = Object.entries(mySeat.value?.properties ?? {}).find(([, property]) => property.cards.includes(id) || property.house === id || property.hotel === id)?.[0]
        const entry = entryFor(id)
        const description = inBank
            ? `${label(id)} · bank`
            : group
                ? `${label(id)} · ${colorLabel(group)} ${entry?.type === 'wildcard' ? 'wildcard' : 'property'}`
                : label(id)

        return { id, value, description, inBank, group, entry }
    })
    const counts = new Map<string, number>()
    for (const asset of assets) counts.set(asset.description, (counts.get(asset.description) ?? 0) + 1)
    const seen = new Map<string, number>()

    return assets.map(asset => {
        const copy = (seen.get(asset.description) ?? 0) + 1
        seen.set(asset.description, copy)
        const copyLabel = (counts.get(asset.description) ?? 0) > 1 ? ` · copy ${copy}` : ''
        return { ...asset, display: `${asset.description}${copyLabel} · ${asset.value}M` }
    })
})

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
const paymentReason = computed(() => {
    if (!pending.value) return 'Pay charge'

    const source = playerName(pending.value.source_id)
    const cardName = label(pending.value.card_id)
    const color = pending.value.color ? ` for ${colorLabel(pending.value.color)} rent` : ''

    return `Pay ${cardName}${color} from ${source}`
})
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

function playShisha(cardId: string, color: string) {
    submit({ type: 'play_shisha', card_id: cardId, color }, clearSelection)
}

function playWil3a(cardId: string, color: string) {
    submit({ type: 'play_wil3a', card_id: cardId, color }, clearSelection)
}

function bankCard(cardId: string) {
    submit({ type: 'bank_card', card_id: cardId }, clearSelection)
}

const confirmBankCardId = ref<string | null>(null)

function requestBankCard(cardId: string) {
    confirmBankCardId.value = cardId
}

function cancelBankCard() {
    confirmBankCardId.value = null
}

function confirmBankCard() {
    if (confirmBankCardId.value) bankCard(confirmBankCardId.value)
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

const activeWildcardColor = computed(() => {
    if (!selectedIsTwoColorWild.value) return undefined
    return activeColorForCard(selectedEntry.value?.id ?? '')
})

const activeRentColor = computed(() => {
    if (!selectedIsPlainRent.value) return undefined
    return activeColorForCard(selectedEntry.value?.id ?? '')
})

function rotateSelectedWildcard() {
    const cardId = selectedEntry.value?.id
    if (!cardId) return

    flippedCardIds.value = flippedCardIds.value.includes(cardId)
        ? flippedCardIds.value.filter(id => id !== cardId)
        : [...flippedCardIds.value, cardId]
}

function selectCard(id: string) {
    selectedCardId.value = selectedCardId.value === id ? null : id
    confirmBankCardId.value = null
    // Reset any in-progress sub-form when switching cards.
    wildcardColor.value = ''
    targetId.value = null
    targetCardId.value = ''
    giveCardId.value = ''
    dealBreakerColor.value = ''
    doubleRentIds.value = []
    rentColor.value = ''
    targetColor.value = ''
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
const targetColor = ref('')
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
const myCompleteSetColors = computed(() => myOwnColors.value.filter(color => {
    const group = mySeat.value?.properties[color]
    return Boolean(group && group.cards.length >= (table.value?.set_size[color] ?? Number.POSITIVE_INFINITY))
}))
const myShishaColors = computed(() => myCompleteSetColors.value.filter(color => mySeat.value?.properties[color]?.house === null))
const myWil3aColors = computed(() => myCompleteSetColors.value.filter(color => {
    const group = mySeat.value?.properties[color]
    return Boolean(group?.house && !group.hotel)
}))

function playDebtCollector(cardId: string) {
    if (targetId.value === null) return
    submit({ type: 'play_debt_collector', card_id: cardId, target_id: targetId.value }, clearSelection)
}

function playBirthday(cardId: string) {
    submit({ type: 'play_birthday', card_id: cardId }, clearSelection)
}

function playRent(cardId: string, isWild: boolean, color: string) {
    if (!color) return
    const payload: Record<string, FormDataConvertible> = { type: 'play_rent', card_id: cardId, color }
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
    submit({ type: 'respond_no', card_id: cardId, target_id: targetIdForCharge }, () => {
        respondCardByTarget.value[String(targetIdForCharge)] = ''
        paySelection.value = []
        isPayModalOpen.value = false
        isPayModalCollapsed.value = false
    })
}

function decline(targetIdForCharge: number) {
    submit({ type: 'decline', target_id: targetIdForCharge })
}

// --- Paying -------------------------------------------------------------

const paySelection = ref<string[]>([])
const isPayModalOpen = ref(false)
const isPayModalCollapsed = ref(false)

watch(
    () => you.value?.owes ?? null,
    (owed, previousOwed) => {
        if (owed !== null && owed !== previousOwed) {
            paySelection.value = []
            actionError.value = null
            isPayModalOpen.value = true
            isPayModalCollapsed.value = false
        } else if (owed === null) {
            paySelection.value = []
            isPayModalOpen.value = false
            isPayModalCollapsed.value = false
        }
    },
    { immediate: true },
)

const payTotal = computed(() =>
    paySelection.value.reduce((sum, id) => sum + (you.value?.payable_assets?.[id] ?? 0), 0),
)

function togglePayCard(id: string) {
    actionError.value = null
    const i = paySelection.value.indexOf(id)
    if (i === -1) paySelection.value.push(id)
    else paySelection.value.splice(i, 1)
}

function openPayModal() {
    actionError.value = null
    isPayModalOpen.value = true
    isPayModalCollapsed.value = false
}

function collapsePayModal() {
    isPayModalOpen.value = false
    isPayModalCollapsed.value = true
}

function pay() {
    if (paySelection.value.length === 0 || payTotal.value < (you.value?.owes ?? 0)) return
    submit({ type: 'pay', card_ids: [...paySelection.value] }, () => {
        paySelection.value = []
        isPayModalOpen.value = false
        isPayModalCollapsed.value = false
    })
}

// --- Hand limit discard ---------------------------------------------------

const overHandLimit = computed(() => (you.value?.hand.length ?? 0) > 7)
const canDiscard = computed(() => canAct.value && table.value?.has_drawn_this_turn === true && overHandLimit.value)
const autoEndTurnReady = computed(() =>
    canAct.value &&
    table.value?.has_drawn_this_turn === true &&
    playsLeft.value <= 0 &&
    !overHandLimit.value,
)
const autoEndTurnAttemptedKey = ref<string | null>(null)

watch(
    () => [autoEndTurnReady.value, submitting.value] as const,
    ([ready, isSubmitting]) => {
        const turnKey = `${table.value?.turn_number ?? ''}:${myId.value}`
        if (!ready || isSubmitting || autoEndTurnAttemptedKey.value === turnKey) return

        autoEndTurnAttemptedKey.value = turnKey
        endTurn()
    },
    { immediate: true },
)

onUnmounted(() => {
    if (previousTurnTimer !== null) clearTimeout(previousTurnTimer)
})
</script>

<template>
    <div class="md-root" :class="{ 'md-root--hand-collapsed': isHandCollapsed }">
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

            <p v-if="actionError && !isPayModalOpen" role="alert" class="md-error">{{ actionError }}</p>

            <!-- Turn / draw pile summary -->
            <section class="md-summary">
                <div class="md-summary-row">
                    <span class="md-turn-status" :class="{ 'md-turn-status--mine': isMyTurn }" aria-live="polite">
                        <strong>{{ isMyTurn ? 'YOUR TURN' : `${playerName(table.current_player_id)}’S TURN` }}</strong>
                        <span v-if="nextPlayerId !== null">Next: {{ playerName(nextPlayerId) }}</span>
                    </span>
                    <span>Plays left this turn: <strong>{{ playsLeft }}</strong></span>
                    <span>Draw pile: <strong>{{ table.draw_pile_count }}</strong></span>
                    <span>Discard pile: <strong>{{ table.discard_pile.length }}</strong></span>
                </div>

                <div v-if="recentActivity.length" class="md-activity" aria-live="polite" aria-label="Recent plays">
                    <strong class="md-activity-title">Recent plays</strong>
                    <ol>
                        <li v-for="event in recentActivity" :key="event.id">
                            <strong>{{ playerName(event.player_id) }}</strong> {{ activityDescription(event) }}
                        </li>
                    </ol>
                </div>

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
                    <p v-if="entry.charge?.phase === 'paying'">
                        You can still play DA 3AND OMMO... before choosing payment{{ pending.kind === 'birthday' || pending.kind === 'rent' ? ` (for ${playerName(entry.targetId)})` : '' }}.
                    </p>
                    <p v-else>
                        You may respond{{ pending.kind === 'birthday' || pending.kind === 'rent' ? ` (for ${playerName(entry.targetId)})` : '' }}:
                    </p>

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
                        {{ entry.charge?.phase === 'paying' ? 'Play DA 3AND OMMO... instead' : 'Play It' }}
                    </button>
                    <button v-if="entry.charge?.phase !== 'paying'" class="md-btn md-btn--muted" :disabled="submitting" @click="decline(entry.targetId)">
                        Decline
                    </button>
                </div>

            </section>

            <!-- My hand -->
            <section class="md-hand" :class="{ 'md-hand--collapsed': isHandCollapsed }">
                <template v-if="isHandCollapsed">
                    <button
                        class="md-hand-expand"
                        type="button"
                        :aria-label="`Show hand cards (${you.hand.length})`"
                        title="Show hand cards"
                        @click="isHandCollapsed = false"
                    >
                        <span aria-hidden="true">▤</span>
                        <span>{{ you.hand.length }}</span>
                    </button>
                    <button
                        v-if="isMyTurn && !table.has_drawn_this_turn && pending === null"
                        class="md-btn md-btn--primary md-hand-collapsed-draw"
                        type="button"
                        :disabled="submitting"
                        @click="draw"
                    >Draw</button>
                </template>
                <div v-else class="md-hand-header">
                    <h3 class="md-section-title">Your Hand ({{ you.hand.length }}/7)</h3>
                    <div class="md-hand-actions">
                        <button
                            class="md-btn md-btn--muted md-hand-collapse"
                            type="button"
                            aria-label="Collapse hand cards"
                            title="Collapse hand cards"
                            @click="isHandCollapsed = true"
                        >
                            ↓
                        </button>
                        <button
                            v-if="you.hand.length > 1"
                            class="md-btn md-btn--muted"
                            :aria-pressed="isReorderingHand"
                            @click="toggleHandReordering"
                        >
                            {{ isReorderingHand ? 'Done sorting' : 'Sort hand' }}
                        </button>
                        <button
                            v-if="isMyTurn && !table.has_drawn_this_turn && pending === null"
                            class="md-btn md-btn--primary"
                            type="button"
                            :disabled="submitting"
                            @click="draw"
                        >Draw</button>
                        <button
                            v-if="isMyTurn && table.has_drawn_this_turn && pending === null && !overHandLimit && playsLeft > 0"
                            class="md-btn"
                            :disabled="submitting"
                            @click="endTurn"
                        >
                            End Turn
                        </button>
                    </div>
                </div>

                <p v-if="isReorderingHand" class="md-hint md-hand-sort-hint">
                    Press and hold a card, then drag it to reorder. Your order is saved on this device.
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
                        v-for="cardId in handOrder"
                        :key="cardId"
                        class="md-hand-card"
                    >
                        <button
                            class="md-card-btn"
                            :class="{ 'md-card-btn--selected': selectedCardId === cardId }"
                            :aria-label="`${isReorderingHand ? 'Reorder' : 'Select'} ${label(cardId)}`"
                            :aria-pressed="selectedCardId === cardId"
                            :disabled="!gameIsLive || isReorderingHand"
                            @click="selectCard(cardId)"
                        >
                            <MasrawyCard :entry="entryFor(cardId)!" :active-color="activeColorForCard(cardId)" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                        </button>
                    </div>
                </VueDraggable>

                <p v-if="!canAct && !selectedEntry" class="md-hint">
                    {{ pending ? 'Waiting on a pending action.' : 'Wait for your turn to play a card.' }}
                </p>

            </section>

            <section v-if="selectedEntry" class="md-play-panel">
                    <header class="md-play-panel-header">
                        <h3>{{ label(selectedEntry.id) }}</h3>
                        <button class="md-play-panel-close" type="button" aria-label="Close card options" @click="clearSelection">×</button>
                    </header>
                    <MasrawyCard
                        :entry="selectedEntry"
                        size="lg"
                        :active-color="selectedIsTwoColorWild ? activeWildcardColor : (selectedIsPlainRent ? activeRentColor : undefined)"
                        :rent-chart="rentChartFor(selectedEntry.id)"
                        :set-size="setSizeFor(selectedEntry.id)"
                        :wild-rent-charts="wildRentChartsFor(selectedEntry.id)"
                        :wild-set-sizes="wildSetSizesFor(selectedEntry.id)"
                    />

                    <div class="md-play-controls">
                        <p v-if="selectedIsTwoColorWild || selectedIsPlainRent" class="md-wildcard-active-hint">
                            Top color is active:
                            <strong>{{ colorLabel((selectedIsTwoColorWild ? activeWildcardColor : activeRentColor) ?? '') }}</strong>.
                            Rotate 180° to switch.
                        </p>
                        <button
                            v-if="selectedIsTwoColorWild || selectedIsPlainRent"
                            class="md-btn md-btn--muted"
                            type="button"
                            @click="rotateSelectedWildcard"
                        >
                            ↻ Rotate 180°
                        </button>
                        <p v-if="!canAct" class="md-hint">
                            {{ pending ? 'You can adjust this card while waiting for the response.' : 'You can prepare this card now. Play options unlock on your turn.' }}
                        </p>

                        <template v-if="canAct">
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
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !activeWildcardColor" @click="playProperty(selectedEntry.id, activeWildcardColor)">
                                Play as Property ({{ colorLabel(activeWildcardColor ?? '') }})
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

                        <!-- GARAB 7AZAK / Pass Go -->
                        <button v-if="selectedEntry.action === 'pass_go'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playPassGo(selectedEntry.id)">
                            Play Pass Go (draw 2)
                        </button>

                        <!-- SHISHA / WIL3A -->
                        <template v-if="selectedEntry.action === 'house'">
                            <select v-model="targetColor" aria-label="Choose a complete set for SHISHA">
                                <option value="" disabled>Choose a complete set</option>
                                <option v-for="c in myShishaColors" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !targetColor" @click="playShisha(selectedEntry.id, targetColor)">
                                Play SHISHA on {{ targetColor ? colorLabel(targetColor) : 'a set' }}
                            </button>
                        </template>
                        <template v-if="selectedEntry.action === 'hotel'">
                            <select v-model="targetColor" aria-label="Choose a set with SHISHA for WIL3A">
                                <option value="" disabled>Choose a complete set with SHISHA</option>
                                <option v-for="c in myWil3aColors" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !targetColor" @click="playWil3a(selectedEntry.id, targetColor)">
                                Play WIL3A on {{ targetColor ? colorLabel(targetColor) : 'a set' }}
                            </button>
                        </template>

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
                            <select v-if="selectedIsWildRent" v-model="rentColor" aria-label="Choose which property color to charge">
                                <option value="" disabled>Which color to charge</option>
                                <option v-for="c in myOwnColors" :key="c" :value="c">
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
                                :disabled="submitting || playsLeft < (1 + doubleRentIds.length) || !(selectedIsPlainRent ? activeRentColor : rentColor) || (selectedIsWildRent && targetId === null)"
                                @click="playRent(selectedEntry.id, !!selectedIsWildRent, selectedIsPlainRent ? activeRentColor! : rentColor)"
                            >
                                Play Rent<span v-if="selectedIsPlainRent && activeRentColor"> ({{ colorLabel(activeRentColor) }})</span>
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

                        <!-- Put the optional money/discard choices after the card's main effect. -->
                        <button
                            v-if="(selectedEntry.type === 'action' || selectedEntry.type === 'rent') && confirmBankCardId !== selectedEntry.id"
                            class="md-btn md-btn--muted"
                            :disabled="submitting || playsLeft < 1"
                            @click="requestBankCard(selectedEntry.id)"
                        >
                            Bank instead · worth {{ selectedEntry.value }}M
                        </button>

                        <div v-if="confirmBankCardId === selectedEntry.id" class="md-bank-confirm" role="alertdialog" aria-labelledby="md-bank-confirm-title">
                            <strong id="md-bank-confirm-title">Bank {{ label(selectedEntry.id) }} for {{ selectedEntry.value }}M?</strong>
                            <p>This uses a play and permanently gives up this card’s effect.</p>
                            <div>
                                <button class="md-btn md-btn--primary" :disabled="submitting" @click="confirmBankCard">Confirm bank</button>
                                <button class="md-btn md-btn--muted" :disabled="submitting" @click="cancelBankCard">Keep card</button>
                            </div>
                        </div>

                        <button class="md-btn md-btn--muted" :disabled="submitting || !canDiscard" @click="discard(selectedEntry.id)">
                            Discard
                        </button>
                        </template>
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
                            <div class="md-seat-bank-stack" :aria-label="`${seat.bank.length} money cards in bank`">
                                <span v-for="(cardId, index) in seat.bank" :key="cardId" class="md-seat-bank-card" :style="{ zIndex: index + 1 }">
                                    <MasrawyCard :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                                </span>
                            </div>
                        </div>
                        <p v-else class="md-seat-bank">Bank: empty</p>

                        <div v-if="Object.keys(seat.properties).length > 0" class="md-seat-properties">
                            <button
                                v-for="(group, color) in seat.properties"
                                :key="color"
                                type="button"
                                class="md-seat-set-preview"
                                :aria-label="`View ${playerName(seat.id)}’s ${colorLabel(String(color))} set in detail`"
                                @click="openPropertySet(seat.id, String(color))"
                            >
                                <span class="md-seat-set-heading">
                                    <strong>{{ colorLabel(String(color)) }}</strong>
                                    <small>{{ group.cards.length + (group.house ? 1 : 0) + (group.hotel ? 1 : 0) }} cards<span v-if="group.house"> · SHISHA</span><span v-if="group.hotel"> · WIL3A</span></small>
                                </span>
                                <span
                                    class="md-seat-set-stack"
                                    aria-hidden="true"
                                    :style="{ '--set-card-count': group.cards.length + (group.house ? 1 : 0) + (group.hotel ? 1 : 0) }"
                                >
                                    <span
                                        v-for="(cardId, index) in [...group.cards, ...(group.house ? [group.house] : []), ...(group.hotel ? [group.hotel] : [])]"
                                        :key="cardId"
                                        class="md-seat-set-card"
                                        :style="{ zIndex: index + 1 }"
                                    >
                                        <MasrawyCard
                                            :entry="entryFor(cardId)!"
                                            :active-color="entryFor(cardId)?.type === 'wildcard' ? String(color) : undefined"
                                            :rent-chart="rentChartFor(cardId)"
                                            :set-size="setSizeFor(cardId)"
                                            :wild-rent-charts="wildRentChartsFor(cardId)"
                                            :wild-set-sizes="wildSetSizesFor(cardId)"
                                        />
                                    </span>
                                </span>
                                <span class="md-seat-set-hint">Click to view cards</span>
                            </button>
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

        <button
            v-if="gameIsLive && !isMyTurn && pending === null && turnViewSeat && !showTurnModal"
            class="md-turn-modal-reopen"
            @click="dismissedTurnPlayerId = null"
        >
            Watch {{ playerName(turnViewSeat.id) }}’s turn
        </button>

        <div v-if="showTurnModal && turnViewSeat" class="md-turn-modal-backdrop" @click.self="dismissTurnModal" @keydown.esc="dismissTurnModal">
            <section class="md-turn-modal" role="dialog" aria-modal="true" aria-labelledby="md-turn-modal-title">
                <header class="md-turn-modal-header">
                    <div>
                        <p class="md-turn-modal-eyebrow">LIVE TURN</p>
                        <h2 id="md-turn-modal-title">{{ playerName(turnViewSeat.id) }} is playing</h2>
                        <p class="md-turn-modal-hand-count">{{ turnViewSeat.hand_count }} cards in hand · hand stays private</p>
                    </div>
                    <button class="md-turn-modal-close" type="button" aria-label="Close turn view" @click="dismissTurnModal">×</button>
                </header>

                <div class="md-turn-modal-body">
                    <div class="md-turn-modal-tableau">
                        <section class="md-turn-modal-section">
                            <h3>
                                Bank <span>{{ seatBankTotal(turnViewSeat) }}M</span>
                            </h3>
                            <div v-if="turnViewSeat.bank.length" class="md-turn-card-stack md-turn-card-stack--bank">
                                <div
                                    v-for="(cardId, index) in turnViewSeat.bank"
                                    :key="cardId"
                                    class="md-turn-card"
                                    :class="{ 'md-turn-card--moved': currentTurnMoveCardIds.has(cardId) }"
                                    :style="{ zIndex: index + 1 }"
                                >
                                    <MasrawyCard
                                        :entry="entryFor(cardId)!"
                                        :rent-chart="rentChartFor(cardId)"
                                        :set-size="setSizeFor(cardId)"
                                        :wild-rent-charts="wildRentChartsFor(cardId)"
                                        :wild-set-sizes="wildSetSizesFor(cardId)"
                                    />
                                </div>
                            </div>
                            <p v-else class="md-turn-modal-empty">No bank cards yet</p>
                        </section>

                        <section class="md-turn-modal-section">
                            <h3>
                                Properties <span>{{ Object.keys(turnViewSeat.properties).length }} sets</span>
                            </h3>
                            <div v-if="Object.keys(turnViewSeat.properties).length" class="md-turn-property-groups">
                                <div v-for="(group, color) in turnViewSeat.properties" :key="color" class="md-turn-property-group">
                                    <h4>
                                        {{ colorLabel(String(color)) }}<span v-if="group.house"> · SHISHA</span
                                        ><span v-if="group.hotel"> · WIL3A</span>
                                    </h4>
                                    <div
                                        class="md-turn-card-stack"
                                        :style="{ '--set-card-count': group.cards.length + (group.house ? 1 : 0) + (group.hotel ? 1 : 0) }"
                                    >
                                        <div
                                            v-for="(cardId, index) in [
                                                ...group.cards,
                                                ...(group.house ? [group.house] : []),
                                                ...(group.hotel ? [group.hotel] : []),
                                            ]"
                                            :key="cardId"
                                            class="md-turn-card"
                                            :class="{ 'md-turn-card--moved': currentTurnMoveCardIds.has(cardId) }"
                                            :style="{ zIndex: index + 1 }"
                                        >
                                            <MasrawyCard
                                                :entry="entryFor(cardId)!"
                                                :active-color="entryFor(cardId)?.type === 'wildcard' ? String(color) : undefined"
                                                :rent-chart="rentChartFor(cardId)"
                                                :set-size="setSizeFor(cardId)"
                                                :wild-rent-charts="wildRentChartsFor(cardId)"
                                                :wild-set-sizes="wildSetSizesFor(cardId)"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="md-turn-modal-empty">No properties on the table yet</p>
                        </section>
                    </div>

                    <aside class="md-turn-modal-log" aria-label="Current player's moves">
                        <h3>
                            Moves this turn <span>{{ turnViewActivity.length }}</span>
                        </h3>
                        <ol v-if="turnViewActivity.length">
                            <li v-for="(event, index) in turnViewActivity" :key="event.id" class="md-turn-activity" :class="{ 'md-turn-activity--latest': index === turnViewActivity.length - 1 }">
                                <span class="md-turn-activity-dot" aria-hidden="true"></span>
                                <div>
                                    <strong>{{ activityDescription(event) }}</strong>
                                    <small>Move {{ event.id }}</small>
                                    <div v-if="activityCardLabels(event).length" class="md-turn-activity-cards">
                                        <span v-for="cardLabel in activityCardLabels(event)" :key="cardLabel">{{ cardLabel }}</span>
                                    </div>
                                </div>
                            </li>
                        </ol>
                        <p v-else class="md-turn-modal-empty">Their moves will appear here as they play.</p>
                    </aside>
                </div>
            </section>
        </div>

        <div
            v-if="selectedPropertySet && selectedPropertySetGroup && selectedPropertySetSeat"
            class="md-set-modal-backdrop"
            @click.self="closePropertySet"
            @keydown.esc="closePropertySet"
        >
            <section class="md-set-modal" role="dialog" aria-modal="true" aria-labelledby="md-set-modal-title">
                <header class="md-set-modal-header">
                    <div>
                        <p class="md-set-modal-eyebrow">{{ playerName(selectedPropertySetSeat.id) }}’S SET</p>
                        <h2 id="md-set-modal-title">
                            {{ colorLabel(selectedPropertySet.color) }}
                            <span v-if="selectedPropertySetGroup.house"> · SHISHA</span>
                            <span v-if="selectedPropertySetGroup.hotel"> · WIL3A</span>
                        </h2>
                    </div>
                    <button class="md-set-modal-close" type="button" aria-label="Close set details" @click="closePropertySet">×</button>
                </header>
                <div class="md-set-modal-cards" :aria-label="`${selectedPropertySetCardIds.length} cards in ${colorLabel(selectedPropertySet.color)} set`">
                    <div v-for="cardId in selectedPropertySetCardIds" :key="cardId" class="md-set-modal-card">
                        <MasrawyCard
                            :entry="entryFor(cardId)!"
                            size="lg"
                            :active-color="entryFor(cardId)?.type === 'wildcard' ? selectedPropertySet.color : undefined"
                            :rent-chart="rentChartFor(cardId)"
                            :set-size="setSizeFor(cardId)"
                            :wild-rent-charts="wildRentChartsFor(cardId)"
                            :wild-set-sizes="wildSetSizesFor(cardId)"
                        />
                    </div>
                </div>
                <footer class="md-set-modal-footer">
                    <button class="md-btn md-btn--muted" type="button" @click="closePropertySet">Close</button>
                </footer>
            </section>
        </div>

        <div v-if="showMyTurnAttention" class="md-turn-attention-backdrop" @click.self="dismissMyTurnAttention" @keydown.esc="dismissMyTurnAttention">
            <section class="md-turn-attention" role="dialog" aria-modal="true" aria-labelledby="md-turn-attention-title">
                <span class="md-turn-attention-icon" aria-hidden="true">⏱</span>
                <p class="md-turn-modal-eyebrow">YOUR TURN</p>
                <h2 id="md-turn-attention-title">{{ playerName(myId) }}, it’s your turn!</h2>
                <p>{{ table.has_drawn_this_turn ? 'Your turn is underway. Continue your plays.' : 'Draw your cards, then make your plays.' }}</p>
                <button class="md-btn md-btn--primary" type="button" @click="dismissMyTurnAttention">Let’s play</button>
            </section>
        </div>

        <div v-if="paymentReceivedEvents.length && !isPayModalOpen && !showWinnerModal" class="md-payment-received-backdrop" @click.self="dismissPaymentReceipt" @keydown.esc="dismissPaymentReceipt">
            <section class="md-payment-received" role="dialog" aria-modal="true" aria-labelledby="md-payment-received-title">
                <header class="md-payment-received-header">
                    <div>
                        <p class="md-turn-modal-eyebrow">RENT RECEIVED</p>
                        <h2 id="md-payment-received-title">You got paid!</h2>
                    </div>
                    <button class="md-turn-modal-close" type="button" aria-label="Close rent receipt" @click="dismissPaymentReceipt">×</button>
                </header>
                <ul class="md-payment-receipt-list">
                    <li v-for="event in paymentReceivedEvents" :key="event.id">
                        <strong>{{ playerName(event.player_id) }} paid you:</strong>
                        <div class="md-payment-receipt-cards">
                            <span v-for="cardId in event.card_ids" :key="cardId">
                                {{ label(cardId) }} · {{ entryFor(cardId)?.value ?? 0 }}M
                            </span>
                        </div>
                    </li>
                </ul>
                <footer class="md-pay-modal-footer">
                    <button class="md-btn md-btn--primary" type="button" @click="dismissPaymentReceipt">Got it</button>
                </footer>
            </section>
        </div>

        <div v-if="showWinnerModal" class="md-winner-backdrop">
            <div class="md-winner-confetti" aria-hidden="true">
                <span
                    v-for="piece in celebrationPieces"
                    :key="piece"
                    class="md-winner-confetti-piece"
                    :style="{
                        left: `${(piece * 37) % 100}%`,
                        animationDelay: `${-((piece % 9) * 0.42)}s`,
                        '--confetti-hue': `${(piece * 47) % 360}`,
                    }"
                ></span>
            </div>
            <section class="md-winner-modal" role="dialog" aria-modal="true" aria-labelledby="md-winner-title">
                <button class="md-winner-close" type="button" aria-label="Close winner announcement" @click="winnerModalDismissed = true">×</button>
                <span class="md-winner-trophy" aria-hidden="true">🏆</span>
                <p class="md-turn-modal-eyebrow">GAME OVER</p>
                <h2 id="md-winner-title">{{ room.winner === String(myId) ? 'You won!' : `${playerName(room.winner ?? '')} wins!` }}</h2>
                <p>{{ room.winner === String(myId) ? 'Congratulations! You completed the winning sets.' : `${playerName(room.winner ?? '')} completed the winning sets.` }}</p>
                <button class="md-btn md-btn--primary" type="button" @click="winnerModalDismissed = true">Celebrate!</button>
            </section>
        </div>

        <button
            v-if="you.owes !== null && isPayModalCollapsed"
            class="md-pay-reopen"
            type="button"
            @click="openPayModal"
        >
            Pay {{ you.owes }}M
        </button>

        <div v-if="isPayModalOpen && you.owes !== null" class="md-pay-modal-backdrop" @click.self="collapsePayModal" @keydown.esc="collapsePayModal">
            <section class="md-pay-modal" role="dialog" aria-modal="true" aria-labelledby="md-pay-modal-title">
                <header class="md-pay-modal-header">
                    <div>
                        <h2 id="md-pay-modal-title">{{ paymentReason }}</h2>
                        <p>You owe <strong>{{ you.owes }}M</strong>. Selected: <strong>{{ payTotal }}M</strong>.</p>
                    </div>
                    <button class="md-btn md-btn--muted md-pay-modal-close" aria-label="Collapse payment window" @click="collapsePayModal">−</button>
                </header>

                <p v-if="actionError" class="md-pay-modal-error" role="alert">{{ actionError }}</p>
                <p v-else-if="payTotal < you.owes" class="md-pay-modal-error" role="status">
                    Select at least {{ you.owes - payTotal }}M more to cover what you owe.
                </p>

                <div v-if="payableOptions.length" class="md-pay-card-grid">
                    <button
                        v-for="asset in payableOptions"
                        :key="asset.id"
                        type="button"
                        class="md-pay-card"
                        :class="{ 'md-pay-card--selected': paySelection.includes(asset.id) }"
                        :aria-pressed="paySelection.includes(asset.id)"
                        @click="togglePayCard(asset.id)"
                    >
                        <span class="md-pay-card-face">
                            <MasrawyCard
                                v-if="asset.entry"
                                :entry="asset.entry"
                                :active-color="asset.group ?? activeColorForCard(asset.id)"
                                :rent-chart="rentChartFor(asset.id)"
                                :set-size="setSizeFor(asset.id)"
                                :wild-rent-charts="wildRentChartsFor(asset.id)"
                                :wild-set-sizes="wildSetSizesFor(asset.id)"
                            />
                        </span>
                        <span class="md-pay-card-info">{{ asset.inBank ? 'Bank' : asset.group ? colorLabel(asset.group) : 'Property' }} · {{ asset.value }}M</span>
                        <span v-if="paySelection.includes(asset.id)" class="md-pay-card-check" aria-hidden="true">✓</span>
                    </button>
                </div>
                <p v-else class="md-pay-empty">You have no cards available to pay with.</p>

                <footer class="md-pay-modal-footer">
                    <button class="md-btn md-btn--muted" @click="collapsePayModal">Collapse</button>
                    <button class="md-btn md-btn--primary" :disabled="submitting || paySelection.length === 0 || payTotal < you.owes" @click="pay">
                        Pay {{ payTotal }}M
                    </button>
                </footer>
            </section>
        </div>
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
    padding-bottom: 290px;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.md-root--hand-collapsed {
    padding-bottom: 88px;
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

.md-hand {
    position: fixed;
    z-index: 900;
    left: 50%;
    bottom: 0;
    width: min(1180px, calc(100vw - 1rem));
    max-height: min(48dvh, 330px);
    overflow: hidden;
    transform: translateX(-50%);
    border-radius: 14px 14px 0 0;
    padding-bottom: calc(1rem + env(safe-area-inset-bottom));
    box-shadow: 0 -8px 28px rgba(0, 0, 0, 0.24);
}

.md-hand--collapsed {
    left: 1rem;
    right: auto;
    bottom: calc(1rem + env(safe-area-inset-bottom));
    width: auto;
    max-height: none;
    overflow: visible;
    transform: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border-radius: 999px;
    padding: 0;
}

.md-hand--collapsed > :not(.md-hand-expand):not(.md-hand-collapsed-draw) {
    display: none;
}

.md-hand-collapsed-draw {
    white-space: nowrap;
}

.md-hand-expand {
    display: grid;
    width: 3.5rem;
    height: 3.5rem;
    place-content: center;
    gap: 0.05rem;
    border: 2px solid var(--rc-primary);
    border-radius: 50%;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    font: inherit;
    font-size: 0.75rem;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.35);
}

.md-hand-expand span:first-child {
    font-size: 1.1rem;
    line-height: 1;
}

.md-hand-collapse {
    min-width: 44px;
    padding-inline: 0.65rem;
    font-size: 1.1rem;
}

.md-summary-row {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    font-size: 0.85rem;
    margin-bottom: 0.75rem;
}

.md-turn-status {
    display: inline-flex;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.5rem 0.75rem;
    border-left: 4px solid var(--rc-border);
    border-radius: 6px;
    background: var(--rc-surface-alt);
}

.md-turn-status strong {
    font-size: 1rem;
}

.md-turn-status span {
    color: var(--rc-text-muted);
    font-size: 0.8rem;
}

.md-turn-status--mine {
    border-color: #15803d;
    background: #dcfce7;
    color: #14532d;
}

.md-turn-status--mine span {
    color: #166534;
}

.md-activity {
    margin: 0.25rem 0 0.75rem;
    padding: 0.7rem 0.85rem;
    border-radius: 7px;
    border: 1px solid var(--rc-border);
    border-left: 4px solid var(--rc-primary);
    background: var(--rc-surface-alt);
    font-size: 0.85rem;
}

.md-activity-title {
    display: block;
    margin-bottom: 0.35rem;
}

.md-activity ol {
    display: grid;
    gap: 0.3rem;
    margin: 0;
    padding-left: 1.2rem;
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

.md-seat-properties {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 190px), 1fr));
    gap: 0.65rem;
}

.md-seat-bank-stack {
    display: flex;
    align-items: flex-start;
    min-height: 108px;
    overflow: hidden;
    padding: 0.2rem 0.2rem 0.4rem;
}

.md-seat-bank-card {
    position: relative;
    flex: 0 0 66px;
    width: 66px;
    height: 102px;
}

.md-seat-bank-card + .md-seat-bank-card {
    margin-left: -39px;
}

.md-seat-bank-card :deep(.mc-card) {
    transform: scale(0.61);
    transform-origin: top left;
}

.md-seat-set-preview {
    display: grid;
    grid-template-rows: auto 1fr auto;
    min-width: 0;
    min-height: 158px;
    overflow: hidden;
    padding: 0.6rem 0.65rem 0.5rem;
    border: 1px solid var(--rc-border);
    border-radius: 9px;
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    text-align: left;
    cursor: pointer;
    transition: border-color 140ms ease, box-shadow 140ms ease, transform 140ms ease;
}

.md-seat-set-preview:hover,
.md-seat-set-preview:focus-visible {
    border-color: var(--rc-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--rc-primary) 22%, transparent);
    transform: translateY(-2px);
}

.md-seat-set-heading {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 0.45rem;
}

.md-seat-set-heading strong {
    font-size: 0.78rem;
}

.md-seat-set-heading small {
    min-width: 0;
    overflow: hidden;
    color: var(--rc-text-muted);
    font-size: 0.64rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.md-seat-set-stack {
    display: flex;
    align-items: flex-start;
    height: 108px;
    overflow: hidden;
    margin-top: 0.35rem;
    padding-left: 0.1rem;
}

.md-seat-set-card {
    position: relative;
    flex: 0 0 66px;
    width: 66px;
    height: 102px;
}

.md-seat-set-card + .md-seat-set-card {
    margin-left: calc(-27px - 3px * var(--set-card-count, 4));
}

.md-seat-set-card :deep(.mc-card) {
    transform: scale(0.61);
    transform-origin: top left;
}

.md-seat-set-hint {
    align-self: end;
    color: var(--rc-text-muted);
    font-size: 0.64rem;
}

.md-set-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(10, 15, 20, 0.8);
}

.md-set-modal {
    display: flex;
    flex-direction: column;
    width: min(1100px, 100%);
    max-height: min(92dvh, 900px);
    overflow: hidden;
    border: 1px solid var(--rc-border);
    border-radius: 14px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.md-set-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--rc-border);
    background: var(--rc-surface-alt);
}

.md-set-modal-header h2,
.md-set-modal-header p {
    margin: 0;
}

.md-set-modal-eyebrow {
    margin-bottom: 0.2rem !important;
    color: var(--rc-primary);
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.14em;
}

.md-set-modal-header h2 {
    font-family: var(--rc-font-display);
    font-size: 1.25rem;
}

.md-set-modal-close {
    display: inline-flex;
    flex: 0 0 44px;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font-size: 2.25rem;
    line-height: 1;
    cursor: pointer;
}

.md-set-modal-cards {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    min-height: 0;
    overflow: auto;
    padding: 1.25rem;
}

.md-set-modal-card {
    flex: 0 0 220px;
    width: 220px;
}

.md-set-modal-footer {
    display: flex;
    justify-content: flex-end;
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--rc-border);
}

@media (max-width: 600px) {
    .md-seat-properties {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.45rem;
    }

    .md-seat-set-preview {
        min-height: 148px;
        padding: 0.5rem 0.45rem 0.4rem;
    }

    .md-seat-set-heading {
        flex-wrap: wrap;
        gap: 0.15rem 0.35rem;
    }

    .md-seat-set-heading strong {
        font-size: 0.72rem;
    }

    .md-seat-set-heading small {
        font-size: 0.58rem;
    }

    .md-seat-set-hint {
        font-size: 0.58rem;
    }

    .md-seat-set-stack {
        height: 88px;
    }

    .md-seat-set-card {
        flex-basis: 52px;
        width: 52px;
        height: 80px;
    }

    .md-seat-set-card + .md-seat-set-card {
        margin-left: calc(-19px - 4px * var(--set-card-count, 4));
    }

    .md-seat-set-card :deep(.mc-card) {
        transform: scale(0.48);
    }

    .md-set-modal-backdrop {
        padding: 0.5rem;
    }

    .md-set-modal-header {
        padding: 0.8rem 1rem;
    }

    .md-set-modal-cards {
        gap: 0.65rem;
        padding: 0.8rem;
    }

}

.md-turn-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 950;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(10, 15, 20, 0.78);
}

.md-turn-modal {
    display: flex;
    flex-direction: column;
    width: min(1080px, 100%);
    max-height: min(92dvh, 960px);
    overflow: hidden;
    border: 1px solid var(--rc-border);
    border-radius: 16px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.45);
}

.md-turn-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.1rem 1.35rem;
    border-bottom: 1px solid var(--rc-border);
    background: var(--rc-surface-alt);
}

.md-turn-modal-header h2,
.md-turn-modal-header p {
    margin: 0;
}

.md-turn-modal-eyebrow {
    margin-bottom: 0.25rem !important;
    color: var(--rc-primary);
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.16em;
}

.md-turn-modal-header h2 {
    font-family: var(--rc-font-display);
    font-size: clamp(1.2rem, 2.5vw, 1.8rem);
}

.md-turn-modal-hand-count {
    margin-top: 0.25rem !important;
    color: var(--rc-text-muted);
    font-size: 0.85rem;
}

.md-turn-modal-close {
    display: inline-flex;
    flex: 0 0 48px;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    background: transparent;
    color: inherit;
    font-size: 2.5rem;
    line-height: 1;
    cursor: pointer;
}

.md-turn-modal-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(230px, 0.34fr);
    min-height: 0;
    overflow: auto;
}

.md-turn-modal-tableau {
    display: grid;
    align-content: start;
    gap: 1.2rem;
    min-width: 0;
    padding: 1.25rem;
}

.md-turn-modal-section h3,
.md-turn-modal-log h3 {
    display: flex;
    align-items: baseline;
    gap: 0.55rem;
    margin: 0 0 0.6rem;
    font-family: var(--rc-font-display);
    font-size: 1rem;
}

.md-turn-modal-section h3 span,
.md-turn-modal-log h3 span {
    color: var(--rc-text-muted);
    font: 600 0.75rem var(--rc-font-body);
}

.md-turn-card-stack {
    display: flex;
    align-items: flex-start;
    min-height: 154px;
    padding: 0.3rem 0.4rem 0.65rem;
    overflow-x: auto;
    overflow-y: hidden;
    scrollbar-width: thin;
}

.md-turn-card {
    position: relative;
    flex: 0 0 106px;
    width: 106px;
    transition:
        transform 160ms ease,
        filter 160ms ease;
}

.md-turn-card + .md-turn-card {
    margin-left: -58px;
}

.md-turn-card--moved {
    z-index: 20 !important;
    transform: translateY(-8px) scale(1.04);
    filter: drop-shadow(0 0 8px #facc15) drop-shadow(0 0 16px rgba(250, 204, 21, 0.72));
    animation: md-turn-card-highlight 1.1s ease-in-out 2;
}

.md-turn-card-stack--bank {
    overflow-x: auto;
}

.md-turn-property-groups {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.65rem;
}

.md-turn-property-group {
    min-width: 0;
    overflow: hidden;
    padding: 0.55rem 0.6rem 0.1rem;
    border: 1px solid var(--rc-border);
    border-radius: 10px;
    background: var(--rc-surface-alt);
}

.md-turn-property-group h4 {
    margin: 0;
    color: var(--rc-text-muted);
    font-size: 0.8rem;
}

.md-turn-property-group .md-turn-card-stack {
    min-height: 108px;
}

.md-turn-property-group .md-turn-card {
    flex-basis: 66px;
    width: 66px;
    height: 102px;
}

.md-turn-property-group .md-turn-card + .md-turn-card {
    margin-left: calc(-27px - 3px * var(--set-card-count, 4));
}

.md-turn-property-group .md-turn-card :deep(.mc-card) {
    transform: scale(0.61);
    transform-origin: top left;
}

.md-turn-modal-log {
    min-width: 0;
    padding: 1.25rem;
    border-left: 1px solid var(--rc-border);
    background: var(--rc-surface-alt);
}

.md-turn-modal-log h3 {
    margin-bottom: 0.9rem;
}

.md-turn-modal-log ol {
    display: grid;
    gap: 0.65rem;
    padding: 0;
    margin: 0;
    list-style: none;
}

.md-turn-activity {
    display: grid;
    grid-template-columns: 10px minmax(0, 1fr);
    gap: 0.6rem;
    align-items: start;
    padding: 0.7rem;
    border: 1px solid var(--rc-border);
    border-radius: 9px;
    background: var(--rc-surface);
    animation: md-turn-activity-in 220ms ease-out both;
}

.md-turn-activity--latest {
    border-color: var(--rc-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--rc-primary) 18%, transparent);
}

.md-turn-activity-dot {
    width: 8px;
    height: 8px;
    margin-top: 0.27rem;
    border-radius: 50%;
    background: var(--rc-primary);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--rc-primary) 18%, transparent);
}

.md-turn-activity strong,
.md-turn-activity small {
    display: block;
}

.md-turn-activity-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
    margin-top: 0.45rem;
}

.md-turn-activity-cards span {
    padding: 0.18rem 0.4rem;
    border: 1px solid color-mix(in srgb, var(--rc-primary) 45%, var(--rc-border));
    border-radius: 999px;
    background: color-mix(in srgb, var(--rc-primary) 12%, var(--rc-surface));
    color: var(--rc-text-on-surface);
    font-size: 0.66rem;
    font-weight: 700;
}

.md-turn-activity strong {
    font-size: 0.82rem;
    line-height: 1.35;
}

.md-turn-activity small {
    margin-top: 0.2rem;
    color: var(--rc-text-muted);
    font-size: 0.68rem;
}

.md-turn-modal-empty {
    margin: 0.4rem 0;
    color: var(--rc-text-muted);
    font-size: 0.82rem;
}

.md-turn-modal-reopen {
    position: fixed;
    right: 1rem;
    bottom: 1rem;
    z-index: 900;
    padding: 0.7rem 1rem;
    border: 1px solid var(--rc-border);
    border-radius: 999px;
    background: var(--rc-primary);
    color: #fff;
    font-weight: 700;
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.25);
    cursor: pointer;
}

.md-root:not(.md-root--hand-collapsed) .md-turn-modal-reopen {
    bottom: calc(min(48dvh, 330px) + 1rem + env(safe-area-inset-bottom));
}

.md-turn-attention-backdrop,
.md-payment-received-backdrop,
.md-winner-backdrop {
    position: fixed;
    inset: 0;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgba(7, 10, 18, 0.82);
}

.md-turn-attention-backdrop {
    z-index: 1200;
}

.md-payment-received-backdrop {
    z-index: 1250;
}

.md-turn-attention,
.md-payment-received,
.md-winner-modal {
    position: relative;
    width: min(540px, 100%);
    max-height: min(90dvh, 760px);
    overflow: auto;
    padding: 1.5rem;
    border: 1px solid var(--rc-border);
    border-radius: 16px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    text-align: center;
    box-shadow: 0 24px 80px rgba(0, 0, 0, 0.55);
    animation: md-attention-pop 380ms cubic-bezier(0.2, 0.85, 0.3, 1.2) both;
}

.md-turn-attention-icon,
.md-winner-trophy {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 3.5rem;
}

.md-turn-attention-icon {
    animation: md-attention-pulse 1s ease-in-out infinite alternate;
}

.md-turn-attention h2,
.md-payment-received h2,
.md-winner-modal h2 {
    margin: 0.2rem 0 0.55rem;
    font-family: var(--rc-font-display);
    font-size: clamp(1.35rem, 4vw, 2rem);
}

.md-turn-attention > p:not(.md-turn-modal-eyebrow),
.md-winner-modal > p:not(.md-turn-modal-eyebrow) {
    margin: 0 0 1rem;
    color: var(--rc-text-muted);
}

.md-turn-attention > .md-btn,
.md-winner-modal > .md-btn {
    min-width: 150px;
}

.md-payment-received {
    text-align: left;
}

.md-payment-received-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.md-payment-received-header h2 {
    margin-bottom: 0;
}

.md-payment-receipt-list {
    display: grid;
    gap: 0.75rem;
    max-height: 45dvh;
    overflow: auto;
    padding: 0;
    margin: 1rem 0;
    list-style: none;
}

.md-payment-receipt-list li {
    padding: 0.8rem;
    border: 1px solid var(--rc-border);
    border-radius: 9px;
    background: var(--rc-surface-alt);
}

.md-payment-receipt-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.5rem;
}

.md-payment-receipt-cards span {
    padding: 0.3rem 0.55rem;
    border: 1px solid color-mix(in srgb, var(--rc-primary) 45%, var(--rc-border));
    border-radius: 999px;
    background: color-mix(in srgb, var(--rc-primary) 12%, var(--rc-surface));
    font-size: 0.78rem;
    font-weight: 700;
}

.md-payment-received .md-pay-modal-footer {
    justify-content: flex-end;
}

.md-winner-backdrop {
    z-index: 1400;
    overflow: hidden;
    background: radial-gradient(ellipse at center, rgba(30, 35, 54, 0.92), rgba(7, 10, 18, 0.96));
}

.md-winner-modal {
    z-index: 1;
    border-color: color-mix(in srgb, #facc15 62%, var(--rc-border));
    box-shadow: 0 0 0 4px rgba(250, 204, 21, 0.1), 0 24px 90px rgba(0, 0, 0, 0.65);
}

.md-winner-close {
    position: absolute;
    top: 0.55rem;
    right: 0.6rem;
    width: 2.6rem;
    height: 2.6rem;
    border: 1px solid var(--rc-border);
    border-radius: 50%;
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    font-size: 1.65rem;
    cursor: pointer;
}

.md-winner-modal h2 {
    color: #facc15;
    font-size: clamp(2rem, 7vw, 3.3rem);
}

.md-winner-trophy {
    animation: md-trophy-celebrate 900ms ease-in-out infinite alternate;
}

.md-winner-confetti {
    position: absolute;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
}

.md-winner-confetti-piece {
    position: absolute;
    top: -1rem;
    width: 8px;
    height: 15px;
    border-radius: 2px;
    background: hsl(var(--confetti-hue) 90% 60%);
    animation: md-confetti-fall 3.7s linear infinite;
}

.md-pay-reopen {
    position: fixed;
    z-index: 1100;
    right: 1rem;
    bottom: calc(1rem + env(safe-area-inset-bottom));
    min-height: 3.25rem;
    padding: 0.7rem 1rem;
    border: 1px solid var(--rc-primary);
    border-radius: 999px;
    background: var(--rc-primary);
    color: #fff;
    font: inherit;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
}

.md-root.md-root--hand-collapsed .md-pay-reopen {
    right: 1rem;
    bottom: calc(5.75rem + env(safe-area-inset-bottom));
}

.md-root:not(.md-root--hand-collapsed) .md-pay-reopen {
    bottom: calc(min(48dvh, 330px) + 1rem + env(safe-area-inset-bottom));
}

@keyframes md-turn-card-highlight {
    0%,
    100% {
        filter: drop-shadow(0 0 5px #facc15);
    }
    50% {
        filter: drop-shadow(0 0 12px #facc15) drop-shadow(0 0 20px rgba(250, 204, 21, 0.8));
    }
}

@keyframes md-turn-activity-in {
    from {
        opacity: 0.4;
        transform: translateX(8px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes md-attention-pop {
    from {
        opacity: 0;
        transform: translateY(18px) scale(0.92);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

@keyframes md-attention-pulse {
    from {
        transform: scale(0.9) rotate(-8deg);
    }
    to {
        transform: scale(1.08) rotate(8deg);
    }
}

@keyframes md-trophy-celebrate {
    from {
        transform: rotate(-8deg) scale(0.96);
    }
    to {
        transform: rotate(8deg) scale(1.08);
    }
}

@keyframes md-confetti-fall {
    to {
        transform: translate3d(18px, 110dvh, 0) rotate(720deg);
    }
}

@media (max-width: 760px) {
    .md-turn-modal-body {
        grid-template-columns: minmax(0, 1fr);
    }

    .md-turn-modal-log {
        border-top: 1px solid var(--rc-border);
        border-left: 0;
    }

    .md-turn-property-groups {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.45rem;
    }

    .md-turn-property-group {
        padding: 0.45rem 0.4rem 0.1rem;
    }

    .md-turn-property-group h4 {
        overflow: hidden;
        font-size: 0.68rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .md-turn-property-group .md-turn-card-stack {
        min-height: 88px;
        padding-inline: 0.15rem;
    }

    .md-turn-property-group .md-turn-card {
        flex-basis: 52px;
        width: 52px;
        height: 80px;
    }

    .md-turn-property-group .md-turn-card + .md-turn-card {
        margin-left: calc(-19px - 4px * var(--set-card-count, 4));
    }

    .md-turn-property-group .md-turn-card :deep(.mc-card) {
        transform: scale(0.48);
    }
}

@media (prefers-reduced-motion: reduce) {
    .md-turn-card--moved,
    .md-turn-activity,
    .md-turn-attention,
    .md-turn-attention-icon,
    .md-winner-trophy,
    .md-winner-confetti-piece {
        animation: none;
    }
}

.md-pay-modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(10, 15, 20, 0.72);
}

.md-pay-modal {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    width: min(760px, 100%);
    max-height: min(90dvh, 900px);
    overflow: hidden;
    padding: 1rem;
    border: 1px solid var(--rc-border);
    border-radius: 12px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    box-shadow: 0 18px 60px rgba(0, 0, 0, 0.35);
}

.md-pay-modal-header,
.md-pay-modal-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.md-pay-modal-header h2,
.md-pay-modal-header p {
    margin: 0;
}

.md-pay-modal-header h2 {
    font-family: var(--rc-font-display);
    font-size: 1.1rem;
}

.md-pay-modal-header p {
    margin-top: 0.25rem;
    color: var(--rc-text-muted);
    font-size: 0.9rem;
}

.md-btn.md-pay-modal-close {
    display: inline-flex;
    flex: 0 0 48px;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    padding: 0;
    font-size: 2.75rem;
    line-height: 1;
}

.md-pay-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(126px, 1fr));
    gap: 0.75rem;
    overflow: auto;
    padding: 0.25rem;
}

.md-pay-card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
    padding: 0.65rem 0.4rem;
    border: 2px solid var(--rc-border);
    border-radius: 9px;
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    cursor: pointer;
    text-align: center;
}

.md-pay-card--selected {
    border-color: var(--rc-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--rc-primary), transparent 72%);
}

.md-pay-card-face {
    display: block;
    flex: 0 0 108px;
    width: 108px;
    height: 165px;
}

.md-pay-card-info {
    font-size: 0.75rem;
    font-weight: 700;
}

.md-pay-card-check {
    position: absolute;
    z-index: 5;
    top: -0.35rem;
    right: -0.35rem;
    display: grid;
    width: 1.6rem;
    height: 1.6rem;
    place-items: center;
    border-radius: 50%;
    border: 2px solid var(--rc-surface);
    background: var(--rc-primary);
    color: #fff;
    font-size: 0.85rem;
    font-weight: 900;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
}

.md-pay-empty {
    margin: 0;
    color: var(--rc-text-muted);
}

.md-pay-modal-error {
    margin: 0;
    padding: 0.65rem 0.8rem;
    border: 1px solid color-mix(in srgb, var(--rc-primary), transparent 55%);
    border-radius: 7px;
    background: color-mix(in srgb, var(--rc-primary), transparent 92%);
    color: var(--rc-primary);
    font-size: 0.9rem;
    font-weight: 700;
}

.md-pay-modal-footer {
    justify-content: flex-end;
    padding-top: 0.75rem;
    border-top: 1px solid var(--rc-border);
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
    position: fixed;
    z-index: 890;
    left: 50%;
    bottom: calc(290px + 0.75rem + env(safe-area-inset-bottom));
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    width: min(1180px, calc(100vw - 1rem));
    max-height: calc(100dvh - 320px - env(safe-area-inset-bottom));
    overflow: auto;
    transform: translateX(-50%);
    gap: 1rem;
    padding: 0.8rem 1rem;
    border: 1px solid var(--rc-border);
    border-radius: 12px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.3);
}

.md-root--hand-collapsed .md-play-panel {
    bottom: calc(5.5rem + env(safe-area-inset-bottom));
    max-height: calc(100dvh - 7rem - env(safe-area-inset-bottom));
}

.md-play-panel-header {
    display: flex;
    flex: 0 0 100%;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.md-play-panel-header h3 {
    overflow: hidden;
    margin: 0;
    font-size: 0.95rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.md-play-panel-close {
    flex: 0 0 auto;
    width: 2.5rem;
    height: 2.5rem;
    border: 1px solid var(--rc-border);
    border-radius: 50%;
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    font-size: 1.4rem;
    line-height: 1;
    cursor: pointer;
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

.md-play-controls > .md-btn:not(.md-btn--muted) {
    background: var(--rc-primary);
    border-color: var(--rc-primary);
    color: #fff;
}

.md-bank-confirm {
    display: grid;
    gap: 0.5rem;
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--rc-primary);
    border-radius: 7px;
    background: var(--rc-surface-alt);
    font-size: 0.9rem;
}

.md-bank-confirm p {
    margin: 0;
    color: var(--rc-text-muted);
}

.md-bank-confirm > div {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.md-wildcard-active-hint {
    width: 100%;
    margin: 0;
    color: var(--rc-text-muted);
    font-size: 0.85rem;
}

.md-wildcard-active-hint strong {
    color: var(--rc-text-on-surface);
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

.md-root select {
    display: block;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    min-height: 46px;
    padding: 0.65rem 2.25rem 0.65rem 0.8rem;
    border: 1px solid var(--rc-border);
    border-radius: 7px;
    background-color: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    font: inherit;
    font-size: 0.9rem;
    line-height: 1.25;
}

.md-root select option {
    background-color: var(--rc-surface);
    color: var(--rc-text-on-surface);
}

input[type='checkbox'] {
    font-size: 0.85rem;
}

@media (max-width: 600px) {
    .md-root {
        gap: 1rem;
    }

    .md-root:not(.md-root--hand-collapsed) {
        padding-bottom: 290px;
    }

    .md-root--hand-collapsed {
        padding-bottom: 88px;
    }

    .md-summary,
    .md-pending,
    .md-players,
    .md-discard,
    .md-panel,
    .md-hand {
        padding: 0.875rem;
    }

    .md-hand {
        max-height: min(48dvh, 330px);
        padding-bottom: calc(0.875rem + env(safe-area-inset-bottom));
    }

    .md-hand--collapsed {
        padding: 0;
    }

    .md-play-panel {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 0.75rem;
        width: calc(100vw - 1rem);
        max-height: calc(100dvh - 310px - env(safe-area-inset-bottom));
        bottom: calc(290px + 0.5rem + env(safe-area-inset-bottom));
        padding: 0.75rem;
    }

    .md-root--hand-collapsed .md-play-panel {
        bottom: calc(5.5rem + env(safe-area-inset-bottom));
        max-height: calc(100dvh - 7rem - env(safe-area-inset-bottom));
    }

    .md-play-panel-header {
        grid-column: 1 / -1;
    }

    .md-play-panel > :first-child {
        justify-self: center;
    }

    .md-play-controls {
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        align-items: stretch;
        min-width: 0;
        width: 100%;
    }

    .md-play-controls .md-btn,
    .md-play-controls select,
    .md-play-controls .md-fieldset {
        width: 100%;
        min-width: 0;
    }

    .md-turn-status {
        width: 100%;
    }

    .md-pay-modal-backdrop {
        padding: 0.5rem;
    }

    .md-pay-modal {
        max-height: 94dvh;
        padding: 0.8rem;
    }

    .md-pay-card-grid {
        grid-template-columns: repeat(auto-fill, minmax(118px, 1fr));
        gap: 0.5rem;
    }
}
</style>
