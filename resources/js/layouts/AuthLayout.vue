<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

defineProps<{
    title?: string
    description?: string
}>()
</script>

<template>
    <div class="au-page">
        <div class="au-card">
            <Link :href="route('home')" class="au-brand">
                Games Center
            </Link>

            <div class="au-heading">
                <h1 v-if="title" class="au-title">{{ title }}</h1>
                <p v-if="description" class="au-description">{{ description }}</p>
            </div>

            <slot />
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');

.au-page {
    --au-ink: #0f1613;
    --au-surface: #16201c;
    --au-surface-raised: #1e2b25;
    --au-border: #2a3a33;
    --au-amber: #e8a33d;
    --au-phosphor: #6fcf97;
    --au-mist: #9fb0a8;
    --au-paper: #eef2ef;
    --au-danger: #e0685f;

    min-height: 100vh;
    background: var(--au-ink);
    color: var(--au-paper);
    font-family: 'Inter', sans-serif;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
}

.au-card {
    width: 100%;
    max-width: 24rem;
    background: var(--au-surface);
    border: 1px solid var(--au-border);
    border-radius: 12px;
    padding: 2rem 1.75rem;
}

.au-brand {
    display: block;
    text-align: center;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 700;
    font-size: 1rem;
    color: var(--au-paper);
    text-decoration: none;
    letter-spacing: 0.01em;
    margin-bottom: 1.75rem;
}

.au-heading {
    text-align: center;
    margin-bottom: 1.75rem;
}

.au-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.3rem;
    font-weight: 700;
}

.au-description {
    margin-top: 0.4rem;
    font-size: 0.85rem;
    color: var(--au-mist);
}
</style>