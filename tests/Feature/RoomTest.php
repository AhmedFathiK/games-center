<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Event;

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
            'configuration' => [],
            'status' => 'waiting',
        ]);

        $room->players()->attach($players->pluck('id')->all());

        $response = $this->actingAs($host)
            ->post("/rooms/{$room->id}/start");

        $response->assertRedirect(route('rooms.show', $room));

        $this->assertEquals('in_progress', $room->refresh()->status);
        $this->assertEquals([
            'phase' => 'setup',
            'round' => 1,
        ], $room->game_state);
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
            'configuration' => [],
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
                    ->component('Games/Index')
                    ->has('games', 1)
                    ->where('games.0.id', $game->id)
                    ->where('games.0.name', 'Mafia')
                    ->where('games.0.slug', 'mafia')
                    ->where('games.0.minimum_players', 5)
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
}
