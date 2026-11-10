<?php

namespace Tests\Feature\Nutrition;

use App\Modules\Nutrition\Enums\NutrientGroup;
use App\Modules\Nutrition\Enums\NutrientUnit;
use App\Modules\Nutrition\Models\Nutrient;
use App\Modules\Nutrition\Models\NutrientTranslation;
use Database\Seeders\NutrientSeeder;
use InvalidArgumentException;
use Tests\Feature\Nutrition\Concerns\ReferencesCatalogRows;
use Tests\TestCase;

class NutrientSeederTest extends TestCase
{
    use ReferencesCatalogRows;

    /**
     * The 29 nutrients from SPEC 15, written out separately from the seeder: key => [group, unit, FDC nutrient id].
     *
     * @var array<string, array{string, string, int}>
     */
    private const EXPECTED = [
        'sugars' => ['fats_and_sugars', 'g', 2000], 'saturated_fat' => ['fats_and_sugars', 'g', 1258],
        'monounsaturated_fat' => ['fats_and_sugars', 'g', 1292], 'polyunsaturated_fat' => ['fats_and_sugars', 'g', 1293],
        'trans_fat' => ['fats_and_sugars', 'g', 1257], 'cholesterol' => ['fats_and_sugars', 'mg', 1253],
        'calcium' => ['minerals', 'mg', 1087], 'iron' => ['minerals', 'mg', 1089], 'magnesium' => ['minerals', 'mg', 1090],
        'phosphorus' => ['minerals', 'mg', 1091], 'potassium' => ['minerals', 'mg', 1092], 'sodium' => ['minerals', 'mg', 1093],
        'zinc' => ['minerals', 'mg', 1095], 'copper' => ['minerals', 'mg', 1098], 'manganese' => ['minerals', 'mg', 1101],
        'selenium' => ['minerals', 'ug', 1103],
        'vitamin_a' => ['vitamins', 'ug', 1106], 'vitamin_c' => ['vitamins', 'mg', 1162], 'vitamin_d' => ['vitamins', 'ug', 1114],
        'vitamin_e' => ['vitamins', 'mg', 1109], 'vitamin_k' => ['vitamins', 'ug', 1185], 'thiamin' => ['vitamins', 'mg', 1165],
        'riboflavin' => ['vitamins', 'mg', 1166], 'niacin' => ['vitamins', 'mg', 1167], 'pantothenic_acid' => ['vitamins', 'mg', 1170],
        'vitamin_b6' => ['vitamins', 'mg', 1175], 'folate' => ['vitamins', 'ug', 1190], 'vitamin_b12' => ['vitamins', 'ug', 1178],
        'choline' => ['vitamins', 'mg', 1180],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NutrientSeeder::class);
    }

    public function test_it_seeds_the_29_nutrients_in_order_with_spanish_labels(): void
    {
        $nutrients = Nutrient::with('translations')->orderBy('sort_order')->get();

        $this->assertCount(29, $nutrients);
        $this->assertSame(array_keys(self::EXPECTED), $nutrients->pluck('key')->all());
        $this->assertSame(range(1, 29), $nutrients->pluck('sort_order')->all());

        foreach ($nutrients as $nutrient) {
            [$group, $unit, $fdcId] = self::EXPECTED[$nutrient->key];
            $this->assertSame(NutrientGroup::from($group), $nutrient->group, "Group of {$nutrient->key}");
            $this->assertSame(NutrientUnit::from($unit), $nutrient->unit, "Unit of {$nutrient->key}");
            $this->assertSame($fdcId, $nutrient->fdc_nutrient_id, "FDC id of {$nutrient->key}");
            $this->assertSame(['es'], $nutrient->translations->pluck('locale')->all(), "Translations of {$nutrient->key}");
        }
    }

    public function test_the_basis_is_in_the_label_in_both_languages(): void
    {
        foreach (['vitamin_a' => ['Vitamin A (RAE)', 'Vitamina A (RAE)'], 'folate' => ['Folate (DFE)', 'Folato (DFE)']] as $key => [$english, $spanish]) {
            $nutrient = Nutrient::where('key', $key)->firstOrFail();
            $this->assertSame($english, $nutrient->label);
            $this->assertSame($spanish, $nutrient->translations()->value('label'));
        }
    }

    public function test_seeding_twice_keeps_the_ids_and_adds_no_translations(): void
    {
        $ids = Nutrient::pluck('id', 'key')->all();

        $this->seed(NutrientSeeder::class);

        $this->assertSame($ids, Nutrient::pluck('id', 'key')->all());
        $this->assertSame(29, NutrientTranslation::count());
    }

    public function test_a_nutrient_dropped_from_the_list_is_hard_deleted(): void
    {
        $id = Nutrient::where('key', 'choline')->value('id');

        $this->seedWithout('choline');

        $this->assertNull(Nutrient::withTrashed()->find($id));
        $this->assertSame(0, NutrientTranslation::where('nutrient_id', $id)->count());
        $this->assertSame(28, Nutrient::count());
    }

    public function test_a_referenced_nutrient_dropped_from_the_list_is_soft_deleted_and_keeps_its_label(): void
    {
        $id = Nutrient::where('key', 'choline')->value('id');
        $this->reference(['nutrient_id' => $id]);

        $this->seedWithout('choline');

        $this->assertNull(Nutrient::find($id), 'Hidden from queries');
        $this->assertTrue(Nutrient::withTrashed()->findOrFail($id)->trashed());
        $this->assertSame('Colina', NutrientTranslation::where('nutrient_id', $id)->value('label'));
        $this->assertSame(28, Nutrient::count());
    }

    public function test_a_dropped_nutrient_that_comes_back_is_restored_with_its_old_id(): void
    {
        $id = Nutrient::where('key', 'choline')->value('id');
        $this->reference(['nutrient_id' => $id]);
        $this->seedWithout('choline');

        $this->seed(NutrientSeeder::class);

        $nutrient = Nutrient::where('key', 'choline')->firstOrFail();
        $this->assertSame($id, $nutrient->id);
        $this->assertFalse($nutrient->trashed());
        $this->assertSame(29, Nutrient::count());
    }

    public function test_a_soft_deleted_nutrient_is_hard_deleted_once_nothing_references_it(): void
    {
        $id = Nutrient::where('key', 'choline')->value('id');
        $reference = $this->reference(['nutrient_id' => $id]);
        $this->seedWithout('choline');

        $this->dropReference($reference);
        $this->seedWithout('choline');

        $this->assertNull(Nutrient::withTrashed()->find($id));
    }

    public function test_a_duplicate_fdc_nutrient_id_throws_and_writes_nothing(): void
    {
        $nutrients = NutrientSeeder::NUTRIENTS;
        $nutrients['choline'][2] = 1178;

        try {
            (new NutrientSeeder($nutrients))->run();
            $this->fail('The seeder accepted a duplicate FDC nutrient id.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('FDC nutrient id [1178]', $e->getMessage());
        }

        $this->assertSame(29, Nutrient::count());
    }

    private function seedWithout(string $key): void
    {
        $nutrients = NutrientSeeder::NUTRIENTS;
        unset($nutrients[$key]);

        (new NutrientSeeder($nutrients))->run();
    }
}
