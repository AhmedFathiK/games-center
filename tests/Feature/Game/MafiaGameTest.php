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

    public function test_initial_phase_round_and_night_step(): void
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
        $this->assertEquals('mafia', $state['night_step']);
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

    public function test_validate_start_rejects_mafia_not_outnumbered_by_town(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 3,
            'doctor' => false,
            'detective' => false,
        ], 6);

        // 3 mafia vs 3 town — mafia is not outnumbered, must be rejected
        // even though it technically leaves "room" for non-mafia players.
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

    public function test_validate_room_configuration_rejects_mafia_not_outnumbered(): void
    {
        $errors = (new MafiaGame())->validateRoomConfiguration([
            'mafia_count' => 16,
            'doctor' => false,
            'detective' => false,
        ], 20);

        $this->assertNotEmpty($errors);
    }

    public function test_validate_room_configuration_passes_for_valid_configuration(): void
    {
        $errors = (new MafiaGame())->validateRoomConfiguration([
            'mafia_count' => 2,
            'doctor' => true,
            'detective' => true,
        ], 10);

        $this->assertEmpty($errors);
    }

    public function test_advance_phase_moves_from_night_to_day_without_incrementing_round(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => false,
            'detective' => false,
        ], 5);

        $room->update([
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

        $newState = (new MafiaGame())->advancePhase($room->fresh());

        $this->assertEquals('day', $newState['phase']);
        $this->assertEquals(1, $newState['round']);
        $this->assertNull($newState['night_step']);
    }

    public function test_advance_phase_moves_from_day_to_night_and_increments_round(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => false,
            'detective' => false,
        ], 5);

        $room->update([
            'status' => 'in_progress',
            'game_state' => ['phase' => 'day', 'round' => 1, 'roles' => [], 'alive' => [], 'winner' => null],
        ]);

        $newState = (new MafiaGame())->advancePhase($room->fresh());

        $this->assertEquals('night', $newState['phase']);
        $this->assertEquals(2, $newState['round']);
        $this->assertEquals('mafia', $newState['night_step']);
    }

    public function test_advance_phase_steps_from_mafia_to_doctor_when_doctor_enabled(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => true,
            'detective' => false,
        ], 5);

        $room->update([
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

        $newState = (new MafiaGame())->advancePhase($room->fresh());

        // Doctor is enabled, so the night is not resolved yet — the turn
        // simply passes to the doctor.
        $this->assertEquals('night', $newState['phase']);
        $this->assertEquals('doctor', $newState['night_step']);
    }

    public function test_advance_phase_skips_disabled_roles(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => false,
            'detective' => true,
        ], 5);

        $room->update([
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

        $newState = (new MafiaGame())->advancePhase($room->fresh());

        // Doctor is disabled, so mafia's turn skips straight to detective.
        $this->assertEquals('night', $newState['phase']);
        $this->assertEquals('detective', $newState['night_step']);
    }

    public function test_advance_phase_resolves_night_only_after_last_enabled_step(): void
    {
        $room = $this->makeRoom([
            'mafia_count' => 1,
            'doctor' => true,
            'detective' => false,
        ], 5);

        $room->update([
            'status' => 'in_progress',
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => [],
                'alive' => [],
                'winner' => null,
                'night_step' => 'doctor',
            ],
        ]);

        $newState = (new MafiaGame())->advancePhase($room->fresh());

        // Doctor was the last enabled role — night resolves, moves to day.
        $this->assertEquals('day', $newState['phase']);
        $this->assertNull($newState['night_step']);
    }

    protected function makeInProgressRoom(array $roles): Room
    {
        $room = $this->makeRoom([
            'mafia_count' => collect($roles)->filter(fn($r) => $r === 'mafia')->count(),
            'doctor' => in_array('doctor', $roles, true),
            'detective' => in_array('detective', $roles, true),
        ], count($roles));

        $playerIds = $room->players()->pluck('users.id')->values();
        $rolesById = $playerIds->mapWithKeys(fn($id, $i) => [$id => array_values($roles)[$i]])->all();

        $room->update([
            'status' => 'in_progress',
            'game_state' => [
                'phase' => 'night',
                'round' => 1,
                'roles' => $rolesById,
                'alive' => collect($rolesById)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
                'winner' => null,
                'night_actions' => ['mafia' => ['selections' => [], 'confirmed' => []]],
                'night_step' => 'mafia',
            ],
        ]);

        return $room->fresh();
    }

    /**
     * Simulates the host manually advancing the night turn to $step,
     * the way advancePhase() would once the previous role is done —
     * used by tests that exercise a later role in isolation.
     */
    protected function setNightStep(Room $room, string $step): Room
    {
        $state = $room->game_state;
        $state['night_step'] = $step;
        $room->update(['game_state' => $state]);

        return $room->fresh();
    }

    public function test_kill_applies_when_all_mafia_confirm_the_same_target(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'mafia', 'civilian', 'civilian', 'civilian']);
        $mafiaIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys();
        $victimId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $game = new MafiaGame();
        $state = $room->game_state;

        foreach ($mafiaIds as $mafiaId) {
            $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_select', 'target_id' => $victimId]);
            $room->update(['game_state' => $state]);
            $room->refresh();
        }

        foreach ($mafiaIds as $mafiaId) {
            $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_confirm']);
            $room->update(['game_state' => $state]);
            $room->refresh();
        }

        $newState = $game->advancePhase($room);

        $this->assertFalse($newState['alive'][$victimId]);
    }

    public function test_kill_does_not_apply_when_mafia_disagree(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'mafia', 'civilian', 'civilian', 'civilian']);
        $mafiaIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->values();
        $civilianIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->values();

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($mafiaIds[0]), ['type' => 'mafia_select', 'target_id' => $civilianIds[0]]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($mafiaIds[0]), ['type' => 'mafia_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $state = $game->submitAction($room, User::find($mafiaIds[1]), ['type' => 'mafia_select', 'target_id' => $civilianIds[1]]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($mafiaIds[1]), ['type' => 'mafia_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $newState = $game->advancePhase($room);

        $this->assertTrue($newState['alive'][$civilianIds[0]]);
        $this->assertTrue($newState['alive'][$civilianIds[1]]);
    }

    public function test_kill_does_not_apply_when_not_all_mafia_confirmed(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'mafia', 'civilian', 'civilian', 'civilian']);
        $mafiaIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->values();
        $victimId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($mafiaIds[0]), ['type' => 'mafia_select', 'target_id' => $victimId]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($mafiaIds[0]), ['type' => 'mafia_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        // second mafia selects but never confirms
        $state = $game->submitAction($room, User::find($mafiaIds[1]), ['type' => 'mafia_select', 'target_id' => $victimId]);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $newState = $game->advancePhase($room);

        $this->assertTrue($newState['alive'][$victimId]);
    }

    public function test_cannot_change_selection_after_confirming(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();
        $civilianIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->values();

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_select', 'target_id' => $civilianIds[0]]);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_select', 'target_id' => $civilianIds[1]]);
    }

    public function test_non_mafia_cannot_select_a_target(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $civilianId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->submitAction($room, User::find($civilianId), [
            'type' => 'mafia_select',
            'target_id' => $civilianId,
        ]);
    }

    public function test_dead_mafia_cannot_act(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();

        $state = $room->game_state;
        $state['alive'][$mafiaId] = false;
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->submitAction($room, User::find($mafiaId), [
            'type' => 'mafia_select',
            'target_id' => $mafiaId,
        ]);
    }

    public function test_cannot_submit_doctor_action_before_doctor_turn(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'doctor', 'civilian', 'civilian', 'civilian']);
        $doctorId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'doctor')->keys()->first();

        // Room is still on the mafia turn (default from the fixture).
        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->submitAction($room, User::find($doctorId), [
            'type' => 'doctor_select',
            'target_id' => $doctorId,
        ]);
    }

    public function test_doctor_save_prevents_mafia_kill_when_confirmed(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'doctor', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();
        $doctorId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'doctor')->keys()->first();
        $victimId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_select', 'target_id' => $victimId]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        // Host advances the turn from mafia to doctor.
        $room = $this->setNightStep($room, 'doctor');

        $state = $game->submitAction($room, User::find($doctorId), ['type' => 'doctor_select', 'target_id' => $victimId]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($doctorId), ['type' => 'doctor_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $newState = $game->advancePhase($room);

        $this->assertTrue($newState['alive'][$victimId]);
    }

    public function test_doctor_can_save_self(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'doctor', 'civilian', 'civilian', 'civilian']);
        $doctorId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'doctor')->keys()->first();

        $room = $this->setNightStep($room, 'doctor');

        $state = (new MafiaGame())->submitAction($room, User::find($doctorId), [
            'type' => 'doctor_select',
            'target_id' => $doctorId,
        ]);

        $this->assertEquals($doctorId, $state['night_actions']['doctor']['selections'][$doctorId]);
    }

    public function test_unconfirmed_doctor_save_does_not_prevent_kill(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'doctor', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();
        $doctorId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'doctor')->keys()->first();
        $victimId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_select', 'target_id' => $victimId]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($mafiaId), ['type' => 'mafia_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $room = $this->setNightStep($room, 'doctor');

        // doctor selects but never confirms
        $state = $game->submitAction($room, User::find($doctorId), ['type' => 'doctor_select', 'target_id' => $victimId]);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $newState = $game->advancePhase($room);

        $this->assertFalse($newState['alive'][$victimId]);
    }

    public function test_detective_check_reveals_mafia_membership(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'detective', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();
        $detectiveId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'detective')->keys()->first();

        $room = $this->setNightStep($room, 'detective');

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($detectiveId), ['type' => 'detective_select', 'target_id' => $mafiaId]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($detectiveId), ['type' => 'detective_confirm']);

        $this->assertTrue($state['night_actions']['detective']['results'][$detectiveId]['is_mafia']);
    }

    public function test_detective_check_on_non_mafia_returns_false(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'detective', 'civilian', 'civilian', 'civilian']);
        $detectiveId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'detective')->keys()->first();
        $civilianId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $room = $this->setNightStep($room, 'detective');

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($detectiveId), ['type' => 'detective_select', 'target_id' => $civilianId]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($detectiveId), ['type' => 'detective_confirm']);

        $this->assertFalse($state['night_actions']['detective']['results'][$detectiveId]['is_mafia']);
    }

    public function test_cannot_change_doctor_selection_after_confirming(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'doctor', 'civilian', 'civilian', 'civilian']);
        $doctorId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'doctor')->keys()->first();
        $civilianIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->values();

        $room = $this->setNightStep($room, 'doctor');

        $game = new MafiaGame();

        $state = $game->submitAction($room, User::find($doctorId), ['type' => 'doctor_select', 'target_id' => $civilianIds[0]]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($doctorId), ['type' => 'doctor_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        $game->submitAction($room, User::find($doctorId), ['type' => 'doctor_select', 'target_id' => $civilianIds[1]]);
    }

    public function test_non_detective_cannot_submit_detective_action(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'detective', 'civilian', 'civilian', 'civilian']);
        $civilianId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->first();

        $room = $this->setNightStep($room, 'detective');

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->submitAction($room, User::find($civilianId), [
            'type' => 'detective_select',
            'target_id' => $civilianId,
        ]);
    }

    public function test_alive_player_can_vote_for_another_alive_player(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $ids = collect($room->game_state['roles'])->keys()->values();

        $room->update(['game_state' => array_merge($room->game_state, ['phase' => 'day'])]);
        $room->refresh();

        $state = (new MafiaGame())->submitAction($room, User::find($ids[0]), [
            'type' => 'vote_select',
            'target_id' => $ids[1],
        ]);

        $this->assertEquals($ids[1], $state['day_votes']['selections'][$ids[0]]);
    }

    public function test_cannot_change_vote_after_confirming(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $ids = collect($room->game_state['roles'])->keys()->values();

        $room->update(['game_state' => array_merge($room->game_state, ['phase' => 'day'])]);
        $room->refresh();

        $game = new MafiaGame();
        $state = $game->submitAction($room, User::find($ids[0]), ['type' => 'vote_select', 'target_id' => $ids[1]]);
        $room->update(['game_state' => $state]);
        $room->refresh();
        $state = $game->submitAction($room, User::find($ids[0]), ['type' => 'vote_confirm']);
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        $game->submitAction($room, User::find($ids[0]), ['type' => 'vote_select', 'target_id' => $ids[2]]);
    }

    public function test_cannot_vote_outside_day_phase(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $ids = collect($room->game_state['roles'])->keys()->values();

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->submitAction($room, User::find($ids[0]), [
            'type' => 'vote_select',
            'target_id' => $ids[1],
        ]);
    }

    public function test_host_can_execute_a_player_regardless_of_votes(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $ids = collect($room->game_state['roles'])->keys()->values();

        $room->update(['game_state' => array_merge($room->game_state, ['phase' => 'day'])]);
        $room->refresh();

        $newState = (new MafiaGame())->executePlayer($room, $ids[3]);

        $this->assertFalse($newState['alive'][$ids[3]]);
    }

    public function test_host_can_skip_execution(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $room->update(['game_state' => array_merge($room->game_state, ['phase' => 'day'])]);
        $room->refresh();

        $newState = (new MafiaGame())->executePlayer($room, null);

        $this->assertTrue(collect($newState['alive'])->every(fn($alive) => $alive === true));
    }

    public function test_day_votes_reset_when_new_day_begins(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $ids = collect($room->game_state['roles'])->keys()->values();

        $state = $room->game_state;
        $state['day_votes']['selections'][$ids[0]] = $ids[1];
        $room->update(['game_state' => $state]);
        $room->refresh();

        $newState = (new MafiaGame())->advancePhase($room);

        $this->assertEmpty($newState['day_votes']['selections']);
    }

    public function test_town_wins_when_last_mafia_is_executed(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();

        $room->update(['game_state' => array_merge($room->game_state, ['phase' => 'day'])]);
        $room->refresh();

        $newState = (new MafiaGame())->executePlayer($room, $mafiaId);

        $this->assertEquals('town', $newState['winner']);
    }

    public function test_mafia_wins_when_mafia_count_reaches_town_count(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'mafia', 'civilian', 'civilian']);
        $civilianIds = collect($room->game_state['roles'])->filter(fn($r) => $r === 'civilian')->keys()->values();

        $state = $room->game_state;
        $state['alive'][$civilianIds[0]] = false;
        $state['phase'] = 'day';
        $room->update(['game_state' => $state]);
        $room->refresh();

        // triggers checkWinCondition without changing alive further
        $newState = (new MafiaGame())->executePlayer($room, null);

        $this->assertEquals('mafia', $newState['winner']);
    }

    public function test_cannot_submit_action_after_game_has_ended(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);
        $mafiaId = collect($room->game_state['roles'])->filter(fn($r) => $r === 'mafia')->keys()->first();

        $state = $room->game_state;
        $state['winner'] = 'town';
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->submitAction($room, User::find($mafiaId), [
            'type' => 'mafia_select',
            'target_id' => $mafiaId,
        ]);
    }

    public function test_cannot_advance_phase_after_game_has_ended(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);

        $state = $room->game_state;
        $state['winner'] = 'town';
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->advancePhase($room);
    }

    public function test_cannot_execute_after_game_has_ended(): void
    {
        $room = $this->makeInProgressRoom(['mafia', 'civilian', 'civilian', 'civilian', 'civilian']);

        $state = $room->game_state;
        $state['winner'] = 'town';
        $state['phase'] = 'day';
        $room->update(['game_state' => $state]);
        $room->refresh();

        $this->expectException(\InvalidArgumentException::class);

        (new MafiaGame())->executePlayer($room, null);
    }
}
