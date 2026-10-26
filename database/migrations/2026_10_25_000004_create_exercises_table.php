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
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->foreignId('equipment_id')->constrained('equipment')->restrictOnDelete();
            $table->string('category');
            $table->string('level')->nullable();
            $table->string('mechanic')->nullable();
            $table->string('force')->nullable();
            $table->string('cue')->nullable();
            $table->json('instructions')->nullable();
            $table->string('source_id', 128)->nullable()->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
