<?php

namespace App\Http\Controllers;

use App\Games\GameRegistry;
use App\Models\Game;
use Inertia\Inertia;

class GameController extends Controller
{
    public function index()
    {
        $games = Game::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get()
            ->map(function (Game $game) {
                $definition = GameRegistry::get($game->slug);

                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'slug' => $game->slug,
                    'description' => $game->description,
                    'minimum_players' => $definition->minimumPlayers(),
                    'maximum_players' => $definition->maximumPlayers(),
                    'host_is_player' => $definition->hostIsPlayer(),
                    'configuration_schema' => $definition->configurationSchema(),
                ];
            });

        return Inertia::render('Games/Index', [
            'games' => $games,
        ]);
    }
}
