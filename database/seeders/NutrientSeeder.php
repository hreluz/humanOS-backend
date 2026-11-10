<?php

namespace Database\Seeders;

use App\Modules\Nutrition\Enums\NutrientGroup;
use App\Modules\Nutrition\Enums\NutrientUnit;
use App\Modules\Nutrition\Models\Nutrient;
use App\Modules\Nutrition\Support\CatalogRemover;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The nutrients tracked beyond the five macro columns (SPEC 15, "Nutrients").
 *
 * Rows are upserted by `key`, so running it again keeps their ids, and a soft-deleted nutrient whose key is
 * back is restored with its old id. Spanish labels are rebuilt from the list. A nutrient no longer in the
 * list is hard-deleted, or soft-deleted when something references it (`CatalogRemover`).
 */
class NutrientSeeder extends Seeder
{
    /**
     * Nutrients in display order: key => [group, unit, FDC nutrient id, English label, Spanish label].
     *
     * `vitamin_a` is in RAE and `folate` in DFE; the basis is in the label, the unit stays `ug`.
     *
     * @var array<string, array{NutrientGroup, NutrientUnit, int, string, string}>
     */
    public const NUTRIENTS = [
        'sugars' => [NutrientGroup::FatsAndSugars, NutrientUnit::Grams, 2000, 'Sugars', 'Azúcares'],
        'saturated_fat' => [NutrientGroup::FatsAndSugars, NutrientUnit::Grams, 1258, 'Saturated fat', 'Grasa saturada'],
        'monounsaturated_fat' => [NutrientGroup::FatsAndSugars, NutrientUnit::Grams, 1292, 'Monounsaturated fat', 'Grasa monoinsaturada'],
        'polyunsaturated_fat' => [NutrientGroup::FatsAndSugars, NutrientUnit::Grams, 1293, 'Polyunsaturated fat', 'Grasa poliinsaturada'],
        'trans_fat' => [NutrientGroup::FatsAndSugars, NutrientUnit::Grams, 1257, 'Trans fat', 'Grasa trans'],
        'cholesterol' => [NutrientGroup::FatsAndSugars, NutrientUnit::Milligrams, 1253, 'Cholesterol', 'Colesterol'],
        'calcium' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1087, 'Calcium', 'Calcio'],
        'iron' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1089, 'Iron', 'Hierro'],
        'magnesium' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1090, 'Magnesium', 'Magnesio'],
        'phosphorus' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1091, 'Phosphorus', 'Fósforo'],
        'potassium' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1092, 'Potassium', 'Potasio'],
        'sodium' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1093, 'Sodium', 'Sodio'],
        'zinc' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1095, 'Zinc', 'Zinc'],
        'copper' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1098, 'Copper', 'Cobre'],
        'manganese' => [NutrientGroup::Minerals, NutrientUnit::Milligrams, 1101, 'Manganese', 'Manganeso'],
        'selenium' => [NutrientGroup::Minerals, NutrientUnit::Micrograms, 1103, 'Selenium', 'Selenio'],
        'vitamin_a' => [NutrientGroup::Vitamins, NutrientUnit::Micrograms, 1106, 'Vitamin A (RAE)', 'Vitamina A (RAE)'],
        'vitamin_c' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1162, 'Vitamin C', 'Vitamina C'],
        'vitamin_d' => [NutrientGroup::Vitamins, NutrientUnit::Micrograms, 1114, 'Vitamin D', 'Vitamina D'],
        'vitamin_e' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1109, 'Vitamin E', 'Vitamina E'],
        'vitamin_k' => [NutrientGroup::Vitamins, NutrientUnit::Micrograms, 1185, 'Vitamin K', 'Vitamina K'],
        'thiamin' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1165, 'Thiamin (B1)', 'Tiamina (B1)'],
        'riboflavin' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1166, 'Riboflavin (B2)', 'Riboflavina (B2)'],
        'niacin' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1167, 'Niacin (B3)', 'Niacina (B3)'],
        'pantothenic_acid' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1170, 'Pantothenic acid (B5)', 'Ácido pantoténico (B5)'],
        'vitamin_b6' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1175, 'Vitamin B6', 'Vitamina B6'],
        'folate' => [NutrientGroup::Vitamins, NutrientUnit::Micrograms, 1190, 'Folate (DFE)', 'Folato (DFE)'],
        'vitamin_b12' => [NutrientGroup::Vitamins, NutrientUnit::Micrograms, 1178, 'Vitamin B12', 'Vitamina B12'],
        'choline' => [NutrientGroup::Vitamins, NutrientUnit::Milligrams, 1180, 'Choline', 'Colina'],
    ];

    /**
     * @param  array<string, array{NutrientGroup, NutrientUnit, int, string, string}>  $nutrients  The list to seed; tests pass a shorter one.
     */
    public function __construct(private array $nutrients = self::NUTRIENTS) {}

    /**
     * Seed the nutrients and their Spanish labels, and remove the ones no longer listed.
     */
    public function run(): void
    {
        $fdcIds = array_column($this->nutrients, 2);
        foreach (array_count_values($fdcIds) as $fdcId => $count) {
            if ($count > 1) {
                throw new InvalidArgumentException("FDC nutrient id [{$fdcId}]: listed {$count} times.");
            }
        }

        DB::transaction(function () {
            $sortOrder = 0;
            foreach ($this->nutrients as $key => [$group, $unit, $fdcId, $label, $spanish]) {
                $nutrient = Nutrient::withTrashed()->firstOrNew(['key' => $key]);
                $nutrient->fill([
                    'label' => $label,
                    'group' => $group,
                    'unit' => $unit,
                    'fdc_nutrient_id' => $fdcId,
                    'sort_order' => ++$sortOrder,
                ]);
                $nutrient->deleted_at = null;
                $nutrient->save();

                $nutrient->translations()->delete();
                $nutrient->translations()->create(['locale' => 'es', 'label' => $spanish]);
            }

            CatalogRemover::remove(Nutrient::whereNotIn('key', array_keys($this->nutrients)));
        });
    }
}
