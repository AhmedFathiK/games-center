<?php

namespace App\Models;

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
}
