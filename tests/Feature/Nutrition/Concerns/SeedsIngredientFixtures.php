<?php

namespace Tests\Feature\Nutrition\Concerns;

use App\Models\User;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPhoto;
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
     * A user's ingredient written directly to the database, with its portions in the order given.
     *
     * @param  array<string, mixed>  $overrides  Column values; `portions` is a list of `[key, label, grams]`.
     */
    protected function userIngredient(User $owner, string $name, array $overrides = []): Ingredient
    {
        $portions = $overrides['portions'] ?? [];
        unset($overrides['portions']);

        $ingredient = Ingredient::create([
            'user_id' => $owner->id,
            'key' => Ingredient::generateUserKey(),
            'name' => $name,
            'category' => 'supplements',
            'kcal' => 380, 'protein' => 33, 'carbs' => 38, 'fat' => 11, 'fiber' => null,
            'source' => 'user',
            'source_id' => null,
            ...$overrides,
        ]);

        foreach (array_values($portions) as $i => [$key, $label, $grams]) {
            $ingredient->portions()->create(['key' => $key, 'label' => $label, 'grams' => $grams, 'position' => $i + 1]);
        }

        return $ingredient;
    }

    /**
     * A `CC BY-SA 4.0` photo entry with every field set.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function photo(string $file, array $overrides = []): array
    {
        return [
            'file' => $file,
            'license' => 'CC BY-SA 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0',
            'author' => 'Example Author',
            ...$overrides,
        ];
    }

    /**
     * Two photos for an entry: a `CC BY-SA 4.0` one with a file name to encode, and a `CC0` one with no author
     * or license URL.
     *
     * @return list<array<string, mixed>>
     */
    protected function twoPhotos(): array
    {
        return [
            $this->photo('Egg, whole (brown).jpg'),
            $this->photo('Egg_cracked.jpg', ['license' => 'CC0 1.0', 'license_url' => null, 'author' => null]),
        ];
    }

    /**
     * The response items for `twoPhotos()`, swapped by `swapPhotoPositions()` when `$swapped`.
     *
     * @return list<array<string, mixed>>
     */
    protected function expectedTwoPhotos(bool $swapped = false): array
    {
        $base = 'https://commons.wikimedia.org/wiki/Special:FilePath/';
        $photos = [
            [
                'url' => $base.'Egg%2C_whole_%28brown%29.jpg?width=960',
                'thumbnail_url' => $base.'Egg%2C_whole_%28brown%29.jpg?width=330',
                'license' => 'CC BY-SA 4.0',
                'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0',
                'author' => 'Example Author',
                'credit_url' => 'https://commons.wikimedia.org/wiki/File:Egg%2C_whole_%28brown%29.jpg',
            ],
            [
                'url' => $base.'Egg_cracked.jpg?width=960',
                'thumbnail_url' => $base.'Egg_cracked.jpg?width=330',
                'license' => 'CC0 1.0',
                'license_url' => null,
                'author' => null,
                'credit_url' => 'https://commons.wikimedia.org/wiki/File:Egg_cracked.jpg',
            ],
        ];

        return $swapped ? array_reverse($photos) : $photos;
    }

    /**
     * Swaps an ingredient's first two photos in the table, so only the `position` order can put them right.
     */
    protected function swapPhotoPositions(string $key): void
    {
        $photos = IngredientPhoto::whereRelation('ingredient', 'key', $key)->orderBy('position')->get();

        $photos[0]->update(['position' => 99]);
        $photos[1]->update(['position' => 1]);
        $photos[0]->update(['position' => 2]);
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
