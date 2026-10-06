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
import TableBoard from './TableBoard.vue'
import LandscapeGate from './LandscapeGate.vue'
import { useI18n } from '@/i18n'

const props = defineProps<{
    room: Room
    auth: { user: AuthUser }
    isHost: boolean
}>()
const { t } = useI18n()

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
const activeHandTab = ref<'hand' | 'properties'>('hand')
const showJustSayNoNotice = ref(false)
const showBirthdayNotice = ref(false)
const isResponsePromptCollapsed = ref(false)
const kickingPlayerId = ref<number | null>(null)
const hostKickError = ref<string | null>(null)

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
    window.Echo.private(`App.Models.User.${myId.value}`)
        .listen('.masrawy.just_say_no_countered', (event: { room_id: number }) => {
            if (event.room_id === props.room.id) showJustSayNoNotice.value = true
        })
        .listen('.masrawy.birthday_played', (event: { room_id: number }) => {
            if (event.room_id === props.room.id) {
                showBirthdayNotice.value = true
                router.reload({ only: ['room'] })
            }
        })

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

const discardStackCardIds = computed(() => {
    const pile = table.value?.discard_pile ?? []
    const topCardId = pile[pile.length - 1]
    if (!topCardId) return []

    const latestActivity = table.value?.recent_activity.at(-1)
    const rentPlayCardIds = latestActivity?.type === 'play_rent' && latestActivity.card_id
        ? [latestActivity.card_id, ...latestActivity.double_rent_card_ids]
        : []
    const pileTopCards = pile.slice(-rentPlayCardIds.length)
    const rentPlayIsStillOnTop = rentPlayCardIds.length > 1
        && pileTopCards.length === rentPlayCardIds.length
        && rentPlayCardIds.every((cardId, index) => pileTopCards[index] === cardId)

    return rentPlayIsStillOnTop ? rentPlayCardIds : [topCardId]
})

const COLORS = [
    'brown', 'light_blue', 'pink', 'orange', 'red',
    'yellow', 'green', 'dark_blue', 'railroad', 'utility',
]
const BANK_VISIBLE_CARD_LIMIT = 5

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

