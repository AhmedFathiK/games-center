<script setup lang="ts">
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'

interface ConfigurationField {
    type: 'integer' | 'boolean'
    default?: number | boolean
    min?: number
    max?: number
}

interface Game {
    id: number
    name: string
    slug: string
    description: string | null
    minimum_players: number
    maximum_players: number
    host_is_player: boolean
    configuration_schema: Record<string, ConfigurationField>
}

const props = defineProps<{
    games: Game[]
}>()

const selectedGame = ref<Game | null>(null)
const configuration = ref<Record<string, number | boolean>>({})
const maxPlayers = ref<number>(0)
const creatingRoom = ref(false)
const error = ref<string | null>(null)

function selectGame(game: Game) {
    selectedGame.value = game
    configuration.value = {}
    maxPlayers.value = game.maximum_players
    error.value = null

    for (const [key, field] of Object.entries(game.configuration_schema)) {
        if (field.default !== undefined) {
            configuration.value[key] = field.default
        } else if (field.type === 'integer' && field.min !== undefined) {
            configuration.value[key] = field.min
        }
    }
}

function fieldLabel(key: string) {
    return key.replace(/_/g, ' ')
}

function stepMaxPlayers(delta: number) {
    if (!selectedGame.value) return
    const next = maxPlayers.value + delta
    maxPlayers.value = Math.min(
        Math.max(next, selectedGame.value.minimum_players),
        selectedGame.value.maximum_players,
    )
}

function stepField(key: string, field: ConfigurationField, delta: number) {
    const current = Number(configuration.value[key] ?? field.default ?? field.min ?? 0)
    let next = current + delta
    if (field.min !== undefined) next = Math.max(next, field.min)
    if (field.max !== undefined) next = Math.min(next, field.max)
    configuration.value[key] = next
}

function toggleField(key: string) {
    configuration.value[key] = !configuration.value[key]
}

async function createRoom() {
    if (!selectedGame.value) return

    creatingRoom.value = true
    error.value = null

    router.post(
        route('rooms.store'),
        {
            game_id: selectedGame.value.id,
            max_players: maxPlayers.value,
            configuration: configuration.value,
        },
        {
            onError: errors => {
                error.value = Object.values(errors)[0] ?? 'Unable to create the room.'
            },
            onFinish: () => {
                creatingRoom.value = false
            },
        },
    )
}
</script>

<template>
    <div class="gc-page">
        <div class="gc-container">
            <header class="gc-header">
                <p class="gc-eyebrow">Games Center</p>
                <h1 class="gc-title">Select a module</h1>
                <p class="gc-subtitle">Choose a game, configure the room, and share the code.</p>
            </header>

            <div class="gc-console" :class="{ 'gc-console--active': selectedGame }">
                <!-- Module rail -->
                <div class="gc-rail">
                    <button
                        v-for="game in props.games"
                        :key="game.id"
                        type="button"
                        class="gc-module"
                        :class="{ 'gc-module--selected': selectedGame?.id === game.id }"
                        @click="selectGame(game)"
                    >
                        <span class="gc-module-cursor" aria-hidden="true">&gt;</span>

                        <span class="gc-module-body">
                            <span class="gc-module-name">{{ game.name }}</span>

                            <span v-if="game.description" class="gc-module-desc">
                                {{ game.description }}
                            </span>

                            <span class="gc-mono gc-range">
                                {{ game.minimum_players }}–{{ game.maximum_players }} players
                            </span>
                        </span>
                    </button>

                    <p v-if="props.games.length === 0" class="gc-empty">
                        No games are available right now.
                    </p>
                </div>

                <!-- Configuration panel -->
                <div v-if="selectedGame" class="gc-panel">
                    <div class="gc-panel-header">
                        <h2 class="gc-panel-title">{{ selectedGame.name }}</h2>
                        <span class="gc-tag">
                            {{ selectedGame.host_is_player ? 'Host plays' : 'Host manages only' }}
                        </span>
                    </div>

                    <div class="gc-field">
                        <label class="gc-field-label">Maximum players</label>
                        <div class="gc-stepper">
                            <button
                                type="button"
                                class="gc-stepper-btn"
                                :disabled="maxPlayers <= selectedGame.minimum_players"
                                @click="stepMaxPlayers(-1)"
                            >
                                −
                            </button>
                            <span class="gc-mono gc-stepper-value">{{ maxPlayers }}</span>
                            <button
                                type="button"
                                class="gc-stepper-btn"
                                :disabled="maxPlayers >= selectedGame.maximum_players"
                                @click="stepMaxPlayers(1)"
                            >
                                +
                            </button>
                        </div>
                    </div>

                    <div
                        v-for="(field, key) in selectedGame.configuration_schema"
                        :key="key"
                        class="gc-field"
                    >
                        <label class="gc-field-label">{{ fieldLabel(key) }}</label>

                        <div v-if="field.type === 'integer'" class="gc-stepper">
                            <button
                                type="button"
                                class="gc-stepper-btn"
                                :disabled="field.min !== undefined && Number(configuration[key]) <= field.min"
                                @click="stepField(key, field, -1)"
                            >
                                −
                            </button>
                            <span class="gc-mono gc-stepper-value">{{ configuration[key] }}</span>
                            <button
                                type="button"
                                class="gc-stepper-btn"
                                :disabled="field.max !== undefined && Number(configuration[key]) >= field.max"
                                @click="stepField(key, field, 1)"
                            >
                                +
                            </button>
                        </div>

                        <button
                            v-else-if="field.type === 'boolean'"
                            type="button"
                            role="switch"
                            :aria-checked="!!configuration[key]"
                            class="gc-toggle"
                            :class="{ 'gc-toggle--on': configuration[key] }"
                            @click="toggleField(key)"
                        >
                            <span class="gc-toggle-thumb" />
                        </button>
                    </div>

                    <p v-if="error" class="gc-error">{{ error }}</p>

                    <button
                        type="button"
                        class="gc-create-btn"
                        :disabled="creatingRoom"
                        @click="createRoom"
                    >
                        {{ creatingRoom ? 'Creating Room…' : 'Create Room' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');

.gc-page {
    --gc-ink: #0f1613;
    --gc-surface: #16201c;
    --gc-surface-raised: #1e2b25;
    --gc-border: #2a3a33;
    --gc-amber: #e8a33d;
    --gc-phosphor: #6fcf97;
    --gc-mist: #9fb0a8;
    --gc-paper: #eef2ef;

    min-height: 100vh;
    background: var(--gc-ink);
    color: var(--gc-paper);
    font-family: 'Inter', sans-serif;
    padding: 2rem 1.5rem 4rem;
}

.gc-mono {
    font-family: 'JetBrains Mono', monospace;
}

.gc-container {
    max-width: 56rem;
    margin: 0 auto;
}

/* Header */
.gc-eyebrow {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.75rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--gc-phosphor);
}

.gc-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 2rem;
    font-weight: 700;
    margin-top: 0.5rem;
}

