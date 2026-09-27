<script setup lang="ts">
import { computed } from 'vue'
import type { CardCatalogEntry } from '@/types/room'
import MasrawyCard from './Card.vue'

type GalleryGroup = {
    key: string
    title: string
    cards: CardCatalogEntry[]
}

const props = defineProps<{
    catalog: Record<string, CardCatalogEntry>
    rentChart: Record<string, number[]>
    setSize: Record<string, number>
}>()

const colors = [
    'brown', 'light_blue', 'pink', 'orange', 'red', 'yellow', 'green',
    'dark_blue', 'railroad', 'utility',
]

const colorNames: Record<string, string> = {
    brown: 'Brown',
    light_blue: 'Light blue',
    pink: 'Pink',
    orange: 'Orange',
    red: 'Red',
    yellow: 'Yellow',
    green: 'Green',
    dark_blue: 'Dark blue',
    railroad: 'Railroad',
    utility: 'Utility',
}

const actionNames: Record<string, string> = {
    pass_go: 'Garab 7azak',
    double_rent: 'Elbis x 2',
    house: 'Shisha',
    hotel: 'Wil3a',
    deal_breaker: 'Hat wa lamo2akhza el short!',
    just_say_no: 'Da 3and ommo...',
    sly_deal: 'Khod ama 2olak',
    forced_deal: 'Ma.. teegy wana agy!',
    debt_collector: 'Hat 5 fi kees',
    birthday: '3id milady ya kelab',
}

const cards = computed(() => Object.values(props.catalog))

function numberedCards(entries: CardCatalogEntry[]): CardCatalogEntry[] {
    return [...entries].sort((a, b) => a.id.localeCompare(b.id, undefined, { numeric: true }))
}

function groupsOf(entries: CardCatalogEntry[], keyFor: (entry: CardCatalogEntry) => string, titleFor: (key: string) => string): GalleryGroup[] {
    const grouped = new Map<string, CardCatalogEntry[]>()

    for (const entry of entries) {
        const key = keyFor(entry)
        grouped.set(key, [...(grouped.get(key) ?? []), entry])
    }

    return [...grouped.entries()].map(([key, groupCards]) => ({
        key,
        title: titleFor(key),
        cards: numberedCards(groupCards),
    }))
}

const propertyGroups = computed(() => colors
    .map(color => ({
        key: color,
        title: `${colorNames[color]} set`,
        cards: numberedCards(cards.value.filter(entry => entry.type === 'property' && entry.color === color)),
    }))
    .filter(group => group.cards.length > 0))

const moneyGroups = computed(() => groupsOf(
    cards.value.filter(entry => entry.type === 'money'),
    entry => String(entry.value),
    value => `${value}M money cards`,
).sort((a, b) => Number(a.key) - Number(b.key)))

const wildcardGroups = computed(() => {
    const wildcards = cards.value.filter(entry => entry.type === 'wildcard')
    const anyColor = wildcards.filter(entry => entry.any_color)
    const twoColor = wildcards.filter(entry => !entry.any_color)
    const pairs = groupsOf(twoColor, entry => [...(entry.colors ?? [])].sort().join('|'), key =>
        key.split('|').map(color => colorNames[color] ?? color).join(' + '),
    )

    return [
        ...pairs,
        ...(anyColor.length ? [{ key: 'el-bob', title: 'EL BOB', cards: numberedCards(anyColor) }] : []),
    ]
})

const rentGroups = computed(() => {
    const rent = cards.value.filter(entry => entry.type === 'rent')
    return [
        ...groupsOf(rent.filter(entry => !entry.any_color), entry => [...(entry.colors ?? [])].sort().join('|'), key =>
            `${key.split('|').map(color => colorNames[color] ?? color).join(' + ')} rent`,
        ),
        ...(rent.some(entry => entry.any_color)
            ? [{ key: 'any', title: 'Any-color rent', cards: numberedCards(rent.filter(entry => entry.any_color)) }]
            : []),
    ]
})

const actionGroups = computed(() => groupsOf(
    cards.value.filter(entry => entry.type === 'action'),
    entry => entry.action ?? entry.id,
    key => actionNames[key] ?? key,
))

const totalCards = computed(() => cards.value.length)

function cardNumber(entry: CardCatalogEntry): string {
    const match = entry.id.match(/_(\d+)$/)
    return match ? `Card ${Number(match[1])}` : entry.id
}

function chartFor(entry: CardCatalogEntry): number[] | undefined {
    return entry.type === 'property' && entry.color ? props.rentChart[entry.color] : undefined
}

function sizeFor(entry: CardCatalogEntry): number | undefined {
    return entry.type === 'property' && entry.color ? props.setSize[entry.color] : undefined
}

function wildChartsFor(entry: CardCatalogEntry): number[][] | undefined {
    const charts = (entry.colors ?? []).map(color => props.rentChart[color]).filter((chart): chart is number[] => Boolean(chart))
    return charts.length === 2 ? charts : undefined
}

function wildSizesFor(entry: CardCatalogEntry): number[] | undefined {
    const sizes = (entry.colors ?? []).map(color => props.setSize[color]).filter((size): size is number => typeof size === 'number')
    return sizes.length === 2 ? sizes : undefined
}
</script>

