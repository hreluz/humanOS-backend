<?php

namespace Tests\Feature\Training;

use App\Models\User;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\WorkoutLocation;
use Database\Seeders\EquipmentSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkoutLocationExerciseApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkoutLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EquipmentSeeder::class);
        $this->user = User::factory()->create();
        $this->location = WorkoutLocation::factory()->for($this->user)->create(['updated_at' => now()->subDay()]);
    }

    public function test_put_adds_the_exercise_and_show_lists_keys_sorted_by_name_then_key(): void
    {
        // Added in an order that only the sort can fix. Two share a name, so only key breaks the tie.
        $this->createExercise('squat', 'Squat');
        $this->createExercise('curl_b', 'Curl');
        $this->createExercise('bench', 'Bench Press');
        $this->createExercise('curl_a', 'Curl');

        foreach (['squat', 'curl_b', 'bench', 'curl_a'] as $key) {
            $this->addExercise($key)->assertNoContent();
        }

        $this->actingAs($this->user)->getJson($this->locationUrl())
            ->assertOk()
            ->assertJsonPath('data.exercises', ['bench', 'curl_a', 'curl_b', 'squat'])
            ->assertJsonPath('data.exercise_count', 4);

        $this->actingAs($this->user)->getJson('/api/v1/training/locations')
            ->assertOk()
            ->assertJsonPath('data.0.exercise_count', 4);
    }

    public function test_put_twice_keeps_one_row(): void
    {
        $exercise = $this->createExercise('bench', 'Bench Press');

        $this->addExercise('bench')->assertNoContent();
        $this->addExercise('bench')->assertNoContent();

        $this->assertSame(1, $this->rows($exercise));
    }

    public function test_delete_removes_the_exercise_and_is_idempotent(): void
    {
        $exercise = $this->createExercise('bench', 'Bench Press');
        $this->location->exercises()->attach($exercise);

        $this->removeExercise('bench')->assertNoContent();
        $this->assertSame(0, $this->rows($exercise));

        $this->removeExercise('bench')->assertNoContent();
        $this->assertSame(0, $this->rows($exercise));
    }

    public function test_updated_at_moves_on_a_change_and_stays_on_a_no_op(): void
    {
        $this->createExercise('bench', 'Bench Press');
        $this->createExercise('squat', 'Squat');

        $this->travelTo(now()->addHour());
        $this->addExercise('bench');
        $added = $this->location->fresh()->updated_at;
        $this->assertTrue($added->isSameSecond(now()));

        $this->travelTo(now()->addHour());
        $this->addExercise('bench');
        $this->removeExercise('squat');
        $this->assertTrue($this->location->fresh()->updated_at->equalTo($added));

        $this->travelTo(now()->addHour());
        $this->removeExercise('bench');
        $this->assertTrue($this->location->fresh()->updated_at->isSameSecond(now()));
    }

    public function test_an_unknown_exercise_is_a_404(): void
    {
        $this->addExercise('no_such_exercise')->assertNotFound();
        $this->removeExercise('no_such_exercise')->assertNotFound();
    }

    public function test_another_users_location_is_a_404_and_their_rows_are_untouched(): void
    {
        $bench = $this->createExercise('bench', 'Bench Press');
        $squat = $this->createExercise('squat', 'Squat');
        $theirs = WorkoutLocation::factory()->create();
        $theirs->exercises()->attach($bench);

        $this->actingAs($this->user)->putJson($this->locationUrl($theirs).'/exercises/squat')->assertNotFound();
        $this->actingAs($this->user)->deleteJson($this->locationUrl($theirs).'/exercises/bench')->assertNotFound();

        $this->assertSame([$bench->id], $theirs->exercises()->pluck('exercises.id')->all());
        $this->assertSame(0, $this->rows($squat, $theirs));
    }

    public function test_a_locations_exercise_cannot_be_hard_deleted(): void
    {
        $exercise = $this->createExercise('bench', 'Bench Press');
        $this->location->exercises()->attach($exercise);

        $this->expectException(QueryException::class);

        $exercise->delete();
    }

    private function addExercise(string $exercise): TestResponse
    {
        return $this->actingAs($this->user)->putJson($this->locationUrl()."/exercises/{$exercise}");
    }

    private function removeExercise(string $exercise): TestResponse
    {
        return $this->actingAs($this->user)->deleteJson($this->locationUrl()."/exercises/{$exercise}");
    }

    private function locationUrl(?WorkoutLocation $location = null): string
    {
        return '/api/v1/training/locations/'.($location ?? $this->location)->key;
    }

    private function rows(Exercise $exercise, ?WorkoutLocation $location = null): int
    {
        return DB::table('workout_location_exercise')
            ->where('workout_location_id', ($location ?? $this->location)->id)
            ->where('exercise_id', $exercise->id)
            ->count();
    }

    private function createExercise(string $key, string $name): Exercise
    {
        return Exercise::create([
            'key' => $key,
            'name' => $name,
            'equipment_id' => Equipment::where('key', 'barbell')->value('id'),
            'category' => 'strength',
        ]);
    }
}
