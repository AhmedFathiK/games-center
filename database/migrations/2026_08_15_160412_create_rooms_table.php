<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('game_id')
                ->constrained('games')
                ->cascadeOnDelete();

            $table->foreignId('host_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('code', 8)->unique();

            $table->unsignedInteger('max_players');

            $table->json('configuration')->nullable();

            $table->string('status')->default('waiting');

            $table->timestamps();

            $table->index(['game_id', 'status']);
            $table->index(['host_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