<template>
    <main class="card-gallery">
        <header class="gallery-header">
            <div>
                <p class="eyebrow">TEMPORARY DESIGN REVIEW</p>
                <h1>Masrawy Deal card gallery</h1>
                <p class="subtitle">Every card in the current deck, grouped by set and card type.</p>
            </div>
            <div class="total-count"><strong>{{ totalCards }}</strong><span>cards</span></div>
        </header>

        <section v-for="group in moneyGroups" :key="`money-${group.key}`" class="gallery-group">
            <div class="group-heading"><h2>{{ group.title }}</h2><span>{{ group.cards.length }} cards</span></div>
            <div class="card-grid">
                <article v-for="entry in group.cards" :key="entry.id" class="gallery-card">
                    <MasrawyCard :entry="entry" size="sm" />
                    <span class="card-number">{{ cardNumber(entry) }}</span>
                </article>
            </div>
        </section>

        <section class="gallery-section">
            <h2 class="section-title">Properties</h2>
            <section v-for="group in propertyGroups" :key="`property-${group.key}`" class="gallery-group">
                <div class="group-heading"><h3>{{ group.title }}</h3><span>{{ group.cards.length }} cards · set of {{ setSize[group.key] }}</span></div>
                <div class="card-grid">
                    <article v-for="entry in group.cards" :key="entry.id" class="gallery-card">
                        <MasrawyCard :entry="entry" size="sm" :rent-chart="chartFor(entry)" :set-size="sizeFor(entry)" />
                        <span class="card-number">{{ cardNumber(entry) }}</span>
                    </article>
                </div>
            </section>
        </section>

        <section class="gallery-section">
            <h2 class="section-title">Property wildcards</h2>
            <section v-for="group in wildcardGroups" :key="`wild-${group.key}`" class="gallery-group">
                <div class="group-heading"><h3>{{ group.title }}</h3><span>{{ group.cards.length }} cards</span></div>
                <div class="card-grid">
                    <article v-for="entry in group.cards" :key="entry.id" class="gallery-card">
                        <MasrawyCard :entry="entry" size="sm" :wild-rent-charts="wildChartsFor(entry)" :wild-set-sizes="wildSizesFor(entry)" />
                        <span class="card-number">{{ cardNumber(entry) }}</span>
                    </article>
                </div>
            </section>
        </section>

        <section class="gallery-section">
            <h2 class="section-title">Rent cards</h2>
            <section v-for="group in rentGroups" :key="`rent-${group.key}`" class="gallery-group">
                <div class="group-heading"><h3>{{ group.title }}</h3><span>{{ group.cards.length }} cards</span></div>
                <div class="card-grid">
                    <article v-for="entry in group.cards" :key="entry.id" class="gallery-card">
                        <MasrawyCard :entry="entry" size="sm" />
                        <span class="card-number">{{ cardNumber(entry) }}</span>
                    </article>
                </div>
            </section>
        </section>

        <section class="gallery-section">
            <h2 class="section-title">Action cards</h2>
            <section v-for="group in actionGroups" :key="`action-${group.key}`" class="gallery-group">
                <div class="group-heading"><h3>{{ group.title }}</h3><span>{{ group.cards.length }} cards</span></div>
                <div class="card-grid">
                    <article v-for="entry in group.cards" :key="entry.id" class="gallery-card">
                        <MasrawyCard :entry="entry" size="sm" />
                        <span class="card-number">{{ cardNumber(entry) }}</span>
                    </article>
                </div>
            </section>
        </section>
    </main>
</template>

<style scoped>
.card-gallery { max-width: 1440px; margin: 0 auto; padding: 32px clamp(16px, 4vw, 56px) 64px; color: #1d211b; }
.gallery-header { display: flex; align-items: flex-end; justify-content: space-between; gap: 24px; margin-bottom: 36px; padding-bottom: 24px; border-bottom: 1px solid #d8ddcf; }
.eyebrow { margin: 0 0 8px; color: #66715c; font-size: 11px; font-weight: 800; letter-spacing: .15em; }
h1, h2, h3, p { margin-top: 0; }
.gallery-header h1 { margin-bottom: 8px; font-size: clamp(26px, 4vw, 38px); font-weight: 800; letter-spacing: -.04em; }
.subtitle { margin-bottom: 0; color: #697164; }
.total-count { display: flex; align-items: baseline; gap: 7px; white-space: nowrap; color: #697164; }
.total-count strong { color: #1d211b; font-size: 30px; }
.gallery-section { margin-top: 42px; }
.section-title { margin-bottom: 18px; padding-bottom: 10px; border-bottom: 2px solid #293326; font-size: 22px; font-weight: 800; }
.gallery-group { margin: 0 0 30px; }
.group-heading { display: flex; align-items: baseline; gap: 12px; margin: 0 0 14px; }
.group-heading h2, .group-heading h3 { margin: 0; font-size: 17px; font-weight: 800; }
.group-heading span { color: #747b70; font-size: 12px; }
.card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(108px, 1fr)); align-items: start; gap: 22px 16px; }
.gallery-card { display: flex; flex-direction: column; align-items: center; gap: 7px; min-width: 0; }
.card-number { color: #747b70; font-size: 11px; font-weight: 700; }
@media (max-width: 520px) {
    .gallery-header { align-items: flex-start; }
    .total-count { flex-direction: column; gap: 0; }
    .card-grid { grid-template-columns: repeat(auto-fill, minmax(88px, 1fr)); gap: 18px 8px; }
    .gallery-card :deep(.mc-card) { transform: scale(.82); transform-origin: top center; margin-bottom: -30px; }
}
</style>
