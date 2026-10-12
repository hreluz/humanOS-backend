<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Modules\Profile\Models\Profile;
use App\Modules\Profile\Models\TargetWeightEntry;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TargetWeightEntryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Carbon::setTestNow('2026-10-11 09:30:00');
    }

    /**
     * Match a JSON number (not a numeric string) equal to the expected value.
     * Whole floats like 65.0 decode as int, so a strict float comparison can't be used.
     */
    private function number(float $expected): Closure
    {
        return fn (mixed $value): bool => (is_int($value) || is_float($value)) && (float) $value === $expected;
    }

    public function test_index_requires_a_token(): void
    {
        $this->getJson('/api/v1/profile/target-weight-entries')->assertUnauthorized();
    }

    public function test_index_lists_the_users_entries_newest_first(): void
    {
        Profile::factory()->for($this->user)->create();
        $old = TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 85.0, 'recorded_at' => now()->subDays(2)]);
        $tieFirst = TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 83.0, 'recorded_at' => now()]);
        $middle = TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 84.0, 'recorded_at' => now()->subDay()]);
        $tieSecond = TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 82.5, 'recorded_at' => now()]);

        $this->actingAs($this->user)->getJson('/api/v1/profile/target-weight-entries')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.*.id', [$tieSecond->id, $tieFirst->id, $middle->id, $old->id])
            ->assertExactJson(['data' => [
                ['id' => $tieSecond->id, 'target_weight' => 82.5, 'target_weight_kg' => 82.5, 'recorded_at' => '2026-10-11T09:30:00Z'],
                ['id' => $tieFirst->id, 'target_weight' => 83.0, 'target_weight_kg' => 83.0, 'recorded_at' => '2026-10-11T09:30:00Z'],
                ['id' => $middle->id, 'target_weight' => 84.0, 'target_weight_kg' => 84.0, 'recorded_at' => '2026-10-10T09:30:00Z'],
                ['id' => $old->id, 'target_weight' => 85.0, 'target_weight_kg' => 85.0, 'recorded_at' => '2026-10-09T09:30:00Z'],
            ]]);
    }

    public function test_index_only_lists_the_users_own_entries(): void
    {
        $mine = TargetWeightEntry::factory()->for($this->user)->create();
        TargetWeightEntry::factory()->count(2)->create();

        $this->actingAs($this->user)->getJson('/api/v1/profile/target-weight-entries')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_target_weight_follows_the_profiles_unit_system(): void
    {
        Profile::factory()->imperial()->for($this->user)->create();
        TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 70.0]);

        $this->actingAs($this->user)->getJson('/api/v1/profile/target-weight-entries')
            ->assertOk()
            ->assertJsonPath('data.0.target_weight', $this->number(154.3))
            ->assertJsonPath('data.0.target_weight_kg', $this->number(70.0));
    }

    public function test_target_weight_is_metric_without_a_profile(): void
    {
        TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 70.0]);

        $this->actingAs($this->user)->getJson('/api/v1/profile/target-weight-entries')
            ->assertOk()
            ->assertJsonPath('data.0.target_weight', $this->number(70.0))
            ->assertJsonPath('data.0.target_weight_kg', $this->number(70.0));
    }

    public function test_index_returns_an_empty_list_without_entries(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/profile/target-weight-entries')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
