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
        Schema::create('ingredient_portions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->decimal('grams', 7, 2);
            $table->unsignedSmallInteger('position');
            $table->softDeletes();
            // Unique including soft-deleted rows. Position isn't unique: a soft-deleted portion keeps its old one.
            $table->unique(['ingredient_id', 'key']);
            $table->index(['ingredient_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_portions');
    }
};