.gc-subtitle {
    margin-top: 0.4rem;
    color: var(--gc-mist);
    font-size: 0.95rem;
}

/* Console layout */
.gc-console {
    margin-top: 2.5rem;
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 768px) {
    .gc-console--active {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        align-items: start;
    }
}

/* Module rail */
.gc-rail {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.gc-module {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    text-align: left;
    background: var(--gc-surface);
    border: 1px solid var(--gc-border);
    border-radius: 10px;
    padding: 1rem 1.1rem;
    cursor: pointer;
    transition: border-color 0.15s ease, transform 0.15s ease, background 0.15s ease;
}

.gc-module:hover {
    background: var(--gc-surface-raised);
    transform: translateX(2px);
}

.gc-module--selected {
    border-color: var(--gc-amber);
    background: var(--gc-surface-raised);
}

.gc-module-cursor {
    font-family: 'JetBrains Mono', monospace;
    color: var(--gc-amber);
    opacity: 0;
    line-height: 1.5rem;
}

.gc-module--selected .gc-module-cursor {
    opacity: 1;
}

.gc-module-body {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.gc-module-name {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 1.05rem;
}

.gc-module-desc {
    font-size: 0.85rem;
    color: var(--gc-mist);
}

.gc-range {
    display: block;
    margin-top: 0.35rem;
    font-size: 0.75rem;
    color: var(--gc-mist);
}

.gc-empty {
    color: var(--gc-mist);
    font-size: 0.9rem;
    padding: 1rem 0;
}

/* Configuration panel */
.gc-panel {
    background: var(--gc-surface);
    border: 1px solid var(--gc-border);
    border-radius: 10px;
    padding: 1.5rem;
}

.gc-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--gc-border);
}

.gc-panel-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.2rem;
    font-weight: 700;
}

.gc-tag {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.7rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--gc-amber);
    border: 1px solid var(--gc-amber);
    border-radius: 999px;
    padding: 0.2rem 0.6rem;
    white-space: nowrap;
}

.gc-field {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.9rem 0;
    border-bottom: 1px solid var(--gc-border);
}

.gc-field:last-of-type {
    border-bottom: none;
}

.gc-field-label {
    text-transform: capitalize;
    font-size: 0.9rem;
    color: var(--gc-paper);
}

/* Stepper */
.gc-stepper {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.gc-stepper-btn {
    width: 1.8rem;
    height: 1.8rem;
    border-radius: 6px;
    border: 1px solid var(--gc-border);
    background: var(--gc-surface-raised);
    color: var(--gc-paper);
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.gc-stepper-btn:hover:not(:disabled) {
    border-color: var(--gc-amber);
}

.gc-stepper-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.gc-stepper-value {
    min-width: 1.6rem;
    text-align: center;
    font-size: 0.95rem;
}

/* Toggle */
.gc-toggle {
    width: 2.6rem;
    height: 1.5rem;
    border-radius: 999px;
    border: 1px solid var(--gc-border);
    background: var(--gc-surface-raised);
    position: relative;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
}

.gc-toggle--on {
    background: var(--gc-phosphor);
    border-color: var(--gc-phosphor);
}

.gc-toggle-thumb {
    position: absolute;
    top: 2px;
    left: 2px;
    width: 1.1rem;
    height: 1.1rem;
    border-radius: 50%;
    background: var(--gc-paper);
    transition: transform 0.15s ease;
}

.gc-toggle--on .gc-toggle-thumb {
    transform: translateX(1.1rem);
}

/* Create button */
.gc-create-btn {
    margin-top: 1.5rem;
    width: 100%;
    padding: 0.75rem;
    border-radius: 8px;
    border: none;
    background: var(--gc-amber);
    color: var(--gc-ink);
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: opacity 0.15s ease;
}

.gc-create-btn:hover:not(:disabled) {
    opacity: 0.9;
}

.gc-create-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.gc-error {
    margin-top: 1rem;
    font-size: 0.85rem;
    color: #e0685f;
}

/* Focus visibility */
.gc-module:focus-visible,
.gc-stepper-btn:focus-visible,
.gc-toggle:focus-visible,
.gc-create-btn:focus-visible {
    outline: 2px solid var(--gc-amber);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
    .gc-module,
    .gc-stepper-btn,
    .gc-toggle,
    .gc-toggle-thumb,
    .gc-create-btn {
        transition: none;
    }
}
</style>