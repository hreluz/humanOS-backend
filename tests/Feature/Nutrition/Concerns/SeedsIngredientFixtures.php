<?php

namespace Tests\Feature\Nutrition\Concerns;

use Database\Seeders\IngredientSeeder;
use Database\Seeders\NutrientSeeder;

/**
 * Seeds small fixture ingredients through `IngredientSeeder`'s directory argument, not the full catalog.
 */
trait SeedsIngredientFixtures
{
    /**
     * @var list<string>
     */
    private array $fixtureDirectories = [];

    protected function tearDownSeedsIngredientFixtures(): void
    {
        foreach ($this->fixtureDirectories as $directory) {
            array_map(unlink(...), glob("{$directory}/*"));
            rmdir($directory);
        }
    }

    /**
     * Seeds the nutrients and the given entries.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    protected function seedIngredients(array $entries): void
    {
        $this->seed(NutrientSeeder::class);

        $directory = sys_get_temp_dir().'/ingredients-'.uniqid();
        mkdir($directory);
        $this->fixtureDirectories[] = $directory;

        file_put_contents("{$directory}/fixtures.json", json_encode($entries));

        (new IngredientSeeder($directory))->run();
    }

    /**
     * A minimal valid entry: no nutrients, portions, translations or aliases unless overridden.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function ingredient(string $key, string $name, array $overrides = []): array
    {
        static $sourceId = 100000;

        return [
            'key' => $key,
            'name' => $name,
            'category' => 'other',
            'source' => 'usda_sr_legacy',
            'source_id' => (string) $sourceId++,
            'kcal' => 50, 'protein' => 1, 'carbs' => 2, 'fat' => 3, 'fiber' => 0,
            'nutrients' => [],
            'portions' => [],
            'translations' => [],
            'aliases' => [],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function egg(array $overrides = []): array
    {
        return $this->ingredient('egg_whole_raw', 'Egg, whole, raw', [
            'category' => 'eggs',
            'source' => 'usda_foundation',
            'source_id' => '748967',
            'kcal' => 148, 'protein' => 12.4, 'carbs' => 0.96, 'fat' => 9.96, 'fiber' => 0,
            // Listed out of `sort_order`, so only the order clause can put them right.
            'nutrients' => ['selenium' => 31.1, 'cholesterol' => 411, 'sugars' => 0],
            'portions' => [
                ['key' => 'large', 'label' => '1 large egg', 'grams' => 50, 'translations' => ['es' => '1 huevo grande']],
                ['key' => 'medium', 'label' => '1 medium egg', 'grams' => 44, 'translations' => ['es' => '1 huevo mediano']],
            ],
            'translations' => ['es' => 'Huevo entero crudo'],
            'aliases' => ['en' => ['hen egg'], 'es' => ['blanquillo']],
            ...$overrides,
        ]);
    }
}
