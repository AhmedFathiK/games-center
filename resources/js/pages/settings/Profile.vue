<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import { ref } from 'vue'
import AppLayout from '@/layouts/AppLayout.vue'
import SettingsNav from '@/components/SettingsNav.vue'
import DeleteAccountModal from '@/components/DeleteAccountModal.vue'
import { type SharedData, type User } from '@/types'

defineOptions({ layout: AppLayout })

interface Props {
    mustVerifyEmail: boolean
    status?: string
}

defineProps<Props>()

const page = usePage<SharedData>()
const user = page.props.auth.user as User

const form = useForm({
    name: user.name,
    email: user.email,
})

const showSaved = ref(false)

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
        onSuccess: () => {
            showSaved.value = true
            setTimeout(() => (showSaved.value = false), 2000)
        },
    })
}
</script>

<template>
    <div class="st-page">
        <Head title="Profile settings" />

        <div class="st-container">
            <header class="st-header">
                <h1 class="st-title">Settings</h1>
                <p class="st-subtitle">Manage your profile and account settings.</p>
            </header>

            <SettingsNav />

            <section class="st-section">
                <h2 class="st-section-title">Profile information</h2>
                <p class="st-section-desc">Update your name and email address</p>

                <form @submit.prevent="submit" class="st-form">
                    <div class="st-field">
                        <label for="name" class="st-label">Name</label>
                        <input id="name" v-model="form.name" required autocomplete="name" class="st-input" placeholder="Full name" />
                        <p v-if="form.errors.name" class="st-error">{{ form.errors.name }}</p>
                    </div>

                    <div class="st-field">
                        <label for="email" class="st-label">Email address</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            required
                            autocomplete="username"
                            class="st-input"
                            placeholder="Email address"
                        />
                        <p v-if="form.errors.email" class="st-error">{{ form.errors.email }}</p>
                    </div>

                    <div v-if="mustVerifyEmail && !user.email_verified_at" class="st-verify-notice">
                        <p>
                            Your email address is unverified.
                            <Link :href="route('verification.send')" method="post" as="button" class="st-link">
                                Click here to re-send the verification email.
                            </Link>
                        </p>
                        <p v-if="status === 'verification-link-sent'" class="st-verify-sent">
                            A new verification link has been sent to your email address.
                        </p>
                    </div>

                    <div class="st-save-row">
                        <button type="submit" class="st-save-btn" :disabled="form.processing">Save</button>
                        <span v-if="showSaved" class="st-saved-text">Saved.</span>
                    </div>
                </form>
            </section>

            <section class="st-section">
                <DeleteAccountModal />
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

.st-section {
    margin-bottom: 2rem;
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

.st-verify-notice {
    font-size: 0.85rem;
    color: var(--st-mist);
}

.st-link {
    color: var(--st-amber);
    text-decoration: underline;
    background: none;
    border: none;
    font-size: inherit;
    cursor: pointer;
    padding: 0;
}

.st-verify-sent {
    margin-top: 0.5rem;
    color: var(--st-phosphor);
    font-weight: 500;
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