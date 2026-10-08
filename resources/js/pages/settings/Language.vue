<script setup lang="ts">
import SettingsNav from '@/components/SettingsNav.vue';
import { useI18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

defineOptions({ layout: AppLayout });

const page = usePage<SharedData>();
const { t } = useI18n();
const form = useForm({ locale: page.props.auth.user.locale ?? page.props.locale ?? 'en' });
const showSaved = ref(false);

function saveLanguage() {
    showSaved.value = false;
    form.post(route('locale.update'), {
        preserveScroll: true,
        onSuccess: () => {
            showSaved.value = true;
            setTimeout(() => (showSaved.value = false), 2500);
        },
    });
}
</script>

<template>
    <div class="st-page">
        <Head :title="t('Language settings')" />

        <div class="st-container">
            <header class="st-header">
                <h1 class="st-title">{{ t('Settings') }}</h1>
                <p class="st-subtitle">{{ t('Manage your profile and account settings.') }}</p>
            </header>

            <SettingsNav />

            <section class="st-section">
                <h2 class="st-section-title">{{ t('Language preference') }}</h2>
                <p class="st-section-desc">{{ t('Choose the language used throughout Games Center. Your selection is applied after saving.') }}</p>

                <form class="st-form" @submit.prevent="saveLanguage">
                    <div class="st-field">
                        <label for="locale" class="st-label">{{ t('Language') }}</label>
                        <select id="locale" v-model="form.locale" class="st-input st-select">
                            <option value="en">{{ t('English') }}</option>
                            <option value="ar">{{ t('Arabic') }}</option>
                        </select>
                        <p v-if="form.errors.locale" role="alert" class="st-error">{{ t(form.errors.locale) }}</p>
                    </div>

                    <div class="st-save-row">
                        <button type="submit" class="st-save-btn" :disabled="form.processing">
                            {{ form.processing ? t('Saving…') : t('Save') }}
                        </button>
                        <span v-if="showSaved" role="status" class="st-saved-text">{{ t('Saved.') }}</span>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>

<style scoped>
.st-page {
    --st-ink: #0f1613;
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

.st-subtitle,
.st-section-desc {
    margin-top: 0.3rem;
    color: var(--st-mist);
    font-size: 0.9rem;
}

.st-section-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
}

.st-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
    margin-top: 1.25rem;
}

.st-field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.st-label {
    color: var(--st-paper);
    font-size: 0.85rem;
}

.st-input {
    border: 1px solid var(--st-border);
    border-radius: 8px;
    padding: 0.6rem 0.8rem;
    background: var(--st-surface-raised);
    color: var(--st-paper);
    font-size: 0.9rem;
    font-family: 'Inter', sans-serif;
}

.st-input:focus {
    outline: none;
    border-color: var(--st-amber);
}

.st-save-row {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.st-save-btn {
    border: none;
    border-radius: 8px;
    padding: 0.6rem 1.3rem;
    background: var(--st-amber);
    color: var(--st-ink);
    font-family: 'Space Grotesk', sans-serif;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
}

.st-save-btn:disabled {
    opacity: 0.6;
    cursor: wait;
}

.st-saved-text {
    color: var(--st-phosphor);
    font-size: 0.85rem;
}

.st-error {
    color: var(--st-danger);
    font-size: 0.78rem;
}
</style>