function propertyColorForCard(playerId: number, cardId: string): string | undefined {
    const seat = table.value?.players.find(player => player.id === playerId)
    return Object.entries(seat?.properties ?? {}).find(([, group]) => group.cards.includes(cardId))?.[0]
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
const turnViewMoveCount = computed(() => turnViewActivity.value.filter(event => event.type !== 'draw').length)
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

        if (previousTurnTimer !== null) {
            clearTimeout(previousTurnTimer)
            previousTurnTimer = null
        }
        previousTurnSnapshot.value = {
            playerId: previousTable.current_player_id,
            activity: previousTable.turn_activity.filter((event) => event.player_id === previousTable.current_player_id),
        }
        dismissedTurnPlayerId.value = null
        if (previousTable.current_player_id === myId.value) {
            showingPreviousTurn.value = false
            return
        }
        showingPreviousTurn.value = true
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
        case 'play_money': return t('banked :card', { card: cardName })
        case 'play_property': return t('played :card into :color', { card: cardName, color })
        case 'bank_card': return t('banked :card as money (:amount M)', { card: cardName, amount: entryFor(event.card_id ?? '')?.value ?? 0 })
        case 'play_pass_go': return t('played :card and drew 2 cards', { card: cardName })
        case 'play_shisha': return t('added SHISHA to the :color Manti2a', { color })
        case 'play_wil3a': return t('added WIL3A to the :color Manti2a', { color })
        case 'play_debt_collector': return t('played :card against :target', { card: cardName, target })
        case 'play_birthday': return t('played :card against everyone', { card: cardName })
        case 'play_rent':
            return `${t('played :card for :color rent', { card: cardName, color })}${target ? ` ${t('against :target', { target })}` : ''}${event.double_rent_card_ids.length ? ` ${t('(doubled)')}` : ''}`
        case 'play_sly_deal': return t('played :card and took :targetCard from :target', { card: cardName, targetCard: targetCardName, target })
        case 'play_forced_deal': return t('played :card and swapped :targetCard for :giveCard', { card: cardName, targetCard: targetCardName, giveCard: giveCardName })
        case 'play_deal_breaker': return t('played :card and took the :color Manti2a from :target', { card: cardName, color: colorLabel(event.target_color ?? ''), target })
        case 'move_wildcard': return t('moved :card to :color', { card: cardName, color })
        case 'discard': return t('discarded :card', { card: cardName })
        case 'respond_no': return t('played :card to stop an action', { card: cardName })
        case 'decline': return t('declined the charge from :target', { target })
        case 'pay': return target
            ? t('paid :cards to :target', { cards: event.card_ids.map(id => label(id)).join('، '), target })
            : t('paid :cards', { cards: event.card_ids.map(id => label(id)).join('، ') })
        case 'draw': return t('drew cards')
        default: return t('made a move')
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

function visibleBankCards(cardIds: string[]): string[] {
    return cardIds.slice(-BANK_VISIBLE_CARD_LIMIT)
}

function hiddenBankCardCount(cardIds: string[]): number {
    return Math.max(0, cardIds.length - BANK_VISIBLE_CARD_LIMIT)
}

// --- Turn / pending state -----------------------------------------------

// Table.vue also renders finished/cancelled Masrawy Deal rooms (see
// Show.vue) since neither has a role-reveal-style screen the way Mafia
// does; every action stays disabled once the game has actually ended.
const gameIsLive = computed(() => props.room.status === 'in_progress')
const isMyTurn = computed(() => gameIsLive.value && table.value?.current_player_id === myId.value)
const pending = computed(() => table.value?.pending ?? null)
const paymentReason = computed(() => {
    if (!pending.value) return t('Pay charge')

    const source = playerName(pending.value.source_id)
    const cardName = label(pending.value.card_id)
    return pending.value.color
        ? t('Pay :cardName for :color rent from :source', {
              cardName,
              color: colorLabel(pending.value.color),
              source,
          })
        : t('Pay :cardName from :source', { cardName, source })
})
const canAct = computed(() => gameIsLive.value && pending.value === null && isMyTurn.value)
const canPlayCard = computed(() => canAct.value && table.value?.has_drawn_this_turn === true)
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
const responsePrompt = computed(() =>
    myOpenResponses.value.find(entry => entry.charge?.phase === 'responding') ?? null,
)
const responseIsPayment = computed(() =>
    pending.value !== null && ['debt_collector', 'birthday', 'rent'].includes(pending.value.kind),
)
const paymentReconsideration = computed(() =>
    myOpenResponses.value.find(entry => entry.charge?.phase === 'paying') ?? null,
)

watch(
    () => {
        if (!responsePrompt.value || !pending.value) return ''
        const chainLength = pending.value.charges[String(responsePrompt.value.targetId)]?.chain.length ?? 0
        return `${pending.value.kind}:${pending.value.card_id}:${responsePrompt.value.targetId}:${chainLength}`
    },
    () => {
        isResponsePromptCollapsed.value = false
    },
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

const selectedRentColor = computed(() => selectedIsPlainRent.value ? activeRentColor.value : rentColor.value)

function rentColorIssue(color: string): string | null {
    const cards = mySeat.value?.properties[color]?.cards ?? []
    if (cards.length === 0) return `You have no ${colorLabel(color)} properties to charge rent on.`
    if (cards.every(cardId => entryFor(cardId)?.any_color)) return 'EL BOB wildcards alone cannot earn rent.'

    return null
}

function rotateSelectedWildcard() {
    const cardId = selectedEntry.value?.id
    if (!cardId) return

    flippedCardIds.value = flippedCardIds.value.includes(cardId)
        ? flippedCardIds.value.filter(id => id !== cardId)
        : [...flippedCardIds.value, cardId]
}

function selectCard(id: string) {
    selectedCardId.value = selectedCardId.value === id ? null : id
    actionError.value = null
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
    giveCardId.value = ''
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

function stealablePropertyGroups(seat?: MasrawySeat): { color: string; cardIds: string[] }[] {
    if (!seat) return []

    return Object.entries(seat.properties)
        .filter(([color, group]) => {
            const hasEnoughCards = group.cards.length >= (table.value?.set_size[color] ?? Number.POSITIVE_INFINITY)
            const hasNonElBobCard = group.cards.some(cardId => !entryFor(cardId)?.any_color)

            return !hasEnoughCards || !hasNonElBobCard
        })
        .map(([color, group]) => ({ color, cardIds: group.cards }))
        .filter(group => group.cardIds.length > 0)
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
const maxDoubleRentCardsThisPlay = computed(() => Math.min(2, Math.max(0, playsLeft.value - 1)))

const targetStealablePropertyGroups = computed(() => stealablePropertyGroups(targetOpponent.value))
const myStealablePropertyGroups = computed(() => stealablePropertyGroups(mySeat.value))
const myOwnColors = computed(() => Object.keys(mySeat.value?.properties ?? {}))
const rentableMyColors = computed(() => myOwnColors.value.filter(color => rentColorIssue(color) === null))
const selectedRentIssue = computed(() => selectedRentColor.value ? rentColorIssue(selectedRentColor.value) : null)
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

function respondNo(targetIdForCharge: number) {
    const cardId = myJustSayNoCards.value[0]
    if (!cardId) return
    isResponsePromptCollapsed.value = false
    if (pending.value?.kind === 'birthday') showBirthdayNotice.value = false
    submit({ type: 'respond_no', card_id: cardId, target_id: targetIdForCharge }, () => {
        paySelection.value = []
        isPayModalOpen.value = false
        isPayModalCollapsed.value = false
    })
}

function decline(targetIdForCharge: number) {
    isResponsePromptCollapsed.value = false
    if (pending.value?.kind === 'birthday') showBirthdayNotice.value = false
    submit({ type: 'decline', target_id: targetIdForCharge })
}

function collapseResponsePrompt() {
    isResponsePromptCollapsed.value = true
    showJustSayNoNotice.value = false
}

function acceptBirthdayCharge() {
    showBirthdayNotice.value = false

    if (responsePrompt.value) {
        decline(responsePrompt.value.targetId)
        return
    }

    if (you.value?.owes !== null && you.value?.owes !== undefined) {
        openPayModal()
    }
}

function kickPlayer(playerId: number) {
    kickingPlayerId.value = playerId
    hostKickError.value = null

    router.post(`/rooms/${props.room.id}/kick/${playerId}`, {}, {
        onSuccess: () => {
            closePlayerSheet()
        },
        onError: errors => {
            hostKickError.value = Object.values(errors)[0] ?? 'Unable to remove that player.'
        },
        onFinish: () => {
            kickingPlayerId.value = null
            confirmKickId.value = null
        },
    })
}

// --- Player sheet (tap a seat on the table) -----------------------------

const selectedPlayerId = ref<number | null>(null)
const confirmKickId = ref<number | null>(null)
const sheetSeat = computed(() => (selectedPlayerId.value === null ? undefined : seatFor(selectedPlayerId.value)))
const sheetCanKick = computed(
    () =>
        gameIsLive.value &&
        props.isHost &&
        sheetSeat.value !== undefined &&
        sheetSeat.value.id !== myId.value &&
        (table.value?.players.length ?? 0) > props.room.game.minimum_players,
)
// Finished/cancelled rooms send every hand, so seats can be tapped to see them.
const revealHands = computed(() => !gameIsLive.value && (table.value?.players.some(seat => seat.hand !== null) ?? false))

function openPlayerSheet(playerId: number) {
    hostKickError.value = null
    confirmKickId.value = null
    selectedPlayerId.value = playerId
}

function closePlayerSheet() {
    selectedPlayerId.value = null
    confirmKickId.value = null
}

function openSetFromSheet(playerId: number, color: string) {
    openPropertySet(playerId, color)
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
const totalPayable = computed(() =>
    Object.values(you.value?.payable_assets ?? {}).reduce((sum, value) => sum + value, 0),
)
const cannotCoverOwed = computed(() => you.value?.owes !== null && you.value?.owes !== undefined && totalPayable.value < you.value.owes)
const canSubmitPayment = computed(() =>
    paySelection.value.length > 0 && (
        cannotCoverOwed.value
            ? paySelection.value.length === payableOptions.value.length
            : payTotal.value >= (you.value?.owes ?? 0)
    ),
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
    if (!canSubmitPayment.value) return
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

// Phones held sideways have very little height: Show.vue's page header is
// hidden (see the :global rules below) and the hand starts collapsed so the
// table gets the screen.
const SHORT_LANDSCAPE_QUERY = '(orientation: landscape) and (max-height: 560px) and (pointer: coarse)'

onMounted(() => {
    document.documentElement.classList.add('md-play-mode')
    if (window.matchMedia(SHORT_LANDSCAPE_QUERY).matches) isHandCollapsed.value = true
})

onUnmounted(() => {
    document.documentElement.classList.remove('md-play-mode')
    if (previousTurnTimer !== null) clearTimeout(previousTurnTimer)
    window.Echo.leave(`App.Models.User.${myId.value}`)
})
</script>

<template>
    <div class="md-root" :class="{ 'md-root--hand-collapsed': isHandCollapsed, 'md-root--ended': !gameIsLive }">
        <LandscapeGate />
        <!-- table/you are only ever null before the game has started, which
             Show.vue's branch guard already keeps this component from
             rendering for — this is a defensive fallback, not an expected
             path, so it stays a plain message rather than a full layout. -->
            <p v-if="!table || !you" class="md-hint">{{ t('Loading…') }}</p>

        <template v-else>
            <div v-if="room.status === 'finished'" class="md-banner md-banner--finished" role="status">
                <span class="md-banner-crown" aria-hidden="true">♛</span>
                <strong>{{ room.winner === String(myId) ? t('You won!') : t(':name won.', { name: playerName(room.winner ?? '') }) }}</strong>
                <small v-if="revealHands">{{ t('Tap a player to see their final hand.') }}</small>
            </div>
            <div v-else-if="room.status === 'cancelled'" class="md-banner md-banner--cancelled" role="status">
                <strong>{{ t('Room cancelled.') }}</strong>
                <small v-if="revealHands">{{ t('Tap a player to see their hand.') }}</small>
            </div>

            <p v-if="actionError && !isPayModalOpen" role="alert" class="md-error">{{ actionError }}</p>

            <!-- Turn / draw pile summary -->
            <section v-if="gameIsLive" class="md-summary">
                <div class="md-summary-row">
                    <span class="md-turn-status" :class="{ 'md-turn-status--mine': isMyTurn }" aria-live="polite">
                        <strong>{{ isMyTurn ? t('YOUR TURN') : t(':name’s turn', { name: playerName(table.current_player_id) }) }}</strong>
                        <span v-if="nextPlayerId !== null">{{ t('Next: :name', { name: playerName(nextPlayerId) }) }}</span>
                    </span>
                    <span>{{ t('Plays left this turn:') }} <strong>{{ playsLeft }}</strong></span>
                    <span>{{ t('Draw pile:') }} <strong>{{ table.draw_pile_count }}</strong></span>
                    <span>{{ t('Discard pile:') }} <strong>{{ table.discard_pile.length }}</strong></span>
                </div>

                <div v-if="recentActivity.length" class="md-activity" aria-live="polite" :aria-label="t('Recent plays')">
                    <strong class="md-activity-title">{{ t('Recent plays') }}</strong>
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
                    {{ t(':name played :card', { name: playerName(pending.source_id), card: label(pending.card_id) }) }}{{ pending.multiplier && pending.multiplier > 1 ? ` (×${pending.multiplier})` : '' }}
                </p>

                <ul class="md-charges">
                    <li v-for="(charge, targetIdKey) in pending.charges" :key="targetIdKey" class="md-charge">
                        <span>{{ playerName(targetIdKey) }}: {{ charge.phase }}</span>
                        <span v-if="charge.owed > 0"> — owes {{ charge.owed }}M</span>
                        <span v-if="charge.outcome"> — {{ charge.outcome }}</span>
                    </li>
                </ul>

            </section>

            <!-- Round 3D table: one sector per seat, me at the bottom -->
            <TableBoard
                :table="table"
                :my-id="myId"
                :is-my-turn="isMyTurn"
                :plays-left="playsLeft"
                :live="gameIsLive"
                :winner-id="room.winner"
                :reveal-hands="revealHands"
                :player-name="playerName"
                :color-label="colorLabel"
                @open-set="openPropertySet"
                @open-player="openPlayerSheet"
            >
                <template #discard>
                    <div class="md-discard-stack" :aria-label="t('Top card of discard pile; :count cards in pile', { count: table.discard_pile.length })">
                        <div
                            v-for="(cardId, index) in discardStackCardIds"
                            :key="cardId"
                            class="md-discard-play-card"
                            :style="{ zIndex: index + 1, '--discard-card-offset': `${index * 3}px` }"
                        >
                            <MasrawyCard :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                        </div>
                    </div>
                </template>
            </TableBoard>

            <!-- My hand -->
            <section v-if="gameIsLive" class="md-hand" :class="{ 'md-hand--collapsed': isHandCollapsed }">
                <template v-if="isHandCollapsed">
                    <button
                        class="md-hand-expand"
                        type="button"
                        :aria-label="t('Show hand cards (:count)', { count: you.hand.length })"
                        :title="t('Show hand cards (:count)', { count: you.hand.length })"
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
                    >{{ t('Draw') }}</button>
                </template>
                <div v-else class="md-hand-panel">
                    <div class="md-hand-header">
                        <h3 class="md-section-title">{{ activeHandTab === 'hand' ? t('Your Hand (:count/7)', { count: you.hand.length }) : t('My properties') }}</h3>
                        <div class="md-hand-actions">
                            <button
                                v-if="activeHandTab === 'hand' && you.hand.length > 1"
                                class="md-btn md-btn--muted"
                                :aria-pressed="isReorderingHand"
                                @click="toggleHandReordering"
                            >
                                {{ t(isReorderingHand ? 'Done sorting' : 'Sort hand') }}
                            </button>
                            <button
                                v-if="activeHandTab === 'hand' && isMyTurn && !table.has_drawn_this_turn && pending === null"
                                class="md-btn md-btn--primary"
                                type="button"
                                :disabled="submitting"
                                @click="draw"
                            >{{ t('Draw') }}</button>
                            <button
                                v-if="activeHandTab === 'hand' && isMyTurn && table.has_drawn_this_turn && pending === null && !overHandLimit && playsLeft > 0"
                                class="md-btn"
                                :disabled="submitting"
                                @click="endTurn"
                            >
                                {{ t('End Turn') }}
                            </button>
                            <button
                                class="md-btn md-btn--muted md-hand-collapse"
                                type="button"
                                :aria-label="t('Collapse hand cards')"
                                :title="t('Collapse hand cards')"
                                @click="isHandCollapsed = true"
                            >
                                ↓
                            </button>
                        </div>
                    </div>

                    <div class="md-hand-tabs" role="tablist" :aria-label="t('Your cards')">
                        <button
                            id="md-hand-tab"
                            class="md-hand-tab"
                            :class="{ 'md-hand-tab--active': activeHandTab === 'hand' }"
                            type="button"
                            role="tab"
                            :aria-selected="activeHandTab === 'hand'"
                            aria-controls="md-hand-panel"
                            @click="activeHandTab = 'hand'"
                        >
                            {{ t('Hand cards') }} <span>{{ you.hand.length }}</span>
                        </button>
                        <button
                            id="md-properties-tab"
                            class="md-hand-tab"
                            :class="{ 'md-hand-tab--active': activeHandTab === 'properties' }"
                            type="button"
                            role="tab"
                            :aria-selected="activeHandTab === 'properties'"
                            aria-controls="md-hand-panel"
                            @click="activeHandTab = 'properties'; isReorderingHand = false"
                        >
                            {{ t('My properties') }} <span>{{ Object.keys(mySeat?.properties ?? {}).length }}</span>
                        </button>
                    </div>

                    <div id="md-hand-panel" class="md-hand-content" role="tabpanel" :aria-labelledby="activeHandTab === 'hand' ? 'md-hand-tab' : 'md-properties-tab'">
                        <template v-if="activeHandTab === 'hand'">
                            <p v-if="isReorderingHand" class="md-hint md-hand-sort-hint">
                                {{ t('Press and hold a card, then drag it to reorder. Your order is saved on this device.') }}
                            </p>

                            <p v-if="isMyTurn && overHandLimit" class="md-hint">
                                {{ t('Discard down to 7 cards before ending your turn.') }}
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
                                <div v-for="cardId in handOrder" :key="cardId" class="md-hand-card">
                                    <button
                                        class="md-card-btn"
                                        :class="{ 'md-card-btn--selected': selectedCardId === cardId }"
                                        :aria-label="`${t(isReorderingHand ? 'Reorder' : 'Select')} ${label(cardId)}`"
                                        :aria-pressed="selectedCardId === cardId"
                                        :disabled="!gameIsLive || isReorderingHand"
                                        @click="selectCard(cardId)"
                                    >
                                        <MasrawyCard :entry="entryFor(cardId)!" :active-color="activeColorForCard(cardId)" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                                    </button>
                                </div>
                            </VueDraggable>

                            <p v-if="pending && !canAct && !selectedEntry" class="md-hint">
                                {{ t('Waiting on a pending action.') }}
                            </p>
                        </template>

                        <template v-else>
                            <div v-if="mySeat && Object.keys(mySeat.properties).length > 0" class="md-seat-properties md-my-properties">
                                <button
                                    v-for="(group, color) in mySeat.properties"
                                    :key="color"
                                    type="button"
                                    class="md-seat-set-preview"
                                    :aria-label="t('View :name’s :color Manti2a in detail', { name: playerName(myId), color: colorLabel(String(color)) })"
                                    @click="openPropertySet(myId, String(color))"
                                >
                                    <span class="md-seat-set-heading">
                                        <strong>{{ colorLabel(String(color)) }}</strong>
                                        <small>{{ t(':count cards', { count: group.cards.length + (group.house ? 1 : 0) + (group.hotel ? 1 : 0) }) }}<span v-if="group.house"> · SHISHA</span><span v-if="group.hotel"> · WIL3A</span></small>
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
                                    <span class="md-seat-set-hint">{{ t('Click to view cards') }}</span>
                                </button>
                            </div>
                            <p v-else class="md-hint">{{ t('No properties on the table yet') }}</p>
                        </template>
                    </div>
                </div>

            </section>

            <section v-if="selectedEntry" class="md-play-panel">
                    <header class="md-play-panel-header">
                        <h3>{{ label(selectedEntry.id) }}</h3>
                        <button class="md-play-panel-close" type="button" :aria-label="t('Close card options')" @click="clearSelection">×</button>
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
                            {{ t('Top color is active:') }}
                            <strong>{{ colorLabel((selectedIsTwoColorWild ? activeWildcardColor : activeRentColor) ?? '') }}</strong>.
                            {{ t('Rotate 180° to switch.') }}
                        </p>
                        <button
                            v-if="selectedIsTwoColorWild || selectedIsPlainRent"
                            class="md-btn md-btn--muted"
                            type="button"
                            @click="rotateSelectedWildcard"
                        >
                            {{ t('Rotate 180°') }}
                        </button>
                        <p v-if="isMyTurn && pending === null && !table.has_drawn_this_turn" class="md-hint">
                            {{ t('Draw before playing a card.') }}
                        </p>
                        <p v-else-if="!canAct" class="md-hint">
                            {{ t(pending ? 'You can adjust this card while waiting for the response.' : 'You can prepare this card now. Play options unlock on your turn.') }}
                        </p>

                        <template v-if="canPlayCard">
                        <!-- Money -->
                        <button v-if="selectedEntry.type === 'money'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playMoney(selectedEntry.id)">
                            {{ t('Play as Money (:amount M)', { amount: selectedEntry.value }) }}
                        </button>

                        <!-- Plain property -->
                        <template v-if="selectedEntry.type === 'property'">
                            <button class="md-btn" :disabled="submitting || playsLeft < 1" @click="playProperty(selectedEntry.id)">
                                {{ t('Play as Property (:color)', { color: colorLabel(selectedEntry.color!) }) }}
                            </button>
                        </template>

                        <!-- Two-color wildcard -->
                        <template v-if="selectedIsTwoColorWild">
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !activeWildcardColor" @click="playProperty(selectedEntry.id, activeWildcardColor)">
                                {{ t('Play as Property (:color)', { color: colorLabel(activeWildcardColor ?? '') }) }}
                            </button>
                        </template>

                        <!-- Any-color (EL BOB) wildcard -->
                        <template v-if="selectedIsElBob">
                            <select v-model="wildcardColor" :aria-label="t('Choose the property color')">
                                <option value="" disabled>{{ t('Choose a color') }}</option>
                                <option v-for="c in COLORS" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !wildcardColor" @click="playProperty(selectedEntry.id, wildcardColor)">
                                {{ t('Play as Property') }}
                            </button>
                        </template>

                        <!-- GARAB 7AZAK / Pass Go -->
                        <button v-if="selectedEntry.action === 'pass_go'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playPassGo(selectedEntry.id)">
                            {{ t('Play Pass Go (draw 2)') }}
                        </button>

                        <!-- SHISHA / WIL3A -->
                        <template v-if="selectedEntry.action === 'house'">
                            <select v-model="targetColor" :aria-label="t('Choose a complete Manti2a for SHISHA')">
                                <option value="" disabled>{{ t('Choose a complete Manti2a') }}</option>
                                <option v-for="c in myShishaColors" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !targetColor" @click="playShisha(selectedEntry.id, targetColor)">
                                {{ t('Play SHISHA on :color', { color: targetColor ? colorLabel(targetColor) : t('a Manti2a') }) }}
                            </button>
                        </template>
                        <template v-if="selectedEntry.action === 'hotel'">
                            <select v-model="targetColor" :aria-label="t('Choose a Manti2a with SHISHA for WIL3A')">
                                <option value="" disabled>{{ t('Choose a complete Manti2a with SHISHA') }}</option>
                                <option v-for="c in myWil3aColors" :key="c" :value="c">{{ colorLabel(c) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || !targetColor" @click="playWil3a(selectedEntry.id, targetColor)">
                                {{ t('Play WIL3A on :color', { color: targetColor ? colorLabel(targetColor) : t('a Manti2a') }) }}
                            </button>
                        </template>

                        <!-- HAT 5 FI KEES / Debt Collector -->
                        <template v-if="selectedEntry.action === 'debt_collector'">
                            <select v-model="targetId" :aria-label="t('Choose a player to charge')">
                                <option :value="null" disabled>{{ t('Choose a player') }}</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null" @click="playDebtCollector(selectedEntry.id)">
                                {{ t('Play (charge 5M)') }}
                            </button>
                        </template>

                        <!-- 3ID MILADY YA KELAB / Birthday -->
                        <button v-if="selectedEntry.action === 'birthday'" class="md-btn" :disabled="submitting || playsLeft < 1" @click="playBirthday(selectedEntry.id)">
                            {{ t('Play (2M from everyone)') }}
                        </button>

                        <!-- ELBIS! rent (regular or wild) -->
                        <template v-if="selectedIsPlainRent || selectedIsWildRent">
                            <p v-if="selectedIsPlainRent && selectedRentIssue" class="md-action-warning" role="alert">
                                {{ selectedRentIssue }} Rotate the rent card to check its other color.
                            </p>
                            <p v-if="selectedIsWildRent && rentableMyColors.length === 0" class="md-action-warning" role="alert">
                                {{ myOwnColors.length ? 'EL BOB wildcards alone cannot earn rent.' : 'You have no properties to charge rent on.' }}
                            </p>
                            <select v-if="selectedIsWildRent && rentableMyColors.length > 0" v-model="rentColor" :aria-label="t('Which color to charge')">
                                <option value="" disabled>{{ t('Which color to charge') }}</option>
                                <option v-for="c in rentableMyColors" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <select v-if="selectedIsWildRent && rentableMyColors.length > 0" v-model="targetId" :aria-label="t('Choose the player to charge')">
                                <option :value="null" disabled>{{ t('Choose a player') }}</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <fieldset v-if="myDoubleRentCards.length > 0 && playsLeft > 1" class="md-fieldset">
                                <legend>{{ t('ELBIS X 2 (optional, doubles the rent, each costs a play)') }}</legend>
                                <label v-for="c in myDoubleRentCards" :key="c" class="md-pay-option">
                                    <input
                                        type="checkbox"
                                        :value="c"
                                        v-model="doubleRentIds"
                                        :disabled="!doubleRentIds.includes(c) && doubleRentIds.length >= maxDoubleRentCardsThisPlay"
                                    />
                                    {{ label(c) }}
                                </label>
                            </fieldset>
                            <button
                                class="md-btn"
                                :disabled="submitting || playsLeft < (1 + doubleRentIds.length) || !(selectedIsPlainRent ? activeRentColor : rentColor) || Boolean(selectedRentIssue) || (selectedIsWildRent && (targetId === null || rentableMyColors.length === 0))"
                                @click="playRent(selectedEntry.id, !!selectedIsWildRent, selectedIsPlainRent ? activeRentColor! : rentColor)"
                            >
                                {{ t('Play Rent') }}<span v-if="selectedIsPlainRent && activeRentColor"> ({{ colorLabel(activeRentColor) }})</span>
                            </button>
                        </template>

                        <!-- KHOD AMA 2OLAK / Sly Deal -->
                        <template v-if="selectedEntry.action === 'sly_deal'">
                            <select v-model="targetId" :aria-label="t('Choose whose property to take')" @change="resetTargetSelections">
                                <option :value="null" disabled>{{ t('Choose a player') }}</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <p v-if="targetId !== null && targetStealablePropertyGroups.length === 0" class="md-hint">
                                {{ t('That player has no available properties to take; complete Manati2 can’t be taken.') }}
                            </p>
                            <div v-if="targetStealablePropertyGroups.length" class="md-property-choice-groups" :aria-label="t('Choose a property to take')">
                                <section v-for="group in targetStealablePropertyGroups" :key="group.color" class="md-property-choice-group">
                                    <h4>{{ colorLabel(group.color) }}</h4>
                                    <div class="md-property-choice-cards">
                                        <button
                                            v-for="cardId in group.cardIds"
                                            :key="cardId"
                                            type="button"
                                            class="md-property-choice-card"
                                            :class="{ 'md-property-choice-card--selected': targetCardId === cardId }"
                                            :aria-label="t('Take :card from :player', { card: label(cardId), player: playerName(targetId!) })"
                                            :aria-pressed="targetCardId === cardId"
                                            @click="targetCardId = cardId; wildcardColor = ''"
                                        >
                                            <MasrawyCard :entry="entryFor(cardId)!" :active-color="entryFor(cardId)?.type === 'wildcard' ? group.color : undefined" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                                        </button>
                                    </div>
                                </section>
                            </div>
                            <select v-if="targetCardId && entryFor(targetCardId)?.type === 'wildcard'" v-model="wildcardColor" :aria-label="t('Choose the taken wildcard’s new color')">
                                <option value="">{{ t('Keep current color') }}</option>
                                <option v-for="c in (entryFor(targetCardId)?.any_color ? COLORS : entryFor(targetCardId)?.colors)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null || !targetCardId" @click="playSlyDeal(selectedEntry.id)">
                                {{ t('Take Property') }}
                            </button>
                        </template>

                        <!-- MA.. TEEGY WANA AGY! / Forced Deal -->
                        <template v-if="selectedEntry.action === 'forced_deal'">
                            <select v-model="targetId" :aria-label="t('Choose whose property to take and replace')" @change="resetTargetSelections">
                                <option :value="null" disabled>{{ t('Choose a player') }}</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <p v-if="targetId !== null && targetStealablePropertyGroups.length === 0" class="md-hint">
                                {{ t('That player has no available properties to swap; complete Manati2 can’t be taken.') }}
                            </p>
                            <div v-if="targetStealablePropertyGroups.length" class="md-property-choice-groups" :aria-label="t('Choose their property to take')">
                                <section v-for="group in targetStealablePropertyGroups" :key="group.color" class="md-property-choice-group">
                                    <h4>{{ colorLabel(group.color) }} · {{ playerName(targetId!) }}</h4>
                                    <div class="md-property-choice-cards">
                                        <button
                                            v-for="cardId in group.cardIds"
                                            :key="cardId"
                                            type="button"
                                            class="md-property-choice-card"
                                            :class="{ 'md-property-choice-card--selected': targetCardId === cardId }"
                                            :aria-label="t('Take :card from :player', { card: label(cardId), player: playerName(targetId!) })"
                                            :aria-pressed="targetCardId === cardId"
                                            @click="targetCardId = cardId; wildcardColor = ''"
                                        >
                                            <MasrawyCard :entry="entryFor(cardId)!" :active-color="entryFor(cardId)?.type === 'wildcard' ? group.color : undefined" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                                        </button>
                                    </div>
                                </section>
                            </div>
                            <p v-if="targetCardId" class="md-hint">{{ t('Choose one of your properties to give:') }}</p>
                            <p v-if="targetCardId && myStealablePropertyGroups.length === 0" class="md-hint">
                                {{ t('You have no properties available to swap; complete Manati2 can’t be given.') }}
                            </p>
                            <div v-if="targetCardId && myStealablePropertyGroups.length" class="md-property-choice-groups" :aria-label="t('Choose one of your properties to give')">
                                <section v-for="group in myStealablePropertyGroups" :key="group.color" class="md-property-choice-group">
                                    <h4>{{ colorLabel(group.color) }}{{ t(' · You') }}</h4>
                                    <div class="md-property-choice-cards">
                                        <button
                                            v-for="cardId in group.cardIds"
                                            :key="cardId"
                                            type="button"
                                            class="md-property-choice-card"
                                            :class="{ 'md-property-choice-card--selected': giveCardId === cardId }"
                                            :aria-label="t('Give :card from :color', { card: label(cardId), color: colorLabel(group.color) })"
                                            :aria-pressed="giveCardId === cardId"
                                            @click="giveCardId = cardId"
                                        >
                                            <MasrawyCard :entry="entryFor(cardId)!" :active-color="entryFor(cardId)?.type === 'wildcard' ? group.color : undefined" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                                        </button>
                                    </div>
                                </section>
                            </div>
                            <select v-if="targetCardId && entryFor(targetCardId)?.type === 'wildcard'" v-model="wildcardColor" :aria-label="t('Choose the taken wildcard’s new color')">
                                <option value="">{{ t('Keep current color') }}</option>
                                <option v-for="c in (entryFor(targetCardId)?.any_color ? COLORS : entryFor(targetCardId)?.colors)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <button
                                class="md-btn"
                                :disabled="submitting || playsLeft < 1 || targetId === null || !targetCardId || !giveCardId"
                                @click="playForcedDeal(selectedEntry.id)"
                            >
                                {{ t('Swap Properties') }}
                            </button>
                        </template>

                        <!-- HAT wa lamo2akhza EL SHORT! / Deal Breaker -->
                        <template v-if="selectedEntry.action === 'deal_breaker'">
                            <select v-model="targetId" :aria-label="t('Choose whose complete Manti2a to take')" @change="resetTargetSelections">
                                <option :value="null" disabled>{{ t('Choose a player') }}</option>
                                <option v-for="o in opponents" :key="o.id" :value="o.id">{{ playerName(o.id) }}</option>
                            </select>
                            <p v-if="targetId !== null && opponentCompleteSetColors(targetOpponent).length === 0" class="md-hint">
                                {{ t('That player has no complete Manati2 to take.') }}
                            </p>
                            <select v-model="dealBreakerColor" :disabled="targetId === null || opponentCompleteSetColors(targetOpponent).length === 0" :aria-label="t('Choose their complete Manti2a')">
                                <option value="" disabled>{{ t('Choose one of their complete Manati2') }}</option>
                                <option v-for="c in opponentCompleteSetColors(targetOpponent)" :key="c" :value="c">
                                    {{ colorLabel(c) }}
                                </option>
                            </select>
                            <button class="md-btn" :disabled="submitting || playsLeft < 1 || targetId === null || !dealBreakerColor" @click="playDealBreaker(selectedEntry.id)">
                                {{ t('Take Complete Manti2a') }}
                            </button>
                        </template>

                        <!-- Put the optional money/discard choices after the card's main effect. -->
                        <button
                            v-if="(selectedEntry.type === 'action' || selectedEntry.type === 'rent') && confirmBankCardId !== selectedEntry.id"
                            class="md-btn md-btn--muted"
                            :disabled="submitting || playsLeft < 1"
                            @click="requestBankCard(selectedEntry.id)"
                        >
                            {{ t('Bank instead · worth :amount M', { amount: selectedEntry.value }) }}
                        </button>

                        <div v-if="confirmBankCardId === selectedEntry.id" class="md-bank-confirm" role="alertdialog" aria-labelledby="md-bank-confirm-title">
                            <strong id="md-bank-confirm-title">{{ t('Bank :card for :amount M?', { card: label(selectedEntry.id), amount: selectedEntry.value }) }}</strong>
                            <p>{{ t('This uses a play and permanently gives up this card’s effect.') }}</p>
                            <div>
                                <button class="md-btn md-btn--primary" :disabled="submitting" @click="confirmBankCard">{{ t('Confirm bank') }}</button>
                                <button class="md-btn md-btn--muted" :disabled="submitting" @click="cancelBankCard">{{ t('Keep card') }}</button>
                            </div>
                        </div>

                        <button class="md-btn md-btn--muted" :disabled="submitting || !canDiscard" @click="discard(selectedEntry.id)">
                            {{ t('Discard') }}
                        </button>
                        </template>
                    </div>
            </section>

            <!-- Move a wildcard (free, on your own turn, no pending action) -->
            <section v-if="canAct && myWildcards.length > 0" class="md-panel">
                <h3 class="md-section-title">{{ t('Move a Wildcard (free)') }}</h3>
                <select v-model="moveWildcardId" :aria-label="t('Choose one of your wildcards to move')" @change="moveWildcardColor = ''">
                    <option value="" disabled>{{ t('Choose one of your wildcards') }}</option>
                    <option v-for="c in myWildcards" :key="c.id" :value="c.id">
                        {{ label(c.id) }} (currently {{ colorLabel(c.group) }})
                    </option>
                </select>
                <select v-model="moveWildcardColor" :disabled="!moveWildcardId" :aria-label="t('Choose the wildcard’s new color')">
                    <option value="" disabled>{{ t('New color') }}</option>
                    <option v-for="c in moveWildcardValidColors(moveWildcardId)" :key="c" :value="c">
                        {{ colorLabel(c) }}
                    </option>
                </select>
                <button class="md-btn" :disabled="submitting || !moveWildcardId || !moveWildcardColor" @click="moveWildcard">
                    {{ t('Move') }}
                </button>
            </section>





        </template>

        <button
            v-if="gameIsLive && !isMyTurn && pending === null && turnViewSeat && !showTurnModal"
            class="md-turn-modal-reopen"
            @click="dismissedTurnPlayerId = null"
        >
            {{ t('Watch :name’s turn', { name: playerName(turnViewSeat.id) }) }}
        </button>

        <div v-if="showTurnModal && turnViewSeat" class="md-turn-modal-backdrop" @click.self="dismissTurnModal" @keydown.esc="dismissTurnModal">
            <section class="md-turn-modal" role="dialog" aria-modal="true" aria-labelledby="md-turn-modal-title">
                <header class="md-turn-modal-header">
                    <div>
                        <p class="md-turn-modal-eyebrow">{{ t('LIVE TURN') }}</p>
                        <h2 id="md-turn-modal-title">{{ t(':name is playing', { name: playerName(turnViewSeat.id) }) }}</h2>
                        <p class="md-turn-modal-hand-count">{{ t(':count cards in hand · hand stays private', { count: turnViewSeat.hand_count }) }}</p>
                    </div>
                    <button class="md-turn-modal-close" type="button" :aria-label="t('Close turn view')" @click="dismissTurnModal">×</button>
                </header>

                <div class="md-turn-modal-body">
                    <div class="md-turn-modal-tableau">
                        <section class="md-turn-modal-section">
                            <h3>
                                {{ t('Bank') }} <span>{{ seatBankTotal(turnViewSeat) }}M</span>
                            </h3>
                            <div v-if="turnViewSeat.bank.length" class="md-turn-card-stack md-turn-card-stack--bank">
                                <div v-if="hiddenBankCardCount(turnViewSeat.bank) > 0" class="md-turn-card md-turn-card--overflow" aria-hidden="true">
                                    +{{ hiddenBankCardCount(turnViewSeat.bank) }}
                                </div>
                                <div
                                    v-for="(cardId, index) in visibleBankCards(turnViewSeat.bank)"
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
                            <p v-else class="md-turn-modal-empty">{{ t('No bank cards yet') }}</p>
                        </section>

                        <section class="md-turn-modal-section">
                            <h3>
                                {{ t('Properties') }} <span>{{ Object.keys(turnViewSeat.properties).length }} {{ t('Manati2') }}</span>
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
                            <p v-else class="md-turn-modal-empty">{{ t('No properties on the table yet') }}</p>
                        </section>
                    </div>

                    <aside class="md-turn-modal-log" :aria-label="t('Current player’s moves')">
                        <h3>
                            {{ t('Moves this turn') }} <span>{{ turnViewMoveCount }}</span>
                        </h3>
                        <ol v-if="turnViewActivity.length">
                            <li v-for="(event, index) in turnViewActivity" :key="event.id" class="md-turn-activity" :class="{ 'md-turn-activity--latest': index === turnViewActivity.length - 1 }">
                                <span class="md-turn-activity-dot" aria-hidden="true"></span>
                                <div>
                                    <strong>{{ activityDescription(event) }}</strong>
                                    <small>{{ t('Move :number', { number: index + 1 }) }}</small>
                                    <div v-if="activityCardLabels(event).length" class="md-turn-activity-cards">
                                        <span v-for="cardLabel in activityCardLabels(event)" :key="cardLabel">{{ cardLabel }}</span>
                                    </div>
                                </div>
                            </li>
                        </ol>
                        <p v-else class="md-turn-modal-empty">{{ t('Their moves will appear here as they play.') }}</p>
                    </aside>
                </div>
            </section>
        </div>

        <!-- Player sheet: everything about one seat, opened by tapping its plate on the table -->
        <div
            v-if="sheetSeat && table"
            class="md-ps-backdrop"
            @click.self="closePlayerSheet"
            @keydown.esc="closePlayerSheet"
        >
            <section class="md-ps" role="dialog" aria-modal="true" aria-labelledby="md-ps-title">
                <header class="md-ps-header">
                    <span class="md-ps-avatar" aria-hidden="true">{{ playerName(sheetSeat.id).charAt(0).toUpperCase() }}</span>
                    <div class="md-ps-title-block">
                        <h2 id="md-ps-title">
                            {{ playerName(sheetSeat.id) }}<span v-if="sheetSeat.id === myId"> {{ t('(you)') }}</span>
                        </h2>
                        <p class="md-ps-tags">
                            <span v-if="gameIsLive && sheetSeat.id === table.current_player_id" class="md-ps-tag md-ps-tag--turn">{{ t('— current turn') }}</span>
                            <span v-if="room.winner !== null && String(sheetSeat.id) === room.winner" class="md-ps-tag md-ps-tag--win">♛ {{ t('Winner') }}</span>
                        </p>
                    </div>
                    <button class="md-ps-close" type="button" :aria-label="t('Close')" @click="closePlayerSheet">×</button>
                </header>

                <div class="md-ps-stats">
                    <span><strong>{{ seatBankTotal(sheetSeat) }}M</strong>{{ t('Bank') }}</span>
                    <span><strong>{{ sheetSeat.hand_count }}</strong>{{ t('Hand cards') }}</span>
                    <span><strong>{{ Object.keys(sheetSeat.properties).length }}</strong>{{ t('Manati2') }}</span>
                </div>

                <div class="md-ps-body">
                    <section v-if="sheetSeat.hand" class="md-ps-section">
                        <h3 class="md-group-label">{{ t('Hand: :count card(s)', { count: sheetSeat.hand_count }) }}</h3>
                        <div class="md-card-row md-ps-cards">
                            <MasrawyCard v-for="cardId in sheetSeat.hand" :key="cardId" :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                        </div>
                    </section>

                    <section class="md-ps-section">
                        <h3 class="md-group-label">{{ t('Bank · :amount M', { amount: seatBankTotal(sheetSeat) }) }}</h3>
                        <div v-if="sheetSeat.bank.length > 0" class="md-seat-bank-stack" :aria-label="t(':count money cards in bank; showing the newest :shown', { count: sheetSeat.bank.length, shown: Math.min(sheetSeat.bank.length, BANK_VISIBLE_CARD_LIMIT) })">
                            <span v-if="hiddenBankCardCount(sheetSeat.bank) > 0" class="md-seat-bank-overflow" aria-hidden="true">
                                +{{ hiddenBankCardCount(sheetSeat.bank) }}
                            </span>
                            <span v-for="(cardId, index) in visibleBankCards(sheetSeat.bank)" :key="cardId" class="md-seat-bank-card" :style="{ zIndex: index + 1 }">
                                <MasrawyCard :entry="entryFor(cardId)!" :rent-chart="rentChartFor(cardId)" :set-size="setSizeFor(cardId)" :wild-rent-charts="wildRentChartsFor(cardId)" :wild-set-sizes="wildSetSizesFor(cardId)" />
                            </span>
                        </div>
                        <p v-else class="md-hint">{{ t('Bank: empty') }}</p>
                    </section>

                    <section class="md-ps-section">
                        <h3 class="md-group-label">{{ t('Properties') }}</h3>
                        <div v-if="Object.keys(sheetSeat.properties).length > 0" class="md-seat-properties">
                            <button
                                v-for="(group, color) in sheetSeat.properties"
                                :key="color"
                                type="button"
                                class="md-seat-set-preview"
                                :aria-label="t('View :name’s :color Manti2a in detail', { name: playerName(sheetSeat.id), color: colorLabel(String(color)) })"
                                @click="openSetFromSheet(sheetSeat.id, String(color))"
                            >
                                <span class="md-seat-set-heading">
                                    <strong>{{ colorLabel(String(color)) }}</strong>
                                    <small>{{ t(':count cards', { count: group.cards.length + (group.house ? 1 : 0) + (group.hotel ? 1 : 0) }) }}<span v-if="group.house"> · SHISHA</span><span v-if="group.hotel"> · WIL3A</span></small>
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
                                <span class="md-seat-set-hint">{{ t('Click to view cards') }}</span>
                            </button>
                        </div>
                        <p v-else class="md-hint">{{ t('No properties on the table yet') }}</p>
                    </section>
                </div>

                <footer v-if="sheetCanKick" class="md-ps-footer">
                    <p v-if="hostKickError" role="alert" class="md-error">{{ hostKickError }}</p>
                    <template v-if="confirmKickId === sheetSeat.id">
                        <span class="md-ps-confirm">{{ t('Remove :name from the game?', { name: playerName(sheetSeat.id) }) }}</span>
                        <button class="md-btn md-btn--danger" type="button" :disabled="kickingPlayerId === sheetSeat.id" @click="kickPlayer(sheetSeat.id)">
                            {{ kickingPlayerId === sheetSeat.id ? t('Removing…') : t('Remove') }}
                        </button>
                        <button class="md-btn md-btn--muted" type="button" @click="confirmKickId = null">{{ t('Keep player') }}</button>
                    </template>
                    <button v-else class="md-btn md-btn--muted" type="button" @click="confirmKickId = sheetSeat.id">{{ t('Kick from game') }}</button>
                </footer>
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
                        <p class="md-set-modal-eyebrow">{{ t(':name’s Manti2a', { name: playerName(selectedPropertySetSeat.id) }) }}</p>
                        <h2 id="md-set-modal-title">
                            {{ colorLabel(selectedPropertySet.color) }}
                            <span v-if="selectedPropertySetGroup.house"> · SHISHA</span>
                            <span v-if="selectedPropertySetGroup.hotel"> · WIL3A</span>
                        </h2>
                    </div>
                    <button class="md-set-modal-close" type="button" :aria-label="t('Close Manti2a details')" @click="closePropertySet">×</button>
                </header>
                <div class="md-set-modal-cards" :aria-label="t(':count cards in :color Manti2a', { count: selectedPropertySetCardIds.length, color: colorLabel(selectedPropertySet.color) })">
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
                    <button class="md-btn md-btn--muted" type="button" @click="closePropertySet">{{ t('Close') }}</button>
                </footer>
            </section>
        </div>

        <div v-if="showMyTurnAttention" class="md-turn-attention-backdrop" @click.self="dismissMyTurnAttention" @keydown.esc="dismissMyTurnAttention">
            <section class="md-turn-attention" role="dialog" aria-modal="true" aria-labelledby="md-turn-attention-title">
                <span class="md-turn-attention-icon" aria-hidden="true">⏱</span>
                <p class="md-turn-modal-eyebrow">{{ t('YOUR TURN') }}</p>
                <h2 id="md-turn-attention-title">{{ t(':name, it’s your turn!', { name: playerName(myId) }) }}</h2>
                <p>{{ t(table.has_drawn_this_turn ? 'Your turn is underway. Continue your plays.' : 'Draw your cards, then make your plays.') }}</p>
                <button class="md-btn md-btn--primary" type="button" @click="dismissMyTurnAttention">{{ t('Let’s play') }}</button>
            </section>
        </div>

        <div v-if="paymentReceivedEvents.length && !isPayModalOpen && !showWinnerModal" class="md-payment-received-backdrop" @click.self="dismissPaymentReceipt" @keydown.esc="dismissPaymentReceipt">
            <section class="md-payment-received" role="dialog" aria-modal="true" aria-labelledby="md-payment-received-title">
                <header class="md-payment-received-header">
                    <div>
                        <p class="md-turn-modal-eyebrow">{{ t('RENT RECEIVED') }}</p>
                        <h2 id="md-payment-received-title">{{ t('You got paid!') }}</h2>
                    </div>
                    <button class="md-turn-modal-close" type="button" :aria-label="t('Close rent receipt')" @click="dismissPaymentReceipt">×</button>
                </header>
                <ul class="md-payment-receipt-list">
                    <li v-for="event in paymentReceivedEvents" :key="event.id">
                        <strong>{{ t(':name paid you:', { name: playerName(event.player_id) }) }}</strong>
                        <div class="md-payment-receipt-cards">
                            <span v-for="cardId in event.card_ids" :key="cardId">
                                {{ label(cardId) }} · {{ entryFor(cardId)?.value ?? 0 }}M
                            </span>
                        </div>
                    </li>
                </ul>
                <footer class="md-pay-modal-footer">
                    <button class="md-btn md-btn--primary" type="button" @click="dismissPaymentReceipt">{{ t('Got it') }}</button>
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
                <button class="md-winner-close" type="button" :aria-label="t('Close winner announcement')" @click="winnerModalDismissed = true">×</button>
                <span class="md-winner-trophy" aria-hidden="true">🏆</span>
                <p class="md-turn-modal-eyebrow">{{ t('GAME OVER') }}</p>
                <h2 id="md-winner-title">{{ room.winner === String(myId) ? t('You won!') : t(':name wins!', { name: playerName(room.winner ?? '') }) }}</h2>
                <p>{{ room.winner === String(myId) ? t('Congratulations! You completed the winning Manati2.') : t(':name completed the winning Manati2.', { name: playerName(room.winner ?? '') }) }}</p>
                <button class="md-btn md-btn--primary" type="button" @click="winnerModalDismissed = true">{{ t('Celebrate!') }}</button>
            </section>
        </div>

        <button
            v-if="you.owes !== null && isPayModalCollapsed"
            class="md-pay-reopen"
            type="button"
            @click="openPayModal"
        >
            {{ t('Pay :amount M', { amount: you.owes }) }}
        </button>

        <div v-if="isPayModalOpen && you.owes !== null" class="md-pay-modal-backdrop" @click.self="collapsePayModal" @keydown.esc="collapsePayModal">
            <section class="md-pay-modal" role="dialog" aria-modal="true" aria-labelledby="md-pay-modal-title">
                <header class="md-pay-modal-header">
                    <div>
                        <h2 id="md-pay-modal-title">{{ paymentReason }}</h2>
                        <p>{{ t('You owe :amount M. Selected: :selected M.', { amount: you.owes, selected: payTotal }) }}</p>
                    </div>
                    <button class="md-btn md-btn--muted md-pay-modal-close" :aria-label="t('Collapse payment window')" @click="collapsePayModal">−</button>
                </header>

                <p v-if="actionError" class="md-pay-modal-error" role="alert">{{ actionError }}</p>
                <p v-else-if="cannotCoverOwed" class="md-pay-modal-error" role="status">
                    {{ t('You only have :amount M available. Select all your payable cards to pay what you can.', { amount: totalPayable }) }}
                </p>
                <p v-else-if="payTotal < you.owes" class="md-pay-modal-error" role="status">
                    {{ t('Select at least :amount M more to cover what you owe.', { amount: you.owes - payTotal }) }}
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
                        <span class="md-pay-card-info">{{ asset.inBank ? t('Bank') : asset.group ? colorLabel(asset.group) : t('Property') }} · {{ asset.value }}M</span>
                        <span v-if="paySelection.includes(asset.id)" class="md-pay-card-check" aria-hidden="true">✓</span>
                    </button>
                </div>
                <p v-else class="md-pay-empty">{{ t('You have no cards available to pay with.') }}</p>

                <footer class="md-pay-modal-footer">
                    <button class="md-btn md-btn--muted" @click="collapsePayModal">{{ t('Collapse') }}</button>
                    <button
                        v-if="paymentReconsideration && myJustSayNoCards.length > 0"
                        class="md-btn md-btn--muted"
                        type="button"
                        :disabled="submitting"
                        @click="respondNo(paymentReconsideration.targetId)"
                    >
                        {{ t('3AND OMO') }}
                    </button>
                    <button class="md-btn md-btn--primary" :disabled="submitting || !canSubmitPayment" @click="pay">
                        {{ cannotCoverOwed ? t('Pay all available') : t('Pay :amount M', { amount: payTotal }) }}
                    </button>
                </footer>
            </section>
        </div>

        <div v-if="responsePrompt && pending && !isResponsePromptCollapsed" class="md-response-choice-backdrop">
            <section class="md-response-choice" role="dialog" aria-modal="true" aria-labelledby="md-response-choice-title">
                <div class="md-response-choice-header">
                    <p class="md-turn-modal-eyebrow">{{ t('YOUR RESPONSE') }}</p>
                    <button class="md-response-choice-collapse" type="button" @click="collapseResponsePrompt">{{ t('Review table') }}</button>
                </div>
                <div v-if="pending.kind === 'birthday'" class="md-response-choice-birthday">
                    <img src="/assets/images/3id%20Milady%20Ya%20Kelab.png" alt="3id Milady Ya Kelab">
                        <strong>{{ t('3id Milady Ya Kelab') }}</strong>
                </div>
                <h2 id="md-response-choice-title">{{ t(':name played :card', { name: playerName(pending.source_id), card: label(pending.card_id) }) }}</h2>
                <div v-if="pending.target_card_id || pending.give_card_id" class="md-response-property-cards">
                    <div v-if="pending.target_card_id && entryFor(pending.target_card_id)" class="md-response-property-card">
                        <strong>{{ t('Card being taken') }}</strong>
                        <MasrawyCard
                            :entry="entryFor(pending.target_card_id)!"
                            :active-color="propertyColorForCard(responsePrompt.targetId, pending.target_card_id)"
                            :rent-chart="rentChartFor(pending.target_card_id)"
                            :set-size="setSizeFor(pending.target_card_id)"
                            :wild-rent-charts="wildRentChartsFor(pending.target_card_id)"
                            :wild-set-sizes="wildSetSizesFor(pending.target_card_id)"
                        />
                    </div>
                    <div v-if="pending.give_card_id && entryFor(pending.give_card_id)" class="md-response-property-card">
                        <strong>{{ t('Card you would give') }}</strong>
                        <MasrawyCard
                            :entry="entryFor(pending.give_card_id)!"
                            :active-color="propertyColorForCard(pending.source_id, pending.give_card_id)"
                            :rent-chart="rentChartFor(pending.give_card_id)"
                            :set-size="setSizeFor(pending.give_card_id)"
                            :wild-rent-charts="wildRentChartsFor(pending.give_card_id)"
                            :wild-set-sizes="wildSetSizesFor(pending.give_card_id)"
                        />
                    </div>
                </div>
                <p>{{ t(responseIsPayment ? 'Do you want to cancel the action or pay the charge?' : 'Do you want to cancel this action or let it happen?') }}</p>
                <div class="md-response-choice-actions">
                    <button
                        v-if="myJustSayNoCards.length > 0"
                        class="md-btn md-btn--primary"
                        type="button"
                        :disabled="submitting"
                        @click="respondNo(responsePrompt.targetId)"
                    >
                        {{ t('3AND OMO') }}
                    </button>
                    <button
                        class="md-btn md-btn--muted"
                        type="button"
                        :disabled="submitting"
                        @click="decline(responsePrompt.targetId)"
                    >
                        {{ t(responseIsPayment ? 'EDFA3' : 'Let it happen') }}
                    </button>
                </div>
                <p v-if="actionError" class="md-pay-modal-error" role="alert">{{ actionError }}</p>
            </section>
        </div>

        <button
            v-if="responsePrompt && pending && isResponsePromptCollapsed"
            class="md-response-choice-reopen"
            type="button"
            @click="isResponsePromptCollapsed = false"
        >
            {{ t('Respond to :card', { card: label(pending.card_id) }) }}
        </button>

        <div v-if="showJustSayNoNotice" class="md-jsn-notice-backdrop" @click.self="showJustSayNoNotice = false" @keydown.esc="showJustSayNoNotice = false">
            <section class="md-jsn-notice" role="dialog" aria-modal="true" aria-labelledby="md-jsn-notice-title">
                <button class="md-turn-modal-close md-jsn-notice-close" type="button" :aria-label="t('Close notification')" @click="showJustSayNoNotice = false">×</button>
                <img src="/assets/images/Da%203and%20Omo%20Ya%20Adham.png" alt="Da 3and Omo Ya Adham" class="md-jsn-notice-image">
                <h2 id="md-jsn-notice-title">{{ t('Da 3and Omo Ya Adham') }}</h2>
                <button class="md-btn md-btn--primary" type="button" @click="showJustSayNoNotice = false">{{ t('Continue') }}</button>
            </section>
        </div>

        <div v-if="showBirthdayNotice && !responsePrompt && myJustSayNoCards.length === 0" class="md-jsn-notice-backdrop" @click.self="showBirthdayNotice = false" @keydown.esc="showBirthdayNotice = false">
            <section class="md-jsn-notice" role="dialog" aria-modal="true" aria-labelledby="md-birthday-notice-title">
                <button class="md-turn-modal-close md-jsn-notice-close" type="button" :aria-label="t('Close notification')" @click="showBirthdayNotice = false">×</button>
                <img src="/assets/images/3id%20Milady%20Ya%20Kelab.png" alt="3id Milady Ya Kelab" class="md-jsn-notice-image">
                <h2 id="md-birthday-notice-title">{{ t('3id Milady Ya Kelab') }}</h2>
                <button class="md-btn md-btn--primary" type="button" :disabled="submitting" @click="acceptBirthdayCharge">{{ t('Edfa3') }}</button>
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
    display: flex;
    flex-direction: column;
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

.md-seat-bank-overflow {
    position: relative;
    z-index: 0;
    display: flex;
    align-items: center;
    flex: 0 0 66px;
    width: 66px;
    height: 102px;
    border: 1px dashed var(--rc-border);
    border-radius: 8px;
    background: var(--rc-surface-alt);
    box-shadow: 2px -2px 0 -1px var(--rc-border), 4px -4px 0 -1px var(--rc-border);
    justify-content: start;
    padding-left: 2px;
    box-sizing: border-box;
    color: var(--rc-text-muted);
    font-size: 0.58rem;
    font-weight: 800;
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

.md-seat-bank-overflow + .md-seat-bank-card {
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

.md-seat-set-card :deep(.mc-card--flipped) {
    transform: translate(61%, 61%) rotate(180deg) scale(0.61);
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

    .md-seat-set-card :deep(.mc-card--flipped) {
        transform: translate(48%, 48%) rotate(180deg) scale(0.48);
        transform-origin: top left;
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
    outline: 2px solid #facc15;
    outline-offset: 2px;
    animation: md-turn-card-highlight 1.1s ease-in-out 2;
}

.md-turn-card--overflow {
    display: flex;
    align-items: center;
    flex-basis: 106px;
    height: 154px;
    border: 1px dashed var(--rc-border);
    border-radius: 10px;
    background: var(--rc-surface-alt);
    box-shadow: 2px -2px 0 -1px var(--rc-border), 4px -4px 0 -1px var(--rc-border);
    justify-content: start;
    padding-left: 8px;
    box-sizing: border-box;
    color: var(--rc-text-muted);
    font-size: 0.85rem;
    font-weight: 800;
}

.md-turn-card-stack--bank {
    overflow-x: auto;
}

@keyframes md-turn-card-highlight {
    0%,
    100% {
        outline-color: rgba(250, 204, 21, 0.55);
        outline-width: 1px;
        outline-offset: 1px;
    }

    50% {
        outline-color: #facc15;
        outline-width: 3px;
        outline-offset: 3px;
    }
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

.md-turn-property-group .md-turn-card :deep(.mc-card--flipped) {
    transform: translate(61%, 61%) rotate(180deg) scale(0.61);
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

        .md-turn-property-group .md-turn-card :deep(.mc-card--flipped) {
            transform: translate(48%, 48%) rotate(180deg) scale(0.48);
            transform-origin: top left;
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

.md-response-choice-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1400;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgba(10, 15, 20, 0.78);
}

.md-jsn-notice-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgb(15 23 42 / 78%);
}

.md-jsn-notice {
    position: relative;
    display: flex;
    width: min(100%, 28rem);
    flex-direction: column;
    align-items: center;
    gap: 1rem;
    border: 1px solid rgb(250 204 21 / 50%);
    border-radius: 1.25rem;
    background: #111827;
    padding: 1.5rem;
    text-align: center;
    box-shadow: 0 24px 80px rgb(0 0 0 / 45%);
}

.md-jsn-notice-image {
    display: block;
    width: 100%;
    max-height: 55vh;
    border-radius: 0.75rem;
    object-fit: contain;
}

.md-jsn-notice h2 {
    margin: 0;
    color: #fde047;
    font-size: 1.35rem;
    font-weight: 800;
}

.md-jsn-notice-close {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    z-index: 1;
}

.md-response-choice {
    display: grid;
    gap: 0.85rem;
    width: min(500px, 100%);
    max-height: min(88dvh, 760px);
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 1.4rem;
    border: 1px solid var(--rc-border);
    border-radius: 14px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    box-shadow: 0 18px 60px rgba(0, 0, 0, 0.45);
}

.md-response-choice > * {
    margin: 0;
}

.md-response-choice-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}

.md-response-choice-collapse {
    border: 1px solid var(--rc-border);
    border-radius: 999px;
    background: transparent;
    color: var(--rc-text-muted);
    padding: 0.35rem 0.7rem;
    font: inherit;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
}

.md-response-choice-collapse:hover {
    color: var(--rc-text-on-surface);
}

.md-response-property-cards {
    display: flex;
    gap: 0.75rem;
    overflow-x: auto;
    padding: 0.25rem 0 0.5rem;
}

.md-response-property-card {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    align-items: center;
    gap: 0.45rem;
    min-width: 8rem;
    color: var(--rc-text-muted);
    font-size: 0.8rem;
    text-align: center;
}

.md-response-choice-reopen {
    position: fixed;
    z-index: 1400;
    bottom: calc(1rem + env(safe-area-inset-bottom));
    left: 50%;
    transform: translateX(-50%);
    border: 1px solid var(--rc-primary);
    border-radius: 999px;
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    padding: 0.8rem 1.15rem;
    font: inherit;
    font-weight: 800;
    box-shadow: 0 8px 28px rgb(0 0 0 / 35%);
    cursor: pointer;
}

.md-response-choice-birthday {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    color: var(--rc-primary);
    font-weight: 800;
    text-align: center;
}

.md-response-choice-birthday img {
    display: block;
    width: min(100%, 22rem);
    max-height: 24vh;
    border-radius: 0.65rem;
    object-fit: contain;
}

.md-response-choice h2 {
    font-family: var(--rc-font-display);
    font-size: clamp(1.2rem, 4vw, 1.65rem);
}

.md-response-choice > p:not(.md-turn-modal-eyebrow, .md-pay-modal-error) {
    color: var(--rc-text-muted);
}

.md-response-choice-actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.65rem;
    margin-top: 0.25rem;
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

.md-hand-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.md-hand-panel {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
    gap: 0.5rem;
}

.md-hand-tabs {
    display: flex;
    flex: 0 0 auto;
    gap: 0.35rem;
    border-bottom: 1px solid var(--rc-border);
}

.md-hand-tab {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    border: 0;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    padding: 0.45rem 0.7rem;
    background: transparent;
    color: var(--rc-text-muted);
    font: inherit;
    font-size: 0.85rem;
    cursor: pointer;
}

.md-hand-tab span {
    display: inline-grid;
    min-width: 1.25rem;
    height: 1.25rem;
    place-items: center;
    border-radius: 999px;
    background: var(--rc-surface-alt);
    font-size: 0.7rem;
}

.md-hand-tab--active {
    border-bottom-color: var(--rc-primary);
    color: var(--rc-text-on-surface);
}

.md-hand-content {
    flex: 1 1 auto;
    min-height: 0;
    overflow: auto;
}

.md-my-properties {
    padding: 0.25rem 0;
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

.md-seat-hand,
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

.md-discard-play-card {
    position: absolute;
    inset: 0 auto auto 0;
    transform: translate(var(--discard-card-offset), var(--discard-card-offset));
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

.md-property-choice-groups {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 210px), 1fr));
    gap: 0.6rem;
    flex: 1 1 100%;
    width: 100%;
    max-height: 300px;
    overflow: auto;
    padding: 0.25rem;
}

.md-property-choice-group {
    min-width: 0;
    padding: 0.55rem;
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    background: var(--rc-surface-alt);
}

.md-property-choice-group h4 {
    margin: 0 0 0.5rem;
    color: var(--rc-text-muted);
    font-size: 0.75rem;
    font-weight: 700;
}

.md-property-choice-cards {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 0.45rem;
}

.md-property-choice-card {
    display: block;
    flex: 0 0 66px;
    width: 66px;
    height: 102px;
    overflow: visible;
    padding: 0;
    border: 2px solid transparent;
    border-radius: 8px;
    background: transparent;
    cursor: pointer;
    transition: transform 120ms ease, border-color 120ms ease, box-shadow 120ms ease;
}

.md-property-choice-card :deep(.mc-card) {
    transform: scale(0.61);
    transform-origin: top left;
}

.md-property-choice-card :deep(.mc-card--flipped) {
    transform: translate(61%, 61%) rotate(180deg) scale(0.61);
    transform-origin: top left;
}

.md-property-choice-card:hover {
    transform: translateY(-2px);
}

.md-property-choice-card--selected {
    border-color: var(--rc-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--rc-primary) 30%, transparent);
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

.md-action-warning {
    width: 100%;
    margin: 0;
    padding: 0.7rem 0.8rem;
    border: 1px solid color-mix(in srgb, #f59e0b 55%, var(--rc-border));
    border-left: 4px solid #f59e0b;
    border-radius: 7px;
    background: rgb(245 158 11 / 18%);
    color: var(--rc-text-on-surface);
    font-size: 0.85rem;
    font-weight: 700;
}

.md-wildcard-active-hint strong {
    color: var(--rc-text-on-surface);
}

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

    .md-property-choice-card {
        flex-basis: 52px;
        width: 52px;
        height: 80px;
    }

    .md-property-choice-card :deep(.mc-card) {
        transform: scale(0.48);
    }

    .md-property-choice-card :deep(.mc-card--flipped) {
        transform: translate(48%, 48%) rotate(180deg) scale(0.48);
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
/* ---- Finished / cancelled ribbon ---------------------------------------- */
.md-banner {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    align-items: center;
    padding: 0.55rem 1rem;
    border-radius: 14px;
    font-family: var(--rc-font-display);
    font-weight: 700;
    text-align: center;
}

.md-banner strong {
    font-size: 1.15rem;
}

.md-banner small {
    font-family: var(--rc-font-body);
    font-size: 0.75rem;
    font-weight: 600;
    opacity: 0.9;
}

.md-banner--finished {
    color: #3b2200;
    background: linear-gradient(90deg, #b45309, #fbbf24 50%, #b45309);
    box-shadow: 0 6px 20px rgb(245 158 11 / 30%);
}

.md-banner-crown {
    font-size: 1.5rem;
    line-height: 1;
}

.md-banner--cancelled {
    color: var(--rc-text-on-surface);
    background: var(--rc-surface);
    border: 1px dashed var(--rc-border);
}

/* ---- Player sheet ------------------------------------------------------- */
.md-ps-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1050;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    background: rgb(10 15 20 / 72%);
}

.md-ps {
    display: flex;
    flex-direction: column;
    width: min(600px, 100%);
    max-height: 88dvh;
    border: 1px solid var(--rc-border);
    border-radius: 18px 18px 0 0;
    color: var(--rc-text-on-surface);
    background: var(--rc-surface);
    box-shadow: 0 -10px 40px rgb(0 0 0 / 45%);
}

.md-ps-header {
    display: flex;
    flex: 0 0 auto;
    gap: 0.75rem;
    align-items: center;
    padding: 0.9rem 1rem 0.6rem;
}

.md-ps-avatar {
    flex: 0 0 auto;
    display: grid;
    width: 2.8rem;
    height: 2.8rem;
    place-items: center;
    border: 2px solid rgb(255 255 255 / 60%);
    border-radius: 50%;
    font: 800 1.2rem var(--rc-font-display);
    color: #0f172a;
    background: var(--rc-primary);
}

.md-ps-title-block {
    flex: 1 1 auto;
    min-width: 0;
}

.md-ps-title-block h2 {
    overflow: hidden;
    margin: 0;
    font-family: var(--rc-font-display);
    font-size: 1.15rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.md-ps-tags {
    display: flex;
    gap: 0.4rem;
    margin: 0.15rem 0 0;
    font-size: 0.72rem;
}

.md-ps-tag--turn {
    color: var(--rc-primary);
    font-weight: 700;
}

.md-ps-tag--win {
    color: #fde047;
    font-weight: 700;
}

.md-ps-close {
    flex: 0 0 auto;
    width: 2.5rem;
    height: 2.5rem;
    border: 1px solid var(--rc-border);
    border-radius: 50%;
    font-size: 1.4rem;
    line-height: 1;
    color: var(--rc-text-on-surface);
    background: var(--rc-surface-alt);
    cursor: pointer;
}

.md-ps-stats {
    display: grid;
    flex: 0 0 auto;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    padding: 0 1rem 0.75rem;
}

.md-ps-stats span {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.4rem;
    border-radius: 10px;
    font-size: 0.68rem;
    color: var(--rc-text-muted);
    background: var(--rc-surface-alt);
}

.md-ps-stats strong {
    font-family: var(--rc-font-display);
    font-size: 1.2rem;
    color: var(--rc-text-on-surface);
}

.md-ps-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 0 1rem 0.75rem;
    overflow: auto;
}

.md-ps-section {
    margin-bottom: 0.9rem;
}

.md-ps-cards {
    margin-bottom: 0;
}

.md-ps-footer {
    display: flex;
    flex: 0 0 auto;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    padding: 0.7rem 1rem calc(0.7rem + env(safe-area-inset-bottom));
    border-top: 1px solid var(--rc-border);
}

.md-ps-footer .md-error {
    flex: 0 0 100%;
}

.md-ps-confirm {
    flex: 1 1 100%;
    font-size: 0.85rem;
    font-weight: 600;
}

.md-btn--danger {
    border-color: #b91c1c;
    color: #fff;
    background: #b91c1c;
}

@media (min-width: 700px) {
    .md-ps-backdrop {
        align-items: center;
        padding: 1rem;
    }

    .md-ps {
        border-radius: 18px;
    }
}

/* Height the table must leave for everything else (see TableBoard's --tb-w):
   page header + summary + the table's top margin + the hand dock, less the
   dock's overlap with the empty rim at the table's bottom. */
.md-root {
    --md-reserve: 24.8rem;
}

.md-root--hand-collapsed {
    --md-reserve: 12rem;
}

/* In play the page title/status badge are noise: keep just a slim back link. */
:global(html.md-play-mode .rc-header) {
    display: none;
}

:global(html.md-play-mode .rc-back-link) {
    margin-bottom: 0.25rem;
    font-size: 0.8rem;
}

:global(html.md-play-mode .rc-page) {
    padding-top: 0.75rem;
    padding-bottom: 0.75rem;
}

.md-hand {
    padding: 0.6rem 0.9rem;
}

/* Slimmer hand dock: tabs and actions share one row (the tab already shows
   the count), which gives the table about 45px more height. */
.md-hand-panel {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-rows: auto minmax(0, 1fr);
    grid-template-areas:
        'tabs actions'
        'content content';
    gap: 0.35rem 0.5rem;
    align-items: end;
}

.md-hand-header {
    display: contents;
}

.md-hand-header .md-section-title {
    display: none;
}

.md-hand-actions {
    grid-area: actions;
    margin-left: 0;
    align-self: center;
}

.md-hand-tabs {
    grid-area: tabs;
}

.md-hand-content {
    grid-area: content;
}

/* One-line summary: the table shows the piles and your plays left itself. */
.md-summary {
    padding: 0.5rem 0.9rem;
}

.md-summary .md-summary-row {
    align-items: center;
    margin-bottom: 0;
}

.md-summary .md-turn-status {
    flex-direction: row;
    gap: 0.6rem;
    align-items: baseline;
    padding: 0.25rem 0.75rem;
}

.md-summary .md-summary-row > span:nth-child(n + 3),
.md-summary .md-activity-title {
    display: none;
}

.md-summary .md-activity ol {
    margin: 0;
    padding: 0;
    list-style: none;
}

.md-summary .md-activity li:nth-child(n + 2) {
    display: none;
}

.md-summary .md-activity {
    margin: 0.4rem 0 0;
    padding: 0.35rem 0.6rem;
    font-size: 0.8rem;
}

/* Finished/cancelled: no hand dock or turn summary, just ribbon + table. */
.md-root--ended,
.md-root--ended.md-root--hand-collapsed {
    --md-reserve: 11rem;
    padding-bottom: 1rem;
}

/* ---- Phones held sideways ------------------------------------------------
   Very little height: the page header goes, the table fills the screen, the
   hand starts collapsed (see onMounted) and the card-play panel becomes a
   side drawer instead of a bottom sheet. Placed last so it wins over the
   narrow-width rules above on small landscape screens. */
@media (orientation: landscape) and (max-height: 560px) {
    :global(html.md-play-mode .rc-back-link),
    :global(html.md-play-mode .rc-header) {
        display: none;
    }

    :global(html.md-play-mode .rc-page) {
        min-height: calc(100dvh - 0.8rem);
        padding: 0.4rem;
    }

    .md-root,
    .md-root:not(.md-root--hand-collapsed),
    .md-root--hand-collapsed {
        gap: 0.4rem;
        margin-top: 0;
        padding-bottom: 0;
    }

    .md-summary,
    .md-pending {
        padding: 0.3rem 0.6rem;
        font-size: 0.78rem;
    }

    .md-summary .md-activity,
    .md-summary .md-turn-status span {
        display: none;
    }

    .md-summary-row {
        gap: 0.8rem;
        align-items: center;
    }

    /* The table gets the whole height: turn summary, pending banner and the
       ended ribbon float over its corners instead of taking a row. */
    .md-root,
    .md-root--hand-collapsed {
        --md-reserve: 2.6rem;
    }

    .md-root--ended,
    .md-root--ended.md-root--hand-collapsed {
        --md-reserve: 4.4rem;
        --tb-top: 2.6rem;
    }

    .md-summary {
        position: fixed;
        top: 0.3rem;
        left: 0.4rem;
        z-index: 880;
        max-width: 38vw;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        font-size: 0.74rem;
    }

    .md-summary .md-summary-row > span:not(.md-turn-status),
    .md-summary .md-turn-status {
        display: none;
    }

    .md-summary .md-turn-status {
        display: inline-flex;
        padding: 0;
        color: #f8fafc;
        background: none;
        border: 0;
    }

    .md-summary .md-turn-status--mine,
    .md-summary .md-turn-status--mine strong {
        color: var(--rc-primary, #f59e0b);
    }

    .md-pending {
        position: fixed;
        top: 0.3rem;
        left: 50%;
        z-index: 885;
        width: min(60vw, 30rem);
        max-height: 40dvh;
        overflow: auto;
        transform: translateX(-50%);
    }

    .md-banner {
        position: fixed;
        top: 0.3rem;
        left: 50%;
        z-index: 880;
        transform: translateX(-50%);
    }

    .md-banner {
        flex-direction: row;
        gap: 0.6rem;
        justify-content: center;
        padding: 0.25rem 0.8rem;
    }

    .md-banner strong {
        font-size: 0.95rem;
    }

    .md-hand {
        max-height: 64dvh;
    }

    .md-root .md-play-panel,
    .md-root--hand-collapsed .md-play-panel {
        top: 0.4rem;
        right: 0.4rem;
        bottom: 0.4rem;
        left: auto;
        width: min(340px, 46vw);
        max-height: none;
        transform: none;
    }

    .md-ps-backdrop {
        align-items: center;
        padding: 0.4rem;
    }

    /* Two columns: who they are on the left, cards (scrolling) on the right. */
    .md-ps {
        display: grid;
        grid-template-columns: 210px minmax(0, 1fr);
        grid-template-rows: auto auto minmax(0, 1fr) auto;
        width: min(760px, 96vw);
        height: min(96dvh, 360px);
        max-height: 96dvh;
        border-radius: 14px;
    }

    .md-ps-header {
        grid-area: 1 / 1;
    }

    .md-ps-stats {
        grid-area: 2 / 1;
        grid-template-columns: 1fr;
        gap: 0.25rem;
    }

    .md-ps-stats span {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        padding: 0.25rem 0.6rem;
    }

    .md-ps-body {
        grid-area: 1 / 2 / 5 / 3;
        padding-top: 0.8rem;
    }

    .md-ps-footer {
        grid-area: 4 / 1;
        border-top: 0;
    }

    .md-set-modal,
    .md-turn-modal,
    .md-pay-modal,
    .md-response-choice {
        max-height: 96dvh;
    }
}
</style>
