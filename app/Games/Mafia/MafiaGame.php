<?php

namespace App\Games\Mafia;

use App\Games\AbstractGame;
use App\Models\Room;
use App\Models\User;

class MafiaGame extends AbstractGame
{
    public function minimumPlayers(): int
    {
        return 4;
    }

    public function maximumPlayers(): int
    {
        return 20;
    }

    public function hostIsPlayer(): bool
    {
        return false;
    }

    public function configurationSchema(): array
    {
        return [
            'mafia_count' => [
                'type' => 'integer',
                'label' => 'Number of Mafia',
                'min' => 1,
                'default' => 1,
            ],
            'doctor' => [
                'type' => 'boolean',
                'label' => 'Doctor',
            ],
            'detective' => [
                'type' => 'boolean',
                'label' => 'Detective',
            ],
        ];
    }

    public function validateRoomConfiguration(array $configuration, int $maxPlayers): array
    {
        $errors = [];

        $mafiaCount = (int) ($configuration['mafia_count'] ?? 0);
        $doctor = (bool) ($configuration['doctor'] ?? false);
        $detective = (bool) ($configuration['detective'] ?? false);

        if ($mafiaCount < 1) {
            $errors[] = 'At least one Mafia member is required.';
        }

        if ($mafiaCount >= $maxPlayers - $mafiaCount) {
            $errors[] = 'Mafia must be outnumbered by the rest of the town (doctor, detective, and civilians combined).';
        }

        $specialRoleCount = $mafiaCount + ($doctor ? 1 : 0) + ($detective ? 1 : 0);

        if ($specialRoleCount > $maxPlayers) {
            $errors[] = 'There are too many special roles for the chosen maximum players.';
        }

        return $errors;
    }

    public function validateStart(Room $room): array
    {
        $errors = [];

        $playerCount = $room->players()->count();
        $mafiaCount = (int) ($room->configuration['mafia_count'] ?? 0);
        $doctor = (bool) ($room->configuration['doctor'] ?? false);
        $detective = (bool) ($room->configuration['detective'] ?? false);

        if ($mafiaCount < 1) {
            $errors[] = 'At least one Mafia member is required.';
        }

        if ($mafiaCount >= $playerCount - $mafiaCount) {
            $errors[] = 'Mafia must be outnumbered by the rest of the town (doctor, detective, and civilians combined).';
        }

        $specialRoleCount = $mafiaCount + ($doctor ? 1 : 0) + ($detective ? 1 : 0);

        if ($specialRoleCount > $playerCount) {
            $errors[] = 'There are too many special roles for the number of players.';
        }

        return $errors;
    }

    protected function freshDayVotes(): array
    {
        return ['selections' => [], 'confirmed' => []];
    }

    public function initializeState(Room $room): array
    {
        $roles = $this->assignRoles($room);

        return [
            'phase' => 'night',
            'round' => 1,
            'roles' => $roles,
            'alive' => collect($roles)->keys()->mapWithKeys(fn($id) => [$id => true])->all(),
            'winner' => null,
            'night_actions' => $this->freshNightActions(),
            'day_votes' => $this->freshDayVotes(),
            'night_step' => 'mafia',
        ];
    }

    protected function freshNightActions(): array
    {
        return [
            'mafia' => ['selections' => [], 'confirmed' => []],
            'doctor' => ['selections' => [], 'confirmed' => []],
            'detective' => ['selections' => [], 'confirmed' => [], 'results' => []],
        ];
    }

    /**
     * Returns a map of player user ID => role string.
     * Currently always random; a manual assignment mode can override
     * or branch this later without touching initializeState().
     */
    protected function assignRoles(Room $room): array
    {
        $playerIds = $room->players()->pluck('users.id')->shuffle()->values();

        $mafiaCount = (int) ($room->configuration['mafia_count'] ?? 0);
        $doctor = (bool) ($room->configuration['doctor'] ?? false);
        $detective = (bool) ($room->configuration['detective'] ?? false);

        $roles = [];
        $cursor = 0;

        foreach ($playerIds->slice($cursor, $mafiaCount) as $id) {
            $roles[$id] = 'mafia';
        }
        $cursor += $mafiaCount;

        if ($doctor) {
            $roles[$playerIds[$cursor]] = 'doctor';
            $cursor++;
        }

        if ($detective) {
            $roles[$playerIds[$cursor]] = 'detective';
            $cursor++;
        }

        foreach ($playerIds->slice($cursor) as $id) {
            $roles[$id] = 'civilian';
        }

        return $roles;
    }

