<?php

namespace Tests\Feature\Training;

use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\EquipmentGroup;
use Database\Seeders\EquipmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catalog from SPEC 07, written out separately from the seeder: group => [equipment key => free-exercise-db value].
     *
     * @var array<string, array<string, string>>
     */
    private const EXPECTED = [
        'gym' => ['barbell' => 'barbell', 'machine' => 'machine', 'cable' => 'cable', 'ez_bar' => 'e-z curl bar'],
        'dumbbell' => ['dumbbell' => 'dumbbell'],
        'bodyweight' => ['body_only' => 'body only'],
        'kettlebell' => ['kettlebell' => 'kettlebells'],
        'bands' => ['bands' => 'bands'],
        'accessories' => ['medicine_ball' => 'medicine ball', 'exercise_ball' => 'exercise ball', 'foam_roll' => 'foam roll', 'other' => 'other'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EquipmentSeeder::class);
    }

    public function test_it_seeds_the_groups_and_equipment_in_order(): void
    {
        $this->assertSame(6, EquipmentGroup::count());
        $this->assertSame(12, Equipment::count());

        $this->assertSame(array_keys(self::EXPECTED), EquipmentGroup::orderBy('sort_order')->pluck('key')->all());

        foreach (self::EXPECTED as $groupKey => $equipment) {
            $group = EquipmentGroup::where('key', $groupKey)->firstOrFail();
            $this->assertSame(array_keys($equipment), $group->equipment->pluck('key')->all(), "Equipment of {$groupKey}");
            $this->assertSame(range(1, count($equipment)), $group->equipment->pluck('sort_order')->all(), "Order of {$groupKey}");
        }
    }

    public function test_every_piece_of_equipment_has_its_source_key_and_group(): void
    {
        foreach (self::EXPECTED as $groupKey => $equipment) {
            foreach ($equipment as $equipmentKey => $sourceKey) {
                $item = Equipment::with('group')->where('key', $equipmentKey)->firstOrFail();
                $this->assertSame($sourceKey, $item->source_key, "Source key of {$equipmentKey}");
                $this->assertSame($groupKey, $item->group->key, "Group of {$equipmentKey}");
            }
        }
    }

    public function test_seeding_twice_keeps_the_counts_and_ids(): void
    {
        $groupIds = EquipmentGroup::orderBy('key')->pluck('id', 'key')->all();
        $equipmentIds = Equipment::orderBy('key')->pluck('id', 'key')->all();

        $this->seed(EquipmentSeeder::class);

        $this->assertSame(6, EquipmentGroup::count());
        $this->assertSame(12, Equipment::count());
        $this->assertSame($groupIds, EquipmentGroup::orderBy('key')->pluck('id', 'key')->all());
        $this->assertSame($equipmentIds, Equipment::orderBy('key')->pluck('id', 'key')->all());
    }

    public function test_seeding_again_restores_a_changed_label(): void
    {
        Equipment::where('key', 'cable')->update(['label' => 'Pulley']);
        EquipmentGroup::where('key', 'gym')->update(['label' => 'Machines']);

        $this->seed(EquipmentSeeder::class);

        $this->assertSame('Cable', Equipment::where('key', 'cable')->value('label'));
        $this->assertSame('Gym', EquipmentGroup::where('key', 'gym')->value('label'));
    }

    public function test_a_group_with_equipment_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);

        EquipmentGroup::where('key', 'gym')->firstOrFail()->delete();
    }
}
