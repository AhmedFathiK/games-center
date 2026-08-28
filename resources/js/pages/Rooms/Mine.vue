<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

interface RoomSummary {
    id: number
    code: string
    status: string
    game: {
        name: string
        slug: string
    }
    host: {
        id: number
        name: string
    }
    is_host: boolean
    updated_at: string | null
}

interface HistoryPage {
    data: RoomSummary[]
    current_page: number
    last_page: number
    prev_page_url: string | null
    next_page_url: string | null
    total: number
}

const props = defineProps<{
    active_room: RoomSummary | null
    history: HistoryPage
}>()

function goToRoom(code: string) {
    router.get(route('rooms.show', code))
}

function goToPage(url: string | null) {
    if (!url) return
    router.get(url, {}, { preserveScroll: true })
}

function formatDate(iso: string | null) {
    if (!iso) return '—'
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}
</script>

<template>
    <div class="mr-page">
        <div class="mr-container">
            <header class="mr-header">
                <p class="mr-eyebrow">Games Center</p>
                <h1 class="mr-title">My Rooms</h1>
                <p class="mr-subtitle">Your current room and your room history.</p>
            </header>

            <!-- Active room -->
            <section class="mr-panel">
                <h2 class="mr-panel-title">Active Room</h2>

                <div v-if="props.active_room" class="mr-active-card" @click="goToRoom(props.active_room.code)">
                    <div class="mr-active-info">
                        <p class="mr-active-game">{{ props.active_room.game.name }}</p>
                        <p class="mr-mono mr-active-meta">
                            {{ props.active_room.code }} ·
                            {{ props.active_room.is_host ? 'Hosting' : 'Playing' }}
                        </p>
                    </div>

                    <span class="mr-badge" :class="`mr-badge--${props.active_room.status}`">
                        {{ props.active_room.status }}
                    </span>
                </div>

                <p v-else class="mr-empty">
                    You don't have an active room right now.
                    <a :href="route('games.index')" class="mr-inline-link">Browse games</a>
                    to create or join one.
                </p>
            </section>

            <!-- History -->
            <section class="mr-panel">
                <div class="mr-panel-header">
                    <h2 class="mr-panel-title">History</h2>
                    <span class="mr-mono mr-count">{{ props.history.total }} total</span>
                </div>

                <div v-if="props.history.data.length > 0" class="mr-history-list">
                    <div
                        v-for="room in props.history.data"
                        :key="room.id"
                        class="mr-history-row"
                        @click="goToRoom(room.code)"
                    >
                        <div class="mr-history-info">
                            <p class="mr-history-game">{{ room.game.name }}</p>
                            <p class="mr-mono mr-history-meta">
                                {{ room.code }} · {{ room.is_host ? 'Hosted' : 'Played' }} ·
                                {{ formatDate(room.updated_at) }}
                            </p>
                        </div>

                        <span class="mr-badge" :class="`mr-badge--${room.status}`">
                            {{ room.status }}
                        </span>
                    </div>
                </div>

                <p v-else class="mr-empty">No finished rooms yet.</p>

                <div v-if="props.history.last_page > 1" class="mr-pagination">
                    <button
                        type="button"
                        class="mr-page-btn"
                        :disabled="!props.history.prev_page_url"
                        @click="goToPage(props.history.prev_page_url)"
                    >
                        ← Prev
                    </button>

                    <span class="mr-mono mr-page-indicator">
                        Page {{ props.history.current_page }} of {{ props.history.last_page }}
                    </span>

                    <button
                        type="button"
                        class="mr-page-btn"
                        :disabled="!props.history.next_page_url"
                        @click="goToPage(props.history.next_page_url)"
                    >
                        Next →
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');

.mr-page {
    --mr-ink: #0f1613;
    --mr-surface: #16201c;
    --mr-surface-raised: #1e2b25;
    --mr-border: #2a3a33;
    --mr-amber: #e8a33d;
    --mr-phosphor: #6fcf97;
    --mr-mist: #9fb0a8;
    --mr-paper: #eef2ef;

    min-height: calc(100vh - 3.5rem);
    background: var(--mr-ink);
    color: var(--mr-paper);
    font-family: 'Inter', sans-serif;
    padding: 2rem 1.5rem 4rem;
}

.mr-mono {
    font-family: 'JetBrains Mono', monospace;
}

.mr-container {
    max-width: 42rem;
    margin: 0 auto;
}

.mr-eyebrow {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--mr-phosphor);
}

.mr-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 700;
    margin-top: 0.5rem;
}

.mr-subtitle {
    margin-top: 0.4rem;
    color: var(--mr-mist);
    font-size: 0.95rem;
}

.mr-panel {
    margin-top: 1.75rem;
    background: var(--mr-surface);
    border: 1px solid var(--mr-border);
    border-radius: 10px;
    padding: 1.5rem;
}

.mr-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.mr-panel-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.1rem;
    font-weight: 700;
}

.mr-count {
    font-size: 0.8rem;
    color: var(--mr-mist);
}

.mr-empty {
    margin-top: 1rem;
    color: var(--mr-mist);
    font-size: 0.9rem;
}

.mr-inline-link {
    color: var(--mr-amber);
    text-decoration: underline;
}

.mr-active-card {
    margin-top: 1rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    background: var(--mr-surface-raised);
    border: 1px solid var(--mr-border);
    border-radius: 8px;
    padding: 1rem 1.1rem;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.mr-active-card:hover {
    border-color: var(--mr-amber);
}

.mr-active-game {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 1rem;
}

.mr-active-meta {
    margin-top: 0.3rem;
    font-size: 0.78rem;
    color: var(--mr-mist);
}

.mr-history-list {
    margin-top: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}

.mr-history-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    background: var(--mr-surface-raised);
    border: 1px solid var(--mr-border);
    border-radius: 8px;
    padding: 0.85rem 1rem;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.mr-history-row:hover {
    border-color: var(--mr-phosphor);
}

.mr-history-game {
    font-weight: 500;
    font-size: 0.92rem;
}

.mr-history-meta {
    margin-top: 0.25rem;
    font-size: 0.75rem;
    color: var(--mr-mist);
}

.mr-badge {
    border: 1px solid var(--mr-border);
    padding: 0.25rem 0.7rem;
    font-size: 0.72rem;
    text-transform: capitalize;
    border-radius: 999px;
    color: var(--mr-mist);
    white-space: nowrap;
}

.mr-badge--waiting {
    border-color: var(--mr-amber);
    color: var(--mr-amber);
}

.mr-badge--in_progress {
    border-color: var(--mr-phosphor);
    color: var(--mr-phosphor);
}

.mr-badge--finished {
    border-color: var(--mr-mist);
    color: var(--mr-mist);
}

.mr-pagination {
    margin-top: 1.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 1.25rem;
}

.mr-page-btn {
    background: transparent;
    border: 1px solid var(--mr-border);
    color: var(--mr-paper);
    border-radius: 6px;
    padding: 0.4rem 0.9rem;
    font-size: 0.8rem;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.mr-page-btn:hover:not(:disabled) {
    border-color: var(--mr-amber);
}

.mr-page-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.mr-page-indicator {
    font-size: 0.78rem;
    color: var(--mr-mist);
}
</style>