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
        Schema::create('workout_location_exercise', function (Blueprint $table) {
            $table->foreignId('workout_location_id')->constrained()->cascadeOnDelete();
            // RESTRICT: an exercise a location uses can't be hard-deleted (CLAUDE.md, "Referencing the catalog").
            $table->foreignId('exercise_id')->constrained()->restrictOnDelete();
            $table->primary(['workout_location_id', 'exercise_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workout_location_exercise');
    }
};
