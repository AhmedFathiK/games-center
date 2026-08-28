<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { themeForGame } from '@/themes/gameThemes'
import NightPhase from './NightPhase.vue'
import DayPhase from './DayPhase.vue'
import GameOver from './GameOver.vue'
import type { Room, AuthUser } from '@/types/room'

const props = defineProps<{
    room: Room
    auth: {
        user: AuthUser
    }
}>()

// --- Theme -----------------------------------------------------------
// Visual identity only. Every id/behavior below is identical regardless
// of which game this room belongs to — only colors/type/motifs change.
const theme = computed(() => themeForGame(props.room.game.slug))

const themeVars = computed(() => ({
    '--rc-bg': theme.value.colors.background,
    '--rc-surface': theme.value.colors.surface,
    '--rc-surface-alt': theme.value.colors.surfaceAlt,
    '--rc-border': theme.value.colors.border,
    '--rc-primary': theme.value.colors.primary,
    '--rc-secondary': theme.value.colors.secondary,
    '--rc-success': theme.value.colors.success,
    '--rc-text-on-bg': theme.value.colors.textOnBackground,
    '--rc-text-on-surface': theme.value.colors.textOnSurface,
    '--rc-text-muted': theme.value.colors.textMuted,
    '--rc-font-display': theme.value.fonts.display,
    '--rc-font-body': theme.value.fonts.body,
    '--rc-font-mono': theme.value.fonts.mono,
}))

function indexLabel(i: number) {
    return `N°${String(i + 1).padStart(2, '0')}`
}

// --- State / actions (unchanged from platform behavior) --------------
const joiningRoom = ref(false)
const joinError = ref<string | null>(null)

const startingGame = ref(false)
const startError = ref<string | null>(null)

const leavingRoom = ref(false)
const leaveError = ref<string | null>(null)

const isHost = computed(() => props.room.host.id === props.auth.user.id)

const isPlayer = computed(() =>
    props.room.players.some(player => player.id === props.auth.user.id),
)

const playersNeeded = computed(() =>
    Math.max(0, props.room.game.minimum_players - props.room.players.length),
)

const canStart = computed(
    () =>
        props.room.status === 'waiting' &&
        isHost.value &&
        playersNeeded.value === 0,
)

function joinRoom() {
    joiningRoom.value = true
    joinError.value = null

    router.post(
        `/rooms/${props.room.id}/join`,
        {},
        {
            onError: errors => {
                joinError.value = Object.values(errors)[0] ?? 'Unable to join the room.'
            },
            onFinish: () => {
                joiningRoom.value = false
            },
        },
    )
}

function leaveRoom() {
    leavingRoom.value = true
    leaveError.value = null

    router.post(
        `/rooms/${props.room.id}/leave`,
        {},
        {
            onError: errors => {
                leaveError.value = Object.values(errors)[0] ?? 'Unable to leave the room.'
            },
            onFinish: () => {
                leavingRoom.value = false
            },
        },
    )
}

function startGame() {
    if (!canStart.value) return

    startingGame.value = true
    startError.value = null

    router.post(
        `/rooms/${props.room.id}/start`,
        {},
        {
            onError: errors => {
                startError.value = Object.values(errors)[0] ?? 'Unable to start the game.'
            },
            onFinish: () => {
                startingGame.value = false
            },
        },
    )
}

// --- Kick a player (host, waiting-room only) --------------------------
const kickingPlayerId = ref<number | null>(null)
const kickError = ref<string | null>(null)

function kickPlayer(playerId: number) {
    kickingPlayerId.value = playerId
    kickError.value = null

    router.post(
        `/rooms/${props.room.id}/kick/${playerId}`,
        {},
        {
            onError: errors => {
                kickError.value = Object.values(errors)[0] ?? 'Unable to remove that player.'
            },
            onFinish: () => {
                kickingPlayerId.value = null
            },
        },
    )
}

// The kicked player themself sees a brief modal, then gets redirected
// out — they're no longer a member, so leaving them on this page would
// just show them the room as an outside visitor with no explanation.
const showKickedModal = ref(false)
let kickedRedirectTimeout: ReturnType<typeof setTimeout> | null = null

function handleSelfKicked() {
    showKickedModal.value = true

    kickedRedirectTimeout = setTimeout(() => {
        router.visit(route('games.index'))
    }, 2500)
}

