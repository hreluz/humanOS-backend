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
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            // Unique including soft-deleted rows, so a key that comes back restores its old row.
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->string('category')->index();
            // Everything is per 100 g (SPEC 15, "Calculation rules").
            $table->decimal('kcal', 7, 2);
            $table->decimal('protein', 7, 3);
            $table->decimal('carbs', 7, 3);
            $table->decimal('fat', 7, 3);
            $table->decimal('fiber', 7, 3);
            $table->string('source');
            // The FDC id. Unique in the data files, not here: a soft-deleted ingredient keeps its id.
            $table->string('source_id', 32)->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