    public function advancePhase(Room $room): array
    {
        $state = $room->game_state;

        if (($state['winner'] ?? null) !== null) {
            throw new \InvalidArgumentException('The game has already ended.');
        }

        if ($state['phase'] === 'night') {
            $currentStep = $state['night_step'] ?? 'mafia';
            $nextStep = $this->nextNightStep($currentStep, $room->configuration ?? []);

            if ($nextStep !== null) {
                // Hand the turn to the next enabled role. No resolution
                // yet — actions stay gated to whichever role is current.
                $state['night_step'] = $nextStep;
            } else {
                // Every enabled special role has had its turn.
                $state = $this->resolveNightActions($state);
                $state = $this->checkWinCondition($state);
                $state['phase'] = 'day';
                $state['night_step'] = null;
                $state['day_votes'] = $this->freshDayVotes();
            }
        } else {
            $state['phase'] = 'night';
            $state['round']++;
            $state['night_actions'] = $this->freshNightActions();
            $state['night_step'] = 'mafia';
        }

        return $state;
    }

    /**
     * Returns the next role whose turn it is during the night, skipping
     * any role not enabled in this room's configuration. Mafia is always
     * enabled (validateRoomConfiguration requires at least one). Returns
     * null once there's no enabled role left to act — the caller resolves
     * the night and moves to day at that point.
     */
    protected function nextNightStep(string $currentStep, array $configuration): ?string
    {
        $order = ['mafia', 'doctor', 'detective'];

        $enabled = [
            'mafia' => true,
            'doctor' => (bool) ($configuration['doctor'] ?? false),
            'detective' => (bool) ($configuration['detective'] ?? false),
        ];

        $index = array_search($currentStep, $order, true);
        $index = $index === false ? -1 : $index;

        for ($i = $index + 1; $i < count($order); $i++) {
            if ($enabled[$order[$i]]) {
                return $order[$i];
            }
        }

        return null;
    }

    protected function resolveNightActions(array $state): array
    {
        $mafia = $state['night_actions']['mafia'] ?? ['selections' => [], 'confirmed' => []];
        $doctor = $state['night_actions']['doctor'] ?? ['selections' => [], 'confirmed' => []];

        $mafiaIds = collect($state['roles'])->filter(fn($role) => $role === 'mafia')->keys();

        $allMafiaConfirmed = $mafiaIds->isNotEmpty()
            && $mafiaIds->every(fn($id) => $mafia['confirmed'][$id] ?? false);

        $distinctTargets = collect($mafia['selections'])
            ->only($mafiaIds->all())
            ->unique();

        if ($allMafiaConfirmed && $distinctTargets->count() === 1) {
            $victimId = $distinctTargets->first();

            $doctorIds = collect($state['roles'])->filter(fn($role) => $role === 'doctor')->keys();
            $savedId = null;

            foreach ($doctorIds as $doctorId) {
                if ($doctor['confirmed'][$doctorId] ?? false) {
                    $savedId = $doctor['selections'][$doctorId] ?? null;
                }
            }

            if ($savedId !== $victimId) {
                $state['alive'][$victimId] = false;
            }
        }

        return $state;
    }

    public function submitAction(Room $room, User $user, array $payload): array
    {
        $state = $room->game_state;

        if (($state['winner'] ?? null) !== null) {
            throw new \InvalidArgumentException('The game has already ended.');
        }

        if (! ($state['alive'][$user->id] ?? false)) {
            throw new \InvalidArgumentException('Dead players cannot act.');
        }

        $type = $payload['type'] ?? null;

        if (in_array($type, ['vote_select', 'vote_confirm'], true)) {
            if (($state['phase'] ?? null) !== 'day') {
                throw new \InvalidArgumentException('Voting can only happen during the day phase.');
            }

            return match ($type) {
                'vote_select' => $this->handleVoteSelect($state, $user, $payload),
                'vote_confirm' => $this->handleVoteConfirm($state, $user),
            };
        }

        if (($state['phase'] ?? null) !== 'night') {
            throw new \InvalidArgumentException('Actions can only be submitted during the night phase.');
        }

        $role = $state['roles'][$user->id] ?? null;

        return match ($type) {
            'mafia_select' => $this->handleSelect($state, 'mafia', $user, $role, $payload),
            'mafia_confirm' => $this->handleConfirm($state, 'mafia', $user, $role),
            'doctor_select' => $this->handleSelect($state, 'doctor', $user, $role, $payload),
            'doctor_confirm' => $this->handleConfirm($state, 'doctor', $user, $role),
            'detective_select' => $this->handleSelect($state, 'detective', $user, $role, $payload),
            'detective_confirm' => $this->handleDetectiveConfirm($state, $user, $role),
            default => throw new \InvalidArgumentException('Unknown action type.'),
        };
    }

