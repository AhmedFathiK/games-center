<script setup lang="ts">
import { ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useInitials } from '@/composables/useInitials'

const page = usePage()
const { getInitials } = useInitials()
const isUserMenuOpen = ref(false)

const userInitials = getInitials(page.props.auth.user.name)
</script>

<template>
    <div class="app-shell">
        <header class="app-header">
            <div class="app-header-container">
                <Link :href="route('home')" class="app-brand">
                    Games Center
                </Link>

                <nav class="app-nav">
                    <Link
                        :href="route('games.index')"
                        class="app-nav-link"
                        :class="{ 'app-nav-link--active': route().current('games.*') || route().current('home') }"
                    >
                        Games
                    </Link>
                    <Link
                        :href="route('rooms.mine')"
                        class="app-nav-link"
                        :class="{ 'app-nav-link--active': route().current('rooms.mine') }"
                    >
                        My Rooms
                    </Link>
                </nav>

                <div class="app-user-menu">
                    <button
                        type="button"
                        class="app-user-btn"
                        :aria-expanded="isUserMenuOpen"
                        aria-haspopup="true"
                        @click="isUserMenuOpen = !isUserMenuOpen"
                    >
                        <span class="app-avatar">{{ userInitials }}</span>
                        <span class="app-username">{{ page.props.auth.user.name }}</span>
                        <span class="app-chevron" :class="{ 'app-chevron--open': isUserMenuOpen }" aria-hidden="true" />
                    </button>

                    <div v-if="isUserMenuOpen" class="app-dropdown">
                        <Link :href="route('profile.edit')" class="app-dropdown-item" @click="isUserMenuOpen = false">
                            Settings
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="app-dropdown-item app-dropdown-item--danger"
                            @click="isUserMenuOpen = false"
                        >
                            Log out
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <main class="app-content">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.app-shell {
    min-height: 100vh;
    background: var(--gc-background, #0f1613);
    color: var(--gc-paper, #eef2ef);
}

.app-header {
    border-bottom: 1px solid var(--gc-border, #2a3a33);
    background: var(--gc-surface, #16201c);
}

.app-header-container {
    max-width: 72rem;
    margin: 0 auto;
    padding: 0 1.5rem;
    height: 3.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.app-brand {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--gc-paper, #eef2ef);
    text-decoration: none;
}

.app-nav {
    display: flex;
    gap: 1.5rem;
}

.app-nav-link {
    color: var(--gc-mist, #9fb0a8);
    text-decoration: none;
    font-size: 0.9rem;
    font-weight: 500;
    transition: color 0.15s ease;
}

.app-nav-link:hover,
.app-nav-link--active {
    color: var(--gc-paper, #eef2ef);
}

.app-user-menu {
    position: relative;
}

.app-user-btn {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: transparent;
    border: none;
    cursor: pointer;
    color: var(--gc-paper, #eef2ef);
    padding: 0.25rem 0.5rem;
    border-radius: 6px;
}

.app-user-btn:hover {
    background: var(--gc-surface-raised, #1e2b25);
}

.app-avatar {
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    background: var(--gc-amber, #e8a33d);
    color: var(--gc-ink, #0f1613);
    font-size: 0.75rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}

.app-username {
    font-size: 0.875rem;
    font-weight: 500;
}

.app-chevron {
    display: inline-block;
    width: 0.4rem;
    height: 0.4rem;
    border-right: 2px solid var(--gc-mist, #9fb0a8);
    border-bottom: 2px solid var(--gc-mist, #9fb0a8);
    transform: rotate(45deg);
    transition: transform 0.2s ease;
    margin-left: 0.2rem;
}

.app-chevron--open {
    transform: rotate(-135deg);
}

.app-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 0.5rem);
    width: 10rem;
    background: var(--gc-surface-raised, #1e2b25);
    border: 1px solid var(--gc-border, #2a3a33);
    border-radius: 8px;
    padding: 0.35rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
    z-index: 50;
}

.app-dropdown-item {
    display: block;
    width: 100%;
    text-align: left;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    color: var(--gc-paper, #eef2ef);
    text-decoration: none;
    background: transparent;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.app-dropdown-item:hover {
    background: var(--gc-surface, #16201c);
}

.app-dropdown-item--danger {
    color: var(--gc-danger, #e0685f);
}
</style>
