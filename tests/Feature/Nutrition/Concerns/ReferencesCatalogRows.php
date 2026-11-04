<?php

namespace Tests\Feature\Nutrition\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A stand-in for a later spec's table (meal logs, recipes) that references catalog rows with `ON DELETE RESTRICT`.
 *
 * MySQL commits any open transaction on DDL, so the table is created before `RefreshDatabase` opens the test's
 * transaction: this hook migrates first if nothing has yet. Rows the tests insert are rolled back as usual.
 * Use it instead of `RefreshDatabase`, which it includes.
 */
trait ReferencesCatalogRows
{
    use RefreshDatabase;

    protected const REFERENCES_TABLE = 'test_catalog_references';

    protected function beforeRefreshingDatabase(): void
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->migrateDatabases();
            $this->app->make(Kernel::class)->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }

        if (! Schema::hasTable(self::REFERENCES_TABLE)) {
            Schema::create(self::REFERENCES_TABLE, function (Blueprint $table) {
                $table->id();
                $table->foreignId('nutrient_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('ingredient_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('ingredient_portion_id')->nullable()->constrained()->restrictOnDelete();
            });
        }
    }

    /**
     * Points a reference at a catalog row, e.g. `['nutrient_id' => 3]`, and returns the reference's id.
     *
     * @param  array<string, int>  $column
     */
    protected function reference(array $column): int
    {
        return DB::table(self::REFERENCES_TABLE)->insertGetId($column);
    }

    protected function dropReference(int $id): void
    {
        DB::table(self::REFERENCES_TABLE)->delete($id);
    }
}
