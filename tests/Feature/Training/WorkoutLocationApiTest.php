<?php

namespace Tests\Feature\Training;

use App\Models\User;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\WorkoutLocation;
use Database\Seeders\EquipmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkoutLocationApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/training/locations';

    /**
     * @return array<string, array{string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['get', self::URL],
            'store' => ['post', self::URL],
            'show' => ['get', self::URL.'/l_x'],
            'update' => ['patch', self::URL.'/l_x'],
            'destroy' => ['delete', self::URL.'/l_x'],
            'add exercise' => ['put', self::URL.'/l_x/exercises/bench'],
            'remove exercise' => ['delete', self::URL.'/l_x/exercises/bench'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_every_route_requires_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    public function test_create_returns_the_new_location_with_no_exercises(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson(self::URL, ['name' => '  Gym Local  '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Gym Local')
            ->assertJsonPath('data.exercise_count', 0)
            ->assertJsonPath('data.exercises', []);

        $key = $response->json('data.key');
        $this->assertMatchesRegularExpression('/^l_[0-9A-Z]{26}$/', $key);
        $this->assertIsString($response->json('data.created_at'));
        $this->assertStringEndsWith('Z', $response->json('data.updated_at'));
        $this->assertDatabaseHas('workout_locations', ['key' => $key, 'user_id' => $user->id, 'name' => 'Gym Local']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidNameProvider(): array
    {
        return [
            'missing' => [[]],
            'blank' => [['name' => '   ']],
            'too long' => [['name' => str_repeat('a', 51)]],
            'not a string' => [['name' => ['Gym']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidNameProvider')]
    public function test_an_invalid_name_returns_422(array $body): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(self::URL, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_a_50_character_name_is_allowed(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['name' => str_repeat('a', 50)])
            ->assertCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function duplicateNameProvider(): array
    {
        return [
            'same' => ['Gimnasio'],
            'other case' => ['GIMNASIO'],
            'other accent' => ['gimnásio'],
        ];
    }

    #[DataProvider('duplicateNameProvider')]
    public function test_a_duplicate_name_returns_422(string $name): void
    {
        $user = User::factory()->create();
        WorkoutLocation::factory()->for($user)->create(['name' => 'Gimnasio']);

        $this->actingAs($user)->postJson(self::URL, ['name' => $name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_another_user_may_use_the_same_name(): void
    {
        WorkoutLocation::factory()->create(['name' => 'Gimnasio']);

        $this->actingAs(User::factory()->create())
            ->postJson(self::URL, ['name' => 'Gimnasio'])
            ->assertCreated();
    }

    public function test_the_21st_location_returns_422_and_other_users_locations_do_not_count(): void
    {
        $user = User::factory()->create();
        WorkoutLocation::factory()->count(5)->create();
        WorkoutLocation::factory()->for($user)->count(19)->create();

        $this->actingAs($user)->postJson(self::URL, ['name' => 'Twentieth'])->assertCreated();

        $this->actingAs($user)->postJson(self::URL, ['name' => 'Twenty-first'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'You can have at most 20 workout locations.']);

        $this->assertSame(20, $user->workoutLocations()->count());
    }

    public function test_the_list_has_only_the_callers_locations_sorted_by_name_then_key(): void
    {
        $user = User::factory()->create();
        WorkoutLocation::factory()->create(['name' => 'Someone else']);
        $house = WorkoutLocation::factory()->for($user)->create(['name' => 'House workout', 'key' => 'l_A']);
        $gym = WorkoutLocation::factory()->for($user)->create(['name' => 'Gym Local', 'key' => 'l_B']);
        $big = WorkoutLocation::factory()->for($user)->create(['name' => 'Big gym', 'key' => 'l_C']);

        $exercises = $this->createExercises(['bench', 'squat']);
        $gym->exercises()->attach($exercises->pluck('id'));
        $big->exercises()->attach($exercises->first()->id);

        $response = $this->actingAs($user)->getJson(self::URL)->assertOk()->assertJsonCount(3, 'data');

        $this->assertSame(['l_C', 'l_B', 'l_A'], array_column($response->json('data'), 'key'));
        $this->assertSame([1, 2, 0], array_column($response->json('data'), 'exercise_count'));
        $this->assertArrayNotHasKey('exercises', $response->json('data.0'));
        $this->assertSame($house->name, $response->json('data.2.name'));
    }

    public function test_show_returns_the_detail_shape(): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create(['name' => 'Gym Local']);
        $location->exercises()->attach($this->createExercises(['bench'])->pluck('id'));

        $this->actingAs($user)->getJson(self::URL."/{$location->key}")
            ->assertOk()
            ->assertExactJson(['data' => [
                'key' => $location->key,
                'name' => 'Gym Local',
                'exercise_count' => 1,
                'exercises' => ['bench'],
                'created_at' => $location->created_at->toIso8601ZuluString(),
                'updated_at' => $location->updated_at->toIso8601ZuluString(),
            ]]);
    }

    public function test_an_empty_patch_returns_200_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create(['name' => 'Gym Local', 'updated_at' => now()->subDay()]);
        $before = $location->fresh()->updated_at->toIso8601ZuluString();

        $this->actingAs($user)->patchJson(self::URL."/{$location->key}", [])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gym Local')
            ->assertJsonPath('data.updated_at', $before)
            ->assertJsonPath('data.exercises', []);

        $this->assertSame($before, $location->fresh()->updated_at->toIso8601ZuluString());
    }

    public function test_patch_renames_the_location_and_keeps_its_key(): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create(['name' => 'Gym Local']);

        $this->actingAs($user)->patchJson(self::URL."/{$location->key}", ['name' => 'Big gym'])
            ->assertOk()
            ->assertJsonPath('data.key', $location->key)
            ->assertJsonPath('data.name', 'Big gym');

        $this->assertSame('Big gym', $location->fresh()->name);
    }

    public function test_patch_may_keep_the_current_name_in_any_case(): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create(['name' => 'Gym Local']);

        $this->actingAs($user)->patchJson(self::URL."/{$location->key}", ['name' => 'Gym Local'])->assertOk();
        $this->actingAs($user)->patchJson(self::URL."/{$location->key}", ['name' => 'GYM LOCAL'])
            ->assertOk()
            ->assertJsonPath('data.name', 'GYM LOCAL');
    }

    public function test_patch_to_another_locations_name_returns_422(): void
    {
        $user = User::factory()->create();
        WorkoutLocation::factory()->for($user)->create(['name' => 'House workout']);
        $location = WorkoutLocation::factory()->for($user)->create(['name' => 'Gym Local']);

        $this->actingAs($user)->patchJson(self::URL."/{$location->key}", ['name' => 'house WORKOUT'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidPatchNameProvider(): array
    {
        return array_diff_key(self::invalidNameProvider(), ['missing' => true]) + ['null' => [['name' => null]]];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidPatchNameProvider')]
    public function test_patch_with_an_invalid_name_returns_422(array $body): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create();

        $this->actingAs($user)->patchJson(self::URL."/{$location->key}", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_delete_removes_the_location_and_its_exercise_rows(): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create();
        $exercises = $this->createExercises(['bench', 'squat']);
        $location->exercises()->attach($exercises->pluck('id'));

        $this->actingAs($user)->deleteJson(self::URL."/{$location->key}")->assertNoContent();

        $this->assertModelMissing($location);
        $this->assertDatabaseMissing('workout_location_exercise', ['workout_location_id' => $location->id]);
        $this->assertSame(2, Exercise::count());
    }

    public function test_another_users_location_is_a_404(): void
    {
        $location = WorkoutLocation::factory()->create(['name' => 'Theirs']);
        $user = User::factory()->create();
        $url = self::URL."/{$location->key}";

        $this->actingAs($user)->getJson($url)->assertNotFound();
        $this->actingAs($user)->patchJson($url, ['name' => 'Mine now'])->assertNotFound();
        $this->actingAs($user)->deleteJson($url)->assertNotFound();

        $this->assertSame('Theirs', $location->fresh()->name);
    }

    public function test_an_unknown_key_is_a_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(self::URL.'/l_'.Str::ulid())
            ->assertNotFound();
    }

    public function test_deleting_the_user_deletes_their_locations_and_exercise_rows(): void
    {
        $user = User::factory()->create();
        $location = WorkoutLocation::factory()->for($user)->create();
        $location->exercises()->attach($this->createExercises(['bench'])->pluck('id'));
        $other = WorkoutLocation::factory()->create();

        $user->delete();

        $this->assertModelMissing($location);
        $this->assertModelExists($other);
        $this->assertSame(0, DB::table('workout_location_exercise')->count());
    }

    /**
     * @param  list<string>  $keys
     * @return Collection<int, Exercise>
     */
    private function createExercises(array $keys): Collection
    {
        $this->seed(EquipmentSeeder::class);
        $equipment = Equipment::where('key', 'barbell')->value('id');

        return collect($keys)->map(fn (string $key): Exercise => Exercise::create([
            'key' => $key,
            'name' => Str::headline($key),
            'equipment_id' => $equipment,
            'category' => 'strength',
        ]));
    }
}
