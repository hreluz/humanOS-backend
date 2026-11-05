<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The SPEC 18 migration that makes `ingredients.fiber` and `ingredients.source_id` nullable.
 *
 * MySQL commits any open transaction on DDL, so this test doesn't use `RefreshDatabase`'s transaction: it runs
 * the migration's `down()` and `up()` on the migrated database and deletes the rows it wrote.
 */
class UserIngredientsMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_11_05_000000_allow_user_ingredients_on_ingredients_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh');
            $this->app->make(Kernel::class)->setArtisan(null);
            RefreshDatabaseState::$migrated = true;
        }
    }

    public function test_migrate_makes_both_columns_nullable_and_rollback_restores_not_null(): void
    {
        $this->assertSame(['fiber' => true, 'source_id' => true], $this->nullability());

        $user = User::factory()->create();
        $id = DB::table('ingredients')->insertGetId([
            'user_id' => $user->id, 'key' => 'u_migrationtest', 'name' => 'Label food', 'category' => 'other',
            'kcal' => 100, 'protein' => 1, 'carbs' => 2, 'fat' => 3, 'fiber' => null,
            'source' => 'user', 'source_id' => null,
        ]);

        $migration = $this->migration();

        try {
            $migration->down();

            $this->assertSame(['fiber' => false, 'source_id' => false], $this->nullability());
            $row = DB::table('ingredients')->find($id);
            $this->assertEquals(0, $row->fiber);
            $this->assertSame('', $row->source_id);
        } finally {
            $migration->up();
            DB::table('ingredients')->delete($id);
            $user->delete();
        }

        $this->assertSame(['fiber' => true, 'source_id' => true], $this->nullability());
    }

    /**
     * @return array<string, bool>
     */
    private function nullability(): array
    {
        return collect(Schema::getColumns('ingredients'))
            ->whereIn('name', ['fiber', 'source_id'])
            ->mapWithKeys(fn (array $column) => [$column['name'] => $column['nullable']])
            ->sortKeys()
            ->all();
    }

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION);
    }
}
