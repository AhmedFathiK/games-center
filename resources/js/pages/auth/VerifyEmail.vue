<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3'
import AuthLayout from '@/layouts/AuthLayout.vue'

defineProps<{
    status?: string
}>()

const form = useForm({})

const submit = () => {
    form.post(route('verification.send'))
}
</script>

<template>
    <AuthLayout title="Verify email" description="Please verify your email address by clicking on the link we just emailed to you.">
        <Head title="Email verification" />

        <p v-if="status === 'verification-link-sent'" class="au-status">
            A new verification link has been sent to the email address you provided during registration.
        </p>

        <form @submit.prevent="submit" class="au-verify-form">
            <button type="submit" class="au-secondary-btn" :disabled="form.processing">
                <span v-if="form.processing" class="au-spinner" />
                Resend verification email
            </button>

            <Link :href="route('logout')" method="post" as="button" class="au-link au-link--centered">
                Log out
            </Link>
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

.au-verify-form {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1.25rem;
}

.au-secondary-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.65rem 1.4rem;
    border-radius: 8px;
    border: 1px solid var(--au-border, #2a3a33);
    background: var(--au-surface-raised, #1e2b25);
    color: var(--au-paper, #eef2ef);
    font-family: 'Space Grotesk', sans-serif;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.au-secondary-btn:hover:not(:disabled) {
    border-color: var(--au-amber, #e8a33d);
}

.au-secondary-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.au-spinner {
    width: 0.9rem;
    height: 0.9rem;
    border: 2px solid rgba(238, 242, 239, 0.25);
    border-top-color: var(--au-paper, #eef2ef);
    border-radius: 50%;
    animation: au-spin 0.6s linear infinite;
}

@keyframes au-spin {
    to {
        transform: rotate(360deg);
    }
}

.au-link {
    color: var(--au-amber, #e8a33d);
    text-decoration: underline;
    font-size: 0.85rem;
    background: none;
    border: none;
    cursor: pointer;
}

.au-link--centered {
    display: block;
}
</style>