// --- Copy link ---------------------------------------------------------
// The shareable URL uses the room code, matching the GET /rooms/{room:code}
// route — not the numeric id used by the action endpoints above.
const linkCopied = ref(false)
let copiedTimeout: ReturnType<typeof setTimeout> | null = null

async function copyRoomLink() {
    const link = `${window.location.origin}/rooms/${props.room.code}`

    try {
        await navigator.clipboard.writeText(link)
    } catch {
        // Clipboard API can fail (permissions, non-HTTPS, older browsers).
        // Fall back to a hidden textarea + the legacy execCommand copy so
        // the button still works rather than silently doing nothing.
        const textarea = document.createElement('textarea')
        textarea.value = link
        textarea.style.position = 'fixed'
        textarea.style.opacity = '0'
        document.body.appendChild(textarea)
        textarea.select()

        try {
            document.execCommand('copy')
        } finally {
            document.body.removeChild(textarea)
        }
    }

    linkCopied.value = true

    if (copiedTimeout) clearTimeout(copiedTimeout)
    copiedTimeout = setTimeout(() => {
        linkCopied.value = false
    }, 2000)
}

// Authorization for `rooms.{id}` (see routes/channels.php) requires
// being the host or an existing player. A visitor viewing the room
// before joining is correctly rejected if they try to subscribe — but
// subscribing only once at mount means that rejection would stick
// forever, even after they join, since Inertia updates props on the
// same component instance rather than remounting it. Subscribing
// reactively — once, the moment membership actually becomes true —
// covers both cases: already a member at mount (subscribes immediately,
// same as before), or becoming one afterward (subscribes right then).
const canAccessRoomChannel = computed(() => isHost.value || isPlayer.value)

let roomChannelSubscribed = false

function subscribeToRoomChannel() {
    if (roomChannelSubscribed) return
    roomChannelSubscribed = true

    window.Echo.private(`rooms.${props.room.id}`)
        .listen('.player.joined', () => router.reload({ only: ['room'] }))
        .listen('.game.started', () => router.reload({ only: ['room'] }))
        .listen('.player.left', () => router.reload({ only: ['room'] }))
        .listen('.player.kicked', (e: { player: { id: number; name: string } }) => {
            if (e.player.id === props.auth.user.id) {
                handleSelfKicked()
            } else {
                router.reload({ only: ['room'] })
            }
        })
        .listen('.phase.changed', () => router.reload({ only: ['room'] }))
        .listen('.vote.updated', () => router.reload({ only: ['room'] }))
        .listen('.player.executed', () => router.reload({ only: ['room'] }))
        .listen('.game.ended', () => router.reload({ only: ['room'] }))
}

watch(canAccessRoomChannel, canAccess => {
    if (canAccess) {
        subscribeToRoomChannel()
    }
})

onMounted(() => {
    if (canAccessRoomChannel.value) {
        subscribeToRoomChannel()
    }

    // Broadcasts fired while this connection was down/reconnecting are
    // simply lost — Pusher doesn't replay them. Resync once the
    // connection comes back up so a flaky handshake or brief network
    // drop doesn't leave this tab silently stale until a manual refresh.
    let hasConnectedBefore = false

    window.Echo.connector.pusher.connection.bind(
        'state_change',
        (states: { previous: string; current: string }) => {
            if (states.current === 'connected') {
                if (hasConnectedBefore) {
                    router.reload({ only: ['room'] })
                }
                hasConnectedBefore = true
            }
        },
    )
})

onUnmounted(() => {
    window.Echo.leave(`rooms.${props.room.id}`)

    if (copiedTimeout) clearTimeout(copiedTimeout)
    if (kickedRedirectTimeout) clearTimeout(kickedRedirectTimeout)
})
</script>

