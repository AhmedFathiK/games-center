<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import type { Room, AuthUser, MafiaNightState, NightActionState } from '@/types/room'

const props = defineProps<{
    room: Room
    auth: { user: AuthUser }
    isHost: boolean
}>()

const me = computed(() => props.room.you)
const myRole = computed(() => me.value?.role ?? null)
const amAlive = computed(() => me.value?.alive ?? null)
const isParticipant = computed(() => myRole.value !== null)

function playerName(id: number | string | null) {
    if (id === null) return 'no one'
    const found = props.room.players.find(p => String(p.id) === String(id))
    return found?.name ?? `Player #${id}`
}

const alivePlayers = computed(() => props.room.players.filter(p => p.alive))

const isMyTurn = computed(() => props.room.night_step === myRole.value)

// Mirrors MafiaGame::nextNightStep() purely for labeling the host's
// advance button — the server is still what actually enforces this.
function nextStepAfter(current: string, configuration: Record<string, number | boolean>) {
    const order: Array<'mafia' | 'doctor' | 'detective'> = ['mafia', 'doctor', 'detective']
    const enabled: Record<string, boolean> = {
        mafia: true,
        doctor: !!configuration.doctor,
        detective: !!configuration.detective,
    }
    const index = order.indexOf(current as 'mafia' | 'doctor' | 'detective')
    for (let i = index + 1; i < order.length; i++) {
        if (enabled[order[i]]) return order[i]
    }
    return null
}

const advanceButtonLabel = computed(() => {
    if (advancing.value) return 'Advancing…'
    const current = props.room.night_step ?? 'mafia'
    const next = nextStepAfter(current, props.room.configuration)
    if (!next) return 'Advance to Day'
    return `Let ${next.charAt(0).toUpperCase()}${next.slice(1)} Act`
})

// --- Live updates for channels beyond the base room channel --------------
// Show.vue already subscribes to `rooms.{id}` and reloads on every
// platform-level event. Mafia coordination and host oversight ride on
// their own private channels (see routes/channels.php) and need their
// own subscriptions here, scoped to whoever is actually allowed to see
// them. Subscribed once at mount — role/host status don't change during
// a game, so there's nothing to re-subscribe on prop updates.
onMounted(() => {
    if (myRole.value === 'mafia') {
        window.Echo.private(`rooms.${props.room.id}.mafia`).listen(
            '.night-action.updated',
            () => router.reload({ only: ['room'] }),
        )
    }

    if (props.isHost) {
        window.Echo.private(`rooms.${props.room.id}.host`).listen(
            '.night-action.updated',
            () => router.reload({ only: ['room'] }),
        )
    }
})

onUnmounted(() => {
    if (myRole.value === 'mafia') {
        window.Echo.leave(`rooms.${props.room.id}.mafia`)
    }

    if (props.isHost) {
        window.Echo.leave(`rooms.${props.room.id}.host`)
    }
})

// --- Submitting actions ------------------------------------------------
const selecting = ref(false)
const confirming = ref(false)
const actionError = ref<string | null>(null)

// Set the moment a target is clicked, cleared once the round-trip
// finishes — lets the picker highlight the choice instantly instead of
// waiting on the network before the UI reacts.
const pendingTargetId = ref<number | string | null>(null)

function submitSelect(type: string, targetId: number) {
    pendingTargetId.value = targetId
    selecting.value = true
    actionError.value = null

    router.post(
        `/rooms/${props.room.id}/actions`,
        { type, target_id: targetId },
        {
            onError: errors => {
                actionError.value = Object.values(errors)[0] ?? 'Unable to submit selection.'
                pendingTargetId.value = null
            },
            onFinish: () => {
                selecting.value = false
                pendingTargetId.value = null
            },
        },
    )
}

function submitConfirm(type: string) {
    confirming.value = true
    actionError.value = null

    router.post(
        `/rooms/${props.room.id}/actions`,
        { type },
        {
            onError: errors => {
                actionError.value = Object.values(errors)[0] ?? 'Unable to confirm.'
            },
            onFinish: () => {
                confirming.value = false
            },
        },
    )
}

const advancing = ref(false)
const advanceError = ref<string | null>(null)

