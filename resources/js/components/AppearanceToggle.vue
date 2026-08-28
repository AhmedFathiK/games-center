<script setup lang="ts">
import { useAppearance } from '@/composables/useAppearance'

const { appearance, updateAppearance } = useAppearance()

const tabs = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'system', label: 'System' },
] as const
</script>

<template>
    <div class="at-toggle">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            class="at-tab"
            :class="{ 'at-tab--active': appearance === tab.value }"
            @click="updateAppearance(tab.value)"
        >
            {{ tab.label }}
        </button>
    </div>
</template>

<style scoped>
.at-toggle {
    display: inline-flex;
    gap: 0.25rem;
    background: var(--st-surface-raised, #1e2b25);
    border: 1px solid var(--st-border, #2a3a33);
    border-radius: 8px;
    padding: 0.25rem;
}

.at-tab {
    background: transparent;
    border: none;
    color: var(--st-mist, #9fb0a8);
    font-size: 0.85rem;
    padding: 0.4rem 0.9rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}

.at-tab:hover {
    color: var(--st-paper, #eef2ef);
}

.at-tab--active {
    background: var(--st-amber, #e8a33d);
    color: var(--st-ink, #0f1613);
    font-weight: 600;
}
</style>