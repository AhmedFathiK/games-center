<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { ref } from 'vue'

const showModal = ref(false)
const passwordInput = ref<HTMLInputElement | null>(null)

const form = useForm({
    password: '',
})

function openModal() {
    showModal.value = true
}

function closeModal() {
    showModal.value = false
    form.clearErrors()
    form.reset()
}

function deleteUser() {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    })
}
</script>

<template>
    <div class="da-block">
        <p class="da-warning-title">Warning</p>
        <p class="da-warning-text">Please proceed with caution, this cannot be undone.</p>

        <button type="button" class="da-trigger-btn" @click="openModal">Delete account</button>

        <div v-if="showModal" class="da-overlay" @click.self="closeModal">
            <div class="da-modal">
                <form @submit.prevent="deleteUser" class="da-form">
                    <h3 class="da-modal-title">Are you sure you want to delete your account?</h3>
                    <p class="da-modal-text">
                        Once your account is deleted, all of its resources and data will also be
                        permanently deleted. Please enter your password to confirm.
                    </p>

                    <div class="da-field">
                        <label for="delete-password" class="sr-only">Password</label>
                        <input
                            id="delete-password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            placeholder="Password"
                            class="da-input"
                        />
                        <p v-if="form.errors.password" class="da-error">{{ form.errors.password }}</p>
                    </div>

                    <div class="da-actions">
                        <button type="button" class="da-cancel-btn" @click="closeModal">Cancel</button>
                        <button type="submit" class="da-confirm-btn" :disabled="form.processing">
                            Delete account
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<style scoped>
.da-block {
    border: 1px solid #4a2422;
    background: rgba(224, 104, 95, 0.08);
    border-radius: 8px;
    padding: 1rem 1.2rem;
}

.da-warning-title {
    font-weight: 600;
    color: var(--st-danger, #e0685f);
    font-size: 0.9rem;
}

.da-warning-text {
    margin-top: 0.2rem;
    font-size: 0.82rem;
    color: var(--st-mist, #9fb0a8);
}

.da-trigger-btn {
    margin-top: 0.9rem;
    background: var(--st-danger, #e0685f);
    color: #1a0f0e;
    border: none;
    border-radius: 6px;
    padding: 0.55rem 1.1rem;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
}

.da-trigger-btn:hover {
    opacity: 0.9;
}

.da-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.65);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 60;
    padding: 1.5rem;
}

.da-modal {
    background: var(--st-surface, #16201c);
    border: 1px solid var(--st-border, #2a3a33);
    border-radius: 10px;
    padding: 1.75rem;
    max-width: 26rem;
    width: 100%;
}

.da-modal-title {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--st-paper, #eef2ef);
}

.da-modal-text {
    margin-top: 0.6rem;
    font-size: 0.85rem;
    color: var(--st-mist, #9fb0a8);
    line-height: 1.5;
}

.da-field {
    margin-top: 1.25rem;
}

.da-input {
    width: 100%;
    background: var(--st-surface-raised, #1e2b25);
    border: 1px solid var(--st-border, #2a3a33);
    border-radius: 8px;
    padding: 0.6rem 0.8rem;
    color: var(--st-paper, #eef2ef);
    font-size: 0.9rem;
}

.da-input:focus {
    outline: none;
    border-color: var(--st-danger, #e0685f);
}

.da-error {
    margin-top: 0.4rem;
    font-size: 0.78rem;
    color: var(--st-danger, #e0685f);
}

.da-actions {
    margin-top: 1.5rem;
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.da-cancel-btn {
    background: transparent;
    border: 1px solid var(--st-border, #2a3a33);
    color: var(--st-paper, #eef2ef);
    border-radius: 6px;
    padding: 0.5rem 1rem;
    font-size: 0.85rem;
    cursor: pointer;
}

.da-confirm-btn {
    background: var(--st-danger, #e0685f);
    color: #1a0f0e;
    border: none;
    border-radius: 6px;
    padding: 0.5rem 1rem;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
}

.da-confirm-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>