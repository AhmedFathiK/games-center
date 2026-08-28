<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/layouts/AuthLayout.vue'

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <AuthLayout title="Create an account" description="Enter your details below to create your account">
        <Head title="Register" />

        <form @submit.prevent="submit" class="au-form">
            <div class="au-field">
                <label for="name" class="au-label">Name</label>
                <input
                    id="name"
                    v-model="form.name"
                    type="text"
                    required
                    autofocus
                    tabindex="1"
                    autocomplete="name"
                    class="au-input"
                    placeholder="Full name"
                />
                <p v-if="form.errors.name" class="au-error">{{ form.errors.name }}</p>
            </div>

            <div class="au-field">
                <label for="email" class="au-label">Email address</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    tabindex="2"
                    autocomplete="email"
                    class="au-input"
                    placeholder="email@example.com"
                />
                <p v-if="form.errors.email" class="au-error">{{ form.errors.email }}</p>
            </div>

            <div class="au-field">
                <label for="password" class="au-label">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    required
                    tabindex="3"
                    autocomplete="new-password"
                    class="au-input"
                    placeholder="Password"
                />
                <p v-if="form.errors.password" class="au-error">{{ form.errors.password }}</p>
            </div>

            <div class="au-field">
                <label for="password_confirmation" class="au-label">Confirm password</label>
                <input
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    type="password"
                    required
                    tabindex="4"
                    autocomplete="new-password"
                    class="au-input"
                    placeholder="Confirm password"
                />
                <p v-if="form.errors.password_confirmation" class="au-error">{{ form.errors.password_confirmation }}</p>
            </div>

            <button type="submit" class="au-submit-btn" tabindex="5" :disabled="form.processing">
                <span v-if="form.processing" class="au-spinner" />
                Create account
            </button>

            <p class="au-footer-text">
                Already have an account?
                <Link :href="route('login')" tabindex="6" class="au-link">Log in</Link>
            </p>
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