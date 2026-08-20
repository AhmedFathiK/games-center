<script setup lang="ts">
import { ref } from 'vue'
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
        }
    }
}

async function createRoom() {
    if (!selectedGame.value) {
        return
    }

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
            onError: (errors) => {
                error.value =
                    errors.max_players ??
                    errors.game_id ??
                    'Unable to create the room.'
            },

            onFinish: () => {
                creatingRoom.value = false
            },
        },
    )
}
</script>

<template>
    <div class="p-6">
        <h1 class="text-2xl font-bold">Games</h1>

        <div class="mt-6 space-y-4">
            <button
                v-for="game in props.games"
                :key="game.id"
                type="button"
                class="block w-full rounded-lg border p-4 text-left transition hover:bg-muted"
                @click="selectGame(game)"
            >
                <h2 class="text-xl font-semibold">
                    {{ game.name }}
                </h2>

                <p class="mt-1 text-sm text-muted-foreground">
                    {{ game.minimum_players }}
                    -
                    {{ game.maximum_players }}
                    players
                </p>
            </button>
        </div>

        <div
            v-if="selectedGame"
            class="mt-8 rounded-lg border p-6"
        >
            <h2 class="text-xl font-semibold">
                {{ selectedGame.name }} — Game Settings
            </h2>

            <div class="mt-6 space-y-4">
                <div>
                    <label class="block font-medium">
                        Maximum players
                    </label>

                    <input
                        v-model.number="maxPlayers"
                        type="number"
                        :min="selectedGame.minimum_players"
                        :max="selectedGame.maximum_players"
                        class="mt-1 w-full rounded-md border px-3 py-2"
                    />
                </div>

                <div
                    v-for="(field, key) in selectedGame.configuration_schema"
                    :key="key"
                >
                    <label class="block font-medium capitalize">
                        {{ key.replace('_', ' ') }}
                    </label>

                    <input
                        v-if="field.type === 'integer'"
                        v-model.number="configuration[key]"
                        type="number"
                        :min="field.min"
                        :max="field.max"
                        class="mt-1 w-full rounded-md border px-3 py-2"
                    />

                    <label
                        v-else-if="field.type === 'boolean'"
                        class="mt-2 flex items-center gap-2"
                    >
                        <input
                            v-model="configuration[key]"
                            type="checkbox"
                        />

                        <span>Enabled</span>
                    </label>
                </div>
            </div>

            <p
                v-if="error"
                class="mt-4 text-sm text-red-600"
            >
                {{ error }}
            </p>

            <button
                type="button"
                class="mt-6 rounded-md border px-4 py-2 font-medium transition hover:bg-muted disabled:opacity-50"
                :disabled="creatingRoom"
                @click="createRoom"
            >
                {{ creatingRoom ? 'Creating Room...' : 'Create Room' }}
            </button>
        </div>
    </div>
</template>