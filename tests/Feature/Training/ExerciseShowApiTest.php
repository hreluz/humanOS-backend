<?php

namespace Tests\Feature\Training;

use App\Models\User;
use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseForce;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\ExerciseMechanic;
use App\Modules\Training\Enums\MuscleRole;
use App\Modules\Training\Enums\PhotoSource;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\Muscle;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\MuscleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExerciseShowApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([MuscleSeeder::class, EquipmentSeeder::class]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->createExercise();

        $this->getJson('/api/v1/training/exercises/barbell_bench_press')->assertUnauthorized();
    }

    public function test_it_returns_the_exercise_with_every_field(): void
    {
        $exercise = $this->createExercise([
            'level' => ExerciseLevel::Beginner,
            'mechanic' => ExerciseMechanic::Compound,
            'force' => ExerciseForce::Push,
            'cue' => 'Shoulder blades pinned back, bar to mid-chest.',
            'instructions' => ['Lie back on a flat bench.', 'Lower the bar to your chest.'],
            'source_id' => 'Barbell_Bench_Press_-_Medium_Grip',
        ]);

        // Attached out of order, so only sort_order can put them right.
        $this->attachMuscle($exercise, 'upper_chest', MuscleRole::Primary, 1);
        $this->attachMuscle($exercise, 'triceps_long', MuscleRole::Secondary, 2);
        $this->attachMuscle($exercise, 'lower_chest', MuscleRole::Primary, 0);
        $this->attachMuscle($exercise, 'triceps_lateral', MuscleRole::Secondary, 1);
        $this->attachMuscle($exercise, 'front_delts', MuscleRole::Secondary, 0);

        // Created out of order, so only position can put them right.
        $this->createPhoto($exercise, ['path' => 'Bench/1.jpg', 'caption' => 'Finish', 'position' => 1]);
        $this->createPhoto($exercise, ['path' => 'Bench/0.jpg', 'caption' => 'Start', 'position' => 0]);

        $base = config('services.exercise_photos.free_exercise_db');

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/exercises/barbell_bench_press')
            ->assertOk();

        $this->assertSame([
            'key' => 'barbell_bench_press',
            'name' => 'Barbell Bench Press',
            'category' => 'strength',
            'level' => 'beginner',
            'mechanic' => 'compound',
            'force' => 'push',
            'cue' => 'Shoulder blades pinned back, bar to mid-chest.',
            'equipment' => 'barbell',
            'equipment_group' => 'gym',
            'primary' => ['lower_chest', 'upper_chest'],
            'secondary' => ['front_delts', 'triceps_lateral', 'triceps_long'],
            'photos' => [
                [
                    'url' => "{$base}/Bench/0.jpg",
                    'caption' => 'Start',
                    'source' => 'free_exercise_db',
                    'license' => 'Unlicense',
                    'author' => null,
                    'credit_url' => null,
                ],
                [
                    'url' => "{$base}/Bench/1.jpg",
                    'caption' => 'Finish',
                    'source' => 'free_exercise_db',
                    'license' => 'Unlicense',
                    'author' => null,
                    'credit_url' => null,
                ],
            ],
            'instructions' => ['Lie back on a flat bench.', 'Lower the bar to your chest.'],
            'source_id' => 'Barbell_Bench_Press_-_Medium_Grip',
        ], $response->json('data'));
    }

    public function test_optional_fields_are_null_when_the_exercise_has_none(): void
    {
        $this->createExercise();

        $data = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/exercises/barbell_bench_press')
            ->assertOk()
            ->json('data');

        foreach (['level', 'mechanic', 'force', 'cue', 'instructions', 'source_id'] as $field) {
            $this->assertArrayHasKey($field, $data);
            $this->assertNull($data[$field], "{$field} should be null.");
        }

        $this->assertSame([], $data['primary']);
        $this->assertSame([], $data['secondary']);
        $this->assertSame([], $data['photos']);
    }

    public function test_photo_urls_follow_the_configured_base_and_wger_photos_carry_credit(): void
    {
        config([
            'services.exercise_photos.free_exercise_db' => 'https://photos.test/free/',
            'services.exercise_photos.wger' => 'https://photos.test/wger',
        ]);

        $exercise = $this->createExercise();
        $this->createPhoto($exercise, ['path' => 'Bench/0.jpg', 'position' => 0]);
        $this->createPhoto($exercise, [
            'source' => PhotoSource::Wger,
            'path' => 'exercise-images/192/bench.png',
            'position' => 1,
            'license' => 'CC-BY-SA 4.0',
            'author' => 'Jane Doe',
            'credit_url' => 'https://wger.de/en/exercise/192',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/exercises/barbell_bench_press')
            ->assertOk();

        $response->assertJsonPath('data.photos.0.url', 'https://photos.test/free/Bench/0.jpg');
        $response->assertJsonPath('data.photos.1.url', 'https://photos.test/wger/exercise-images/192/bench.png');
        $response->assertJsonPath('data.photos.1.source', 'wger');
        $response->assertJsonPath('data.photos.1.license', 'CC-BY-SA 4.0');
        $response->assertJsonPath('data.photos.1.author', 'Jane Doe');
        $response->assertJsonPath('data.photos.1.credit_url', 'https://wger.de/en/exercise/192');
    }

    public function test_an_unknown_key_returns_404_in_the_error_envelope(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/training/exercises/does_not_exist')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('data');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createExercise(array $attributes = []): Exercise
    {
        return Exercise::create([
            'key' => 'barbell_bench_press',
            'name' => 'Barbell Bench Press',
            'equipment_id' => Equipment::where('key', 'barbell')->value('id'),
            'category' => ExerciseCategory::Strength,
            ...$attributes,
        ]);
    }

    private function attachMuscle(Exercise $exercise, string $key, MuscleRole $role, int $sortOrder): void
    {
        $exercise->muscles()->attach(Muscle::where('key', $key)->value('id'), [
            'role' => $role->value,
            'sort_order' => $sortOrder,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createPhoto(Exercise $exercise, array $attributes): void
    {
        $exercise->photos()->create([
            'source' => PhotoSource::FreeExerciseDb,
            'caption' => 'Start',
            'license' => 'Unlicense',
            ...$attributes,
        ]);
    }
}
