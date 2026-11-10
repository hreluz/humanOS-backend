<?php

namespace Tests\Feature\Nutrition;

use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPhoto;
use App\Modules\Nutrition\Models\IngredientPortion;
use App\Modules\Nutrition\Support\IngredientPhotoLicense;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\NutrientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checks the catalog files in `database/data/ingredients` against the rules in `docs/ingredient-catalog.md` (SPEC 15, SPEC 17).
 */
class IngredientCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const SNAKE_CASE = '/^[a-z0-9]+(_[a-z0-9]+)*$/';

    /**
     * @var list<array<string, mixed>>
     */
    private array $ingredients;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ingredients = IngredientSeeder::entries();
    }

    public function test_the_catalog_holds_180_to_230_ingredients(): void
    {
        $this->assertGreaterThanOrEqual(180, count($this->ingredients));
        $this->assertLessThanOrEqual(230, count($this->ingredients));
    }

    public function test_each_file_holds_its_category_sorted_by_name(): void
    {
        foreach (glob(database_path('data/ingredients').'/*.json') as $file) {
            $category = basename($file, '.json');
            $this->assertNotNull(IngredientCategory::tryFrom($category), "Unknown category file {$category}.json");

            $entries = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame([$category], array_values(array_unique(array_column($entries, 'category'))), "Categories in {$category}.json");

            $names = array_column($entries, 'name');
            $sorted = $names;
            sort($sorted, SORT_STRING | SORT_FLAG_CASE);
            $this->assertSame($sorted, $names, "{$category}.json is sorted by name");
        }
    }

    public function test_keys_are_unique_snake_case_and_source_ids_are_unique(): void
    {
        $keys = array_column($this->ingredients, 'key');
        $this->assertSame($keys, array_unique($keys), 'Duplicate keys');
        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression(self::SNAKE_CASE, $key);
            // `u_` is reserved for users' ingredient keys (SPEC 18).
            $this->assertStringStartsNotWith(IngredientSeeder::USER_KEY_PREFIX, $key);
        }

        $sourceIds = array_column($this->ingredients, 'source_id');
        $this->assertCount(count($this->ingredients), $sourceIds, 'Every entry has a source_id');
        $this->assertSame($sourceIds, array_unique($sourceIds), 'Duplicate source ids');
    }

    public function test_every_entry_has_a_usda_source_and_fdc_id(): void
    {
        foreach ($this->ingredients as $entry) {
            $this->assertNotNull(IngredientSource::tryFrom($entry['source']), "Source of {$entry['key']}");
            $this->assertNotSame(IngredientSource::User->value, $entry['source'], "Source of {$entry['key']}");
            $this->assertMatchesRegularExpression('/^\d+$/', (string) $entry['source_id'], "FDC id of {$entry['key']}");
        }
    }

    public function test_portion_keys_are_snake_case_and_unique_within_their_ingredient(): void
    {
        foreach ($this->ingredients as $entry) {
            $keys = array_column($entry['portions'], 'key');
            $this->assertSame($keys, array_unique($keys), "Duplicate portion keys in {$entry['key']}");
            foreach ($keys as $key) {
                $this->assertMatchesRegularExpression(self::SNAKE_CASE, $key, "Portion key in {$entry['key']}");
            }
        }
    }

    public function test_english_and_spanish_names_are_unique(): void
    {
        $english = array_map(mb_strtolower(...), array_column($this->ingredients, 'name'));
        $this->assertSame([], array_keys(array_filter(array_count_values($english), fn (int $count) => $count > 1)), 'Duplicate English names');

        $spanish = array_map(fn (array $entry) => mb_strtolower($entry['translations']['es'] ?? ''), $this->ingredients);
        $this->assertSame([], array_keys(array_filter(array_count_values($spanish), fn (int $count) => $count > 1)), 'Duplicate Spanish names');
    }

    public function test_every_ingredient_and_portion_has_spanish_and_portions_weigh_something(): void
    {
        foreach ($this->ingredients as $entry) {
            $this->assertNotEmpty($entry['translations']['es'] ?? null, "Spanish name of {$entry['key']}");

            foreach ($entry['portions'] as $portion) {
                $this->assertNotEmpty($portion['translations']['es'] ?? null, "Spanish label of {$entry['key']}.{$portion['key']}");
                $this->assertGreaterThan(0, $portion['grams'], "Grams of {$entry['key']}.{$portion['key']}");
            }
        }
    }

    public function test_macros_are_plausible_per_100_g(): void
    {
        foreach ($this->ingredients as $entry) {
            foreach (['kcal', 'protein', 'carbs', 'fat', 'fiber'] as $macro) {
                $this->assertIsNumeric($entry[$macro] ?? null, "{$macro} of {$entry['key']}");
                $this->assertGreaterThanOrEqual(0, $entry[$macro], "{$macro} of {$entry['key']}");
            }

            $this->assertLessThanOrEqual(902, $entry['kcal'], "kcal of {$entry['key']}");
            $this->assertLessThanOrEqual(102, $entry['protein'] + $entry['carbs'] + $entry['fat'], "Macros of {$entry['key']} add up to more than 100 g");
        }
    }

    public function test_nutrient_keys_exist_and_amounts_are_not_negative(): void
    {
        foreach ($this->ingredients as $entry) {
            foreach ($entry['nutrients'] as $key => $amount) {
                $this->assertArrayHasKey($key, NutrientSeeder::NUTRIENTS, "Nutrient {$key} of {$entry['key']}");
                $this->assertGreaterThanOrEqual(0, $amount, "Nutrient {$key} of {$entry['key']}");
            }
        }
    }

    public function test_the_values_fixture_matches_the_catalog(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/ingredient-values.json')), true, flags: JSON_THROW_ON_ERROR);
        $catalog = array_column($this->ingredients, null, 'key');

        foreach ($fixture as $expected) {
            $key = $expected['key'];
            $this->assertArrayHasKey($key, $catalog, "Fixture entry {$key} is in the catalog");
            $entry = $catalog[$key];

            $this->assertSame($expected['source_id'], $entry['source_id'], "FDC id of {$key}");
            foreach (['kcal', 'protein', 'carbs', 'fat', 'fiber'] as $macro) {
                if (array_key_exists($macro, $expected)) {
                    $this->assertMatchesSource($expected[$macro], $entry[$macro], "{$macro} of {$key}");
                }
            }
            foreach ($expected['nutrients'] as $nutrient => $amount) {
                $this->assertArrayHasKey($nutrient, $entry['nutrients'], "{$nutrient} of {$key}");
                $this->assertMatchesSource($amount, $entry['nutrients'][$nutrient], "{$nutrient} of {$key}");
            }
        }
    }

    public function test_the_values_fixture_covers_every_category_that_has_ingredients(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/ingredient-values.json')), true, flags: JSON_THROW_ON_ERROR);
        $categories = array_column($this->ingredients, 'category', 'key');

        $covered = array_unique(array_map(fn (array $expected) => $categories[$expected['key']] ?? null, $fixture));

        $this->assertEqualsCanonicalizing(array_values(array_unique($categories)), array_values(array_filter($covered)));
    }

    /**
     * Rule 10: one to three Commons photos, each with an allowed license and the credit it requires.
     */
    public function test_every_entry_has_one_to_three_photos_with_an_allowed_license_and_credit(): void
    {
        foreach ($this->ingredients as $entry) {
            $key = $entry['key'];
            $photos = $entry['photos'] ?? [];

            $this->assertGreaterThanOrEqual(1, count($photos), "{$key} has no photos");
            $this->assertLessThanOrEqual(IngredientSeeder::MAX_PHOTOS, count($photos), "{$key} has too many photos");

            $files = array_map(fn (array $photo) => IngredientPhoto::canonicalFile($photo['file']), $photos);
            $this->assertSame($files, array_values(array_unique($files)), "{$key} repeats a photo");

            foreach ($photos as $photo) {
                $license = $photo['license'];
                $this->assertTrue(IngredientPhotoLicense::isAllowed($license), "{$key}: {$photo['file']} has a license that isn't allowed [{$license}]");

                if (IngredientPhotoLicense::requiresAttribution($license)) {
                    $this->assertNotEmpty($photo['author'] ?? null, "{$key}: {$photo['file']} needs an author");
                    $this->assertNotEmpty($photo['license_url'] ?? null, "{$key}: {$photo['file']} needs a license_url");
                }
            }
        }
    }

    public function test_the_whole_catalog_seeds_and_a_second_run_keeps_the_ids(): void
    {
        $this->seed([NutrientSeeder::class, IngredientSeeder::class]);
        $ingredients = Ingredient::pluck('id', 'key')->all();
        $portions = IngredientPortion::orderBy('id')->pluck('id')->all();

        $this->seed(IngredientSeeder::class);

        $this->assertCount(count($this->ingredients), $ingredients);
        $this->assertSame($ingredients, Ingredient::pluck('id', 'key')->all());
        $this->assertSame($portions, IngredientPortion::orderBy('id')->pluck('id')->all());
    }

    /**
     * The fixture holds values as FoodData Central's API returns them; the bulk download rounds some Foundation values
     * to three significant figures. A wrong mapping (wrong id or unit) is off by far more than that rounding.
     */
    private function assertMatchesSource(float|int $expected, float|int $actual, string $message): void
    {
        $this->assertEqualsWithDelta($expected, $actual, max(0.01, 0.005 * abs($expected)), $message);
    }
}
