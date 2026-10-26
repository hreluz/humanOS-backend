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
        Schema::create('exercise_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercise_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->string('path');
            $table->string('caption');
            $table->unsignedSmallInteger('position');
            $table->string('license', 32);
            $table->string('author')->nullable();
            $table->string('credit_url')->nullable();
            $table->unique(['exercise_id', 'position']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercise_photos');
    }
};
