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
        Schema::create('ingredient_nutrient', function (Blueprint $table) {
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nutrient_id')->constrained()->cascadeOnDelete();
            // In the nutrient's unit, per 100 g. A missing row means "not measured".
            $table->decimal('amount', 10, 3);
            $table->primary(['ingredient_id', 'nutrient_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_nutrient');
    }
};
