<?php

namespace Tests\Feature\Training;

use App\Models\User;
use App\Modules\Training\Enums\MuscleRole;
use App\Modules\Training\Enums\PhotoSource;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\Muscle;
use App\Modules\Training\Models\WorkoutLocation;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\MuscleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ExerciseLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private WorkoutLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([MuscleSeeder::class, EquipmentSeeder::class]);
        $this->user = User::factory()->create();
        $this->location = WorkoutLocation::factory()->for($this->user)->create();
    }

    public function test_only_the_locations_exercises_come_back(): void
    {
        $this->atLocation($this->createExercise('squat', 'Squat'));
        $this->atLocation($this->createExercise('bench', 'Bench Press'));
        $this->createExercise('deadlift', 'Deadlift');
        WorkoutLocation::factory()->create()->exercises()->attach($this->createExercise('curl', 'Curl'));

        $this->list()
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->assertKeys(['bench', 'squat']);
    }

    public function test_an_empty_location_returns_nothing(): void
    {
        $this->createExercise('squat', 'Squat');

        $this->list()
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('data', []);
    }

    public function test_it_combines_with_equipment_group_equipment_and_search(): void
    {
        $this->atLocation($this->createExercise('barbell_squat', 'Barbell Squat', 'barbell'));
        $this->atLocation($this->createExercise('barbell_row', 'Barbell Row', 'barbell'));
        $this->atLocation($this->createExercise('kettlebell_swing', 'Kettlebell Swing', 'kettlebell'));
        $this->createExercise('cable_row', 'Cable Row', 'cable');

        $this->assertKeys(['barbell_row', 'barbell_squat'], 'equipment_group[]=gym');
        $this->assertKeys(['kettlebell_swing'], 'equipment[]=kettlebell');
        $this->assertKeys(['barbell_row'], 'search=row');
        $this->assertKeys([], 'equipment[]=cable');
    }

    public function test_it_combines_with_a_muscle_and_keeps_the_ranking(): void
    {
        // At the location: a secondary, a primary at position 2, a primary at position 1. Not at it: a primary at 1.
        $this->atLocation($this->createTaggedExercise('a_lunge', 'A Lunge', secondary: ['quads']));
        $this->atLocation($this->createTaggedExercise('b_squat', 'B Squat', primary: ['glute_max', 'quads']));
        $this->atLocation($this->createTaggedExercise('c_leg_extension', 'C Leg Extension', primary: ['quads']));
        $this->createTaggedExercise('d_leg_press', 'D Leg Press', primary: ['quads']);

        $this->assertKeys(['c_leg_extension', 'b_squat', 'a_lunge'], 'muscle=quads');
        $this->assertKeys(['c_leg_extension', 'b_squat'], 'muscle=quads&role=primary');
    }

    public function test_it_combines_with_a_muscle_and_an_equipment_group(): void
    {
        $this->atLocation($this->createTaggedExercise('barbell_press', 'Barbell Press', primary: ['lower_chest'], equipment: 'barbell'));
        $this->atLocation($this->createTaggedExercise('dumbbell_press', 'Dumbbell Press', primary: ['lower_chest'], equipment: 'dumbbell'));
        $this->atLocation($this->createTaggedExercise('dumbbell_curl', 'Dumbbell Curl', primary: ['biceps'], equipment: 'dumbbell'));
        $this->createTaggedExercise('dumbbell_fly', 'Dumbbell Fly', primary: ['lower_chest'], equipment: 'dumbbell');

        $this->assertKeys(['dumbbell_press'], 'muscle=lower_chest&equipment_group[]=dumbbell');
        $this->assertKeys(['barbell_press'], 'muscle=lower_chest&equipment_group[]=gym');
    }

    public function test_another_users_or_an_unknown_key_returns_422(): void
    {
        $theirs = WorkoutLocation::factory()->create();

        $this->list("location={$theirs->key}")->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->list('location=l_'.Str::ulid())->assertUnprocessable()->assertJsonValidationErrors('location');
        $this->list('location[]=x')->assertUnprocessable()->assertJsonValidationErrors('location');
    }

    public function test_the_query_count_does_not_depend_on_the_page_size(): void
    {
        foreach (range(1, 30) as $number) {
            $exercise = $this->createTaggedExercise("exercise_{$number}", "Exercise {$number}", primary: ['quads']);
            $exercise->photos()->create([
                'source' => PhotoSource::FreeExerciseDb,
                'path' => "Exercise/{$number}.jpg",
                'caption' => 'Start',
                'position' => 0,
                'license' => 'Unlicense',
            ]);
            $this->atLocation($exercise);
        }

        $this->assertSame($this->countQueries('per_page=10', 10), $this->countQueries('per_page=100', 30));
        $this->assertSame($this->countQueries('muscle=quads&per_page=10', 10), $this->countQueries('muscle=quads&per_page=100', 30));
    }

    private function countQueries(string $query, int $expectedItems): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->list($query)->assertOk()->assertJsonCount($expectedItems, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertKeys(array $expected, string $query = ''): void
    {
        $response = $this->list($query)->assertOk();

        $this->assertSame($expected, array_column($response->json('data'), 'key'));
    }

    /**
     * The list filtered by the test's location, unless the query sets its own `location`.
     */
    private function list(string $query = ''): TestResponse
    {
        $location = str_contains($query, 'location') ? '' : "location={$this->location->key}&";

        return $this->actingAs($this->user)->getJson("/api/v1/training/exercises?{$location}{$query}");
    }

    private function atLocation(Exercise $exercise): void
    {
        $this->location->exercises()->attach($exercise);
    }

    /**
     * @param  list<string>  $primary
     * @param  list<string>  $secondary
     */
    private function createTaggedExercise(
        string $key,
        string $name,
        array $primary = [],
        array $secondary = [],
        string $equipment = 'barbell',
    ): Exercise {
        $exercise = $this->createExercise($key, $name, $equipment);

        foreach ([MuscleRole::Primary->value => $primary, MuscleRole::Secondary->value => $secondary] as $role => $muscles) {
            foreach ($muscles as $index => $muscle) {
                $exercise->muscles()->attach(Muscle::where('key', $muscle)->sole()->id, ['role' => $role, 'sort_order' => $index + 1]);
            }
        }

        return $exercise;
    }

    private function createExercise(string $key, string $name, string $equipment = 'barbell'): Exercise
    {
        return Exercise::create([
            'key' => $key,
            'name' => $name,
            'equipment_id' => Equipment::where('key', $equipment)->value('id'),
            'category' => 'strength',
        ]);
    }
}
