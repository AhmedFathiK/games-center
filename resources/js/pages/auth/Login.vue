<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/layouts/AuthLayout.vue'

defineProps<{
    canResetPassword?: boolean
    status?: string
}>()

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

function submit() {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password')
        },
    })
}
</script>

<template>
    <AuthLayout title="Log in" description="Welcome back! Please enter your details.">
        <Head title="Log in" />

        <p v-if="status" class="au-status">{{ status }}</p>

        <form @submit.prevent="submit" class="au-form">
            <div class="au-field">
                <label for="email" class="au-label">Email address</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    autocomplete="email"
                    autofocus
                    class="au-input"
                    placeholder="email@example.com"
                    required
                />
                <p v-if="form.errors.email" class="au-error">{{ form.errors.email }}</p>
            </div>

            <div class="au-field">
                <label for="password" class="au-label">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    autocomplete="current-password"
                    class="au-input"
                    required
                />
                <p v-if="form.errors.password" class="au-error">{{ form.errors.password }}</p>
            </div>

            <div class="au-row">
                <label class="au-checkbox-label">
                    <input id="remember" v-model="form.remember" type="checkbox" class="au-checkbox" />
                    <span>Remember me</span>
                </label>

                <Link v-if="canResetPassword" :href="route('password.request')" class="au-link">
                    Forgot password?
                </Link>
            </div>

            <button type="submit" class="au-submit-btn" :disabled="form.processing">
                <span v-if="form.processing" class="au-spinner" />
                {{ form.processing ? 'Logging in…' : 'Log in' }}
            </button>

            <p class="au-footer-text">
                Don't have an account?
                <Link :href="route('register')" class="au-link">Sign up</Link>
            </p>
        </form>
    </AuthLayout>
</template>

<style scoped>
.au-status {
    text-align: center;
    font-size: 0.85rem;
    color: var(--au-phosphor, #6fcf97);
    margin-bottom: 1rem;
}

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

.au-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.au-checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    color: var(--au-mist, #9fb0a8);
    cursor: pointer;
}

.au-checkbox {
    accent-color: var(--au-amber, #e8a33d);
    width: 0.95rem;
    height: 0.95rem;
    cursor: pointer;
}

.au-link {
    color: var(--au-amber, #e8a33d);
    text-decoration: underline;
    font-size: 0.85rem;
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

.au-footer-text {
    text-align: center;
    font-size: 0.85rem;
    color: var(--au-mist, #9fb0a8);
}
</style>