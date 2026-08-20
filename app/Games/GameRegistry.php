<?php

namespace App\Games;

use App\Games\Contracts\GameDefinition;
use App\Games\Mafia\MafiaGame;
use InvalidArgumentException;

class GameRegistry
{
    /**
     * @return array<string, class-string<GameDefinition>>
     */
    public static function all(): array
    {
        return [
            'mafia' => MafiaGame::class,
        ];
    }

    public static function get(string $slug): GameDefinition
    {
        $games = self::all();

        if (! isset($games[$slug])) {
            throw new InvalidArgumentException("Unknown game: {$slug}");
        }

        return app($games[$slug]);
    }
}
