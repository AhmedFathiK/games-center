<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import type { Room, AuthUser } from '@/types/room'

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

// --- Public vote board ---------------------------------------------------
const votes = computed(() => props.room.day_votes)

function voteFor(id: number | string) {
    return votes.value?.selections?.[String(id)] ?? null
}

function voteConfirmedFor(id: number | string) {
    return votes.value?.confirmed?.[String(id)] ?? false
}

// Tally of "how many players currently have this person selected" —
// day_votes is public to everyone already, so this is just an
// aggregation of data already on the page, not a new privacy surface.
// Sorted by count so the leading target is easy to spot at a glance.
const voteTally = computed(() => {
    const selections = votes.value?.selections ?? {}
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

function voteCountFor(id: number | string) {
    return voteTally.value.find(t => t.id === String(id))?.count ?? 0
}

// --- My vote (select -> confirm -> lock, same pattern as night actions) --
const myVoteSelection = computed(() => {
    // Optimistic: show the pending pick instantly instead of waiting on
    // the round-trip, same as the night-phase pickers.
    if (pendingVoteTargetId.value !== null) return pendingVoteTargetId.value
    return votes.value?.selections?.[String(props.auth.user.id)] ?? null
})

const myVoteConfirmed = computed(() => votes.value?.confirmed?.[String(props.auth.user.id)] ?? false)

const selecting = ref(false)
const confirming = ref(false)
const actionError = ref<string | null>(null)
const pendingVoteTargetId = ref<number | string | null>(null)

function submitVoteSelect(targetId: number) {
    pendingVoteTargetId.value = targetId
    selecting.value = true
    actionError.value = null

    router.post(
        `/rooms/${props.room.id}/actions`,
        { type: 'vote_select', target_id: targetId },
        {
            onError: errors => {
                actionError.value = Object.values(errors)[0] ?? 'Unable to submit your vote.'
                pendingVoteTargetId.value = null
            },
            onFinish: () => {
                selecting.value = false
                pendingVoteTargetId.value = null
            },
        },
    )
}

function submitVoteConfirm() {
    confirming.value = true
    actionError.value = null

    router.post(
        `/rooms/${props.room.id}/actions`,
        { type: 'vote_confirm' },
        {
            onError: errors => {
                actionError.value = Object.values(errors)[0] ?? 'Unable to confirm your vote.'
            },
            onFinish: () => {
                confirming.value = false
            },
        },
    )
}

// --- Host: execute, skip, or advance to night -----------------------------
const selectedExecuteTarget = ref<number | null>(null)
const executing = ref(false)
const executeError = ref<string | null>(null)

function toggleExecuteTarget(id: number) {
    selectedExecuteTarget.value = selectedExecuteTarget.value === id ? null : id
}

function submitExecute(targetId: number | null) {
    executing.value = true
    executeError.value = null

    router.post(
        `/rooms/${props.room.id}/execute`,
        { target_id: targetId },
        {
            onError: errors => {
                executeError.value = Object.values(errors)[0] ?? 'Unable to execute.'
            },
            onFinish: () => {
                executing.value = false
                selectedExecuteTarget.value = null
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
</script>

<template>
    <div class="dp-root">
        <div class="dp-banner">
            <span class="dp-stamp">Day · Round {{ room.round }}</span>
        </div>

        <!-- Public vote board -->
        <section class="dp-panel">
            <h2 class="dp-panel-title">Town Vote</h2>
            <p class="dp-hint">Voting is advisory — the host decides who, if anyone, is executed.</p>

            <div v-if="voteTally.length > 0" class="dp-tally">
                <span v-for="t in voteTally" :key="t.id" class="dp-tally-badge">
                    {{ t.name }} <strong>{{ t.count }}</strong>
                </span>
            </div>

            <div class="dp-roster">
                <div v-for="p in room.players" :key="p.id" class="dp-row" :class="{ 'dp-row--dead': !p.alive }">
                    <span class="dp-row-name">
                        {{ p.name }}<span v-if="!p.alive" class="dp-dead-tag"> (dead)</span>
                    </span>
                    <span class="dp-row-status">
                        <template v-if="voteConfirmedFor(p.id)">
                            Voted: {{ playerName(voteFor(p.id)) }}
                        </template>
                        <template v-else-if="voteFor(p.id)">
                            Selected: {{ playerName(voteFor(p.id)) }} (pending)
                        </template>
                        <template v-else>No vote yet</template>
                    </span>
                </div>
            </div>
        </section>

        <!-- My vote -->
        <section v-if="isParticipant && amAlive" class="dp-panel">
            <h2 class="dp-panel-title">Cast Your Vote</h2>

            <p class="dp-status-line">
                <template v-if="myVoteConfirmed">
                    Confirmed: {{ playerName(myVoteSelection) }}. This cannot be changed.
                </template>
                <template v-else-if="myVoteSelection">
                    Selected: {{ playerName(myVoteSelection) }} — confirm to lock it in.
                </template>
                <template v-else>Choose who you think should be executed.</template>
            </p>

            <div class="dp-target-picker">
                <button
                    v-for="p in alivePlayers"
                    :key="p.id"
                    type="button"
                    class="dp-target"
                    :class="{ 'dp-target--selected': String(myVoteSelection) === String(p.id) }"
                    :disabled="selecting || myVoteConfirmed"
                    @click="submitVoteSelect(p.id)"
                >
                    {{ p.name }}{{ p.id === auth.user.id ? ' (yourself)' : '' }}
                </button>
            </div>

            <button
                v-if="myVoteSelection && !myVoteConfirmed"
                type="button"
                class="dp-btn dp-btn--primary"
                :disabled="confirming"
                @click="submitVoteConfirm"
            >
                {{ confirming ? 'Confirming…' : 'Confirm Vote' }}
            </button>

            <p v-if="actionError" class="dp-error">{{ actionError }}</p>
        </section>

        <section v-else-if="isParticipant && !amAlive" class="dp-panel">
            <h2 class="dp-panel-title">You Have Been Eliminated</h2>
            <p class="dp-muted">You can no longer vote, but you can keep watching how the day unfolds.</p>
        </section>

        <!-- Host controls -->
        <section v-if="isHost" class="dp-panel">
            <h2 class="dp-panel-title">Host — Execute or Skip</h2>
            <p class="dp-hint">
                You may execute anyone, no one, or ignore the vote entirely — the tally above is advisory only.
            </p>

            <div class="dp-target-picker">
                <button
                    v-for="p in alivePlayers"
                    :key="p.id"
                    type="button"
                    class="dp-target"
                    :class="{ 'dp-target--selected': selectedExecuteTarget === p.id }"
                    :disabled="executing"
                    @click="toggleExecuteTarget(p.id)"
                >
                    <span class="dp-target-name">{{ p.name }}</span>
                    <span v-if="voteCountFor(p.id) > 0" class="dp-target-badge">{{ voteCountFor(p.id) }}</span>
                </button>
            </div>

            <div class="dp-host-actions">
                <button
                    type="button"
                    class="dp-btn dp-btn--danger"
                    :disabled="executing || selectedExecuteTarget === null"
                    @click="submitExecute(selectedExecuteTarget)"
                >
                    {{ executing ? 'Working…' : 'Execute Selected' }}
                </button>

                <button
                    type="button"
                    class="dp-btn"
                    :disabled="executing"
                    @click="submitExecute(null)"
                >
                    {{ executing ? 'Working…' : 'Skip Execution' }}
                </button>
            </div>

            <p v-if="executeError" class="dp-error">{{ executeError }}</p>

            <div class="dp-divider" />

            <button
                type="button"
                class="dp-btn dp-btn--primary"
                :disabled="advancing"
                @click="advancePhase"
            >
                {{ advancing ? 'Advancing…' : 'Advance to Night' }}
            </button>

            <p v-if="advanceError" class="dp-error">{{ advanceError }}</p>
        </section>
    </div>
</template>

<style scoped>
/* Colors/fonts inherited via CSS custom properties set on Show.vue's
   root .rc-page element — this component doesn't compute its own theme. */

.dp-root {
    margin-top: 1.5rem;
}

.dp-banner {
    display: flex;
    justify-content: center;
    margin-bottom: 1.5rem;
}

.dp-stamp {
    font-family: var(--rc-font-display);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-size: 0.85rem;
    color: var(--rc-secondary);
    border: 2px solid var(--rc-secondary);
    border-radius: 2px;
    padding: 0.4rem 1.1rem;
    transform: rotate(-2deg);
}

.dp-panel {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1.5rem;
    margin-top: 1.25rem;
}

.dp-panel-title {
    font-family: var(--rc-font-display);
    font-weight: 600;
    font-size: 1.05rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.dp-hint {
    margin-top: 0.4rem;
    font-size: 0.85rem;
    color: var(--rc-text-muted);
}

.dp-status-line {
    margin-top: 0.5rem;
    font-size: 0.9rem;
    color: var(--rc-text-muted);
}

.dp-divider {
    margin: 1.25rem 0;
    border-bottom: 2px dashed var(--rc-border);
}

/* Vote tally — quick-scan pills so no one has to count matching votes
   across the per-voter roster below. */
.dp-tally {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.9rem;
}

.dp-tally-badge {
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

.dp-tally-badge strong {
    font-family: var(--rc-font-mono);
    color: var(--rc-secondary);
}

.dp-roster {
    margin-top: 1rem;
}

.dp-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    background: var(--rc-surface-alt);
    border-bottom: 1px dashed var(--rc-border);
    padding: 0.6rem 0.75rem;
}

.dp-row--dead {
    opacity: 0.55;
}

.dp-dead-tag {
    font-size: 0.75rem;
    color: var(--rc-primary);
}

.dp-row-name {
    font-weight: 500;
}

.dp-row-status {
    font-family: var(--rc-font-mono);
    font-size: 0.8rem;
    color: var(--rc-text-muted);
    text-align: right;
}

.dp-target-picker {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    margin-top: 1rem;
}

.dp-target {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
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

.dp-target:hover:not(:disabled) {
    border-color: var(--rc-secondary);
}

.dp-target--selected {
    border-color: var(--rc-primary);
    border-width: 2px;
}

.dp-target:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.dp-target-badge {
    flex-shrink: 0;
    font-family: var(--rc-font-mono);
    font-size: 0.75rem;
    background: var(--rc-surface);
    border: 1px solid var(--rc-secondary);
    color: var(--rc-secondary);
    border-radius: 999px;
    padding: 0.1rem 0.5rem;
}

.dp-host-actions {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    flex-wrap: wrap;
}

.dp-btn {
    margin-top: 1rem;
    border-radius: 2px;
    padding: 0.6rem 1.1rem;
    font-family: var(--rc-font-display);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-size: 0.8rem;
    border: 1px solid var(--rc-border);
    background: var(--rc-surface-alt);
    color: var(--rc-text-on-surface);
    cursor: pointer;
}

.dp-host-actions .dp-btn {
    margin-top: 0;
}

.dp-btn--primary {
    background: var(--rc-secondary);
    border-color: var(--rc-secondary);
    color: #fff;
}

.dp-btn--danger {
    background: var(--rc-primary);
    border-color: var(--rc-primary);
    color: #fff;
}

.dp-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.dp-muted {
    margin-top: 0.5rem;
    font-size: 0.9rem;
    color: var(--rc-text-muted);
}

.dp-error {
    margin-top: 0.75rem;
    font-size: 0.85rem;
    color: var(--rc-primary);
}
</style>