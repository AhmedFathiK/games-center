<?php

namespace Tests\Feature\Games;

use App\Games\Mafia\MafiaGame;
use App\Models\Game;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MafiaGameTest extends TestCase
{
    use RefreshDatabase;

    protected function makeRoom(array $configuration, int $playerCount): Room
    {
        $game = Game::create([
            'name' => 'Mafia',
            'slug' => 'mafia',
            'enabled' => true,
        ]);

        $host = User::factory()->create();

        $room = Room::create([
            'game_id' => $game->id,
            'host_id' => $host->id,
            'code' => 'ABC123',
            'max_players' => 20,
            'configuration' => $configuration,
            'status' => 'waiting',
        ]);

        $players = User::factory()->count($playerCount)->create();
        $room->players()->attach($players->pluck('id')->all());

        return $room->fresh();
    }

    public function test_assigns_correct_number_of_mafia(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 2,
            'doctor' => false,
            'detective' => false,
        ], 6);

        $state = (new MafiaGame())->initializeState($room);

        $mafiaCount = collect($state['roles'])
            ->filter(fn($role) => $role === 'mafia')
            ->count();

        $this->assertEquals(2, $mafiaCount);
    }

    public function test_assigns_doctor_and_detective_when_enabled(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => true,
            'detective' => true,
        ], 6);

        $state = (new MafiaGame())->initializeState($room);
        $roles = array_values($state['roles']);

        $this->assertContains('doctor', $roles);
        $this->assertContains('detective', $roles);
    }

    public function test_remaining_players_are_civilians(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => true,
            'detective' => false,
        ], 6);

        $state = (new MafiaGame())->initializeState($room);

        $civilianCount = collect($state['roles'])
            ->filter(fn($role) => $role === 'civilian')
            ->count();

        // 6 players - 1 mafia - 1 doctor = 4 civilians
        $this->assertEquals(4, $civilianCount);
    }

    public function test_every_player_starts_alive(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => false,
            'detective' => false,
        ], 5);

        $state = (new MafiaGame())->initializeState($room);

        $this->assertCount(5, $state['alive']);
        $this->assertTrue(collect($state['alive'])->every(fn($alive) => $alive === true));
    }

    public function test_initial_phase_and_round(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => false,
            'detective' => false,
        ], 5);

        $state = (new MafiaGame())->initializeState($room);

        $this->assertEquals('night', $state['phase']);
        $this->assertEquals(1, $state['round']);
        $this->assertNull($state['winner']);
    }

    public function test_validate_start_requires_at_least_one_mafia(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 0,
            'doctor' => false,
            'detective' => false,
        ], 5);

        $errors = (new MafiaGame())->validateStart($room);

        $this->assertNotEmpty($errors);
    }

    public function test_validate_start_rejects_mafia_count_equal_to_player_count(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 5,
            'doctor' => false,
            'detective' => false,
        ], 5);

        $errors = (new MafiaGame())->validateStart($room);

        $this->assertNotEmpty($errors);
    }

    public function test_validate_start_passes_for_valid_configuration(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => true,
            'detective' => true,
        ], 6);

        $errors = (new MafiaGame())->validateStart($room);

        $this->assertEmpty($errors);
    }
}
