<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMafia(): Game
    {
        return Game::create([
            'name' => 'Mafia',
            'slug' => 'mafia',
            'enabled' => true,
        ]);
    }

    public function test_host_can_create_a_room(): void
    {
        $game = $this->seedMafia();

        $host = User::factory()->create();

        $response = $this->actingAs($host)->post('/rooms', [
            'game_id' => $game->id,
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 2,
                'doctor' => true,
                'detective' => true,
            ],
        ]);

        $response->assertRedirect();

        $room = Room::first();

        $this->assertNotNull($room);

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'game_id' => $game->id,
            'host_id' => $host->id,
            'max_players' => 10,
            'status' => 'waiting',
        ]);

        $this->assertEquals([
            'mafia_count' => 2,
            'doctor' => true,
            'detective' => true,
        ], $room->configuration);

        // Mafia's hostIsPlayer() is false.
        $this->assertDatabaseMissing('room_players', [
            'room_id' => $room->id,
            'user_id' => $host->id,
        ]);
    }

    public function test_user_can_join_a_waiting_room(): void
    {
        $game = $this->seedMafia();

        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 2,
                'doctor' => true,
                'detective' => true,
            ],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($player)
            ->post("/rooms/{$room->id}/join");

        $response->assertRedirect();

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);

        $this->assertEquals(1, $room->refresh()->players()->count());
    }

    public function test_user_cannot_join_a_room_twice(): void
    {
        $game = $this->seedMafia();

        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($player)
            ->postJson("/rooms/{$room->id}/join");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'You are already in this room.',
            ]);

        $this->assertEquals(1, $room->refresh()->players()->count());
    }

    public function test_user_cannot_join_a_room_that_has_started(): void
    {
        $game = $this->seedMafia();

        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($player)
            ->postJson("/rooms/{$room->id}/join");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'This room is no longer accepting players.',
            ]);

        $this->assertDatabaseMissing('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_user_cannot_join_a_full_room(): void
    {
        $game = $this->seedMafia();

        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();
        $newPlayer = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 5,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($newPlayer)
            ->postJson("/rooms/{$room->id}/join");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'This room is full.',
            ]);

        $this->assertEquals(5, $room->refresh()->players()->count());
    }

    public function test_host_cannot_start_a_room_with_too_few_players(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/start");

        $response->assertRedirect()
            ->assertSessionHasErrors('room');

        $this->assertEquals('waiting', $room->refresh()->status);
    }

    public function test_only_host_can_start_a_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($player)
            ->post("/rooms/{$room->id}/start");

        $response->assertRedirect()
            ->assertSessionHasErrors('room');

        $this->assertEquals('waiting', $room->refresh()->status);
    }

    public function test_host_can_start_room_when_minimum_players_are_present(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 1,
                'doctor' => false,
                'detective' => false,
            ],
            'status' => 'waiting',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/start");

        $response->assertRedirect(route('rooms.show', $room));

        $this->assertEquals('in_progress', $room->refresh()->status);
        $this->assertEquals('night', $room->game_state['phase']);
        $this->assertEquals(1, $room->game_state['round']);
        $this->assertEquals('mafia', $room->game_state['night_step']);
    }

    public function test_game_started_event_is_broadcast_when_host_starts_room(): void
    {
        Event::fake([\App\Events\GameStarted::class]);

        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 1,
                'doctor' => false,
                'detective' => false,
            ],
            'status' => 'waiting',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $this->actingAs($host)->post("/rooms/{$room->id}/start");

        Event::assertDispatched(\App\Events\GameStarted::class, function ($event) use ($room) {
            return $event->room->id === $room->id;
        });
    }

    public function test_room_can_be_retrieved_with_game_host_and_players(): void
    {
        $game = $this->seedMafia();

        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 2,
                'doctor' => true,
                'detective' => true,
            ],
            'status' => 'waiting',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)
            ->get("/rooms/{$room->code}");

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Show')
                    ->where('room.id', $room->id)
                    ->where('room.game.id', $game->id)
                    ->where('room.host.id', $host->id)
                    ->has('room.players', 5)
                    ->where('room.configuration.mafia_count', 2)
            );
    }

    public function test_authenticated_user_can_get_enabled_games(): void
    {
        $game = $this->seedMafia();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get('/games');

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Index')
                    ->has('games', 1)
                    ->where('games.0.id', $game->id)
                    ->where('games.0.name', 'Mafia')
                    ->where('games.0.slug', 'mafia')
                    ->where('games.0.minimum_players', 4)
                    ->where('games.0.maximum_players', 20)
                    ->where('games.0.host_is_player', false)
                    ->where('games.0.configuration_schema.mafia_count.type', 'integer')
                    ->where('games.0.configuration_schema.doctor.type', 'boolean')
                    ->where('games.0.configuration_schema.detective.type', 'boolean')
            );
    }

    public function test_host_cannot_start_room_with_invalid_mafia_configuration(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 0, // invalid: needs at least 1
                'doctor' => false,
                'detective' => false,
            ],
            'status' => 'waiting',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/start");

        $response->assertRedirect()
            ->assertSessionHasErrors('room');

        $this->assertEquals('waiting', $room->refresh()->status);
    }

    public function test_room_creation_rejects_mafia_not_outnumbered_by_town(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $response = $this->actingAs($host)->post('/rooms', [
            'game_id' => $game->id,
            'max_players' => 20,
            'configuration' => [
                'mafia_count' => 16,
                'doctor' => false,
                'detective' => false,
            ],
        ]);

        $response->assertRedirect()
            ->assertSessionHasErrors('configuration');

        $this->assertNull(Room::first());
    }

    public function test_host_can_advance_the_phase(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => false, 'detective' => false],
            'status' => 'in_progress',
            'game_state' => ['phase' => 'night', 'round' => 1, 'roles' => [], 'alive' => [], 'winner' => null],
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/advance");

        $response->assertRedirect(route('rooms.show', $room));

        // No doctor/detective enabled, so mafia's turn is the only night
        // step — one advance call resolves the night and reaches day.
        $this->assertEquals('day', $room->refresh()->game_state['phase']);
    }

    public function test_advance_steps_through_special_roles_before_resolving_night(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => true, 'detective' => true],
            'status' => 'in_progress',
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => [],
                'alive' => [],
                'winner' => null,
                'night_step' => 'mafia',
            ],
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $this->actingAs($host)->post("/rooms/{$room->id}/advance");
        $this->assertEquals('night', $room->refresh()->game_state['phase']);
        $this->assertEquals('doctor', $room->game_state['night_step']);

        $this->actingAs($host)->post("/rooms/{$room->id}/advance");
        $this->assertEquals('night', $room->refresh()->game_state['phase']);
        $this->assertEquals('detective', $room->game_state['night_step']);

        $this->actingAs($host)->post("/rooms/{$room->id}/advance");
        $this->assertEquals('day', $room->refresh()->game_state['phase']);
        $this->assertNull($room->game_state['night_step']);
    }

    public function test_non_host_cannot_advance_the_phase(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
            'game_state' => ['phase' => 'night', 'round' => 1, 'roles' => [], 'alive' => [], 'winner' => null],
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->post("/rooms/{$room->id}/advance");

        $response->assertRedirect()->assertSessionHasErrors('room');
        $this->assertEquals('night', $room->refresh()->game_state['phase']);
    }

    public function test_cannot_advance_a_room_that_is_not_in_progress(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/advance");

        $response->assertRedirect()->assertSessionHasErrors('room');
    }

    public function test_action_submission_broadcasts_to_both_mafia_and_host_channels(): void
    {
        Event::fake([\App\Events\NightActionUpdated::class, \App\Events\HostNightActionUpdated::class]);

        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => true, 'detective' => false],
            'status' => 'in_progress',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $playerIds = $players->pluck('id')->values();
        $roles = [
            $playerIds[0] => 'mafia',
            $playerIds[1] => 'doctor',
            $playerIds[2] => 'civilian',
            $playerIds[3] => 'civilian',
            $playerIds[4] => 'civilian',
        ];

        $room->update([
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => $roles,
                'alive' => collect($roles)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
                'winner' => null,
                'night_actions' => [
                    'mafia' => ['selections' => [], 'confirmed' => []],
                    'doctor' => ['selections' => [], 'confirmed' => []],
                    'detective' => ['selections' => [], 'confirmed' => [], 'results' => []],
                ],
                // Mafia's turn has already passed for this test — it's
                // specifically exercising the doctor's action.
                'night_step' => 'doctor',
            ],
        ]);

        $doctor = User::find($playerIds[1]);

        $this->actingAs($doctor)->post("/rooms/{$room->id}/actions", [
            'type' => 'doctor_select',
            'target_id' => $playerIds[1],
        ]);

        Event::assertDispatched(\App\Events\NightActionUpdated::class);
        Event::assertDispatched(\App\Events\HostNightActionUpdated::class);
    }

    public function test_cannot_submit_action_before_that_roles_turn(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => true, 'detective' => false],
            'status' => 'in_progress',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $playerIds = $players->pluck('id')->values();
        $roles = [
            $playerIds[0] => 'mafia',
            $playerIds[1] => 'doctor',
            $playerIds[2] => 'civilian',
            $playerIds[3] => 'civilian',
            $playerIds[4] => 'civilian',
        ];

        $room->update([
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => $roles,
                'alive' => collect($roles)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
                'winner' => null,
                'night_actions' => [
                    'mafia' => ['selections' => [], 'confirmed' => []],
                    'doctor' => ['selections' => [], 'confirmed' => []],
                    'detective' => ['selections' => [], 'confirmed' => [], 'results' => []],
                ],
                // Still mafia's turn — doctor should not be able to act yet.
                'night_step' => 'mafia',
            ],
        ]);

        $doctor = User::find($playerIds[1]);

        $response = $this->actingAs($doctor)->post("/rooms/{$room->id}/actions", [
            'type' => 'doctor_select',
            'target_id' => $playerIds[1],
        ]);

        $response->assertRedirect()->assertSessionHasErrors('action');
    }

    public function test_room_status_becomes_finished_when_win_condition_is_met(): void
    {
        Event::fake([\App\Events\GameEnded::class]);

        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => false, 'detective' => false],
            'status' => 'in_progress',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $playerIds = $players->pluck('id')->values();
        $roles = [
            $playerIds[0] => 'mafia',
            $playerIds[1] => 'civilian',
            $playerIds[2] => 'civilian',
            $playerIds[3] => 'civilian',
            $playerIds[4] => 'civilian',
        ];

        $room->update([
            'game_state' => [
                'phase' => 'day',
                'round' => 1,
                'roles' => $roles,
                'alive' => collect($roles)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
                'winner' => null,
                'night_actions' => [
                    'mafia' => ['selections' => [], 'confirmed' => []],
                    'doctor' => ['selections' => [], 'confirmed' => []],
                    'detective' => ['selections' => [], 'confirmed' => [], 'results' => []],
                ],
                'day_votes' => ['selections' => [], 'confirmed' => []],
            ],
        ]);

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/execute", [
            'target_id' => $playerIds[0], // the only mafia
        ]);

        $response->assertRedirect(route('rooms.show', $room));

        $room->refresh();

        $this->assertEquals('finished', $room->status);
        $this->assertEquals('town', $room->game_state['winner']);

        Event::assertDispatched(\App\Events\GameEnded::class);
    }

    public function test_player_can_leave_a_waiting_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->post("/rooms/{$room->id}/leave");

        $response->assertRedirect(route('games.index'));

        $this->assertDatabaseMissing('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_player_cannot_leave_a_room_they_are_not_in(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $outsider = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($outsider)->post("/rooms/{$room->id}/leave");

        $response->assertRedirect()->assertSessionHasErrors('room');
    }

    public function test_player_cannot_leave_a_room_that_is_in_progress(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->post("/rooms/{$room->id}/leave");

        $response->assertRedirect()->assertSessionHasErrors('room');

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_leaving_broadcasts_player_left_event(): void
    {
        Event::fake([\App\Events\PlayerLeft::class]);

        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $this->actingAs($player)->post("/rooms/{$room->id}/leave");

        Event::assertDispatched(\App\Events\PlayerLeft::class, function ($event) use ($room, $player) {
            return $event->room->id === $room->id && $event->player->id === $player->id;
        });
    }

    public function test_show_includes_the_requesting_players_own_role_only(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => false, 'detective' => false],
            'status' => 'in_progress',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $playerIds = $players->pluck('id')->values();
        $roles = [
            $playerIds[0] => 'mafia',
            $playerIds[1] => 'civilian',
            $playerIds[2] => 'civilian',
            $playerIds[3] => 'civilian',
            $playerIds[4] => 'civilian',
        ];

        $room->update([
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => $roles,
                'alive' => collect($roles)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
                'winner' => null,
                'night_actions' => [
                    'mafia' => ['selections' => [], 'confirmed' => []],
                    'doctor' => ['selections' => [], 'confirmed' => []],
                    'detective' => ['selections' => [], 'confirmed' => [], 'results' => []],
                ],
                'day_votes' => ['selections' => [], 'confirmed' => []],
                'night_step' => 'mafia',
            ],
        ]);

        $mafiaPlayer = User::find($playerIds[0]);

        $response = $this->actingAs($mafiaPlayer)->get("/rooms/{$room->code}");

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Show')
                    ->where('room.you.role', 'mafia')
                    ->where('room.you.alive', true)
                    ->where('room.night_step', 'mafia')
                    ->missing('room.roles')
                    ->missing('room.players.0.role')
            );
    }

    public function test_show_returns_null_you_before_game_starts(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->get("/rooms/{$room->code}");

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Show')
                    ->where('room.you', null)
            );
    }

    public function test_find_redirects_to_the_room_when_code_exists(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $seeker = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($seeker)->post('/rooms/find', [
            'code' => 'ABC123',
        ]);

        $response->assertRedirect(route('rooms.show', $room));
    }

    public function test_find_resolves_code_regardless_of_case_and_whitespace(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $seeker = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($seeker)->post('/rooms/find', [
            'code' => '  abc123  ',
        ]);

        $response->assertRedirect(route('rooms.show', $room));
    }

    public function test_find_returns_a_validation_error_for_a_nonexistent_code(): void
    {
        $this->seedMafia();
        $seeker = User::factory()->create();

        $response = $this->actingAs($seeker)->post('/rooms/find', [
            'code' => 'ZZZZZZ',
        ]);

        $response->assertRedirect()
            ->assertSessionHasErrors('code');

        $this->assertNull(Room::first());
    }

    public function test_find_requires_a_code(): void
    {
        $seeker = User::factory()->create();

        $response = $this->actingAs($seeker)->post('/rooms/find', []);

        $response->assertRedirect()
            ->assertSessionHasErrors('code');
    }

    public function test_host_cannot_create_a_room_while_hosting_another_active_room(): void
    {
        $game = $this->seedMafia();
        $user = User::factory()->create();

        Room::create([
            'game_id' => $game->id,
            'host_id' => $user->id,
            'code' => 'AAA111',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($user)->post('/rooms', [
            'game_id' => $game->id,
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 1,
                'doctor' => false,
                'detective' => false,
            ],
        ]);

        $response->assertRedirect()
            ->assertSessionHasErrors('room');

        $this->assertEquals(1, Room::count());
    }

    public function test_user_cannot_create_a_room_while_playing_in_another_active_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $existingRoom = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'AAA111',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
        ]);

        $existingRoom->players()->attach($player->id);

        $response = $this->actingAs($player)->post('/rooms', [
            'game_id' => $game->id,
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 1,
                'doctor' => false,
                'detective' => false,
            ],
        ]);

        $response->assertRedirect()
            ->assertSessionHasErrors('room');

        $this->assertEquals(1, Room::count());
    }

    public function test_user_can_create_a_room_after_their_previous_room_finished(): void
    {
        $game = $this->seedMafia();
        $user = User::factory()->create();

        Room::create([
            'game_id' => $game->id,
            'host_id' => $user->id,
            'code' => 'AAA111',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'finished',
        ]);

        $response = $this->actingAs($user)->post('/rooms', [
            'game_id' => $game->id,
            'max_players' => 10,
            'configuration' => [
                'mafia_count' => 1,
                'doctor' => false,
                'detective' => false,
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors('room');

        $this->assertEquals(2, Room::count());
    }

    public function test_user_cannot_join_a_room_while_playing_in_another_active_room(): void
    {
        $game = $this->seedMafia();
        $hostA = User::factory()->create();
        $hostB = User::factory()->create();
        $player = User::factory()->create();

        $roomA = Room::create([
            'game_id' => $game->id,
            'host_id' => $hostA->id,
            'code' => 'AAA111',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);
        $roomA->players()->attach($player->id);

        $roomB = Room::create([
            'game_id' => $game->id,
            'host_id' => $hostB->id,
            'code' => 'BBB222',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($player)
            ->postJson("/rooms/{$roomB->id}/join");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'You are already in an active room.',
            ]);

        $this->assertDatabaseMissing('room_players', [
            'room_id' => $roomB->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_host_cannot_join_another_room_while_hosting_an_active_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $otherHost = User::factory()->create();

        Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'AAA111',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
        ]);

        $roomB = Room::create([
            'game_id' => $game->id,
            'host_id' => $otherHost->id,
            'code' => 'BBB222',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)
            ->postJson("/rooms/{$roomB->id}/join");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'You are already in an active room.',
            ]);

        $this->assertDatabaseMissing('room_players', [
            'room_id' => $roomB->id,
            'user_id' => $host->id,
        ]);
    }

    public function test_user_can_join_a_room_after_their_previous_room_finished(): void
    {
        $game = $this->seedMafia();
        $previousHost = User::factory()->create();
        $newHost = User::factory()->create();
        $player = User::factory()->create();

        $finishedRoom = Room::create([
            'game_id' => $game->id,
            'host_id' => $previousHost->id,
            'code' => 'AAA111',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'finished',
        ]);
        $finishedRoom->players()->attach($player->id);

        $newRoom = Room::create([
            'game_id' => $game->id,
            'host_id' => $newHost->id,
            'code' => 'BBB222',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($player)
            ->post("/rooms/{$newRoom->id}/join");

        $response->assertRedirect();

        $this->assertDatabaseHas('room_players', [
            'room_id' => $newRoom->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_host_can_kick_a_player_from_a_waiting_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/kick/{$player->id}");

        $response->assertRedirect(route('rooms.show', $room));

        $this->assertDatabaseMissing('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_kick_broadcasts_player_kicked_event(): void
    {
        Event::fake([\App\Events\PlayerKicked::class]);

        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $this->actingAs($host)->post("/rooms/{$room->id}/kick/{$player->id}");

        Event::assertDispatched(\App\Events\PlayerKicked::class, function ($event) use ($room, $player) {
            return $event->room->id === $room->id && $event->player->id === $player->id;
        });
    }

    public function test_non_host_cannot_kick_a_player(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();
        $bystander = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($bystander)
            ->post("/rooms/{$room->id}/kick/{$player->id}");

        $response->assertRedirect()->assertSessionHasErrors('room');

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_cannot_kick_a_player_that_is_not_in_the_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $outsider = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/kick/{$outsider->id}");

        $response->assertRedirect()->assertSessionHasErrors('room');
    }

    #[DataProvider('nonWaitingStatuses')]
    public function test_cannot_kick_a_player_unless_room_is_waiting(string $status): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => $status,
        ]);

        $room->players()->attach($player->id);

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/kick/{$player->id}");

        $response->assertRedirect()->assertSessionHasErrors('room');

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public static function nonWaitingStatuses(): array
    {
        return [
            'in progress' => ['in_progress'],
            'finished' => ['finished'],
            'cancelled' => ['cancelled'],
            'any other unrecognized status' => ['something_else'],
        ];
    }

    public function test_kicked_player_can_rejoin_the_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($player->id);

        $this->actingAs($host)->post("/rooms/{$room->id}/kick/{$player->id}");

        $response = $this->actingAs($player)
            ->post("/rooms/{$room->id}/join");

        $response->assertRedirect(route('rooms.show', $room));

        $this->assertDatabaseHas('room_players', [
            'room_id' => $room->id,
            'user_id' => $player->id,
        ]);
    }

    public function test_mine_returns_the_users_current_active_room_as_host(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)->get('/my-rooms');

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Mine')
                    ->where('active_room.id', $room->id)
                    ->where('active_room.is_host', true)
            );
    }

    public function test_mine_returns_the_users_current_active_room_as_player(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
        ]);
        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->get('/my-rooms');

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Mine')
                    ->where('active_room.id', $room->id)
                    ->where('active_room.is_host', false)
            );
    }

    public function test_mine_returns_null_active_room_when_user_has_none(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/my-rooms');

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Mine')
                    ->where('active_room', null)
            );
    }

    public function test_mine_history_includes_only_finished_rooms_for_the_user(): void
    {
        $game = $this->seedMafia();
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $finishedAsHost = Room::create([
            'game_id' => $game->id,
            'host_id' => $user->id,
            'code' => 'FIN001',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'finished',
        ]);

        $finishedAsPlayer = Room::create([
            'game_id' => $game->id,
            'host_id' => $stranger->id,
            'code' => 'FIN002',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'finished',
        ]);
        $finishedAsPlayer->players()->attach($user->id);

        // Finished, but this user has no relation to it — must not appear.
        Room::create([
            'game_id' => $game->id,
            'host_id' => $stranger->id,
            'code' => 'FIN003',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'finished',
        ]);

        // Still active for someone else entirely — must never appear in
        // anyone's history regardless of relation.
        Room::create([
            'game_id' => $game->id,
            'host_id' => $stranger->id,
            'code' => 'ACT001',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($user)->get('/my-rooms');

        $response->assertOk();

        $historyIds = collect($response->viewData('page')['props']['history']['data'])
            ->pluck('id')
            ->all();

        $this->assertEqualsCanonicalizing(
            [$finishedAsHost->id, $finishedAsPlayer->id],
            $historyIds
        );
    }

    public function test_mine_history_is_paginated(): void
    {
        $game = $this->seedMafia();
        $user = User::factory()->create();

        for ($i = 0; $i < 12; $i++) {
            Room::create([
                'game_id' => $game->id,
                'host_id' => $user->id,
                'code' => 'HIS' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'max_players' => 10,
                'configuration' => [],
                'status' => 'finished',
            ]);
        }

        $response = $this->actingAs($user)->get('/my-rooms');

        $response->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertCount(10, $props['history']['data']);
        $this->assertEquals(1, $props['history']['current_page']);
        $this->assertEquals(2, $props['history']['last_page']);
        $this->assertNotNull($props['history']['next_page_url']);
        $this->assertNull($props['history']['prev_page_url']);
        $this->assertEquals(12, $props['history']['total']);
    }

    public function test_cancel_requires_the_word_confirm(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)->postJson("/rooms/{$room->id}/cancel", [
            'confirmation' => 'yes please',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_cancel_confirmation_is_case_insensitive_and_trims_whitespace(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => '  CONFIRM  ',
        ]);

        $response->assertRedirect(route('games.index'));
        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_host_cancelling_a_waiting_room_deletes_it(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);
        $room->players()->attach($player->id);

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        $response->assertRedirect(route('games.index'));

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseMissing('room_players', ['room_id' => $room->id]);
    }

    public function test_host_cancelling_an_in_progress_room_marks_it_cancelled_and_keeps_it(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => false, 'detective' => false],
            'status' => 'in_progress',
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => [],
                'alive' => [],
                'winner' => null,
                'night_step' => 'mafia',
            ],
        ]);
        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        $response->assertRedirect(route('rooms.show', $room));

        $room->refresh();
        $this->assertEquals('cancelled', $room->status);
        $this->assertNull($room->game_state['winner']);
    }

    public function test_cancel_broadcasts_room_cancelled_event(): void
    {
        Event::fake([\App\Events\RoomCancelled::class]);

        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $this->actingAs($host)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        Event::assertDispatched(\App\Events\RoomCancelled::class, function ($event) use ($room) {
            return $event->room->id === $room->id && $event->deleted === true;
        });
    }

    public function test_non_host_cannot_cancel_while_host_is_active(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
            'host_last_seen_at' => now(),
        ]);
        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        $response->assertRedirect()->assertSessionHasErrors('room');
        $this->assertEquals('in_progress', $room->refresh()->status);
    }

    public function test_non_host_can_cancel_once_host_heartbeat_is_stale(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
            'host_last_seen_at' => now()->subMinutes(5),
        ]);
        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        $response->assertRedirect(route('rooms.show', $room));
        $this->assertEquals('cancelled', $room->refresh()->status);
    }

    public function test_non_host_cannot_stale_cancel_a_waiting_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
            'host_last_seen_at' => now()->subMinutes(10),
        ]);
        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        $response->assertRedirect()->assertSessionHasErrors('room');
        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_cannot_cancel_a_finished_room(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'finished',
        ]);

        $response = $this->actingAs($host)->post("/rooms/{$room->id}/cancel", [
            'confirmation' => 'confirm',
        ]);

        $response->assertRedirect()->assertSessionHasErrors('room');
    }

    public function test_host_heartbeat_updates_last_seen(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
            'host_last_seen_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($host)->post("/rooms/{$room->id}/heartbeat")
            ->assertNoContent();

        $this->assertTrue($room->refresh()->host_last_seen_at->gt(now()->subMinute()));
    }

    public function test_non_host_cannot_send_heartbeat(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $player = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'in_progress',
        ]);
        $room->players()->attach($player->id);

        $response = $this->actingAs($player)->postJson("/rooms/{$room->id}/heartbeat");

        $response->assertStatus(422);
    }

    public function test_heartbeat_rejected_outside_in_progress(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $response = $this->actingAs($host)->postJson("/rooms/{$room->id}/heartbeat");

        $response->assertStatus(422);
    }

    public function test_starting_a_room_seeds_host_last_seen_at(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => false, 'detective' => false],
            'status' => 'waiting',
        ]);
        $room->players()->attach($players->pluck('id')->all());

        $this->actingAs($host)->post("/rooms/{$room->id}/start");

        $this->assertNotNull($room->refresh()->host_last_seen_at);
    }

    public function test_show_marks_role_reveal_visible_for_cancelled_rooms(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(5)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => ['mafia_count' => 1, 'doctor' => false, 'detective' => false],
            'status' => 'cancelled',
        ]);
        $room->players()->attach($players->pluck('id')->all());

        $playerIds = $players->pluck('id')->values();
        $roles = [
            $playerIds[0] => 'mafia',
            $playerIds[1] => 'civilian',
            $playerIds[2] => 'civilian',
            $playerIds[3] => 'civilian',
            $playerIds[4] => 'civilian',
        ];

        $room->update([
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => $roles,
                'alive' => collect($roles)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
                'winner' => null,
            ],
        ]);

        $response = $this->actingAs($players->first())->get("/rooms/{$room->code}");

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Show')
                    ->where('room.role_reveal.' . $playerIds[0], 'mafia')
            );
    }

    public function test_mine_includes_player_count(): void
    {
        $game = $this->seedMafia();
        $host = User::factory()->create();
        $players = User::factory()->count(3)->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 10,
            'configuration' => [],
            'status' => 'waiting',
        ]);
        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)->get('/my-rooms');

        $response->assertOk()
            ->assertInertia(
                fn($page) => $page
                    ->component('Rooms/Mine')
                    ->where('active_room.player_count', 3)
            );
    }
}
