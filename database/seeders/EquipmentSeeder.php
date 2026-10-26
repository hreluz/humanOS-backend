<?php

namespace Database\Seeders;

use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\EquipmentGroup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The equipment catalog, grouped the way clients filter exercises (SPEC 07).
 *
 * Rows are upserted by `key`, so running it again updates them in place and keeps their ids.
 * It never deletes: removed equipment gets its own migration.
 */
class EquipmentSeeder extends Seeder
{
    /**
     * Groups in display order, each with its equipment in display order: key => [label, free-exercise-db value].
     *
     * @var array<string, array{label: string, equipment: array<string, array{string, string}>}>
     */
    private const GROUPS = [
        'gym' => ['label' => 'Gym', 'equipment' => [
            'barbell' => ['Barbell', 'barbell'],
            'machine' => ['Machine', 'machine'],
            'cable' => ['Cable', 'cable'],
            'ez_bar' => ['EZ curl bar', 'e-z curl bar'],
        ]],
        'dumbbell' => ['label' => 'Dumbbell', 'equipment' => [
            'dumbbell' => ['Dumbbell', 'dumbbell'],
        ]],
        'bodyweight' => ['label' => 'Bodyweight', 'equipment' => [
            'body_only' => ['Body only', 'body only'],
        ]],
        'kettlebell' => ['label' => 'Kettlebell', 'equipment' => [
            'kettlebell' => ['Kettlebell', 'kettlebells'],
        ]],
        'bands' => ['label' => 'Bands', 'equipment' => [
            'bands' => ['Resistance bands', 'bands'],
        ]],
        'accessories' => ['label' => 'Accessories', 'equipment' => [
            'medicine_ball' => ['Medicine ball', 'medicine ball'],
            'exercise_ball' => ['Exercise ball', 'exercise ball'],
            'foam_roll' => ['Foam roll', 'foam roll'],
            'other' => ['Other', 'other'],
        ]],
    ];

    /**
     * Seed the equipment groups and equipment.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $groupOrder = 0;
            foreach (self::GROUPS as $groupKey => $group) {
                $equipmentGroup = EquipmentGroup::updateOrCreate(
                    ['key' => $groupKey],
                    ['label' => $group['label'], 'sort_order' => ++$groupOrder],
                );

                $equipmentOrder = 0;
                foreach ($group['equipment'] as $equipmentKey => [$label, $sourceKey]) {
                    Equipment::updateOrCreate(
                        ['key' => $equipmentKey],
                        [
                            'equipment_group_id' => $equipmentGroup->id,
                            'label' => $label,
                            'source_key' => $sourceKey,
                            'sort_order' => ++$equipmentOrder,
                        ],
                    );
                }
            }
        });
    }
}
