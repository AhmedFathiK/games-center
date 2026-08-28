<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsNav from '@/components/SettingsNav.vue'

defineOptions({ layout: AppLayout })

const passwordInput = ref<HTMLInputElement | null>(null)
const currentPasswordInput = ref<HTMLInputElement | null>(null)
const showSaved = ref(false)

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            showSaved.value = true
            setTimeout(() => (showSaved.value = false), 2000)
        },
        onError: (errors: Record<string, string>) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation')
                passwordInput.value?.focus()
            }

            if (errors.current_password) {
                form.reset('current_password')
                currentPasswordInput.value?.focus()
            }
        },
    })
}
</script>

<template>
    <div class="st-page">
        <Head title="Password settings" />

        <div class="st-container">
            <header class="st-header">
                <h1 class="st-title">Settings</h1>
                <p class="st-subtitle">Manage your profile and account settings.</p>
            </header>

            <SettingsNav />

            <section class="st-section">
                <h2 class="st-section-title">Update password</h2>
                <p class="st-section-desc">Ensure your account is using a long, random password to stay secure</p>

                <form @submit.prevent="updatePassword" class="st-form">
                    <div class="st-field">
                        <label for="current_password" class="st-label">Current password</label>
                        <input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            autocomplete="current-password"
                            class="st-input"
                            placeholder="Current password"
                        />
                        <p v-if="form.errors.current_password" class="st-error">{{ form.errors.current_password }}</p>
                    </div>

                    <div class="st-field">
                        <label for="password" class="st-label">New password</label>
                        <input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            class="st-input"
                            placeholder="New password"
                        />
                        <p v-if="form.errors.password" class="st-error">{{ form.errors.password }}</p>
                    </div>

                    <div class="st-field">
                        <label for="password_confirmation" class="st-label">Confirm password</label>
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            class="st-input"
                            placeholder="Confirm password"
                        />
                        <p v-if="form.errors.password_confirmation" class="st-error">{{ form.errors.password_confirmation }}</p>
                    </div>

                    <div class="st-save-row">
                        <button type="submit" class="st-save-btn" :disabled="form.processing">Save password</button>
                        <span v-if="showSaved" class="st-saved-text">Saved.</span>
                    </div>
                </form>
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

.st-form {
    margin-top: 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.st-field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.st-label {
    font-size: 0.85rem;
    color: var(--st-paper);
}

.st-input {
    background: var(--st-surface-raised);
    border: 1px solid var(--st-border);
    border-radius: 8px;
    padding: 0.6rem 0.8rem;
    color: var(--st-paper);
    font-size: 0.9rem;
    font-family: 'Inter', sans-serif;
}

.st-input:focus {
    outline: none;
    border-color: var(--st-amber);
}

.st-error {
    font-size: 0.78rem;
    color: var(--st-danger);
}

.st-save-row {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.st-save-btn {
    background: var(--st-amber);
    color: var(--st-ink);
    border: none;
    border-radius: 8px;
    padding: 0.6rem 1.3rem;
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
}

.st-save-btn:hover:not(:disabled) {
    opacity: 0.9;
}

.st-save-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.st-saved-text {
    font-size: 0.85rem;
    color: var(--st-mist);
}
</style>