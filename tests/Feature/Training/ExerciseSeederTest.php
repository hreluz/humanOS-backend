<?php

namespace Tests\Feature\Training;

use App\Modules\Training\Enums\PhotoSource;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\ExercisePhoto;
use App\Modules\Training\Models\Muscle;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\MuscleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ExerciseSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([MuscleSeeder::class, EquipmentSeeder::class, ExerciseSeeder::class]);
    }

    public function test_it_seeds_every_exercise_in_the_file(): void
    {
        $entries = json_decode(file_get_contents(database_path('data/exercises.json')), true);

        $this->assertGreaterThanOrEqual(138, count($entries));
        $this->assertSame(count($entries), Exercise::count());
        $this->assertSame(array_column($entries, 'key'), Exercise::orderBy('id')->pluck('key')->all());
    }

    public function test_tags_and_photos_match_the_file(): void
    {
        $exercise = Exercise::with('equipment')->where('key', 'barbell_bench_press')->firstOrFail();

        $this->assertSame('barbell', $exercise->equipment->key);
        $this->assertSame('Barbell_Bench_Press_-_Medium_Grip', $exercise->source_id);
        $this->assertSame(['lower_chest', 'upper_chest'], $exercise->primaryMuscles->pluck('key')->all());
        $this->assertSame(['front_delts', 'triceps_lateral', 'triceps_long'], $exercise->secondaryMuscles->pluck('key')->all());
        $this->assertSame([1, 2, 3], $exercise->secondaryMuscles->pluck('pivot.sort_order')->all());

        $this->assertSame([
            [1, 'Barbell_Bench_Press_-_Medium_Grip/0.jpg', 'Start'],
            [2, 'Barbell_Bench_Press_-_Medium_Grip/1.jpg', 'End'],
            [3, 'Wide-Grip_Barbell_Bench_Press/0.jpg', 'Wide-Grip Barbell Bench Press · start'],
            [4, 'Wide-Grip_Barbell_Bench_Press/1.jpg', 'Wide-Grip Barbell Bench Press · end'],
            [5, 'Smith_Machine_Bench_Press/0.jpg', 'Smith Machine Bench Press · start'],
            [6, 'Smith_Machine_Bench_Press/1.jpg', 'Smith Machine Bench Press · end'],
        ], $exercise->photos->map(fn (ExercisePhoto $photo) => [$photo->position, $photo->path, $photo->caption])->all());

        foreach ($exercise->photos as $photo) {
            $this->assertSame(PhotoSource::FreeExerciseDb, $photo->source);
            $this->assertSame('Unlicense', $photo->license);
        }
    }

    public function test_seeding_twice_keeps_the_counts_and_ids(): void
    {
        $ids = Exercise::orderBy('key')->pluck('id', 'key')->all();
        $tags = DB::table('exercise_muscle')->count();
        $photos = ExercisePhoto::count();

        $this->seed(ExerciseSeeder::class);

        $this->assertSame($ids, Exercise::orderBy('key')->pluck('id', 'key')->all());
        $this->assertSame($tags, DB::table('exercise_muscle')->count());
        $this->assertSame($photos, ExercisePhoto::count());
    }

    public function test_seeding_again_restores_a_changed_name_and_a_removed_tag(): void
    {
        $exercise = Exercise::where('key', 'push_up')->firstOrFail();
        $exercise->update(['name' => 'Press-up']);
        $exercise->muscles()->detach(Muscle::where('key', 'serratus')->value('id'));

        $this->seed(ExerciseSeeder::class);

        $exercise->refresh();
        $this->assertSame('Push-up', $exercise->name);
        $this->assertContains('serratus', $exercise->secondaryMuscles->pluck('key')->all());
    }

    public function test_an_unknown_muscle_key_throws_and_writes_nothing(): void
    {
        $entry = fn (string $key, string $muscle) => [
            'key' => $key, 'name' => $key, 'equipment' => 'body_only', 'category' => 'strength',
            'level' => null, 'mechanic' => null, 'force' => null, 'cue' => null, 'instructions' => null, 'source_id' => null,
            'primary' => [$muscle], 'secondary' => [], 'photos' => [],
        ];
        $path = tempnam(sys_get_temp_dir(), 'exercises');
        file_put_contents($path, json_encode([$entry('valid_exercise', 'quads'), $entry('broken_exercise', 'neck')]));
        $count = Exercise::count();

        try {
            (new ExerciseSeeder($path))->run();
            $this->fail('The seeder accepted an unknown muscle key.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unknown muscle [neck]', $e->getMessage());
        } finally {
            unlink($path);
        }

        $this->assertSame($count, Exercise::count());
        $this->assertFalse(Exercise::where('key', 'valid_exercise')->exists());
    }

    public function test_a_muscle_used_by_an_exercise_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);

        Muscle::where('key', 'quads')->firstOrFail()->delete();
    }

    public function test_deleting_an_exercise_deletes_its_tags_and_photos(): void
    {
        $exercise = Exercise::where('key', 'barbell_bench_press')->firstOrFail();

        $exercise->delete();

        $this->assertSame(0, DB::table('exercise_muscle')->where('exercise_id', $exercise->id)->count());
        $this->assertSame(0, ExercisePhoto::where('exercise_id', $exercise->id)->count());
    }

    public function test_photo_urls_follow_the_configured_base(): void
    {
        $photo = ExercisePhoto::where('path', 'Barbell_Bench_Press_-_Medium_Grip/0.jpg')->firstOrFail();

        config(['services.exercise_photos.free_exercise_db' => 'https://cdn.example.com/exercises/']);
        $this->assertSame('https://cdn.example.com/exercises/Barbell_Bench_Press_-_Medium_Grip/0.jpg', $photo->url);

        $wger = new ExercisePhoto(['source' => PhotoSource::Wger, 'path' => 'exercise-images/91/Crunches-1.png']);
        config(['services.exercise_photos.wger' => 'https://wger.example.com/media']);
        $this->assertSame('https://wger.example.com/media/exercise-images/91/Crunches-1.png', $wger->url);
    }
}