<template>
    <div class="rc-page" :class="`rc-theme-${theme.slug}`" :style="themeVars">
        <div class="rc-container">
            <Link :href="route('games.index')" class="rc-back-link rc-mono">
                &larr; Back to Games
            </Link>

            <!-- Room Header -->
            <div class="rc-header">
                <div>
                    <h1 class="rc-title">{{ room.game.name }}</h1>

                    <div class="rc-code-row">
                        <p class="rc-subtitle">
                            Room code: <span class="rc-mono rc-code">{{ room.code }}</span>
                        </p>

                        <button
                            type="button"
                            class="rc-copy-btn rc-mono"
                            @click="copyRoomLink"
                        >
                            {{ linkCopied ? 'Copied' : 'Copy Link' }}
                        </button>
                    </div>
                </div>

                <span class="rc-badge" :class="`rc-badge--${room.status}`">
                    {{ room.status }}
                </span>
            </div>

            <!-- Waiting room -->
            <template v-if="room.status === 'waiting'">
                <!-- Host -->
                <section class="rc-panel">
                    <h2 class="rc-panel-title">{{ theme.labels.hostSectionTitle }}</h2>

                    <div class="rc-row">
                        <span class="rc-row-name">{{ room.host.name }}</span>
                        <span class="rc-row-tag">{{ isHost ? 'Host · You' : 'Host' }}</span>
                    </div>

                    <div v-if="isHost" class="rc-divider" />

                    <div v-if="isHost" class="rc-start-block">
                        <button
                            type="button"
                            class="rc-btn rc-btn--primary"
                            :disabled="startingGame || !canStart"
                            @click="startGame"
                        >
                            <template v-if="startingGame">Starting…</template>
                            <template v-else-if="playersNeeded > 0">
                                Need {{ playersNeeded }} more {{ playersNeeded === 1 ? 'player' : 'players' }}
                            </template>
                            <template v-else>Start Game</template>
                        </button>

                        <p v-if="startError" class="rc-error">{{ startError }}</p>
                    </div>
                </section>

                <!-- Players -->
                <section class="rc-panel">
                    <div class="rc-panel-header">
                        <h2 class="rc-panel-title">{{ theme.labels.rosterSectionTitle }}</h2>
                        <span class="rc-mono rc-count">
                            {{ room.players.length }} / {{ room.max_players }}
                        </span>
                    </div>

                    <div class="rc-roster">
                        <div v-for="(player, i) in room.players" :key="player.id" class="rc-row">
                            <span v-if="theme.motifs.useIndexNumbers" class="rc-mono rc-index">
                                {{ indexLabel(i) }}
                            </span>
                            <span class="rc-row-name">{{ player.name }}</span>

                            <button
                                v-if="isHost"
                                type="button"
                                class="rc-kick-btn"
                                :disabled="kickingPlayerId === player.id"
                                @click="kickPlayer(player.id)"
                            >
                                {{ kickingPlayerId === player.id ? 'Removing…' : 'Kick' }}
                            </button>
                        </div>

                        <p v-if="room.players.length === 0" class="rc-muted">
                            No players have joined yet.
                        </p>
                    </div>

                    <p v-if="kickError" class="rc-error">{{ kickError }}</p>

                    <div class="rc-divider" />

                    <div class="rc-membership">
                        <button
                            v-if="!isHost && !isPlayer"
                            type="button"
                            class="rc-btn rc-btn--secondary"
                            :disabled="joiningRoom"
                            @click="joinRoom"
                        >
                            {{ joiningRoom ? 'Joining…' : 'Join Room' }}
                        </button>

                        <p v-else-if="isHost" class="rc-muted">You are the host of this room.</p>
                        <p v-else class="rc-muted">You are in this room.</p>

                        <button
                            v-if="isPlayer"
                            type="button"
                            class="rc-btn rc-btn--danger"
                            :disabled="leavingRoom"
                            @click="leaveRoom"
                        >
                            {{ leavingRoom ? 'Leaving…' : 'Leave Room' }}
                        </button>

                        <p v-if="joinError" class="rc-error">{{ joinError }}</p>
                        <p v-if="leaveError" class="rc-error">{{ leaveError }}</p>
                    </div>
                </section>

                <!-- Game Settings -->
                <section class="rc-panel">
                    <h2 class="rc-panel-title">{{ theme.labels.settingsSectionTitle }}</h2>

                    <div class="rc-ledger">
                        <div v-for="(value, key) in room.configuration" :key="key" class="rc-ledger-row">
                            <span class="rc-ledger-key">{{ String(key).replace(/_/g, ' ') }}</span>
                            <span class="rc-ledger-leader" aria-hidden="true" />
                            <span class="rc-mono rc-ledger-value">
                                {{ typeof value === 'boolean' ? (value ? 'Enabled' : 'Disabled') : value }}
                            </span>
                        </div>
                    </div>
                </section>
            </template>

            <!-- In progress: night phase -->
            <NightPhase
                v-else-if="room.status === 'in_progress' && room.phase === 'night'"
                :room="room"
                :auth="auth"
                :is-host="isHost"
            />

            <!-- In progress: day phase -->
            <DayPhase
                v-else-if="room.status === 'in_progress' && room.phase === 'day'"
                :room="room"
                :auth="auth"
                :is-host="isHost"
            />

            <!-- Finished -->
            <GameOver
                v-else-if="room.status === 'finished'"
                :room="room"
                :auth="auth"
                :is-host="isHost"
            />
        </div>

        <!-- Kicked modal -->
        <div v-if="showKickedModal" class="rc-kicked-overlay">
            <div class="rc-kicked-modal">
                <p class="rc-kicked-title">Removed from Room</p>
                <p class="rc-kicked-text">The host has removed you from this room.</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
