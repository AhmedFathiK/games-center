<script setup lang="ts">
/**
 * Masrawy Deal is built for a sideways phone: the tilted table needs width
 * more than height. Browsers can't truly force rotation (iOS Safari offers no
 * lock at all, Android only locks in fullscreen), so on a touch phone held
 * upright this covers the screen with a "rotate your phone" prompt and offers
 * the fullscreen + orientation-lock shortcut where the browser supports it.
 * Desktop windows (fine pointer) are never gated, however narrow.
 *
 * Once the phone is sideways, a small fullscreen toggle is offered because the
 * browser toolbar costs a third of a landscape phone's height.
 */
import { onMounted, onUnmounted, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useI18n } from '@/i18n'

const { t } = useI18n()

const PORTRAIT_PHONE = '(pointer: coarse) and (max-width: 600px) and (orientation: portrait)'
const LANDSCAPE_PHONE = '(pointer: coarse) and (orientation: landscape) and (max-height: 560px)'

type LockableOrientation = ScreenOrientation & { lock?: (orientation: string) => Promise<void> }

const portraitPhone = ref(false)
const landscapePhone = ref(false)
const isFullscreen = ref(false)
const fullscreenSupported = ref(false)
const lockNote = ref<string | null>(null)

const portraitQuery = window.matchMedia(PORTRAIT_PHONE)
const landscapeQuery = window.matchMedia(LANDSCAPE_PHONE)

function sync() {
    portraitPhone.value = portraitQuery.matches
    landscapePhone.value = landscapeQuery.matches
    isFullscreen.value = document.fullscreenElement !== null
}

// Browsers only allow fullscreen from a tap, and drop it whenever the phone
// locks or an app takes over. So the first tap after that goes back to
// fullscreen + landscape on its own, unless the player left fullscreen on
// purpose with the toggle.
const OPT_OUT_KEY = 'md-fullscreen-opt-out'

function optedOut(): boolean {
    try {
        return sessionStorage.getItem(OPT_OUT_KEY) === '1'
    } catch {
        return false
    }
}

function setOptOut(value: boolean) {
    try {
        if (value) sessionStorage.setItem(OPT_OUT_KEY, '1')
        else sessionStorage.removeItem(OPT_OUT_KEY)
    } catch {
        // Private mode: the toggle still works, it just won't be remembered.
    }
}

function resumeOnTap(event: Event) {
    if (!fullscreenSupported.value || document.fullscreenElement || optedOut()) return
    if (!portraitPhone.value && !landscapePhone.value) return
    if ((event.target as Element | null)?.closest?.('a')) return

    void enterFullscreenAndLock(true)
}

async function enterFullscreenAndLock(quiet = false) {
    lockNote.value = null
    setOptOut(false)

    try {
        if (!document.fullscreenElement) await document.documentElement.requestFullscreen?.()
        const orientation = screen.orientation as LockableOrientation | undefined
        if (!orientation?.lock) throw new Error('orientation lock unavailable')
        await orientation.lock('landscape')
    } catch {
        if (!quiet) lockNote.value = t('This browser can’t lock rotation. Turn off your phone’s rotation lock and turn it sideways.')
    }
}

async function toggleFullscreen() {
    try {
        if (document.fullscreenElement) {
            setOptOut(true)
            await document.exitFullscreen()
        } else {
            await enterFullscreenAndLock()
        }
    } catch {
        // Fullscreen is a convenience; the table works without it.
    }
}

onMounted(() => {
    fullscreenSupported.value = typeof document.documentElement.requestFullscreen === 'function'
    sync()
    portraitQuery.addEventListener('change', sync)
    landscapeQuery.addEventListener('change', sync)
    document.addEventListener('fullscreenchange', sync)
    document.addEventListener('click', resumeOnTap, true)
})

onUnmounted(() => {
    portraitQuery.removeEventListener('change', sync)
    landscapeQuery.removeEventListener('change', sync)
    document.removeEventListener('fullscreenchange', sync)
    document.removeEventListener('click', resumeOnTap, true)
})
</script>

<template>
    <div v-if="portraitPhone" class="lg-gate" role="alertdialog" aria-modal="true" aria-labelledby="lg-title">
        <div class="lg-phone" aria-hidden="true"><span class="lg-phone-body"></span></div>
        <h2 id="lg-title" class="lg-title">{{ t('Rotate your phone') }}</h2>
        <p class="lg-text">{{ t('Masrawy Deal is played sideways so the whole table fits on screen.') }}</p>
        <button v-if="fullscreenSupported" type="button" class="lg-btn" @click="enterFullscreenAndLock()">
            {{ t('Lock landscape') }}
        </button>
        <p v-if="lockNote" class="lg-note" role="status">{{ lockNote }}</p>
        <Link :href="route('games.index')" class="lg-leave">{{ t('Back to Games') }}</Link>
    </div>

    <button
        v-else-if="landscapePhone && fullscreenSupported"
        type="button"
        class="lg-fs"
        :aria-label="isFullscreen ? t('Exit fullscreen') : t('Fullscreen')"
        :title="isFullscreen ? t('Exit fullscreen') : t('Fullscreen')"
        @click="toggleFullscreen"
    >
        {{ isFullscreen ? '⤡' : '⤢' }}
    </button>
</template>

<style scoped>
.lg-gate {
    position: fixed;
    inset: 0;
    z-index: 2000;
    display: flex;
    flex-direction: column;
    gap: 0.9rem;
    align-items: center;
    justify-content: center;
    padding: 2rem 1.5rem;
    text-align: center;
    color: #f8fafc;
    background: radial-gradient(circle at 50% 35%, #1e293b, #0b1220 70%);
}

.lg-phone {
    width: 64px;
    height: 64px;
    display: grid;
    place-items: center;
    animation: lg-turn 2.6s ease-in-out infinite;
}

.lg-phone-body {
    width: 30px;
    height: 54px;
    border: 4px solid var(--rc-primary, #f59e0b);
    border-radius: 8px;
    box-shadow: inset 0 -6px 0 rgb(245 158 11 / 35%);
}

@keyframes lg-turn {
    0%,
    20% {
        transform: rotate(0deg);
    }

    55%,
    100% {
        transform: rotate(-90deg);
    }
}

.lg-title {
    margin: 0;
    font-family: var(--rc-font-display, serif);
    font-size: 1.4rem;
}

.lg-text {
    max-width: 22rem;
    margin: 0;
    font-size: 0.9rem;
    line-height: 1.5;
    color: #cbd5e1;
}

.lg-btn {
    min-height: 46px;
    padding: 0.6rem 1.4rem;
    border: 0;
    border-radius: 999px;
    font: 700 0.9rem var(--rc-font-body, sans-serif);
    color: #0f172a;
    background: var(--rc-primary, #f59e0b);
    cursor: pointer;
}

.lg-note {
    max-width: 22rem;
    margin: 0;
    font-size: 0.8rem;
    color: #fcd34d;
}

.lg-leave {
    margin-top: 0.5rem;
    font-size: 0.8rem;
    color: #94a3b8;
    text-decoration: underline;
}

.lg-fs {
    position: fixed;
    top: 0.4rem;
    right: 0.4rem;
    z-index: 960;
    width: 2.4rem;
    height: 2.4rem;
    border: 1px solid rgb(255 255 255 / 25%);
    border-radius: 50%;
    font-size: 1.1rem;
    color: #f8fafc;
    background: rgb(15 23 42 / 70%);
    cursor: pointer;
}

@media (prefers-reduced-motion: reduce) {
    .lg-phone {
        animation: none;
        transform: rotate(-90deg);
    }
}
</style>
