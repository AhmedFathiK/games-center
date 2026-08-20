<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'

interface Game {
    id: number
    name: string
    slug: string
    minimum_players: number
}

interface User {
    id: number
    name: string
}

interface Room {
    id: number
    code: string
    max_players: number
    status: string
    configuration: Record<string, number | boolean>
    game: Game
    host: User
    players: User[]
}

interface AuthUser {
    id: number
    name: string
}

const props = defineProps<{
    room: Room
    auth: {
        user: AuthUser
    }
}>()

const joiningRoom = ref(false)
const joinError = ref<string | null>(null)

const startingGame = ref(false)
const startError = ref<string | null>(null)

const isHost = computed(() => {
    return props.room.host.id === props.auth.user.id
})

const isPlayer = computed(() => {
    return props.room.players.some(
        player => player.id === props.auth.user.id,
    )
})

const playersNeeded = computed(() => {
    return Math.max(
        0,
        props.room.game.minimum_players - props.room.players.length,
    )
})

const canStart = computed(() => {
    return (
        props.room.status === 'waiting' &&
        isHost.value &&
        playersNeeded.value === 0
    )
})

function joinRoom() {
    joiningRoom.value = true
    joinError.value = null

    router.post(
        `/rooms/${props.room.id}/join`,
        {},
        {
            onError: (errors) => {
                joinError.value =
                    Object.values(errors)[0] ??
                    'Unable to join the room.'
            },
            onFinish: () => {
                joiningRoom.value = false
            },
        },
    )
}

function startGame() {
    if (!canStart.value) {
        return
    }

    startingGame.value = true
    startError.value = null

    router.post(
        `/rooms/${props.room.id}/start`,
        {},
        {
            onError: (errors) => {
                startError.value =
                    Object.values(errors)[0] ??
                    'Unable to start the game.'
            },
            onFinish: () => {
                startingGame.value = false
            },
        },
    )
}

onMounted(() => {
    window.Echo
        .private(`rooms.${props.room.id}`)
        .listen('.player.joined', () => {
            router.reload({
                only: ['room'],
            })
        })
        .listen('.game.started', () => {
            router.reload({
                only: ['room'],
            })
        })
})

onUnmounted(() => {
    window.Echo.leave(`rooms.${props.room.id}`)
})

</script>

<template>
    <div class="p-6">
        <div class="mx-auto max-w-2xl">
            <!-- Room Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold">
                        {{ room.game.name }}
                    </h1>

                    <p class="mt-1 text-sm text-muted-foreground">
                        Room code:
                        <span class="font-mono font-semibold">
                            {{ room.code }}
                        </span>
                    </p>
                </div>

                <span
                    class="rounded-full border px-3 py-1 text-sm capitalize"
                >
                    {{ room.status }}
                </span>
            </div>

            <!-- Host -->
            <div class="mt-8 rounded-lg border p-6">
                <h2 class="text-xl font-semibold">
                    Host
                </h2>

                <div class="mt-4 rounded-md border px-4 py-3">
                    <span class="font-medium">
                        {{ room.host.name }}
                    </span>

                    <span
                        v-if="isHost"
                        class="ml-2 text-sm text-muted-foreground"
                    >
                        Host · You
                    </span>

                    <span
                        v-else
                        class="ml-2 text-sm text-muted-foreground"
                    >
                        Host
                    </span>
                </div>

                <!-- Start Game -->
                <div
                    v-if="isHost && room.status === 'waiting'"
                    class="mt-6 border-t pt-6"
                >
                    <button
                        type="button"
                        class="rounded-md border px-4 py-2 font-medium transition hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="startingGame || !canStart"
                        @click="startGame"
                    >
                        <template v-if="startingGame">
                            Starting...
                        </template>

                        <template v-else-if="playersNeeded > 0">
                            Need
                            {{ playersNeeded }}
                            {{ playersNeeded === 1 ? 'more player' : 'more players' }}
                        </template>

                        <template v-else>
                            Start Game {{ playersNeeded }}
                        </template>
                    </button>

                    <p
                        v-if="startError"
                        class="mt-3 text-sm text-red-600"
                    >
                        {{ startError }}
                    </p>
                </div>
            </div>

            <!-- Players -->
            <div class="mt-6 rounded-lg border p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-semibold">
                        Players
                    </h2>

                    <span class="text-sm text-muted-foreground">
                        {{ room.players.length }} / {{ room.max_players }}
                    </span>
                </div>

                <div class="mt-4 space-y-2">
                    <div
                        v-for="player in room.players"
                        :key="player.id"
                        class="rounded-md border px-4 py-3"
                    >
                        {{ player.name }}
                    </div>

                    <p
                        v-if="room.players.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No players have joined yet.
                    </p>
                </div>

                <!-- Join / Host Status -->
                <div class="mt-6">
                    <button
                        v-if="!isHost && !isPlayer"
                        type="button"
                        class="rounded-md border px-4 py-2 font-medium transition hover:bg-muted disabled:opacity-50"
                        :disabled="joiningRoom"
                        @click="joinRoom"
                    >
                        {{ joiningRoom ? 'Joining...' : 'Join Room' }}
                    </button>

                    <p
                        v-else-if="isHost"
                        class="text-sm text-muted-foreground"
                    >
                        You are the host of this room.
                    </p>

                    <p
                        v-else
                        class="text-sm text-muted-foreground"
                    >
                        You are in this room.
                    </p>

                    <p
                        v-if="joinError"
                        class="mt-3 text-sm text-red-600"
                    >
                        {{ joinError }}
                    </p>
                </div>
            </div>

            <!-- Game Settings -->
            <div class="mt-6 rounded-lg border p-6">
                <h2 class="text-xl font-semibold">
                    Game Settings
                </h2>

                <div class="mt-4">
                    <div
                        v-for="(value, key) in room.configuration"
                        :key="key"
                        class="flex justify-between border-b py-3 last:border-0"
                    >
                        <span class="capitalize">
                            {{ key.replace(/_/g, ' ') }}
                        </span>

                        <span class="font-medium">
                            {{
                                typeof value === 'boolean'
                                    ? value
                                        ? 'Enabled'
                                        : 'Disabled'
                                    : value
                            }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>