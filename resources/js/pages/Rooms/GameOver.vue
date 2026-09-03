<script setup lang="ts">
import { computed } from 'vue'
import type { Room, AuthUser } from '@/types/room'

const props = defineProps<{
    room: Room
    auth: { user: AuthUser }
    isHost: boolean
}>()

const winnerLabel = computed(() => {
    if (props.room.winner === 'town') return 'Town Wins'
    if (props.room.winner === 'mafia') return 'Mafia Wins'
    return 'Game Over'
})

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
    <div class="go-root">
        <div class="go-banner">
            <span class="go-stamp" :class="`go-stamp--${room.winner ?? 'unknown'}`">
                {{ winnerLabel }}
            </span>
        </div>

        <section class="go-panel">
            <h2 class="go-panel-title">Role Reveal</h2>

            <div v-for="group in revealGroups" :key="group.role" class="go-group">
                <h3 class="go-group-title">{{ group.label }}</h3>

                <div v-for="p in group.players" :key="p.id" class="go-row" :class="{ 'go-row--dead': !p.alive }">
                    <span class="go-row-name">
                        {{ p.name }}
                        <span v-if="p.id === auth.user.id" class="go-row-you">(you)</span>
                    </span>
                    <span class="go-row-status">{{ p.alive ? 'Survived' : 'Died' }}</span>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
/* Colors/fonts inherited via CSS custom properties set on Show.vue's
   root .rc-page element — this component doesn't compute its own theme. */

.go-root {
    margin-top: 1.5rem;
}

.go-banner {
    display: flex;
    justify-content: center;
    margin-bottom: 1.5rem;
}

.go-stamp {
    font-family: var(--rc-font-display);
    text-transform: uppercase;
    letter-spacing: 0.1em;
    font-size: 1.4rem;
    font-weight: 700;
    border: 3px solid var(--rc-primary);
    color: var(--rc-primary);
    border-radius: 2px;
    padding: 0.6rem 1.6rem;
    transform: rotate(-3deg);
}

.go-stamp--town {
    border-color: var(--rc-success);
    color: var(--rc-success);
}

.go-stamp--mafia {
    border-color: var(--rc-primary);
    color: var(--rc-primary);
}

.go-panel {
    background: var(--rc-surface);
    color: var(--rc-text-on-surface);
    border: 1px solid var(--rc-border);
    border-radius: 8px;
    padding: 1.5rem;
}

.go-panel-title {
    font-family: var(--rc-font-display);
    font-weight: 600;
    font-size: 1.05rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.go-group {
    margin-top: 1.25rem;
}

.go-group-title {
    font-family: var(--rc-font-display);
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--rc-secondary);
    margin-bottom: 0.5rem;
}

.go-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    background: var(--rc-surface-alt);
    border-bottom: 1px dashed var(--rc-border);
    padding: 0.6rem 0.75rem;
}

.go-row--dead {
    opacity: 0.6;
}

.go-row-name {
    font-weight: 500;
}

.go-row-you {
    font-size: 0.75rem;
    color: var(--rc-text-muted);
    font-weight: 400;
}

.go-row-status {
    font-family: var(--rc-font-mono);
    font-size: 0.8rem;
    color: var(--rc-text-muted);
}
</style>