/*
 * NOTE: @import must be top-level, so this loads whenever this component
 * mounts, even for the default theme (which doesn't use these faces).
 * For production, move this <link> into the games/mafia-specific layout
 * or resources/views/app.blade.php gated by game slug, so non-Mafia
 * rooms never pay for a font fetch they don't render.
 */
@import url('https://fonts.googleapis.com/css2?family=Special+Elite&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap');

.rc-page {
    min-height: 100vh;
    background: var(--rc-bg);
    color: var(--rc-text-on-bg);
    font-family: var(--rc-font-body);
    padding: 1.5rem;
}

.rc-container {
    max-width: 42rem;
    margin: 0 auto;
}

.rc-mono {
    font-family: var(--rc-font-mono);
}

/* Back link */
.rc-back-link {
    display: inline-block;
    font-size: 0.8rem;
    color: var(--rc-text-muted);
    text-decoration: none;
    margin-bottom: 1rem;
    transition: color 0.15s ease;
}

.rc-back-link:hover {
    color: var(--rc-text-on-bg);
}

.rc-theme-mafia .rc-back-link {
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

/* Header */
.rc-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}

.rc-title {
    font-family: var(--rc-font-display);
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 0.01em;
}

.rc-code-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 0.25rem;
    flex-wrap: wrap;
}

.rc-subtitle {
    font-size: 0.875rem;
    color: var(--rc-text-muted);
}

.rc-code {
    font-weight: 600;
    color: var(--rc-text-on-bg);
}

.rc-copy-btn {
    border: 1px solid var(--rc-border);
    background: transparent;
    color: var(--rc-text-muted);
    border-radius: 6px;
    padding: 0.2rem 0.6rem;
    font-size: 0.75rem;
    cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease;
}

.rc-copy-btn:hover {
    border-color: var(--rc-primary);
    color: var(--rc-primary);
}

.rc-theme-mafia .rc-copy-btn {
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-radius: 2px;
}

.rc-badge {
    border: 1px solid var(--rc-border);
    padding: 0.25rem 0.75rem;
    font-size: 0.8rem;
    text-transform: capitalize;
    border-radius: 999px;
    color: var(--rc-text-muted);
}

.rc-theme-mafia .rc-badge {
    font-family: var(--rc-font-display);
    background: transparent;
    border: 2px solid var(--rc-primary);
    color: var(--rc-primary);
    border-radius: 2px;
    transform: rotate(-4deg);
    padding: 0.35rem 0.9rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 0.7rem;
}

.rc-theme-mafia .rc-badge--in_progress {
    border-color: var(--rc-secondary);
    color: var(--rc-secondary);
}

.rc-theme-mafia .rc-badge--finished {
    border-color: var(--rc-success);
    color: var(--rc-success);
}

/* Panels */
.rc-panel {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1.5rem;
    margin-top: 1.5rem;
}

.rc-theme-mafia .rc-panel {
    border-radius: 2px;
    box-shadow: 0 1px 0 var(--rc-surface-alt), 0 6px 16px rgba(0, 0, 0, 0.35);
    position: relative;
}

.rc-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.rc-panel-title {
    font-family: var(--rc-font-display);
    font-size: 1.1rem;
    font-weight: 600;
}

.rc-theme-mafia .rc-panel-title {
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 0.95rem;
}

/* Divider — plain rule or dashed "perforation" */
.rc-divider {
    margin: 1.25rem 0;
    border-top: 1px solid var(--rc-border);
}