function advancePhase() {
    advancing.value = true
    advanceError.value = null

    router.post(
        `/rooms/${props.room.id}/advance`,
        {},
        {
            onError: errors => {
                advanceError.value = Object.values(errors)[0] ?? 'Unable to advance the phase.'
            },
            onFinish: () => {
                advancing.value = false
            },
        },
    )
}

// --- Mafia coordination state -------------------------------------------
const mafiaState = computed<MafiaNightState | null>(() =>
    myRole.value === 'mafia' ? (me.value?.night_action as MafiaNightState) : null,
)

const myMafiaSelection = computed(() => mafiaState.value?.selections?.[String(props.auth.user.id)] ?? null)
const myMafiaConfirmed = computed(() => mafiaState.value?.confirmed?.[String(props.auth.user.id)] ?? false)

const mafiaRoster = computed(() => {
    if (myRole.value !== 'mafia') return []
    const teammates = props.room.you?.mafia_team ?? []
    return [{ id: props.auth.user.id, name: 'You' }, ...teammates]
})

function mafiaPickFor(id: number) {
    if (id === props.auth.user.id && pendingTargetId.value !== null) {
        return pendingTargetId.value
    }
    return mafiaState.value?.selections?.[String(id)] ?? null
}

function mafiaConfirmedFor(id: number) {
    return mafiaState.value?.confirmed?.[String(id)] ?? false
}

const mafiaConsensusLocked = computed(() => {
    const roster = mafiaRoster.value
    if (roster.length === 0) return false
    if (!roster.every(m => mafiaConfirmedFor(m.id))) return false
    const targets = new Set(roster.map(m => String(mafiaPickFor(m.id))))
    return targets.size === 1
})

// --- Doctor / detective solo state --------------------------------------
const soloAction = computed<NightActionState | null>(() =>
    myRole.value === 'doctor' || myRole.value === 'detective'
        ? (me.value?.night_action as NightActionState)
        : null,
)

const mySoloSelection = computed(() => soloAction.value?.selected_target_id ?? null)
const mySoloConfirmed = computed(() => soloAction.value?.confirmed ?? false)

// --- Host oversight state -------------------------------------------------
const hostRoles = computed(() => props.room.host_view?.roles ?? {})
const hostNightActions = computed(() => props.room.host_view?.night_actions ?? null)

function playersWithRole(role: string) {
    return Object.entries(hostRoles.value)
        .filter(([, r]) => r === role)
        .map(([id]) => ({ id: Number(id), name: playerName(id) }))
}

// Tally of "how many mafia currently have this target selected" —
// derived entirely from data the host already receives via host_view,
// so the host doesn't have to manually count matching selections across
// the per-member rows below. Counts pending and confirmed selections
// alike; sorted by count so the leading target is easy to spot.
const mafiaTargetTally = computed(() => {
    const selections = hostNightActions.value?.mafia?.selections ?? {}
    const counts: Record<string, number> = {}

    for (const targetId of Object.values(selections)) {
        if (targetId === null || targetId === undefined) continue
        const key = String(targetId)
        counts[key] = (counts[key] ?? 0) + 1
    }

    return Object.entries(counts)
        .map(([id, count]) => ({ id, name: playerName(id), count }))
        .sort((a, b) => b.count - a.count)
})
</script>

