<?php

namespace Tests\Feature\Training;

use App\Models\User;
use Database\Seeders\EquipmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentGroupApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The catalog in display order, written out separately from the seeder: group => equipment keys.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED = [
        'gym' => ['barbell', 'machine', 'cable', 'ez_bar'],
        'dumbbell' => ['dumbbell'],
        'bodyweight' => ['body_only'],
        'kettlebell' => ['kettlebell'],
        'bands' => ['bands'],
        'accessories' => ['medicine_ball', 'exercise_ball', 'foam_roll', 'other'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EquipmentSeeder::class);
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/training/equipment-groups')->assertUnauthorized();
    }

    public function test_it_lists_the_groups_and_equipment_in_display_order(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/equipment-groups')
            ->assertOk()
            ->assertJsonCount(6, 'data');

        $groups = $response->json('data');
        $this->assertSame(array_keys(self::EXPECTED), array_column($groups, 'key'));
        $this->assertSame(12, array_sum(array_map(fn (array $group): int => count($group['equipment']), $groups)));

        foreach ($groups as $group) {
            $this->assertSame(['key', 'label', 'equipment'], array_keys($group));
            $this->assertSame(
                self::EXPECTED[$group['key']],
                array_column($group['equipment'], 'key'),
                "Equipment of {$group['key']} is not in display order.",
            );
        }

        $response->assertJsonPath('data.0.label', 'Gym');
        $response->assertJsonPath('data.0.equipment.0', ['key' => 'barbell', 'label' => 'Barbell']);
    }

    public function test_it_does_not_return_the_source_key(): void
    {
        $groups = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/equipment-groups')
            ->assertOk()
            ->json('data');

        foreach ($groups as $group) {
            foreach ($group['equipment'] as $equipment) {
                $this->assertSame(['key', 'label'], array_keys($equipment));
            }
        }
    }
}
