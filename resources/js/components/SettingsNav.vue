<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

const tabs = [
    { label: 'Profile', routeName: 'profile.edit' },
    { label: 'Password', routeName: 'password.edit' },
    { label: 'Appearance', routeName: 'appearance' },
] as const
</script>

<template>
    <nav class="sn-nav">
        <Link
            v-for="tab in tabs"
            :key="tab.routeName"
            :href="route(tab.routeName)"
            class="sn-tab"
            :class="{ 'sn-tab--active': route().current(tab.routeName) }"
        >
            {{ tab.label }}
        </Link>
    </nav>
</template>

<style scoped>
.sn-nav {
    display: flex;
    gap: 0.4rem;
    border-bottom: 1px solid var(--st-border, #2a3a33);
    margin-bottom: 1.75rem;
}

.sn-tab {
    padding: 0.6rem 0.9rem;
    font-size: 0.85rem;
    color: var(--st-mist, #9fb0a8);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: color 0.15s ease, border-color 0.15s ease;
}

.sn-tab:hover {
    color: var(--st-paper, #eef2ef);
}

.sn-tab--active {
    color: var(--st-amber, #e8a33d);
    border-color: var(--st-amber, #e8a33d);
}
</style>