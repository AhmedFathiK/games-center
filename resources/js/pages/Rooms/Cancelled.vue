<script setup lang="ts">
import { computed } from 'vue'
import type { Room, AuthUser } from '@/types/room'

const props = defineProps<{
    room: Room
    auth: { user: AuthUser }
    isHost: boolean
}>()

const roleOrder = ['mafia', 'doctor', 'detective', 'civilian'] as const

const roleLabels: Record<string, string> = {
    mafia: 'Mafia',
    doctor: 'Doctor',
    detective: 'Detective',
    civilian: 'Civilian',
}

interface RevealedPlayer {
    id: number
    name: string
    role: string
    alive: boolean
}

// role_reveal is populated for cancelled rooms the same way it is for
// finished ones (see RoomController::show()) — everyone gets to see
// exactly what state the game was in when it was cut short, so no
// dispute over "who was about to win" needs anyone to go dig through
// the database.
const revealGroups = computed(() => {
    const reveal = props.room.role_reveal ?? {}

    const players: RevealedPlayer[] = props.room.players.map(p => ({
        id: p.id,
        name: p.name,
        alive: p.alive,
        role: reveal[String(p.id)] ?? 'civilian',
    }))

    return roleOrder
        .map(role => ({
            role,
            label: roleLabels[role],
            players: players.filter(p => p.role === role),
        }))
        .filter(group => group.players.length > 0)
})
</script>

<template>
    <div class="ca-root">
        <div class="ca-banner">
            <span class="ca-stamp">Room Cancelled</span>
        </div>

        <p class="ca-note">
            This game was cancelled before it finished. The state below
            reflects the room at the moment it was cancelled.
        </p>

        <section v-if="revealGroups.length > 0" class="ca-panel">
            <h2 class="ca-panel-title">Role Reveal</h2>

            <div v-for="group in revealGroups" :key="group.role" class="ca-group">
                <h3 class="ca-group-title">{{ group.label }}</h3>

                <div v-for="p in group.players" :key="p.id" class="ca-row" :class="{ 'ca-row--dead': !p.alive }">
                    <span class="ca-row-name">
                        {{ p.name }}
                        <span v-if="p.id === auth.user.id" class="ca-row-you">(you)</span>
                    </span>
                    <span class="ca-row-status">{{ p.alive ? 'Alive' : 'Died' }}</span>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
/* Colors/fonts inherited via CSS custom properties set on Show.vue's
   root .rc-page element — this component doesn't compute its own theme. */

.ca-root {
    margin-top: 1.5rem;
}

.ca-banner {
    display: flex;
    justify-content: center;
    margin-bottom: 1rem;
}

.ca-stamp {
    font-family: var(--rc-font-display);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-size: 1.4rem;
    font-weight: 700;
    border: 3px solid var(--rc-text-muted);
    color: var(--rc-text-muted);
    border-radius: 2px;
    padding: 0.6rem 1.6rem;
    transform: rotate(-3deg);
}

.ca-note {
    text-align: center;
    font-size: 0.85rem;
    color: var(--rc-text-muted);
    max-width: 28rem;
    margin: 0 auto 1.5rem;
    line-height: 1.5;
}

.ca-panel {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1.5rem;
}

.ca-panel-title {
    font-family: var(--rc-font-display);
    font-weight: 600;
    font-size: 1.05rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.ca-group {
    margin-top: 1.25rem;
}

.ca-group-title {
    font-family: var(--rc-font-display);
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--rc-secondary);
    margin-bottom: 0.5rem;
}

.ca-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    background: var(--rc-surface-alt);
    border-bottom: 1px dashed var(--rc-border);
    padding: 0.6rem 0.75rem;
}

.ca-row--dead {
    opacity: 0.6;
}

.ca-row-name {
    font-weight: 500;
}

.ca-row-you {
    font-size: 0.75rem;
    color: var(--rc-text-muted);
    font-weight: 400;
}

.ca-row-status {
    font-family: var(--rc-font-mono);
    font-size: 0.8rem;
    color: var(--rc-text-muted);
}
</style>