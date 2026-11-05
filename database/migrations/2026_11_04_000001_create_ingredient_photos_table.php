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
        Schema::create('ingredient_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            // IngredientPhotoSource: `commons` for now, `upload` later.
            $table->string('source', 16);
            // For `commons`, the canonical file name without the `File:` prefix.
            $table->string('path');
            // Commons `LicenseShortName`, e.g. `CC BY-SA 4.0`. Required for `commons` by the seeder.
            $table->string('license', 32)->nullable();
            // Commons `LicenseUrl`. Required for CC BY and CC BY-SA by the seeder.
            $table->string('license_url')->nullable();
            // Plain-text credit. `null` only for `CC0` and `Public domain`.
            $table->string('author')->nullable();
            // 1-based display order; 1 is the main photo.
            $table->unsignedTinyInteger('position');
            $table->unique(['ingredient_id', 'position']);
            $table->unique(['ingredient_id', 'source', 'path']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredient_photos');
    }
};