.rc-theme-mafia .rc-divider {
    border-top: none;
    border-bottom: 2px dashed var(--rc-border);
}

/* Rows (host, roster entries) */
.rc-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    border: 1px solid var(--rc-border);
    background: var(--rc-surface);
    border-radius: 6px;
    padding: 0.75rem 1rem;
    margin-top: 0.75rem;
}

.rc-row:first-child {
    margin-top: 1rem;
}

.rc-theme-mafia .rc-row {
    background: var(--rc-surface-alt);
    border: none;
    border-radius: 0;
    border-bottom: 1px dashed var(--rc-border);
}

.rc-index {
    color: var(--rc-text-muted);
    font-size: 0.8rem;
    min-width: 2.5rem;
}

.rc-row-name {
    font-weight: 500;
    flex: 1;
}

.rc-row-tag {
    font-size: 0.85rem;
    color: var(--rc-text-muted);
}

.rc-kick-btn {
    border: 1px solid var(--rc-border);
    background: transparent;
    color: var(--rc-text-muted);
    border-radius: 6px;
    padding: 0.3rem 0.7rem;
    font-size: 0.75rem;
    cursor: pointer;
    white-space: nowrap;
    transition: border-color 0.15s ease, color 0.15s ease;
}

.rc-kick-btn:hover:not(:disabled) {
    border-color: var(--rc-primary);
    color: var(--rc-primary);
}

.rc-kick-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.rc-theme-mafia .rc-kick-btn {
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-radius: 2px;
}

.rc-roster {
    display: flex;
    flex-direction: column;
}

.rc-count {
    font-size: 0.875rem;
    color: var(--rc-text-muted);
}

.rc-muted {
    font-size: 0.875rem;
    color: var(--rc-text-muted);
}

/* Buttons */
.rc-btn {
    border-radius: 6px;
    padding: 0.6rem 1.1rem;
    font-weight: 500;
    font-size: 0.9rem;
    border: 1px solid var(--rc-border);
    cursor: pointer;
    transition: opacity 0.15s ease, transform 0.1s ease;
}

.rc-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.rc-btn--primary {
    background: var(--rc-primary);
    border-color: var(--rc-primary);
    color: #fff;
}

.rc-btn--secondary {
    background: var(--rc-secondary);
    border-color: var(--rc-secondary);
    color: #fff;
}

.rc-btn--danger {
    margin-top: 0.75rem;
    background: transparent;
    border-color: var(--rc-primary);
    color: var(--rc-primary);
}

.rc-theme-mafia .rc-btn {
    font-family: var(--rc-font-display);
    border-radius: 2px;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.8rem;
}

.rc-theme-mafia .rc-btn:not(:disabled):hover {
    transform: translateY(-1px);
}

.rc-start-block {
    margin-top: 0;
}

.rc-membership {
    margin-top: 1.5rem;
}

.rc-error {
    margin-top: 0.75rem;
    font-size: 0.85rem;
    color: var(--rc-primary);
}

/* Settings ledger */
.rc-ledger {
    margin-top: 1rem;
}

.rc-ledger-row {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
    padding: 0.6rem 0;
    border-bottom: 1px solid var(--rc-border);
}

.rc-ledger-row:last-child {
    border-bottom: none;
}

.rc-ledger-key {
    text-transform: capitalize;
}

.rc-ledger-leader {
    flex: 1;
}

.rc-theme-mafia .rc-ledger-row {
    border-bottom: none;
}

.rc-theme-mafia .rc-ledger-leader {
    border-bottom: 1px dotted var(--rc-text-muted);
    height: 0.6rem;
}

.rc-ledger-value {
    font-weight: 600;
}

/* Kicked modal */
.rc-kicked-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1.5rem;
}

.rc-kicked-modal {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1.75rem 2rem;
    max-width: 22rem;
    text-align: center;
}

.rc-theme-mafia .rc-kicked-modal {
    border-radius: 2px;
    border: 2px solid var(--rc-primary);
}

.rc-kicked-title {
    font-family: var(--rc-font-display);
    font-size: 1.15rem;
    font-weight: 700;
}

.rc-theme-mafia .rc-kicked-title {
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 1rem;
}

.rc-kicked-text {
    margin-top: 0.6rem;
    font-size: 0.9rem;
    color: var(--rc-text-muted);
}
</style>