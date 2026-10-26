<?php

namespace Tests\Feature\Training;

use App\Modules\Training\Enums\BodyView;
use App\Modules\Training\Models\Muscle;
use App\Modules\Training\Models\MuscleArea;
use Database\Seeders\MuscleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MuscleSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catalog from SPEC 06, written out separately from the seeder: area => [muscle key => view].
     *
     * @var array<string, array<string, string>>
     */
    private const EXPECTED = [
        'chest' => ['upper_chest' => 'front', 'lower_chest' => 'front', 'serratus' => 'front'],
        'shoulders' => ['front_delts' => 'front', 'side_delts' => 'front', 'rear_delts' => 'back'],
        'arms' => ['biceps' => 'front', 'triceps_long' => 'back', 'triceps_lateral' => 'back', 'forearm_flexors' => 'front', 'forearm_extensors' => 'back'],
        'core' => ['upper_abs' => 'front', 'lower_abs' => 'front', 'obliques' => 'front'],
        'back' => ['upper_traps' => 'back', 'mid_traps' => 'back', 'lower_traps' => 'back', 'lats' => 'back', 'lower_back' => 'back'],
        'legs' => [
            'glute_max' => 'back', 'glute_med' => 'back', 'hip_flexors' => 'front', 'adductors' => 'front', 'quads' => 'front',
            'hamstrings_inner' => 'back', 'hamstrings_outer' => 'back', 'gastrocnemius' => 'back', 'soleus' => 'back', 'tibialis' => 'front',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MuscleSeeder::class);
    }

    public function test_it_seeds_the_areas_and_muscles_in_order(): void
    {
        $this->assertSame(6, MuscleArea::count());
        $this->assertSame(29, Muscle::count());

        $this->assertSame(array_keys(self::EXPECTED), MuscleArea::orderBy('sort_order')->pluck('key')->all());

        foreach (self::EXPECTED as $areaKey => $muscles) {
            $area = MuscleArea::where('key', $areaKey)->firstOrFail();
            $this->assertSame(array_keys($muscles), $area->muscles->pluck('key')->all(), "Muscles of {$areaKey}");
            $this->assertSame(range(1, count($muscles)), $area->muscles->pluck('sort_order')->all(), "Order of {$areaKey}");
        }
    }

    public function test_every_muscle_has_its_view_and_area(): void
    {
        foreach (self::EXPECTED as $areaKey => $muscles) {
            foreach ($muscles as $muscleKey => $view) {
                $muscle = Muscle::with('area')->where('key', $muscleKey)->firstOrFail();
                $this->assertSame(BodyView::from($view), $muscle->body_view, "View of {$muscleKey}");
                $this->assertSame($areaKey, $muscle->area->key, "Area of {$muscleKey}");
            }
        }
    }

    public function test_it_labels_the_forearm_extensors_with_the_brachioradialis(): void
    {
        $this->assertSame('Brachioradialis & forearm extensors', Muscle::where('key', 'forearm_extensors')->value('label'));
    }

    public function test_seeding_twice_keeps_the_counts_and_ids(): void
    {
        $areaIds = MuscleArea::orderBy('key')->pluck('id', 'key')->all();
        $muscleIds = Muscle::orderBy('key')->pluck('id', 'key')->all();

        $this->seed(MuscleSeeder::class);

        $this->assertSame(6, MuscleArea::count());
        $this->assertSame(29, Muscle::count());
        $this->assertSame($areaIds, MuscleArea::orderBy('key')->pluck('id', 'key')->all());
        $this->assertSame($muscleIds, Muscle::orderBy('key')->pluck('id', 'key')->all());
    }

    public function test_seeding_again_restores_a_changed_label(): void
    {
        Muscle::where('key', 'lats')->update(['label' => 'Wings']);
        MuscleArea::where('key', 'legs')->update(['label' => 'Lower body']);

        $this->seed(MuscleSeeder::class);

        $this->assertSame('Lats', Muscle::where('key', 'lats')->value('label'));
        $this->assertSame('Legs', MuscleArea::where('key', 'legs')->value('label'));
    }

    public function test_an_area_with_muscles_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);

        MuscleArea::where('key', 'chest')->firstOrFail()->delete();
    }
}