<template>
    <div class="np-root">
        <div class="np-banner">
            <span class="np-stamp">
                Night · Round {{ room.round }} · {{ (room.night_step ?? 'mafia').charAt(0).toUpperCase() + (room.night_step ?? 'mafia').slice(1) }}'s Turn
            </span>
        </div>

        <!-- Host oversight — always shown to the host, in addition to any player panel below -->
        <section v-if="isHost" class="np-panel">
            <h2 class="np-panel-title">Host View — Full Night Report</h2>

            <div class="np-host-block">
                <h3 class="np-host-role-title">Mafia</h3>

                <div v-if="mafiaTargetTally.length > 0" class="np-tally">
                    <span v-for="t in mafiaTargetTally" :key="t.id" class="np-tally-badge">
                        {{ t.name }} <strong>{{ t.count }}</strong>
                    </span>
                </div>

                <div v-for="p in playersWithRole('mafia')" :key="p.id" class="np-row">
                    <span class="np-row-name">{{ p.name }}</span>
                    <span class="np-row-status">
                        <template v-if="hostNightActions?.mafia.confirmed[p.id]">
                            Confirmed: {{ playerName(hostNightActions.mafia.selections[p.id]) }}
                        </template>
                        <template v-else-if="hostNightActions?.mafia.selections[p.id]">
                            Selected: {{ playerName(hostNightActions.mafia.selections[p.id]) }} (pending)
                        </template>
                        <template v-else>No selection yet</template>
                    </span>
                </div>

                <p v-if="playersWithRole('mafia').length === 0" class="np-muted">No Mafia in this game.</p>
            </div>

            <div class="np-divider" />

            <div class="np-host-block">
                <h3 class="np-host-role-title">Doctor</h3>

                <div v-for="p in playersWithRole('doctor')" :key="p.id" class="np-row">
                    <span class="np-row-name">{{ p.name }}</span>
                    <span class="np-row-status">
                        <template v-if="hostNightActions?.doctor.confirmed[p.id]">
                            Confirmed: {{ playerName(hostNightActions.doctor.selections[p.id]) }}
                        </template>
                        <template v-else-if="hostNightActions?.doctor.selections[p.id]">
                            Selected: {{ playerName(hostNightActions.doctor.selections[p.id]) }} (pending)
                        </template>
                        <template v-else>No selection yet</template>
                    </span>
                </div>

                <p v-if="playersWithRole('doctor').length === 0" class="np-muted">No Doctor in this game.</p>
            </div>

            <div class="np-divider" />

            <div class="np-host-block">
                <h3 class="np-host-role-title">Detective</h3>

                <div v-for="p in playersWithRole('detective')" :key="p.id" class="np-row">
                    <span class="np-row-name">{{ p.name }}</span>
                    <span class="np-row-status">
                        <template v-if="hostNightActions?.detective.results[p.id]">
                            Checked {{ playerName(hostNightActions.detective.results[p.id].target_id) }} —
                            {{ hostNightActions.detective.results[p.id].is_mafia ? 'Mafia' : 'Not Mafia' }}
                        </template>
                        <template v-else-if="hostNightActions?.detective.selections[p.id]">
                            Selected: {{ playerName(hostNightActions.detective.selections[p.id]) }} (pending)
                        </template>
                        <template v-else>No selection yet</template>
                    </span>
                </div>

                <p v-if="playersWithRole('detective').length === 0" class="np-muted">No Detective in this game.</p>
            </div>

            <div class="np-divider" />

            <button
                type="button"
                class="np-btn np-btn--primary"
                :disabled="advancing"
                @click="advancePhase"
            >
                {{ advanceButtonLabel }}
            </button>

            <p v-if="advanceError" class="np-error">{{ advanceError }}</p>
        </section>

        <!-- Player panel — only rendered for an actual participant in this game (host of a game where hostIsPlayer() is false has no role and sees only the panel above) -->
        <template v-if="isParticipant">
            <section v-if="myRole === 'mafia' && amAlive" class="np-panel">
                <h2 class="np-panel-title">Mafia — Choose a Target</h2>
                <p class="np-hint">All Mafia must confirm the same target for the kill to succeed.</p>

                <div class="np-roster">
                    <div v-for="m in mafiaRoster" :key="m.id" class="np-row">
                        <span class="np-row-name">{{ m.name }}</span>
                        <span class="np-row-status">
                            <template v-if="mafiaConfirmedFor(m.id)">
                                Confirmed: {{ playerName(mafiaPickFor(m.id)) }}
                            </template>
                            <template v-else-if="mafiaPickFor(m.id)">
                                Selected: {{ playerName(mafiaPickFor(m.id)) }} (pending)
                            </template>
                            <template v-else>No selection yet</template>
                        </span>
                    </div>
                </div>

                <p class="np-consensus" :class="{ 'np-consensus--locked': mafiaConsensusLocked }">
                    {{ mafiaConsensusLocked ? 'Target locked in.' : 'Waiting for full Mafia consensus.' }}
                </p>

                <p v-if="!isMyTurn && !myMafiaConfirmed" class="np-status-line">
                    The host has moved on — your turn has ended for this round.
                </p>

                <div v-if="isMyTurn" class="np-target-picker">
                    <button
                        v-for="p in alivePlayers"
                        :key="p.id"
                        type="button"
                        class="np-target"
                        :class="{ 'np-target--selected': String(pendingTargetId ?? myMafiaSelection) === String(p.id) }"
                        :disabled="selecting || myMafiaConfirmed"
                        @click="submitSelect('mafia_select', p.id)"
                    >
                        {{ p.name }}
                    </button>
                </div>

                <button
                    v-if="isMyTurn && myMafiaSelection && !myMafiaConfirmed"
                    type="button"
                    class="np-btn np-btn--primary"
                    :disabled="confirming"
                    @click="submitConfirm('mafia_confirm')"
                >
                    {{ confirming ? 'Confirming…' : 'Confirm Kill Target' }}
                </button>

                <p v-if="myMafiaConfirmed" class="np-locked">
                    You confirmed: {{ playerName(myMafiaSelection) }}. This cannot be changed.
                </p>

                <p v-if="actionError" class="np-error">{{ actionError }}</p>
            </section>

            <section v-else-if="myRole === 'doctor' && amAlive" class="np-panel">
                <h2 class="np-panel-title">Doctor — Choose Someone to Save</h2>

                <p class="np-status-line">
                    <template v-if="mySoloConfirmed">
                        Confirmed: {{ playerName(mySoloSelection) }}. This cannot be changed.
                    </template>
                    <template v-else-if="!isMyTurn">
                        It's currently {{ room.night_step }}'s turn. Sit tight.
                    </template>
                    <template v-else-if="mySoloSelection">
                        Selected: {{ playerName(mySoloSelection) }} — confirm to lock it in.
                    </template>
                    <template v-else>Choose a player to save tonight.</template>
                </p>

                <div class="np-target-picker">
                    <button
                        v-for="p in alivePlayers"
                        :key="p.id"
                        type="button"
                        class="np-target"
                        :class="{ 'np-target--selected': String(pendingTargetId ?? mySoloSelection) === String(p.id) }"
                        :disabled="selecting || mySoloConfirmed || !isMyTurn"
                        @click="submitSelect('doctor_select', p.id)"
                    >
                        {{ p.name }}{{ p.id === auth.user.id ? ' (yourself)' : '' }}
                    </button>
                </div>

                <button
                    v-if="isMyTurn && mySoloSelection && !mySoloConfirmed"
                    type="button"
                    class="np-btn np-btn--primary"
                    :disabled="confirming"
                    @click="submitConfirm('doctor_confirm')"
                >
                    {{ confirming ? 'Confirming…' : 'Confirm Save' }}
                </button>

                <p v-if="actionError" class="np-error">{{ actionError }}</p>
            </section>

            <section v-else-if="myRole === 'detective' && amAlive" class="np-panel">
                <h2 class="np-panel-title">Detective — Investigate a Player</h2>

                <p class="np-status-line">
                    <template v-if="room.you?.detective_result">
                        {{ playerName(room.you.detective_result.target_id) }} is
                        <strong>{{ room.you.detective_result.is_mafia ? 'Mafia' : 'not Mafia' }}</strong>.
                    </template>
                    <template v-else-if="!isMyTurn">
                        It's currently {{ room.night_step }}'s turn. Sit tight.
                    </template>
                    <template v-else-if="mySoloSelection">
                        Selected: {{ playerName(mySoloSelection) }} — confirm to investigate.
                    </template>
                    <template v-else>Choose a player to investigate tonight.</template>
                </p>

                <div class="np-target-picker">
                    <button
                        v-for="p in alivePlayers.filter(p => p.id !== auth.user.id)"
                        :key="p.id"
                        type="button"
                        class="np-target"
                        :class="{ 'np-target--selected': String(pendingTargetId ?? mySoloSelection) === String(p.id) }"
                        :disabled="selecting || mySoloConfirmed || !!room.you?.detective_result || !isMyTurn"
                        @click="submitSelect('detective_select', p.id)"
                    >
                        {{ p.name }}
                    </button>
                </div>

                <button
                    v-if="isMyTurn && mySoloSelection && !mySoloConfirmed && !room.you?.detective_result"
                    type="button"
                    class="np-btn np-btn--primary"
                    :disabled="confirming"
                    @click="submitConfirm('detective_confirm')"
                >
                    {{ confirming ? 'Confirming…' : 'Confirm Investigation' }}
                </button>

                <p v-if="actionError" class="np-error">{{ actionError }}</p>
            </section>

            <!-- Civilian (alive) or dead player of any role -->
            <section v-else class="np-panel">
                <h2 class="np-panel-title">
                    {{ amAlive ? 'Night Falls' : 'You Have Been Eliminated' }}
                </h2>
                <p class="np-muted">
                    <template v-if="amAlive">
                        Nothing to do right now — it's currently {{ room.night_step ?? 'Mafia' }}'s turn.
                    </template>
                    <template v-else>
                        You can no longer act, but you can keep watching how the game unfolds.
                    </template>
                </p>
            </section>
        </template>
    </div>
