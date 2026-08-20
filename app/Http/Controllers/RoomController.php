<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Models\Game;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use App\Events\PlayerJoined;
use App\Events\GameStarted;

class RoomController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'max_players' => ['required', 'integer', 'min:1'],
            'configuration' => ['nullable', 'array'],
        ]);

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

        if ($room->players()->count() >= $room->max_players) {
            throw ValidationException::withMessages([
                'room' => 'This room is full.',
            ]);
        }

        $room->players()->attach($user->id);
        broadcast(new PlayerJoined($room, $user));

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

        return Inertia::render('Rooms/Show', [
            'room' => [
                'id' => $room->id,
                'code' => $room->code,
                'max_players' => $room->max_players,
                'status' => $room->status,
                'configuration' => $room->configuration,

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
                ])->values(),
            ],
        ]);
    }
}
