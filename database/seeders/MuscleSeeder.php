<?php

namespace Database\Seeders;

use App\Modules\Training\Enums\BodyView;
use App\Modules\Training\Models\Muscle;
use App\Modules\Training\Models\MuscleArea;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The muscle catalog drawn by the clients' body map (SPEC 06).
 *
 * Rows are upserted by `key`, so running it again updates them in place and keeps their ids.
 * It never deletes: a removed muscle gets its own migration.
 */
class MuscleSeeder extends Seeder
{
    /**
     * Areas in display order, each with its muscles in display order: key => [label, view].
     *
     * @var array<string, array{label: string, muscles: array<string, array{string, BodyView}>}>
     */
    private const AREAS = [
        'chest' => ['label' => 'Chest', 'muscles' => [
            'upper_chest' => ['Upper chest', BodyView::Front],
            'lower_chest' => ['Mid & lower chest', BodyView::Front],
            'serratus' => ['Serratus', BodyView::Front],
        ]],
        'shoulders' => ['label' => 'Shoulders', 'muscles' => [
            'front_delts' => ['Front delts', BodyView::Front],
            'side_delts' => ['Side delts', BodyView::Front],
            'rear_delts' => ['Rear delts', BodyView::Back],
        ]],
        'arms' => ['label' => 'Arms', 'muscles' => [
            'biceps' => ['Biceps', BodyView::Front],
            'triceps_long' => ['Triceps (long head)', BodyView::Back],
            'triceps_lateral' => ['Triceps (lateral head)', BodyView::Back],
            'forearm_flexors' => ['Forearm flexors', BodyView::Front],
            'forearm_extensors' => ['Brachioradialis & forearm extensors', BodyView::Back],
        ]],
        'core' => ['label' => 'Core', 'muscles' => [
            'upper_abs' => ['Upper abs', BodyView::Front],
            'lower_abs' => ['Lower abs', BodyView::Front],
            'obliques' => ['Obliques', BodyView::Front],
        ]],
        'back' => ['label' => 'Back', 'muscles' => [
            'upper_traps' => ['Upper traps', BodyView::Back],
            'mid_traps' => ['Mid traps', BodyView::Back],
            'lower_traps' => ['Lower traps', BodyView::Back],
            'lats' => ['Lats', BodyView::Back],
            'lower_back' => ['Lower back', BodyView::Back],
        ]],
        'legs' => ['label' => 'Legs', 'muscles' => [
            'glute_max' => ['Glute max', BodyView::Back],
            'glute_med' => ['Glute med', BodyView::Back],
            'hip_flexors' => ['Hip flexors', BodyView::Front],
            'adductors' => ['Adductors', BodyView::Front],
            'quads' => ['Quads', BodyView::Front],
            'hamstrings_inner' => ['Inner hamstrings', BodyView::Back],
            'hamstrings_outer' => ['Outer hamstrings', BodyView::Back],
            'gastrocnemius' => ['Calves (gastrocnemius)', BodyView::Back],
            'soleus' => ['Soleus', BodyView::Back],
            'tibialis' => ['Tibialis', BodyView::Front],
        ]],
    ];

    /**
     * Seed the muscle areas and muscles.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $areaOrder = 0;
            foreach (self::AREAS as $areaKey => $area) {
                $muscleArea = MuscleArea::updateOrCreate(
                    ['key' => $areaKey],
                    ['label' => $area['label'], 'sort_order' => ++$areaOrder],
                );

                $muscleOrder = 0;
                foreach ($area['muscles'] as $muscleKey => [$label, $view]) {
                    Muscle::updateOrCreate(
                        ['key' => $muscleKey],
                        [
                            'muscle_area_id' => $muscleArea->id,
                            'label' => $label,
                            'body_view' => $view,
                            'sort_order' => ++$muscleOrder,
                        ],
                    );
                }
            }
        });
    }
}