</template>

<style scoped>
/* Colors/fonts inherited via CSS custom properties set on Show.vue's
   root .rc-page element — this component doesn't compute its own theme. */

.np-root {
    margin-top: 1.5rem;
}

.np-banner {
    display: flex;
    justify-content: center;
    margin-bottom: 1.5rem;
}

.np-stamp {
    font-family: var(--rc-font-display);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 0.85rem;
    color: var(--rc-primary);
    border: 2px solid var(--rc-primary);
    border-radius: 2px;
    padding: 0.4rem 1.1rem;
    transform: rotate(-2deg);
}

.np-panel {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1.5rem;
    margin-top: 1.25rem;
}

.np-panel-title {
    font-family: var(--rc-font-display);
    font-weight: 600;
    font-size: 1.05rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.np-hint {
    margin-top: 0.4rem;
    font-size: 0.85rem;
    color: var(--rc-text-muted);
}

.np-status-line {
    margin-top: 0.5rem;
    font-size: 0.9rem;
    color: var(--rc-text-muted);
}

.np-divider {
    margin: 1.25rem 0;
    border-bottom: 2px dashed var(--rc-border);
}

.np-host-block {
    margin-top: 0.5rem;
}

.np-host-role-title {
    font-family: var(--rc-font-display);
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--rc-secondary);
    margin-bottom: 0.5rem;
}

