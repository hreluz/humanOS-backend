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
        Schema::create('nutrient_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nutrient_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('label');
            $table->unique(['nutrient_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nutrient_translations');
    }
};