    protected function handleSelect(array $state, string $roleKey, User $user, ?string $role, array $payload): array
    {
        if ($role !== $roleKey) {
            throw new \InvalidArgumentException("Only the {$roleKey} can submit this action.");
        }

        if (($state['night_step'] ?? null) !== $roleKey) {
            throw new \InvalidArgumentException("It is not the {$roleKey}'s turn yet.");
        }

        if ($state['night_actions'][$roleKey]['confirmed'][$user->id] ?? false) {
            throw new \InvalidArgumentException('Your selection is already confirmed and cannot be changed.');
        }

        $targetId = (string) ($payload['target_id'] ?? '');

        if (! ($state['alive'][$targetId] ?? false)) {
            throw new \InvalidArgumentException('Target must be an alive player.');
        }

        $state['night_actions'][$roleKey]['selections'][$user->id] = $targetId;

        return $state;
    }

    protected function handleConfirm(array $state, string $roleKey, User $user, ?string $role): array
    {
        if ($role !== $roleKey) {
            throw new \InvalidArgumentException("Only the {$roleKey} can submit this action.");
        }

        if (($state['night_step'] ?? null) !== $roleKey) {
            throw new \InvalidArgumentException("It is not the {$roleKey}'s turn yet.");
        }

        if (! isset($state['night_actions'][$roleKey]['selections'][$user->id])) {
            throw new \InvalidArgumentException('Select a target before confirming.');
        }

        $state['night_actions'][$roleKey]['confirmed'][$user->id] = true;

        return $state;
    }

    protected function handleDetectiveConfirm(array $state, User $user, ?string $role): array
    {
        $state = $this->handleConfirm($state, 'detective', $user, $role);

        $targetId = $state['night_actions']['detective']['selections'][$user->id];
        $isMafia = ($state['roles'][$targetId] ?? null) === 'mafia';

        $state['night_actions']['detective']['results'][$user->id] = [
            'target_id' => $targetId,
            'is_mafia' => $isMafia,
        ];

        return $state;
    }

    protected function handleVoteSelect(array $state, User $user, array $payload): array
    {
        if ($state['day_votes']['confirmed'][$user->id] ?? false) {
            throw new \InvalidArgumentException('Your vote is already confirmed and cannot be changed.');
        }

        $targetId = (string) ($payload['target_id'] ?? '');

        if (! ($state['alive'][$targetId] ?? false)) {
            throw new \InvalidArgumentException('Target must be an alive player.');
        }

        $state['day_votes']['selections'][$user->id] = $targetId;

        return $state;
    }

    protected function handleVoteConfirm(array $state, User $user): array
    {
        if (! isset($state['day_votes']['selections'][$user->id])) {
            throw new \InvalidArgumentException('Select a target before confirming.');
        }

        $state['day_votes']['confirmed'][$user->id] = true;

        return $state;
    }

    public function executePlayer(Room $room, ?string $targetId): array
    {
        $state = $room->game_state;

        if (($state['winner'] ?? null) !== null) {
            throw new \InvalidArgumentException('The game has already ended.');
        }

        if (($state['phase'] ?? null) !== 'day') {
            throw new \InvalidArgumentException('Players can only be executed during the day phase.');
        }

        if ($targetId !== null) {
            if (! ($state['alive'][$targetId] ?? false)) {
                throw new \InvalidArgumentException('Target must be an alive player.');
            }

            $state['alive'][$targetId] = false;
        }

        return $this->checkWinCondition($state);
    }

    protected function checkWinCondition(array $state): array
    {
        if (($state['winner'] ?? null) !== null) {
            return $state;
        }

        $aliveRoles = collect($state['alive'])
            ->filter(fn($alive) => $alive)
            ->keys()
            ->map(fn($id) => $state['roles'][$id] ?? null);

        $mafiaAlive = $aliveRoles->filter(fn($role) => $role === 'mafia')->count();
        $townAlive = $aliveRoles->filter(fn($role) => $role !== 'mafia')->count();

        if ($mafiaAlive === 0) {
            $state['winner'] = 'town';
        } elseif ($mafiaAlive >= $townAlive) {
            $state['winner'] = 'mafia';
        }

        return $state;
    }
}
