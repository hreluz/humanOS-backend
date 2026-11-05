<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Enums\IngredientPhotoSource;
use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPhoto;
use App\Modules\Nutrition\Models\IngredientPortion;
use App\Modules\Nutrition\Models\Nutrient;
use Closure;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\NutrientSeeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Nutrition\Concerns\ReferencesCatalogRows;
use Tests\TestCase;

/**
 * `IngredientSeeder` against small fixture files written to a temporary directory.
 */
class IngredientSeederTest extends TestCase
{
    use ReferencesCatalogRows;

    /**
     * Tables rebuilt from the files, to check a reseed leaves no duplicates.
     *
     * @var list<string>
     */
    private const CHILD_TABLES = ['ingredient_nutrient', 'ingredient_portions', 'ingredient_portion_translations', 'ingredient_translations', 'ingredient_aliases', 'ingredient_photos'];

    /**
     * @var list<string>
     */
    private array $directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NutrientSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            array_map(unlink(...), glob("{$directory}/*"));
            rmdir($directory);
        }

        parent::tearDown();
    }

    public function test_it_loads_an_entry_with_all_its_child_rows(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg()]]);

        $egg = Ingredient::with(['nutrients', 'portions.translations', 'translations', 'aliases'])->where('key', 'egg_whole_raw')->firstOrFail();

        $this->assertSame('Egg, whole, raw', $egg->name);
        $this->assertSame(IngredientCategory::Eggs, $egg->category);
        $this->assertSame(IngredientSource::UsdaFoundation, $egg->source);
        $this->assertSame('748967', $egg->source_id);
        $this->assertSame([148.0, 12.4, 0.96, 10.0, 0.0], [$egg->kcal, $egg->protein, $egg->carbs, $egg->fat, $egg->fiber]);

        $this->assertSame(['cholesterol' => 411.0, 'selenium' => 31.1], $egg->nutrients->mapWithKeys(fn (Nutrient $n) => [$n->key => (float) $n->pivot->amount])->all());

        $this->assertSame(
            [[1, 'large', '1 large egg', 50.0, '1 huevo grande'], [2, 'cup', '1 cup', 243.0, '1 taza']],
            $egg->portions->map(fn (IngredientPortion $p) => [$p->position, $p->key, $p->label, $p->grams, $p->translations->firstWhere('locale', 'es')?->label])->all(),
        );

        $this->assertSame(['es' => 'Huevo entero crudo'], $egg->translations->pluck('name', 'locale')->all());
        $this->assertEqualsCanonicalizing([['en', 'hen egg'], ['es', 'blanquillo']], $egg->aliases->map(fn ($a) => [$a->locale, $a->name])->all());
    }

    public function test_it_loads_every_file_in_the_directory(): void
    {
        $this->seedFiles(['a.json' => [$this->egg()], 'b.json' => [$this->entry('milk_whole', '746782')]]);

        $this->assertEqualsCanonicalizing(['egg_whole_raw', 'milk_whole'], Ingredient::pluck('key')->all());
    }

    public function test_a_missing_nutrient_has_no_row_and_a_zero_has_a_row(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(['nutrients' => ['cholesterol' => 411, 'vitamin_c' => 0]])]]);

        $amounts = DB::table('ingredient_nutrient')
            ->join('nutrients', 'nutrients.id', '=', 'ingredient_nutrient.nutrient_id')
            ->pluck('amount', 'nutrients.key')->map(fn ($amount) => (float) $amount)->all();

        $this->assertSame(['cholesterol' => 411.0, 'vitamin_c' => 0.0], $amounts);
        $this->assertArrayNotHasKey('selenium', $amounts);
    }

    public function test_seeding_twice_keeps_the_ids_and_leaves_no_duplicate_child_rows(): void
    {
        $files = ['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]];
        $this->seedFiles($files);
        $ingredients = Ingredient::pluck('id', 'key')->all();
        $portions = IngredientPortion::pluck('id', 'key')->all();
        $counts = $this->childCounts();

        $this->seedFiles($files);

        $this->assertSame($ingredients, Ingredient::pluck('id', 'key')->all());
        $this->assertSame($portions, IngredientPortion::pluck('id', 'key')->all());
        $this->assertSame($counts, $this->childCounts());
    }

    public function test_changed_values_portion_labels_and_grams_update_in_place(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg()]]);
        $id = Ingredient::where('key', 'egg_whole_raw')->value('id');
        $portionId = IngredientPortion::where('key', 'large')->value('id');

        $egg = $this->egg(['kcal' => 143, 'nutrients' => ['cholesterol' => 372, 'selenium' => 31.1], 'translations' => ['es' => 'Huevo crudo']]);
        $egg['portions'][0] = ['key' => 'large', 'label' => '1 large egg (shelled)', 'grams' => 50.3, 'translations' => ['es' => '1 huevo grande (sin cáscara)']];
        $this->seedFiles(['eggs.json' => [$egg]]);

        $ingredient = Ingredient::with('nutrients', 'translations')->findOrFail($id);
        $this->assertSame(143.0, $ingredient->kcal);
        $this->assertSame(372.0, (float) $ingredient->nutrients->firstWhere('key', 'cholesterol')->pivot->amount);
        $this->assertSame('Huevo crudo', $ingredient->translations->first()->name);

        $portion = IngredientPortion::with('translations')->findOrFail($portionId);
        $this->assertSame(['1 large egg (shelled)', 50.3], [$portion->label, $portion->grams]);
        $this->assertSame('1 huevo grande (sin cáscara)', $portion->translations->first()->label);
    }

    public function test_reordering_portions_changes_only_their_position(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg()]]);
        $before = IngredientPortion::orderBy('key')->get(['id', 'key', 'label', 'grams'])->toArray();

        $egg = $this->egg();
        $egg['portions'] = array_reverse($egg['portions']);
        $this->seedFiles(['eggs.json' => [$egg]]);

        $this->assertSame($before, IngredientPortion::orderBy('key')->get(['id', 'key', 'label', 'grams'])->toArray());
        $this->assertSame(['cup', 'large'], Ingredient::firstOrFail()->portions->pluck('key')->all());
    }

    public function test_an_unreferenced_ingredient_or_portion_removed_from_the_files_is_hard_deleted(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);
        $white = Ingredient::where('key', 'egg_white_raw')->value('id');
        $cup = IngredientPortion::where('key', 'cup')->value('id');

        $egg = $this->egg();
        array_pop($egg['portions']);
        $this->seedFiles(['eggs.json' => [$egg]]);

        $this->assertNull(Ingredient::withTrashed()->find($white));
        $this->assertSame(0, DB::table('ingredient_translations')->where('ingredient_id', $white)->count());
        $this->assertNull(IngredientPortion::withTrashed()->find($cup));
        $this->assertSame(0, DB::table('ingredient_portion_translations')->where('ingredient_portion_id', $cup)->count());
    }

    public function test_a_referenced_ingredient_removed_from_the_files_is_soft_deleted_and_keeps_its_children(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);
        $id = Ingredient::where('key', 'egg_whole_raw')->value('id');
        $this->reference(['ingredient_id' => $id]);
        $counts = $this->childCounts($id);

        $this->seedFiles(['eggs.json' => [$this->entry('egg_white_raw', '747997')]]);

        $this->assertSame(['egg_white_raw'], Ingredient::query()->pluck('key')->all());
        $this->assertTrue(Ingredient::withTrashed()->findOrFail($id)->trashed());
        $this->assertSame($counts, $this->childCounts($id));
    }

    public function test_a_referenced_portion_removed_from_the_files_is_soft_deleted_and_hidden(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg()]]);
        $cup = IngredientPortion::where('key', 'cup')->value('id');
        $this->reference(['ingredient_portion_id' => $cup]);

        $egg = $this->egg();
        array_pop($egg['portions']);
        $this->seedFiles(['eggs.json' => [$egg]]);

        $this->assertSame(['large'], Ingredient::firstOrFail()->portions->pluck('key')->all());
        $this->assertTrue(IngredientPortion::withTrashed()->findOrFail($cup)->trashed());
        $this->assertSame('1 taza', DB::table('ingredient_portion_translations')->where('ingredient_portion_id', $cup)->value('label'));
    }

    public function test_an_ingredient_whose_portion_is_referenced_is_soft_deleted(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);
        $id = Ingredient::where('key', 'egg_whole_raw')->value('id');
        $this->reference(['ingredient_portion_id' => IngredientPortion::where('key', 'cup')->value('id')]);

        $this->seedFiles(['eggs.json' => [$this->entry('egg_white_raw', '747997')]]);

        $this->assertNull(Ingredient::find($id));
        $this->assertTrue(Ingredient::withTrashed()->findOrFail($id)->trashed());
        $this->assertSame(2, IngredientPortion::where('ingredient_id', $id)->count());
    }

    public function test_a_soft_deleted_ingredient_or_portion_whose_key_is_back_is_restored_with_its_old_id(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);
        $id = Ingredient::where('key', 'egg_white_raw')->value('id');
        $cup = IngredientPortion::where('key', 'cup')->value('id');
        $this->reference(['ingredient_id' => $id]);
        $this->reference(['ingredient_portion_id' => $cup]);

        $egg = $this->egg();
        array_pop($egg['portions']);
        $this->seedFiles(['eggs.json' => [$egg]]);
        $this->assertNull(Ingredient::find($id));
        $this->assertNull(IngredientPortion::find($cup));

        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);

        $this->assertSame($id, Ingredient::where('key', 'egg_white_raw')->value('id'));
        $this->assertSame($cup, IngredientPortion::where('key', 'cup')->value('id'));
        $this->assertSame(2, Ingredient::count());
    }

    /**
     * @return array<string, array{Closure(self): array<string, list<array<string, mixed>>>, string}>
     */
    public static function invalidCatalogs(): array
    {
        return [
            'duplicate key' => [fn (self $t) => ['a.json' => [$t->egg()], 'b.json' => [$t->egg(['source_id' => '1'])]], 'Ingredient key [egg_whole_raw]: listed 2 times'],
            'duplicate source id' => [fn (self $t) => ['a.json' => [$t->egg(), $t->entry('egg_white_raw', '748967')]], 'Ingredient source_id [748967]: listed 2 times'],
            'missing source id' => [fn (self $t) => ['a.json' => [$t->egg(['source_id' => null])]], 'missing source_id'],
            'duplicate portion key' => [fn (self $t) => ['a.json' => [$t->egg(['portions' => [$t->portion('large'), $t->portion('large')]])]], 'portion [large] listed twice'],
            'unknown category' => [fn (self $t) => ['a.json' => [$t->egg(['category' => 'snacks'])]], 'unknown category [snacks]'],
            'unknown source' => [fn (self $t) => ['a.json' => [$t->egg(['source' => 'food_table'])]], 'unknown source [food_table]'],
            'unknown nutrient' => [fn (self $t) => ['a.json' => [$t->egg(['nutrients' => ['omega_3' => 1]])]], 'unknown nutrient [omega_3]'],
            'unknown translation locale' => [fn (self $t) => ['a.json' => [$t->egg(['translations' => ['sp' => 'Huevo']])]], 'unknown translation locale [sp]'],
            'unknown alias locale' => [fn (self $t) => ['a.json' => [$t->egg(['aliases' => ['fr' => ['oeuf']]])]], 'unknown alias locale [fr]'],
            'unknown portion locale' => [fn (self $t) => ['a.json' => [$t->egg(['portions' => [$t->portion('large', ['sp' => 'uno'])]])]], 'portion [large] translation locale [sp]'],
            'missing macro' => [fn (self $t) => ['a.json' => [array_diff_key($t->egg(), ['fiber' => true])]], 'missing macro [fiber]'],
            'null macro' => [fn (self $t) => ['a.json' => [$t->egg(['protein' => null])]], 'missing macro [protein]'],
            'non-commercial license' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => 'CC BY-NC 4.0'])]), "photo [A.jpg] has a license that isn't allowed [CC BY-NC 4.0]"],
            'no-derivatives license' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => 'CC BY-ND 4.0'])]), "license that isn't allowed [CC BY-ND 4.0]"],
            'GFDL license' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => 'GFDL'])]), "license that isn't allowed [GFDL]"],
            'missing license' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => null])]), 'photo [A.jpg] has no license'],
            'missing file' => [fn (self $t) => $t->withPhotos([$t->photo('')]), 'photo 1 has no file'],
            'missing author under CC BY' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => 'CC BY 4.0', 'author' => null])]), 'photo [A.jpg] needs author under [CC BY 4.0]'],
            'missing author under CC BY-SA' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['author' => null])]), 'photo [A.jpg] needs author under [CC BY-SA 4.0]'],
            'missing license url under CC BY' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => 'CC BY 2.0', 'license_url' => null])]), 'photo [A.jpg] needs license_url under [CC BY 2.0]'],
            'missing license url under CC BY-SA' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license' => 'CC BY-SA 3.0 de', 'license_url' => null])]), 'photo [A.jpg] needs license_url under [CC BY-SA 3.0 de]'],
            'author of 256 characters' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['author' => str_repeat('é', 256)])]), 'photo [A.jpg] author is longer than 255 characters'],
            'ftp license url' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license_url' => 'ftp://creativecommons.org/licenses/by-sa/4.0'])]), 'photo [A.jpg] license_url is not an http or https URL'],
            'license url that is not a url' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg', ['license_url' => 'creativecommons by-sa'])]), 'photo [A.jpg] license_url is not an http or https URL'],
            'four photos' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg'), $t->photo('B.jpg'), $t->photo('C.jpg'), $t->photo('D.jpg')]), 'has 4 photos, at most 3 allowed'],
            'same file twice' => [fn (self $t) => $t->withPhotos([$t->photo('A.jpg'), $t->photo('A.jpg')]), 'photo [A.jpg] listed twice'],
            'same file spelled differently' => [fn (self $t) => $t->withPhotos([$t->photo('Avocado Hass.jpg'), $t->photo('avocado_Hass.jpg')]), 'photo [Avocado_Hass.jpg] listed twice'],
            'photos not a list' => [fn (self $t) => ['a.json' => [$t->egg(['photos' => ['main' => $t->photo('A.jpg')]])]], 'photos must be a list'],
        ];
    }

    /**
     * @param  Closure(self): array<string, list<array<string, mixed>>>  $files
     */
    #[DataProvider('invalidCatalogs')]
    public function test_an_invalid_catalog_throws_and_writes_nothing(Closure $files, string $message): void
    {
        $this->assertSeedingFails($files($this), $message);
    }

    public function test_seeded_ingredients_are_system_ingredients(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);

        $this->assertSame([null, null], Ingredient::pluck('user_id')->all());
        $this->assertSame(2, Ingredient::system()->count());
    }

    public function test_a_users_ingredient_not_in_the_files_survives_a_seed(): void
    {
        $mine = $this->userIngredient('my_granola');

        $this->seedFiles(['eggs.json' => [$this->egg()]]);
        $this->seedFiles(['eggs.json' => [$this->entry('egg_white_raw', '747997')]]);

        $mine->refresh();
        $this->assertFalse($mine->trashed());
        $this->assertSame('my_granola', $mine->name);
        $this->assertEqualsCanonicalizing(['egg_white_raw', 'my_granola'], Ingredient::pluck('key')->all());
    }

    public function test_a_key_held_by_a_users_ingredient_throws_and_writes_nothing(): void
    {
        $mine = $this->userIngredient('egg_whole_raw');

        try {
            $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997')]]);
            $this->fail('The seeder took over a user\'s ingredient.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Ingredient key [egg_whole_raw]: belongs to a user's ingredient", $e->getMessage());
        }

        $this->assertSame([$mine->id], Ingredient::withTrashed()->pluck('id')->all());
        $this->assertSame(['egg_whole_raw', $mine->user_id], [$mine->fresh()->name, $mine->fresh()->user_id]);
        $this->assertSame(array_fill_keys(self::CHILD_TABLES, 0), $this->childCounts());
    }

    public function test_deleting_a_user_deletes_their_ingredients_and_leaves_system_ones(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg()]]);
        $mine = $this->userIngredient('my_granola');
        $other = $this->userIngredient('their_granola');

        $mine->user->delete();

        $this->assertNull(Ingredient::withTrashed()->find($mine->id));
        $this->assertEqualsCanonicalizing(['egg_whole_raw', 'their_granola'], Ingredient::pluck('key')->all());
        $this->assertNotNull($other->fresh());
    }

    public function test_an_entry_with_two_photos_loads_both_in_order(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(['photos' => [
            $this->photo('Egg_whole.jpg'),
            $this->photo('Egg_cracked.jpg', ['license' => 'CC0 1.0', 'license_url' => 'https://creativecommons.org/publicdomain/zero/1.0/deed.en', 'author' => null]),
        ]])]]);

        $egg = Ingredient::with('photos')->firstOrFail();

        $this->assertSame(
            [
                [IngredientPhotoSource::Commons, 'Egg_whole.jpg', 'CC BY-SA 4.0', 'https://creativecommons.org/licenses/by-sa/4.0', 'Example Author', 1],
                [IngredientPhotoSource::Commons, 'Egg_cracked.jpg', 'CC0 1.0', 'https://creativecommons.org/publicdomain/zero/1.0/deed.en', null, 2],
            ],
            $egg->photos->map(fn (IngredientPhoto $p) => [$p->source, $p->path, $p->license, $p->license_url, $p->author, $p->position])->all(),
        );
        $this->assertNotNull($egg->photos->first()->created_at);
        $this->assertNotNull($egg->photos->first()->updated_at);
    }

    public function test_an_entry_without_photos_or_with_an_empty_list_loads_none(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(), $this->entry('egg_white_raw', '747997') + ['photos' => []]]]);

        $this->assertSame(0, IngredientPhoto::count());
    }

    public function test_no_author_or_license_url_is_needed_under_cc0_or_public_domain(): void
    {
        $this->seedFiles($this->withPhotos([
            $this->photo('A.jpg', ['license' => 'CC0', 'author' => null, 'license_url' => null]),
            $this->photo('B.jpg', ['license' => 'Public domain', 'author' => null, 'license_url' => null]),
        ]));

        $this->assertSame([[null, null], [null, null]], IngredientPhoto::orderBy('position')->get()->map(fn (IngredientPhoto $p) => [$p->author, $p->license_url])->all());
    }

    public function test_reordering_removing_one_or_all_photos_is_reflected_on_the_next_run(): void
    {
        $paths = fn () => Ingredient::firstOrFail()->photos->pluck('path', 'position')->all();

        $this->seedFiles($this->withPhotos([$this->photo('A.jpg'), $this->photo('B.jpg'), $this->photo('C.jpg')]));
        $this->assertSame([1 => 'A.jpg', 2 => 'B.jpg', 3 => 'C.jpg'], $paths());

        $this->seedFiles($this->withPhotos([$this->photo('C.jpg'), $this->photo('A.jpg'), $this->photo('B.jpg')]));
        $this->assertSame([1 => 'C.jpg', 2 => 'A.jpg', 3 => 'B.jpg'], $paths());

        $this->seedFiles($this->withPhotos([$this->photo('C.jpg'), $this->photo('B.jpg')]));
        $this->assertSame([1 => 'C.jpg', 2 => 'B.jpg'], $paths());

        $this->seedFiles(['eggs.json' => [$this->egg()]]);
        $this->assertSame(0, IngredientPhoto::count());
    }

    public function test_an_http_license_url_is_stored_as_given(): void
    {
        $this->seedFiles($this->withPhotos([$this->photo('A.jpg', ['license' => 'CC BY-SA 3.0', 'license_url' => 'http://creativecommons.org/licenses/by-sa/3.0/'])]));

        $this->assertSame('http://creativecommons.org/licenses/by-sa/3.0/', IngredientPhoto::firstOrFail()->license_url);
    }

    public function test_a_file_is_stored_in_canonical_form(): void
    {
        $this->seedFiles($this->withPhotos([
            $this->photo('Avocado Hass - halved.jpg'),
            $this->photo('avocado_whole.jpg'),
            $this->photo('File:Avocado tree.jpg'),
        ]));

        $this->assertSame(['Avocado_Hass_-_halved.jpg', 'Avocado_whole.jpg', 'Avocado_tree.jpg'], Ingredient::firstOrFail()->photos->pluck('path')->all());
    }

    public function test_the_same_file_on_two_ingredients_loads_on_both(): void
    {
        $shared = [$this->photo('Chicken_breast.jpg')];
        $this->seedFiles(['meat.json' => [
            $this->entry('chicken_breast_grilled', '1') + ['photos' => $shared],
            $this->entry('chicken_breast_boiled', '2') + ['photos' => $shared],
        ]]);

        $this->assertSame(2, IngredientPhoto::where('path', 'Chicken_breast.jpg')->distinct()->count('ingredient_id'));
    }

    public function test_hard_deleting_an_ingredient_deletes_its_photos(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(['photos' => [$this->photo('A.jpg'), $this->photo('B.jpg')]]), $this->entry('egg_white_raw', '747997')]]);
        $id = Ingredient::where('key', 'egg_whole_raw')->value('id');

        $this->seedFiles(['eggs.json' => [$this->entry('egg_white_raw', '747997')]]);

        $this->assertNull(Ingredient::withTrashed()->find($id));
        $this->assertSame(0, IngredientPhoto::where('ingredient_id', $id)->count());
    }

    public function test_a_soft_deleted_ingredient_keeps_its_photos(): void
    {
        $this->seedFiles(['eggs.json' => [$this->egg(['photos' => [$this->photo('A.jpg')]]), $this->entry('egg_white_raw', '747997')]]);
        $id = Ingredient::where('key', 'egg_whole_raw')->value('id');
        $this->reference(['ingredient_id' => $id]);

        $this->seedFiles(['eggs.json' => [$this->entry('egg_white_raw', '747997')]]);

        $this->assertTrue(Ingredient::withTrashed()->findOrFail($id)->trashed());
        $this->assertSame(['A.jpg'], IngredientPhoto::where('ingredient_id', $id)->pluck('path')->all());
    }

    public function test_a_soft_deleted_nutrient_counts_as_unknown(): void
    {
        $nutrients = NutrientSeeder::NUTRIENTS;
        unset($nutrients['selenium']);
        $this->reference(['nutrient_id' => Nutrient::where('key', 'selenium')->value('id')]);
        (new NutrientSeeder($nutrients))->run();

        $this->assertSeedingFails(['a.json' => [$this->egg()]], 'unknown nutrient [selenium]');
    }

    /**
     * The whole egg from the spec's format example, with its values from FoodData Central 748967.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function egg(array $overrides = []): array
    {
        return [...[
            'key' => 'egg_whole_raw',
            'name' => 'Egg, whole, raw',
            'category' => 'eggs',
            'source' => 'usda_foundation',
            'source_id' => '748967',
            'kcal' => 148, 'protein' => 12.4, 'carbs' => 0.96, 'fat' => 10, 'fiber' => 0,
            'nutrients' => ['cholesterol' => 411, 'selenium' => 31.1],
            'portions' => [
                ['key' => 'large', 'label' => '1 large egg', 'grams' => 50, 'translations' => ['es' => '1 huevo grande']],
                ['key' => 'cup', 'label' => '1 cup', 'grams' => 243, 'translations' => ['es' => '1 taza']],
            ],
            'translations' => ['es' => 'Huevo entero crudo'],
            'aliases' => ['en' => ['hen egg'], 'es' => ['blanquillo']],
        ], ...$overrides];
    }

    /**
     * A minimal valid entry.
     *
     * @return array<string, mixed>
     */
    public function entry(string $key, string $sourceId): array
    {
        return [
            'key' => $key, 'name' => $key, 'category' => 'other', 'source' => 'usda_sr_legacy', 'source_id' => $sourceId,
            'kcal' => 50, 'protein' => 1, 'carbs' => 2, 'fat' => 3, 'fiber' => 0,
            'nutrients' => [], 'portions' => [], 'translations' => ['es' => "{$key} (es)"], 'aliases' => [],
        ];
    }

    /**
     * @param  array<string, string>  $translations
     * @return array<string, mixed>
     */
    public function portion(string $key, array $translations = ['es' => 'uno']): array
    {
        return ['key' => $key, 'label' => $key, 'grams' => 10, 'translations' => $translations];
    }

    /**
     * A `CC BY-SA 4.0` photo with every field set.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function photo(string $file, array $overrides = []): array
    {
        return [...[
            'file' => $file,
            'license' => 'CC BY-SA 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0',
            'author' => 'Example Author',
        ], ...$overrides];
    }

    /**
     * A catalog of the egg with these photos.
     *
     * @param  list<array<string, mixed>>  $photos
     * @return array<string, list<array<string, mixed>>>
     */
    public function withPhotos(array $photos): array
    {
        return ['eggs.json' => [$this->egg(['photos' => $photos])]];
    }

    /**
     * An ingredient owned by a new user.
     */
    private function userIngredient(string $key): Ingredient
    {
        return Ingredient::create([
            'user_id' => User::factory()->create()->id,
            'key' => $key, 'name' => $key, 'category' => 'other', 'source' => 'usda_sr_legacy', 'source_id' => '1',
            'kcal' => 400, 'protein' => 10, 'carbs' => 60, 'fat' => 12, 'fiber' => 7,
        ]);
    }

    /**
     * Writes the files to a new temporary directory and seeds from it.
     *
     * @param  array<string, list<array<string, mixed>>>  $files
     */
    private function seedFiles(array $files): void
    {
        $directory = sys_get_temp_dir().'/ingredients-'.uniqid();
        mkdir($directory);
        $this->directories[] = $directory;

        foreach ($files as $name => $entries) {
            file_put_contents("{$directory}/{$name}", json_encode($entries));
        }

        (new IngredientSeeder($directory))->run();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $files
     */
    private function assertSeedingFails(array $files, string $message): void
    {
        try {
            $this->seedFiles($files);
            $this->fail('The seeder accepted an invalid catalog.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(0, Ingredient::withTrashed()->count());
        $this->assertSame(array_fill_keys(self::CHILD_TABLES, 0), $this->childCounts());
    }

    /**
     * Row counts of the tables rebuilt from the files, for every ingredient or only `$ingredientId`.
     *
     * @return array<string, int>
     */
    private function childCounts(?int $ingredientId = null): array
    {
        $counts = [];
        foreach (self::CHILD_TABLES as $table) {
            $query = DB::table($table);
            if ($table === 'ingredient_portion_translations') {
                $query->whereIn('ingredient_portion_id', DB::table('ingredient_portions')
                    ->when($ingredientId, fn ($q) => $q->where('ingredient_id', $ingredientId))->select('id'));
            } elseif ($ingredientId) {
                $query->where('ingredient_id', $ingredientId);
            }
            $counts[$table] = $query->count();
        }

        return $counts;
    }
}
