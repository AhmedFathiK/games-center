<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useInitials } from '@/composables/useInitials'
import { type SharedData } from '@/types'

const page = usePage<SharedData>()
const { getInitials } = useInitials()

const menuOpen = ref(false)

function toggleMenu() {
    menuOpen.value = !menuOpen.value
}

function closeMenu() {
    menuOpen.value = false
}

function handleClickOutside(event: MouseEvent) {
    const target = event.target as HTMLElement
    if (!target.closest('.al-user')) {
        closeMenu()
    }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))
</script>

<template>
    <div class="al-shell">
        <nav class="al-nav">
            <div class="al-nav-inner">
                <Link :href="route('home')" class="al-brand">
                    Games Center
                </Link>

                <div class="al-links">
                    <Link
                        :href="route('games.index')"
                        class="al-link"
                        :class="{ 'al-link--active': route().current('games.index') || route().current('home') }"
                    >
                        Games
                    </Link>
                    <Link
                        :href="route('rooms.mine')"
                        class="al-link"
                        :class="{ 'al-link--active': route().current('rooms.mine') }"
                    >
                        My Rooms
                    </Link>
                </div>

                <div class="al-user">
                    <button type="button" class="al-user-btn" @click="toggleMenu">
                        <span class="al-avatar">{{ getInitials(page.props.auth.user.name) }}</span>
                        <span class="al-user-name">{{ page.props.auth.user.name }}</span>
                    </button>

                    <div v-if="menuOpen" class="al-menu">
                        <Link :href="route('profile.edit')" class="al-menu-item" @click="closeMenu">
                            Settings
                        </Link>

                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="al-menu-item al-menu-item--btn"
                        >
                            Log out
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <main class="al-main">
            <slot />
        </main>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');

.al-shell {
    --al-ink: #0f1613;
    --al-surface: #16201c;
    --al-surface-raised: #1e2b25;
    --al-border: #2a3a33;
    --al-amber: #e8a33d;
    --al-phosphor: #6fcf97;
    --al-mist: #9fb0a8;
    --al-paper: #eef2ef;

    min-height: 100vh;
}

.al-nav {
    background: var(--al-surface);
    border-bottom: 1px solid var(--al-border);
    position: sticky;
    top: 0;
    z-index: 40;
}

.al-nav-inner {
    max-width: 64rem;
    margin: 0 auto;
    padding: 0 1.5rem;
    height: 3.5rem;
    display: flex;
    align-items: center;
    gap: 2rem;
}

.al-brand {
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 1rem;
    color: var(--al-paper);
    text-decoration: none;
    letter-spacing: 0.01em;
    white-space: nowrap;
}

.al-links {
    display: flex;
    gap: 1.5rem;
    flex: 1;
}

.al-link {
    font-family: 'JetBrains Mono', monospace;
    font-size: 0.8rem;
    letter-spacing: 0.04em;
    color: var(--al-mist);
    text-decoration: none;
    padding: 0.35rem 0;
    border-bottom: 2px solid transparent;
    transition: color 0.15s ease, border-color 0.15s ease;
}

.al-link:hover {
    color: var(--al-paper);
}

.al-link--active {
    color: var(--al-amber);
    border-color: var(--al-amber);
}

.al-user {
    position: relative;
}

.al-user-btn {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0.3rem 0.4rem;
    border-radius: 8px;
    transition: background 0.15s ease;
}

.al-user-btn:hover {
    background: var(--al-surface-raised);
}

.al-avatar {
    width: 1.9rem;
    height: 1.9rem;
    border-radius: 50%;
    background: var(--al-phosphor);
    color: var(--al-ink);
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.al-user-name {
    font-size: 0.85rem;
    color: var(--al-paper);
    white-space: nowrap;
}

.al-menu {
    position: absolute;
    right: 0;
    top: calc(100% + 0.5rem);
    background: var(--al-surface-raised);
    border: 1px solid var(--al-border);
    border-radius: 8px;
    min-width: 10rem;
    padding: 0.4rem;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
}

.al-menu-item {
    display: block;
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    color: var(--al-paper);
    font-size: 0.85rem;
    padding: 0.5rem 0.6rem;
    border-radius: 6px;
    cursor: pointer;
    text-decoration: none;
    font-family: 'Inter', sans-serif;
}

.al-menu-item:hover {
    background: var(--al-surface);
}

.al-menu-item--btn {
    color: #e0685f;
}

.al-main {
    min-height: calc(100vh - 3.5rem);
}

@media (max-width: 640px) {
    .al-user-name {
        display: none;
    }

    .al-links {
        gap: 1rem;
    }
}
</style>