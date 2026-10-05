<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3'
import { watch } from 'vue'
import { useI18n } from '@/i18n'

const page = usePage()
const { t } = useI18n()

watch(() => page.props.locale, value => {
    const locale = value === 'ar' ? 'ar' : 'en'
    document.documentElement.lang = locale
    document.documentElement.dir = locale === 'ar' ? 'rtl' : 'ltr'
}, { immediate: true })

function changeLanguage(event: Event) {
    const locale = (event.target as HTMLSelectElement).value
    router.post(route('locale.update'), { locale }, { preserveScroll: true })
}
</script>

<template>
    <label class="language-switcher">
        <span class="sr-only">{{ t('Language') }}</span>
        <select :value="page.props.locale" :aria-label="t('Language')" @change="changeLanguage">
            <option value="en">{{ t('English') }}</option>
            <option value="ar">{{ t('Arabic') }}</option>
        </select>
    </label>
</template>

<style scoped>
.language-switcher select {
    border: 1px solid var(--gc-border, #2a3a33);
    border-radius: 6px;
    padding: 0.35rem 0.5rem;
    background: var(--gc-surface, #16201c);
    color: var(--gc-paper, #eef2ef);
    font: inherit;
}
</style>
