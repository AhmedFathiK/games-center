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

export interface Room {
    id: number
    code: string
    max_players: number
    status: string
    phase: 'night' | 'day' | null
    round: number | null
    winner: string | null
    night_step: 'mafia' | 'doctor' | 'detective' | null
    configuration: Record<string, number | boolean>
    day_votes: DayVotes | null
    game: Game
    host: AuthUser
    players: Player[]
    you: You | null
    host_view: HostView | null
}