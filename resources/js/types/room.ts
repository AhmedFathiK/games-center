export interface Game {
    id: number
    name: string
    slug: string
    minimum_players: number
}

export interface Player {
    id: number
    name: string
    alive: boolean
}

export interface AuthUser {
    id: number
    name: string
}

export interface NightActionState {
    selected_target_id: number | string | null
    confirmed: boolean
}

export interface MafiaNightState {
    selections: Record<string, number | string>
    confirmed: Record<string, boolean>
}

export interface DetectiveResult {
    target_id: number | string
    is_mafia: boolean
}

export interface You {
    role: 'mafia' | 'doctor' | 'detective' | 'civilian' | null
    alive: boolean | null
    detective_result: DetectiveResult | null
    // Mafia get the full coordinated tree (everyone's picks); doctor/detective
    // only ever see their own selection state. See RoomController::show().
    night_action: NightActionState | MafiaNightState | null
    mafia_team: { id: number; name: string }[] | null
}

export interface HostView {
    roles: Record<string, string> | null
    night_actions: {
        mafia: MafiaNightState
        doctor: MafiaNightState
        detective: MafiaNightState & { results: Record<string, DetectiveResult> }
    } | null
}

export interface DayVotes {
    selections: Record<string, number | string>
    confirmed: Record<string, boolean>
}

// --- Masrawy Deal's own view shape --------------------------------------
// Every game's `viewFor()` may use the `you` key for its own per-viewer
// payload — Mafia's `You` above is Mafia's shape, this is Masrawy Deal's.
// See MasrawyDealGame::viewFor() for what each field means server-side.

export interface MasrawyPropertyGroup {
    cards: string[]
    house: string | null
    hotel: string | null
}

export interface MasrawyChargeEntry {
    phase: 'responding' | 'paying' | 'done'
    chain: { player_id: number; card_id: string }[]
    owed: number
    outcome: 'applied' | 'cancelled' | null
}

export interface MasrawyPending {
    kind: 'debt_collector' | 'birthday' | 'rent' | 'sly_deal' | 'forced_deal' | 'deal_breaker'
    source_id: number
    card_id: string
    color?: string
    multiplier?: number
    target_card_id?: string
    give_card_id?: string
    charges: Record<string, MasrawyChargeEntry>
}

// Mirrors CardCatalog::get()'s shape exactly — the backend sends one of
// these per card id that actually appears anywhere in the payload (see
// MasrawyDealGame::viewFor()/collectVisibleCardIds()), so the frontend
// never has to know card data on its own; it only renders whatever this
// says. Fields are type-specific: `color` only for property, `colors`/
// `any_color` for wildcard and rent, `action` for action cards,
// `charges_all` for rent.
export interface CardCatalogEntry {
    id: string
    type: 'money' | 'property' | 'wildcard' | 'rent' | 'action'
    label: string
    description: string | null
    value: number
    color?: string
    colors?: string[]
    any_color?: boolean
    action?: string
    charges_all?: boolean
}

export interface MasrawySeat {
    id: number
    hand_count: number
    hand: string[] | null
    bank: string[]
    properties: Record<string, MasrawyPropertyGroup>
}

export interface MasrawyYou {
    hand: string[]
    responding_to: number[]
    owes: number | null
    payable_assets: Record<string, number> | null
}

export interface MasrawyTableState {
    current_player_id: number
    has_drawn_this_turn: boolean
    cards_played_this_turn: number
    draw_pile_count: number
    discard_pile: string[]
    players: MasrawySeat[]
    pending: MasrawyPending | null
    // See CardCatalogEntry — one entry per card id visible anywhere in
    // this payload, never the whole deck.
    catalog: Record<string, CardCatalogEntry>
    // Mirrors CardCatalog::RENT_CHART/SET_SIZE — small, fully public,
    // sent once here rather than duplicated onto every property card's
    // own catalog entry.
    rent_chart: Record<string, number[]>
    set_size: Record<string, number>
}

export interface Room {
    id: number
    code: string
    max_players: number
    status: string
    phase: 'night' | 'day' | null
    round: number | null
    winner: string | null
    night_step: 'mafia' | 'doctor' | 'detective' | null
    role_reveal: Record<string, string> | null
    configuration: Record<string, number | boolean>
    day_votes: DayVotes | null
    game: Game
    host: AuthUser
    players: Player[]
    // Polymorphic per-game payload (see GameDefinition::viewFor()) — Mafia
    // puts its `You` shape here, Masrawy Deal puts `MasrawyYou`. Each
    // game-specific component casts this to its own game's type rather
    // than the shared Room type trying to model every game's shape.
    you: You | MasrawyYou | null
    host_view: HostView | null
    // Masrawy Deal-only: its viewFor() also returns a `table` key. Absent
    // (undefined) for any other game.
    table?: MasrawyTableState | null
    host_stale?: boolean
    player_count?: number
}