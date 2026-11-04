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
        Schema::create('ingredient_portion_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_portion_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 8);
            $table->string('label');
            $table->unique(['ingredient_portion_id', 'locale'], 'ingredient_portion_translations_portion_locale_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_portion_translations');
    }
};
