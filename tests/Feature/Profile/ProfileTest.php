<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use App\Modules\Profile\Models\Profile;
use App\Modules\Profile\Models\TargetWeightEntry;
use App\Modules\Profile\Models\WeightEntry;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileTest extends TestCase
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
     * A complete, valid metric PUT body.
     *
     * @return array<string, mixed>
     */
    private static function metricBody(array $overrides = []): array
    {
        return array_merge([
            'unit_system' => 'metric',
            'birth_date' => '1990-04-15',
            'sex' => 'male',
            'height' => 180,
            'weight' => 82.4,
            'target_weight' => 78,
            'activity_level' => 'very_active',
        ], $overrides);
    }

    /**
     * A complete, valid imperial PUT body.
     *
     * @return array<string, mixed>
     */
    private static function imperialBody(array $overrides = []): array
    {
        return array_merge([
            'unit_system' => 'imperial',
            'birth_date' => '1990-04-15',
            'sex' => 'female',
            'height' => 65,
            'weight' => 154.3,
            'target_weight' => 140,
            'activity_level' => 'moderately_active',
        ], $overrides);
    }

    /**
     * Match a JSON number (not a numeric string) equal to the expected value.
     * Whole floats like 65.0 decode as int, so a strict float comparison can't be used.
     */
    private function number(float $expected): Closure
    {
        return fn (mixed $value): bool => (is_int($value) || is_float($value)) && (float) $value === $expected;
    }

    public function test_show_requires_a_token(): void
    {
        $this->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_show_returns_404_without_a_profile(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/profile')->assertNotFound();
    }

    public function test_show_returns_a_metric_profile(): void
    {
        Profile::factory()->for($this->user)->create([
            'birth_date' => '1990-04-15',
            'sex' => 'male',
            'height_cm' => 180.0,
            'activity_level' => 'very_active',
        ]);
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 82.4]);
        TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 78.0]);

        $this->actingAs($this->user)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertExactJson(['data' => [
                'birth_date' => '1990-04-15',
                'age' => 36,
                'sex' => 'male',
                'activity_level' => 'very_active',
                'unit_system' => 'metric',
                'height' => 180.0,
                'weight' => 82.4,
                'target_weight' => 78.0,
                'height_cm' => 180.0,
                'weight_kg' => 82.4,
                'target_weight_kg' => 78.0,
                'updated_at' => '2026-10-11T09:30:00Z',
            ]]);
    }

    public function test_show_returns_an_imperial_profile_in_imperial_and_metric(): void
    {
        Profile::factory()->imperial()->for($this->user)->create(['height_cm' => 165.1]);
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 70.0]);
        TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 63.5]);

        $this->actingAs($this->user)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.unit_system', 'imperial')
            ->assertJsonPath('data.height', $this->number(65.0))
            ->assertJsonPath('data.weight', $this->number(154.3))
            ->assertJsonPath('data.target_weight', $this->number(140.0))
            ->assertJsonPath('data.height_cm', $this->number(165.1))
            ->assertJsonPath('data.weight_kg', $this->number(70.0))
            ->assertJsonPath('data.target_weight_kg', $this->number(63.5));
    }

    public function test_show_reads_weights_from_the_latest_entries(): void
    {
        Profile::factory()->for($this->user)->create();
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 85.0, 'recorded_at' => now()->subDays(2)]);
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 83.0, 'recorded_at' => now()]);
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 84.0, 'recorded_at' => now()->subDay()]);
        TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 80.0, 'recorded_at' => now()]);
        TargetWeightEntry::factory()->for($this->user)->create(['target_weight_kg' => 75.0, 'recorded_at' => now()->subDay()]);
        WeightEntry::factory()->create(['weight_kg' => 99.9, 'recorded_at' => now()->addDay()]);

        $this->actingAs($this->user)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.weight_kg', $this->number(83.0))
            ->assertJsonPath('data.target_weight_kg', $this->number(80.0));
    }

    public function test_show_breaks_recorded_at_ties_by_id(): void
    {
        Profile::factory()->for($this->user)->create();
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 85.0, 'recorded_at' => now()]);
        WeightEntry::factory()->for($this->user)->create(['weight_kg' => 84.0, 'recorded_at' => now()]);

        $this->actingAs($this->user)->getJson('/api/v1/profile')
            ->assertJsonPath('data.weight_kg', $this->number(84.0));
    }

    public function test_show_returns_null_weights_when_there_are_no_entries(): void
    {
        Profile::factory()->for($this->user)->create();

        $this->actingAs($this->user)->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.weight', null)
            ->assertJsonPath('data.weight_kg', null)
            ->assertJsonPath('data.target_weight', null)
            ->assertJsonPath('data.target_weight_kg', null);
    }

    public function test_update_requires_a_token(): void
    {
        $this->putJson('/api/v1/profile', self::metricBody())->assertUnauthorized();
    }

    public function test_update_creates_the_profile(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::metricBody())
            ->assertOk()
            ->assertExactJson(['data' => [
                'birth_date' => '1990-04-15',
                'age' => 36,
                'sex' => 'male',
                'activity_level' => 'very_active',
                'unit_system' => 'metric',
                'height' => 180.0,
                'weight' => 82.4,
                'target_weight' => 78.0,
                'height_cm' => 180.0,
                'weight_kg' => 82.4,
                'target_weight_kg' => 78.0,
                'updated_at' => '2026-10-11T09:30:00Z',
            ]]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $this->user->id,
            'birth_date' => '1990-04-15',
            'sex' => 'male',
            'height_cm' => 180.0,
            'activity_level' => 'very_active',
            'unit_system' => 'metric',
        ]);
    }

    public function test_a_second_update_replaces_the_profile(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::metricBody())->assertOk();

        Carbon::setTestNow(now()->addMinute());
        $this->putJson('/api/v1/profile', self::metricBody([
            'birth_date' => '1985-01-02',
            'sex' => 'female',
            'height' => 170.5,
            'weight' => 65,
            'target_weight' => 60.5,
            'activity_level' => 'sedentary',
        ]))->assertOk();

        $this->assertSame(1, Profile::where('user_id', $this->user->id)->count());

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.birth_date', '1985-01-02')
            ->assertJsonPath('data.sex', 'female')
            ->assertJsonPath('data.height_cm', $this->number(170.5))
            ->assertJsonPath('data.activity_level', 'sedentary')
            ->assertJsonPath('data.weight_kg', $this->number(65.0))
            ->assertJsonPath('data.target_weight_kg', $this->number(60.5));
    }

    public function test_imperial_input_is_stored_in_metric_and_returned_in_imperial(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::imperialBody())
            ->assertOk()
            ->assertJsonPath('data.unit_system', 'imperial')
            ->assertJsonPath('data.height', $this->number(65.0))
            ->assertJsonPath('data.weight', $this->number(154.3))
            ->assertJsonPath('data.target_weight', $this->number(140.0))
            ->assertJsonPath('data.height_cm', $this->number(165.1))
            ->assertJsonPath('data.weight_kg', $this->number(69.99))
            ->assertJsonPath('data.target_weight_kg', $this->number(63.5));

        $this->assertDatabaseHas('profiles', ['user_id' => $this->user->id, 'height_cm' => 165.1, 'unit_system' => 'imperial']);
        $this->assertDatabaseHas('weight_entries', ['user_id' => $this->user->id, 'weight_kg' => 69.99]);
        $this->assertDatabaseHas('target_weight_entries', ['user_id' => $this->user->id, 'target_weight_kg' => 63.5]);
    }

    public function test_an_imperial_weight_comes_back_exactly_as_entered(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::imperialBody(['weight' => 180, 'target_weight' => 165.5]))
            ->assertOk()
            ->assertJsonPath('data.weight', $this->number(180.0))
            ->assertJsonPath('data.weight_kg', $this->number(81.65))
            ->assertJsonPath('data.target_weight', $this->number(165.5));

        $this->getJson('/api/v1/profile')
            ->assertOk()
            ->assertJsonPath('data.weight', $this->number(180.0))
            ->assertJsonPath('data.target_weight', $this->number(165.5));
    }

    public function test_each_update_adds_one_weight_and_one_target_weight_entry(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::metricBody())->assertOk();

        $this->assertSame(1, $this->user->weightEntries()->count());
        $this->assertSame(1, $this->user->targetWeightEntries()->count());
        $this->assertEquals(
            $this->user->weightEntries()->sole()->recorded_at,
            $this->user->targetWeightEntries()->sole()->recorded_at,
        );

        Carbon::setTestNow(now()->addMinute());
        $this->putJson('/api/v1/profile', self::metricBody(['weight' => 81, 'target_weight' => 77]))->assertOk();

        $this->assertSame(2, $this->user->weightEntries()->count());
        $this->assertSame(2, $this->user->targetWeightEntries()->count());
        $this->assertEquals(
            $this->user->latestWeightEntry()->sole()->recorded_at,
            $this->user->latestTargetWeightEntry()->sole()->recorded_at,
        );
    }

    public function test_an_unchanged_update_still_adds_entries(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::metricBody())->assertOk();
        $this->putJson('/api/v1/profile', self::metricBody())->assertOk();

        $this->assertSame(2, $this->user->weightEntries()->count());
        $this->assertSame(2, $this->user->targetWeightEntries()->count());
    }

    public function test_a_failed_update_adds_no_entries(): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', self::metricBody(['weight' => 10]))
            ->assertUnprocessable();

        $this->assertDatabaseCount('profiles', 0);
        $this->assertDatabaseCount('weight_entries', 0);
        $this->assertDatabaseCount('target_weight_entries', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fieldProvider(): array
    {
        return collect(array_keys(self::metricBody()))
            ->mapWithKeys(fn (string $field) => [$field => [$field]])
            ->all();
    }

    #[DataProvider('fieldProvider')]
    public function test_update_requires_every_field(string $field): void
    {
        $body = self::metricBody();
        unset($body[$field]);

        $this->actingAs($this->user)->putJson('/api/v1/profile', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodyProvider(): array
    {
        return [
            'unknown unit system' => [self::metricBody(['unit_system' => 'stones']), 'unit_system'],
            'unknown sex' => [self::metricBody(['sex' => 'other']), 'sex'],
            'unknown activity level' => [self::metricBody(['activity_level' => 'couch']), 'activity_level'],
            'non-numeric height' => [self::metricBody(['height' => 'tall']), 'height'],
            'badly formatted birth date' => [self::metricBody(['birth_date' => '15/04/1990']), 'birth_date'],
            'metric height too low' => [self::metricBody(['height' => 49.9]), 'height'],
            'metric height too high' => [self::metricBody(['height' => 272.1]), 'height'],
            'metric weight too low' => [self::metricBody(['weight' => 19.9]), 'weight'],
            'metric weight too high' => [self::metricBody(['weight' => 500.1]), 'weight'],
            'metric target weight too low' => [self::metricBody(['target_weight' => 19.9]), 'target_weight'],
            'metric target weight too high' => [self::metricBody(['target_weight' => 500.1]), 'target_weight'],
            'imperial height too low' => [self::imperialBody(['height' => 19.6]), 'height'],
            'imperial height too high' => [self::imperialBody(['height' => 107.2]), 'height'],
            'imperial weight too low' => [self::imperialBody(['weight' => 44.0]), 'weight'],
            'imperial weight too high' => [self::imperialBody(['weight' => 1102.4]), 'weight'],
            'imperial target weight too low' => [self::imperialBody(['target_weight' => 44.0]), 'target_weight'],
            'imperial target weight too high' => [self::imperialBody(['target_weight' => 1102.4]), 'target_weight'],
            'metric weight with two decimals' => [self::metricBody(['weight' => 82.45]), 'weight'],
            'metric target weight with two decimals' => [self::metricBody(['target_weight' => 78.05]), 'target_weight'],
            'imperial weight with two decimals' => [self::imperialBody(['weight' => 154.35]), 'weight'],
            'imperial target weight with two decimals' => [self::imperialBody(['target_weight' => 140.25]), 'target_weight'],
            'metric value read as imperial' => [self::imperialBody(['height' => 180]), 'height'],
            'younger than 13' => [self::metricBody(['birth_date' => '2013-10-12']), 'birth_date'],
            'older than 120' => [self::metricBody(['birth_date' => '1905-10-11']), 'birth_date'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidBodyProvider')]
    public function test_update_rejects_invalid_values(array $body, string $field): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function boundaryBodyProvider(): array
    {
        return [
            'metric minimums' => [self::metricBody(['height' => 50, 'weight' => 20, 'target_weight' => 20])],
            'metric maximums' => [self::metricBody(['height' => 272, 'weight' => 500, 'target_weight' => 500])],
            'imperial minimums' => [self::imperialBody(['height' => 19.7, 'weight' => 44.1, 'target_weight' => 44.1])],
            'imperial maximums' => [self::imperialBody(['height' => 107.1, 'weight' => 1102.3, 'target_weight' => 1102.3])],
            'exactly 13 today' => [self::metricBody(['birth_date' => '2013-10-11'])],
            'exactly 120 today' => [self::metricBody(['birth_date' => '1906-10-11'])],
            '120 and turning 121 tomorrow' => [self::metricBody(['birth_date' => '1905-10-12'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('boundaryBodyProvider')]
    public function test_update_accepts_boundary_values(array $body): void
    {
        $this->actingAs($this->user)->putJson('/api/v1/profile', $body)->assertOk();
    }
}
