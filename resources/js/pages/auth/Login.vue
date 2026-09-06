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

        <div v-if="status" class="auth-status">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="auth-form">
            <div class="auth-field">
                <label for="email" class="auth-label">Email address</label>
                <input
                    id="email"
                    v-model="form.email"
                    type="email"
                    class="auth-input"
                    required
                    autofocus
                    autocomplete="email"
                />
                <p v-if="form.errors.email" class="auth-error">{{ form.errors.email }}</p>
            </div>

            <div class="auth-field">
                <label for="password" class="auth-label">Password</label>
                <input
                    id="password"
                    v-model="form.password"
                    type="password"
                    class="auth-input"
                    required
                    autocomplete="current-password"
                />
                <p v-if="form.errors.password" class="auth-error">{{ form.errors.password }}</p>
            </div>

            <div class="auth-row">
                <label class="auth-checkbox-label">
                    <input
                        id="remember"
                        v-model="form.remember"
                        type="checkbox"
                        class="auth-checkbox"
                    />
                    <span>Remember me</span>
                </label>

                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="auth-link"
                >
                    Forgot password?
                </Link>
            </div>

            <button type="submit" class="auth-btn-primary" :disabled="form.processing">
                <span v-if="form.processing">Logging in...</span>
                <span v-else>Log in</span>
            </button>

            <p class="auth-footer-text">
                Don't have an account?
                <Link :href="route('register')" class="auth-link">Sign up</Link>
            </p>
        </form>
    </AuthLayout>
</template>
