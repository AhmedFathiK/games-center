/**
 * Per-game visual themes for the shared Room components.
 *
 * Room/lobby structure (Rooms/Show.vue, and later the night/day/game-over
 * screens) is platform-level and shared across every game. What CHANGES
 * per game is purely presentational: palette, type, and a handful of
 * "motif" flags that turn structural decoration on/off.
 *
 * A new game (Phase 10, Monopoly Deal, ...) needs a new entry here, not a
 * new copy of Show.vue. Until someone writes a themed entry for a game,
 * `defaultTheme` is used — deliberately plain, so we never guess at a
 * new game's identity ahead of actually building it.
 */

export interface GameTheme {
    slug: string

    colors: {
        background: string // page background
        surface: string // primary card/panel surface
        surfaceAlt: string // secondary surface (nested panels, rows)
        border: string // hairline/divider color
        primary: string // danger / kill / "team A" accent
        secondary: string // host / management accent
        success: string // alive / safe / "team B" accent
        textOnBackground: string
        textOnSurface: string
        textMuted: string
    }

    fonts: {
        display: string // headers, stamps, badges — used sparingly
        body: string
        mono: string // codes, counts, index numbers, any "data"
    }

    motifs: {
        /** Card surfaces read as a physical object (folder) vs a plain panel. */
        surfaceStyle: 'folder' | 'plain'
        /** Section dividers use a dashed "perforation" vs a plain rule. */
        dividerStyle: 'perforated' | 'plain'
        /** Status/role indicators render as a rotated ink stamp vs a plain pill. */
        badgeStyle: 'stamp' | 'pill'
        /** Roster/log entries get sequential index numbers (N°01...), since order is real information. */
        useIndexNumbers: boolean
    }

    /** Copy overrides. Functional meaning must stay identical across themes — only flavor changes. */
    labels: {
        hostSectionTitle: string
        rosterSectionTitle: string
        settingsSectionTitle: string
    }
}

export const defaultTheme: GameTheme = {
    slug: 'default',
    colors: {
        background: '#f8fafc',
        surface: '#ffffff',
        surfaceAlt: '#f1f5f9',
        border: '#e2e8f0',
        primary: '#334155',
        secondary: '#64748b',
        success: '#16a34a',
        textOnBackground: '#0f172a',
        textOnSurface: '#0f172a',
        textMuted: '#64748b',
    },
    fonts: {
        display: 'inherit',
        body: 'inherit',
        mono: 'ui-monospace, SFMono-Regular, monospace',
    },
    motifs: {
        surfaceStyle: 'plain',
        dividerStyle: 'plain',
        badgeStyle: 'pill',
        useIndexNumbers: false,
    },
    labels: {
        hostSectionTitle: 'Host',
        rosterSectionTitle: 'Players',
        settingsSectionTitle: 'Game Settings',
    },
}

export const mafiaTheme: GameTheme = {
    slug: 'mafia',
    colors: {
        background: '#14161c', // charcoal-ink
        surface: '#ece2c6', // aged manila
        surfaceAlt: '#ddccA0', // darker manila
        border: '#b9a97c',
        primary: '#9c2b26', // stamp-red
        secondary: '#a3822e', // brass
        success: '#4b6a4f', // evidence-green
        textOnBackground: '#ece2c6',
        textOnSurface: '#1c1a14',
        textMuted: '#6b6250',
    },
    fonts: {
        display: "'Special Elite', cursive",
        body: "'IBM Plex Sans', sans-serif",
        mono: "'IBM Plex Mono', monospace",
    },
    motifs: {
        surfaceStyle: 'folder',
        dividerStyle: 'perforated',
        badgeStyle: 'stamp',
        useIndexNumbers: true,
    },
    labels: {
        hostSectionTitle: 'Case Handler',
        rosterSectionTitle: 'Witness Log',
        settingsSectionTitle: 'Case Parameters',
    },
}

const themes: Record<string, GameTheme> = {
    mafia: mafiaTheme,
}

export function themeForGame(slug: string): GameTheme {
    return themes[slug] ?? defaultTheme
}