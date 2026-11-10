<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            // `null` means "not on the label", never `0` (SPEC 18). The seeder still requires it for catalog entries.
            $table->decimal('fiber', 7, 3)->nullable()->change();
            // `null` for users' ingredients, which have no source id (SPEC 18).
            $table->string('source_id', 32)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('ingredients')->whereNull('fiber')->update(['fiber' => 0]);
        DB::table('ingredients')->whereNull('source_id')->update(['source_id' => '']);

        Schema::table('ingredients', function (Blueprint $table) {
            $table->decimal('fiber', 7, 3)->nullable(false)->change();
            $table->string('source_id', 32)->nullable(false)->change();
        });
    }
};
