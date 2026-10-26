<?php

namespace Tests\Feature\Training;

use App\Models\User;
use Database\Seeders\MuscleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MuscleAreaApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catalog in display order, written out separately from the seeder: area => [muscle key => view].
     *
     * @var array<string, array<string, string>>
     */
    private const EXPECTED = [
        'chest' => ['upper_chest' => 'front', 'lower_chest' => 'front', 'serratus' => 'front'],
        'shoulders' => ['front_delts' => 'front', 'side_delts' => 'front', 'rear_delts' => 'back'],
        'arms' => ['biceps' => 'front', 'triceps_long' => 'back', 'triceps_lateral' => 'back', 'forearm_flexors' => 'front', 'forearm_extensors' => 'back'],
        'core' => ['upper_abs' => 'front', 'lower_abs' => 'front', 'obliques' => 'front'],
        'back' => ['neck' => 'back', 'upper_traps' => 'back', 'mid_traps' => 'back', 'lower_traps' => 'back', 'lats' => 'back', 'lower_back' => 'back'],
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

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/training/muscle-areas')->assertUnauthorized();
    }

    public function test_it_lists_the_areas_and_muscles_in_display_order(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/muscle-areas')
            ->assertOk()
            ->assertJsonCount(6, 'data');

        $areas = $response->json('data');
        $this->assertSame(array_keys(self::EXPECTED), array_column($areas, 'key'));
        $this->assertSame(30, array_sum(array_map(fn (array $area): int => count($area['muscles']), $areas)));

        foreach ($areas as $area) {
            $this->assertSame(['key', 'label', 'muscles'], array_keys($area));
            $this->assertSame(
                self::EXPECTED[$area['key']],
                array_column($area['muscles'], 'body_view', 'key'),
                "Muscles of {$area['key']} are not in display order.",
            );
        }

        $response->assertJsonPath('data.4.muscles.0', ['key' => 'neck', 'label' => 'Neck', 'body_view' => 'back']);
        $response->assertJsonPath('data.0.label', 'Chest');
    }
}
