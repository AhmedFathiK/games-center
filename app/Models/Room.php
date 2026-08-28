<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'host_id',
        'code',
        'max_players',
        'configuration',
        'game_state',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'game_state' => 'array',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'room_players')
            ->withTimestamps();
    }

    public function isWaiting(): bool
    {
        return $this->status === 'waiting';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isFinished(): bool
    {
        return $this->status === 'finished';
    }

    /**
     * Rooms that count as "in use" for the one-active-room-per-user rule.
     * A finished room (and, later, a cancelled one) no longer occupies a
     * user's single active-room slot.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['waiting', 'in_progress']);
    }

    /**
     * Rooms where the given user is either the host or a joined player.
     * Shared by activeFor() (current-room lookup) and the My Rooms
     * history query, so the "am I involved in this room" definition
     * lives in exactly one place.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('host_id', $userId)
                ->orWhereHas('players', fn(Builder $p) => $p->where('users.id', $userId));
        });
    }

    /**
     * The active room (if any) where the given user is either the host
     * or a joined player. A user may only be host/player of one active
     * room at a time — used to block both room creation and joining a
     * second room while already committed to one.
     */
    public static function activeFor(int $userId): ?self
    {
        return static::active()->forUser($userId)->first();
    }
}
