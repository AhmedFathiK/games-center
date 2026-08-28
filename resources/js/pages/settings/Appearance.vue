<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsNav from '@/components/SettingsNav.vue'
import AppearanceToggle from '@/components/AppearanceToggle.vue'

defineOptions({ layout: AppLayout })
</script>

<template>
    <div class="st-page">
        <Head title="Appearance settings" />

        <div class="st-container">
            <header class="st-header">
                <h1 class="st-title">Settings</h1>
                <p class="st-subtitle">Manage your profile and account settings.</p>
            </header>

            <SettingsNav />

            <section class="st-section">
                <h2 class="st-section-title">Appearance settings</h2>
                <p class="st-section-desc">Update your account's appearance settings</p>

                <div class="st-appearance-block">
                    <AppearanceToggle />
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap');

.st-page {
    --st-ink: #0f1613;
    --st-surface: #16201c;
    --st-surface-raised: #1e2b25;
    --st-border: #2a3a33;
    --st-amber: #e8a33d;
    --st-phosphor: #6fcf97;
    --st-mist: #9fb0a8;
    --st-paper: #eef2ef;
    --st-danger: #e0685f;

    min-height: calc(100vh - 3.5rem);
    background: var(--st-ink);
    color: var(--st-paper);
    font-family: 'Inter', sans-serif;
    padding: 2rem 1.5rem 4rem;
}

.st-container {
    max-width: 36rem;
    margin: 0 auto;
}

.st-header {
    margin-bottom: 1.5rem;
}

.st-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.6rem;
    font-weight: 700;
}

.st-subtitle {
    margin-top: 0.3rem;
    color: var(--st-mist);
    font-size: 0.9rem;
}

.st-section-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
}

.st-section-desc {
    margin-top: 0.25rem;
    font-size: 0.85rem;
    color: var(--st-mist);
}

.st-appearance-block {
    margin-top: 1.25rem;
}
</style>