/* Selection tally — quick-scan pills so the host doesn't have to count
   matching picks across the per-member rows below. */
.np-tally {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.np-tally-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: var(--rc-surface-alt);
    border: 1px solid var(--rc-secondary);
    color: var(--rc-text-on-surface);
    border-radius: 999px;
    padding: 0.25rem 0.7rem;
    font-size: 0.78rem;
}

.np-tally-badge strong {
    font-family: var(--rc-font-mono);
    color: var(--rc-secondary);
}

.np-roster {
    margin-top: 1rem;
}

.np-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    background: var(--rc-surface-alt);
    border-bottom: 1px dashed var(--rc-border);
    padding: 0.6rem 0.75rem;
}

.np-row-name {
    font-weight: 500;
}

.np-row-status {
    font-family: var(--rc-font-mono);
    font-size: 0.8rem;
    color: var(--rc-text-muted);
    text-align: right;
}

.np-consensus {
    margin-top: 1rem;
    font-size: 0.85rem;
    color: var(--rc-text-muted);
}

.np-consensus--locked {
    color: var(--rc-success);
    font-weight: 600;
}

.np-target-picker {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-top: 1rem;
}

.np-target {
    text-align: left;
    background: var(--rc-surface-alt);
    border: 1px solid var(--rc-border);
    border-radius: 4px;
    padding: 0.6rem 0.9rem;
    font-family: var(--rc-font-body);
    font-size: 0.9rem;
    cursor: pointer;
    transition: border-color 0.1s ease;
}

.np-target:hover:not(:disabled) {
    border-color: var(--rc-secondary);
}

.np-target--selected {
    border-color: var(--rc-primary);
    border-width: 2px;
}

.np-target:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.np-btn {
    margin-top: 1rem;
    border-radius: 2px;
    padding: 0.6rem 1.1rem;
    font-family: var(--rc-font-display);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.8rem;
    border: 1px solid var(--rc-border);
    cursor: pointer;
}

.np-btn--primary {
    background: var(--rc-primary);
    border-color: var(--rc-primary);
    color: #fff;
}

.np-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.np-locked {
    margin-top: 1rem;
    font-size: 0.9rem;
    color: var(--rc-success);
    font-weight: 600;
}

.np-muted {
    margin-top: 0.5rem;
    font-size: 0.9rem;
    color: var(--rc-text-muted);
}

.np-error {
    margin-top: 0.75rem;
    font-size: 0.85rem;
    color: var(--rc-primary);
}
</style>