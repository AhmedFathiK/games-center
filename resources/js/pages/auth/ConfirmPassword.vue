<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/layouts/AuthLayout.vue'

const form = useForm({
    password: '',
})

const submit = () => {
    form.post(route('password.confirm'), {
        onFinish: () => {
            form.reset()
        },
    })
}
</script>

<template>
    <AuthLayout
        title="Confirm your password"
        description="This is a secure area of the application. Please confirm your password before continuing."
    >
        <Head title="Confirm password" />

        <form @submit.prevent="submit" class="au-form">
            <div class="au-field">
                <label for="password" class="au-label">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    autocomplete="current-password"
                    autofocus
                    class="au-input"
                />
                <p v-if="form.errors.password" class="au-error">{{ form.errors.password }}</p>
            </div>

            <button type="submit" class="au-submit-btn" :disabled="form.processing">
                <span v-if="form.processing" class="au-spinner" />
                Confirm password
            </button>
        </form>
    </AuthLayout>
</template>

<style scoped>
.au-form {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.au-field {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
}

.au-label {
    font-size: 0.85rem;
    color: var(--au-paper, #eef2ef);
}

.au-input {
    background: var(--au-surface-raised, #1e2b25);
    border: 1px solid var(--au-border, #2a3a33);
    border-radius: 8px;
    padding: 0.6rem 0.8rem;
    color: var(--au-paper, #eef2ef);
    font-size: 0.9rem;
    font-family: 'Inter', sans-serif;
}

.au-input:focus {
    outline: none;
    border-color: var(--au-amber, #e8a33d);
}

.au-error {
    font-size: 0.78rem;
    color: var(--au-danger, #e0685f);
}

.au-submit-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.7rem;
    border-radius: 8px;
    border: none;
    background: var(--au-amber, #e8a33d);
    color: var(--au-ink, #0f1613);
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: opacity 0.15s ease;
}

.au-submit-btn:hover:not(:disabled) {
    opacity: 0.9;
}

.au-submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.au-spinner {
    width: 0.9rem;
    height: 0.9rem;
    border: 2px solid rgba(15, 22, 19, 0.3);
    border-top-color: var(--au-ink, #0f1613);
    border-radius: 50%;
    animation: au-spin 0.6s linear infinite;
}

@keyframes au-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>