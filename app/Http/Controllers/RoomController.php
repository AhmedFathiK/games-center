<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Events\PlayerJoined;
use App\Events\GameStarted;
use App\Events\HostNightActionUpdated;
use App\Events\NightActionUpdated;
use App\Events\PhaseChanged;
use App\Events\PlayerExecuted;
use App\Events\PlayerKicked;
use App\Events\VoteUpdated;
use App\Events\GameEnded;
use App\Events\PlayerLeft;

class RoomController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'max_players' => ['required', 'integer', 'min:1'],
            'configuration' => ['nullable', 'array'],
        ]);

        // A user can only be host/player of one active (waiting or
        // in_progress) room at a time. Checked first, before any other
        // validation, since there is nothing else worth validating if
        // this request can't succeed regardless.
        if (Room::activeFor($request->user()->id) !== null) {
            throw ValidationException::withMessages([
                'room' => 'You are already in an active room.',
            ]);
        }

        $gameRecord = Game::findOrFail($validated['game_id']);

        $game = GameRegistry::get($gameRecord->slug);

        if ($validated['max_players'] < $game->minimumPlayers()) {
            throw ValidationException::withMessages([
                'max_players' => "This game requires at least {$game->minimumPlayers()} players.",
            ]);
        }

        if (
            $game->maximumPlayers() !== null &&
            $validated['max_players'] > $game->maximumPlayers()
        ) {
            throw ValidationException::withMessages([
                'max_players' => "This game allows a maximum of {$game->maximumPlayers()} players.",
            ]);
        }

        $configuration = $validated['configuration'] ?? [];

        $this->validateConfiguration(
            $game->configurationSchema(),
            $configuration
        );

        $configErrors = $game->validateRoomConfiguration(
            $configuration,
            $validated['max_players']
        );

        if (! empty($configErrors)) {
            throw ValidationException::withMessages([
                'configuration' => $configErrors,
            ]);
        }

        $room = DB::transaction(function () use (
            $gameRecord,
            $game,
            $validated,
            $configuration,
            $request
        ) {
            $room = Room::create([
                'game_id' => $gameRecord->id,
                'host_id' => $request->user()->id,
                'code' => $this->generateRoomCode(),
                'max_players' => $validated['max_players'],
                'configuration' => $configuration,
                'status' => 'waiting',
            ]);

            if ($game->hostIsPlayer()) {
                $room->players()->attach($request->user()->id);
            }

            return $room;
        });

        return redirect()->route('rooms.show', $room);
    }

    private function generateRoomCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        } while (Room::where('code', $code)->exists());

        return $code;
    }

    private function validateConfiguration(
        array $schema,
        array $configuration
    ): void {
        foreach ($schema as $key => $rules) {
            if (!array_key_exists($key, $configuration)) {
                // Booleans reasonably default to false when omitted (an
                // unchecked toggle). Integers have no such safe absence —
                // silently treating a missing required count as 0 is how
                // a room could previously be created with zero Mafia.
                if ($rules['type'] === 'integer') {
                    throw ValidationException::withMessages([
                        "configuration.$key" => "$key is required.",
                    ]);
                }

                continue;
            }

            $value = $configuration[$key];

            if ($rules['type'] === 'integer') {
                if (!is_int($value)) {
                    throw ValidationException::withMessages([
                        "configuration.$key" =>
                        "$key must be an integer.",
                    ]);
                }

                if (
                    isset($rules['min']) &&
                    $value < $rules['min']
                ) {
                    throw ValidationException::withMessages([
                        "configuration.$key" =>
                        "$key must be at least {$rules['min']}.",
                    ]);
                }

                if (
                    isset($rules['max']) &&
                    $value > $rules['max']
                ) {
                    throw ValidationException::withMessages([
                        "configuration.$key" =>
                        "$key must not exceed {$rules['max']}.",
                    ]);
                }
            }

            if (
                $rules['type'] === 'boolean' &&
                !is_bool($value)
            ) {
                throw ValidationException::withMessages([
                    "configuration.$key" =>
                    "$key must be true or false.",
                ]);
            }
        }
    }

    public function join(Request $request, Room $room)
    {
        $user = $request->user();

        if (! $room->isWaiting()) {
            throw ValidationException::withMessages([
                'room' => 'This room is no longer accepting players.',
            ]);
        }

        if ($room->players()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'room' => 'You are already in this room.',
            ]);
        }

        // A user can only be host/player of one active room at a time.
        // This runs after the "already in this room" check above, so by
        // this point we know they're not already a member of *this*
        // room — an active hit here necessarily means a different room.
        if (Room::activeFor($user->id) !== null) {
            throw ValidationException::withMessages([
                'room' => 'You are already in an active room.',
            ]);
        }

        if ($room->players()->count() >= $room->max_players) {
            throw ValidationException::withMessages([
                'room' => 'This room is full.',
            ]);
        }

        $room->players()->attach($user->id);
        broadcast(new PlayerJoined($room, $user));

        return redirect()->route('rooms.show', $room);
    }

    public function find(Request $request)
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $code = strtoupper(trim($validated['code']));

        $room = Room::where('code', $code)->first();

        if (! $room) {
            // A raw 404 here would break out of the Inertia SPA and
            // land on Laravel's default error page. Throwing a
            // ValidationException instead lets this render as a normal
            // inline form error, same as every other room action.
            throw ValidationException::withMessages([
                'code' => 'No room found with that code.',
            ]);
        }

        return redirect()->route('rooms.show', $room);
    }

    public function leave(Request $request, Room $room)
    {
        $user = $request->user();

        if (! $room->isWaiting()) {
            throw ValidationException::withMessages([
                'room' => 'You can only leave a room while it is waiting to start.',
            ]);
        }

        if (! $room->players()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'room' => 'You are not in this room.',
            ]);
        }

        $room->players()->detach($user->id);
        broadcast(new PlayerLeft($room, $user));

        return redirect()->route('games.index');
    }

    public function kick(Request $request, Room $room, User $user)
    {
        $host = $request->user();

        if ($room->host_id !== $host->id) {
            throw ValidationException::withMessages([
                'room' => 'Only the host can remove players.',
            ]);
        }

        if (! $room->isWaiting()) {
            throw ValidationException::withMessages([
                'room' => 'Players can only be removed while the room is waiting to start.',
            ]);
        }

        if ($user->id === $room->host_id) {
            throw ValidationException::withMessages([
                'room' => 'The host cannot be removed.',
            ]);
        }

        if (! $room->players()->where('users.id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'room' => 'That player is not in this room.',
            ]);
        }

        $room->players()->detach($user->id);
        broadcast(new PlayerKicked($room, $user));

        return redirect()->route('rooms.show', $room);
    }

    public function start(Request $request, Room $room)
    {
        $user = $request->user();

        if ($room->host_id !== $user->id) {
            throw ValidationException::withMessages([
                'room' => 'Only the host can start this room.',
            ]);
        }

        if (! $room->isWaiting()) {
            throw ValidationException::withMessages([
                'room' => 'This room has already started or finished.',
            ]);
        }

        $gameDefinition = GameRegistry::get($room->game->slug);

        $playerCount = $room->players()->count();

        if ($playerCount < $gameDefinition->minimumPlayers()) {
            throw ValidationException::withMessages([
                'room' => "Not enough players to start this game. Minimum is {$gameDefinition->minimumPlayers()}.",
            ]);
        }

        if (
            $gameDefinition->maximumPlayers() !== null &&
            $playerCount > $gameDefinition->maximumPlayers()
        ) {
            throw ValidationException::withMessages([
                'room' => 'Too many players for this game.',
            ]);
        }

        $startErrors = $gameDefinition->validateStart($room);

        if (! empty($startErrors)) {
            throw ValidationException::withMessages([
                'room' => $startErrors,
            ]);
        }

        DB::transaction(function () use ($room, $gameDefinition) {
            $gameState = $gameDefinition->initializeState($room);

            $room->update([
                'status' => 'in_progress',
                'game_state' => $gameState,
            ]);
        });

        broadcast(new GameStarted($room));

        return redirect()->route('rooms.show', $room);
    }

    public function show(Request $request, Room $room)
    {
        $room->load([
            'game',
            'host',
            'players',
        ]);

        $user = $request->user();
        $gameState = $room->game_state;
        $isHost = $room->host_id === $user->id;

        $you = null;
        $hostView = null;

        if ($gameState !== null) {
            $role = $gameState['roles'][$user->id] ?? null;

            // The requesting player's own in-progress night-action state.
            // Every select/confirm submission redirects back through this
            // endpoint, so this is the only way a player recovers "I already
            // picked someone, awaiting confirmation" after that round-trip.
            //
            // Mafia is a coordinated role: members are shown the full
            // mafia night_actions tree (everyone's picks/confirmations),
            // matching what the `rooms.{id}.mafia` channel broadcasts live.
            // Doctor/detective only ever see their own selection.
            $nightAction = null;

            if ($role === 'mafia') {
                $nightAction = $gameState['night_actions']['mafia'] ?? null;
            } elseif (in_array($role, ['doctor', 'detective'], true)) {
                $nightAction = [
                    'selected_target_id' => $gameState['night_actions'][$role]['selections'][$user->id] ?? null,
                    'confirmed' => $gameState['night_actions'][$role]['confirmed'][$user->id] ?? false,
                ];
            }

            $mafiaTeam = null;

            if ($role === 'mafia') {
                $teammateIds = collect($gameState['roles'])
                    ->filter(fn($r, $id) => $r === 'mafia' && (int) $id !== (int) $user->id)
                    ->keys();

                $mafiaTeam = $room->players
                    ->whereIn('id', $teammateIds)
                    ->map(fn($player) => [
                        'id' => $player->id,
                        'name' => $player->name,
                    ])
                    ->values();
            }

            $you = [
                'role' => $role,
                'alive' => $gameState['alive'][$user->id] ?? null,
                'detective_result' => $role === 'detective'
                    ? ($gameState['night_actions']['detective']['results'][$user->id] ?? null)
                    : null,
                'night_action' => $nightAction,
                'mafia_team' => $mafiaTeam,
            ];

            // Host sees everything: all mafia/doctor/detective picks, via
            // a key that is only ever populated for the actual host. The
            // role map is included here too so the host UI can label whose
            // pick is whose — safe, since only the host receives this key.
            if ($isHost) {
                $hostView = [
                    'roles' => $gameState['roles'] ?? null,
                    'night_actions' => $gameState['night_actions'] ?? null,
                ];
            }
        }

        return Inertia::render('Rooms/Show', [
            'room' => [
                'id' => $room->id,
                'code' => $room->code,
                'max_players' => $room->max_players,
                'status' => $room->status,
                'configuration' => $room->configuration,

                'phase' => $gameState['phase'] ?? null,
                'round' => $gameState['round'] ?? null,
                'winner' => $gameState['winner'] ?? null,
                'night_step' => $gameState['night_step'] ?? null,

                // Roles are private during play, but once the game has
                // truly ended there's nothing left to protect — everyone
                // gets the full reveal for the game-over screen.
                'role_reveal' => ($gameState['winner'] ?? null) !== null
                    ? ($gameState['roles'] ?? null)
                    : null,

                // Day voting is public by design — everyone in the room
                // sees the same selections/confirmations.
                'day_votes' => $gameState['day_votes'] ?? null,

                'game' => [
                    'id' => $room->game->id,
                    'name' => $room->game->name,
                    'slug' => $room->game->slug,
                    'minimum_players' => $room->game->minimum_players,
                ],

                'host' => [
                    'id' => $room->host->id,
                    'name' => $room->host->name,
                ],

                'players' => $room->players->map(fn($player) => [
                    'id' => $player->id,
                    'name' => $player->name,
                    'alive' => $gameState['alive'][$player->id] ?? true,
                ])->values(),

                'you' => $you,
                'host_view' => $hostView,
            ],
        ]);
    }

    public function mine(Request $request)
    {
        $user = $request->user();

        $activeRoom = Room::active()
            ->forUser($user->id)
            ->with(['game', 'host'])
            ->first();

        $history = Room::whereNotIn('status', ['waiting', 'in_progress'])
            ->forUser($user->id)
            ->with(['game', 'host'])
            ->orderByDesc('updated_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Rooms/Mine', [
            'active_room' => $activeRoom
                ? $this->roomSummary($activeRoom, $user->id)
                : null,

            'history' => [
                'data' => collect($history->items())
                    ->map(fn(Room $room) => $this->roomSummary($room, $user->id))
                    ->values(),
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'prev_page_url' => $history->previousPageUrl(),
                'next_page_url' => $history->nextPageUrl(),
                'total' => $history->total(),
            ],
        ]);
    }

    private function roomSummary(Room $room, int $userId): array
    {
        return [
            'id' => $room->id,
            'code' => $room->code,
            'status' => $room->status,
            'game' => [
                'name' => $room->game->name,
                'slug' => $room->game->slug,
            ],
            'host' => [
                'id' => $room->host->id,
                'name' => $room->host->name,
            ],
            'is_host' => $room->host_id === $userId,
            'updated_at' => optional($room->updated_at)->toIso8601String(),
        ];
    }

    public function advance(Request $request, Room $room)
    {
        $user = $request->user();

        if ($room->host_id !== $user->id) {
            throw ValidationException::withMessages([
                'room' => 'Only the host can advance the game.',
            ]);
        }

        if (! $room->isInProgress()) {
            throw ValidationException::withMessages([
                'room' => 'This room is not currently in progress.',
            ]);
        }

        $gameDefinition = GameRegistry::get($room->game->slug);

        try {
            $newState = $gameDefinition->advancePhase($room);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'room' => $e->getMessage(),
            ]);
        }

        $room->update(['game_state' => $newState]);

        broadcast(new PhaseChanged($room));

        if (($newState['winner'] ?? null) !== null) {
            $room->update(['status' => 'finished']);
            broadcast(new GameEnded($room));
        }

        return redirect()->route('rooms.show', $room);
    }

    public function act(Request $request, Room $room)
    {
        $user = $request->user();

        if (! $room->isInProgress()) {
            throw ValidationException::withMessages([
                'action' => 'This room is not currently in progress.',
            ]);
        }

        $gameDefinition = GameRegistry::get($room->game->slug);

        try {
            $newState = $gameDefinition->submitAction($room, $user, $request->all());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'action' => $e->getMessage(),
            ]);
        }

        $room->update(['game_state' => $newState]);

        if (in_array($request->input('type'), ['vote_select', 'vote_confirm'], true)) {
            broadcast(new VoteUpdated($room));
        } else {
            broadcast(new NightActionUpdated($room));
            broadcast(new HostNightActionUpdated($room));
        }

        return redirect()->route('rooms.show', $room);
    }

    public function execute(Request $request, Room $room)
    {
        $user = $request->user();

        if ($room->host_id !== $user->id) {
            throw ValidationException::withMessages([
                'room' => 'Only the host can execute a player.',
            ]);
        }

        if (! $room->isInProgress()) {
            throw ValidationException::withMessages([
                'room' => 'This room is not currently in progress.',
            ]);
        }

        $gameDefinition = GameRegistry::get($room->game->slug);

        try {
            $newState = $gameDefinition->executePlayer($room, $request->input('target_id'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'room' => $e->getMessage(),
            ]);
        }

        $room->update(['game_state' => $newState]);

        broadcast(new PlayerExecuted($room));

        if (($newState['winner'] ?? null) !== null) {
            $room->update(['status' => 'finished']);
            broadcast(new GameEnded($room));
        }

        return redirect()->route('rooms.show', $room);
    }
}
