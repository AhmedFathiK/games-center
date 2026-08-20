<?php

namespace Database\Seeders;

use App\Games\GameRegistry;
use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        foreach (GameRegistry::all() as $slug => $gameClass) {
            $game = app($gameClass);

            Game::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $this->nameFromSlug($slug),
                    'description' => null,
                    'enabled' => true,
                ]
            );
        }
    }

    private function nameFromSlug(string $slug): string
    {
        return str($slug)
            ->replace('-', ' ')
            ->title()
            ->toString();
    }
}
