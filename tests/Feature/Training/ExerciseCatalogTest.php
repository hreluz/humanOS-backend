<?php

namespace Tests\Feature\Training;

use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseForce;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\ExerciseMechanic;
use App\Modules\Training\Enums\PhotoSource;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Muscle;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\ExerciseSeeder;
use Database\Seeders\MuscleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checks the catalog files in `database/data/exercises` against the tagging rules in `docs/exercise-tagging.md` (SPEC 08, SPEC 09).
 */
class ExerciseCatalogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Groups every muscle must have a primary exercise in.
     *
     * @var list<string>
     */
    private const COVERED_GROUPS = ['gym', 'dumbbell', 'bodyweight', 'kettlebell', 'bands', 'accessories'];

    /**
     * Muscle and group pairs with no recognised exercise, documented in `docs/exercise-tagging.md`.
     *
     * @var list<array{string, string}>
     */
    private const COVERAGE_EXCEPTIONS = [
        ['forearm_extensors', 'bodyweight'],
        ['neck', 'dumbbell'],
        ['serratus', 'kettlebell'],
        ['neck', 'kettlebell'],
        ['lower_traps', 'kettlebell'],
        ['glute_med', 'kettlebell'],
        ['hip_flexors', 'kettlebell'],
        ['soleus', 'bands'],
        ['serratus', 'accessories'],
    ];

    /**
     * Muscles with no recognised stretch, documented in `docs/exercise-tagging.md`.
     *
     * @var list<string>
     */
    private const STRETCH_EXCEPTIONS = ['serratus', 'lower_traps'];

    /**
     * @var list<array<string, mixed>>
     */
    private array $exercises;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([MuscleSeeder::class, EquipmentSeeder::class]);

        $this->exercises = ExerciseSeeder::entries();
    }

    public function test_keys_are_unique_snake_case_and_source_ids_are_unique(): void
    {
        $keys = array_column($this->exercises, 'key');
        $this->assertSame($keys, array_unique($keys), 'Duplicate keys');

        foreach ($keys as $key) {
            $this->assertMatchesRegularExpression('/^[a-z0-9]+(_[a-z0-9]+)*$/', $key);
        }

        $sourceIds = array_filter(array_column($this->exercises, 'source_id'));
        $this->assertSame(array_values($sourceIds), array_values(array_unique($sourceIds)), 'Duplicate source ids');
    }

    public function test_every_free_exercise_db_exercise_is_in_the_catalog_or_skipped(): void
    {
        $ids = json_decode(file_get_contents(base_path('tests/Fixtures/free-exercise-db-ids.json')), true, flags: JSON_THROW_ON_ERROR);
        $skipped = array_column(json_decode(file_get_contents(database_path('data/exercises/'.ExerciseSeeder::SKIPPED_FILE)), true, flags: JSON_THROW_ON_ERROR), 'source_id');
        $sourceIds = array_values(array_filter(array_column($this->exercises, 'source_id')));

        $this->assertSame([], array_values(array_diff($ids, $sourceIds, $skipped)), 'free-exercise-db ids neither in the catalog nor skipped');
        $this->assertSame([], array_values(array_diff([...$sourceIds, ...$skipped], $ids)), 'Source ids that free-exercise-db does not have at the pinned commit');
    }

    public function test_skipped_exercises_have_a_reason_and_are_not_in_the_catalog(): void
    {
        $skipped = json_decode(file_get_contents(database_path('data/exercises/'.ExerciseSeeder::SKIPPED_FILE)), true, flags: JSON_THROW_ON_ERROR);
        $sourceIds = array_filter(array_column($this->exercises, 'source_id'));

        foreach ($skipped as $entry) {
            $this->assertSame(['source_id', 'reason'], array_keys($entry));
            $this->assertNotEmpty($entry['reason'], "Reason for skipping {$entry['source_id']}");
            $this->assertNotContains($entry['source_id'], $sourceIds, "{$entry['source_id']} is both skipped and in the catalog");
        }

        $ids = array_column($skipped, 'source_id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Duplicate skipped ids');
    }

    public function test_every_value_is_valid(): void
    {
        $muscles = Muscle::pluck('key')->all();
        $equipment = Equipment::pluck('key')->all();

        foreach ($this->exercises as $exercise) {
            $key = $exercise['key'];

            $this->assertNotSame('', $exercise['name'], "Name of {$key}");
            $this->assertContains($exercise['equipment'], $equipment, "Equipment of {$key}");
            $this->assertNotNull(ExerciseCategory::tryFrom($exercise['category']), "Category of {$key}");

            foreach (['level' => ExerciseLevel::class, 'mechanic' => ExerciseMechanic::class, 'force' => ExerciseForce::class] as $field => $enum) {
                if ($exercise[$field] !== null) {
                    $this->assertNotNull($enum::tryFrom($exercise[$field]), "{$field} of {$key}");
                }
            }

            foreach ([...$exercise['primary'], ...$exercise['secondary']] as $muscle) {
                $this->assertContains($muscle, $muscles, "Muscle of {$key}");
            }
        }
    }

    public function test_every_exercise_has_a_primary_and_no_muscle_twice(): void
    {
        foreach ($this->exercises as $exercise) {
            $this->assertNotEmpty($exercise['primary'], "Primary of {$exercise['key']}");

            $muscles = [...$exercise['primary'], ...$exercise['secondary']];
            $this->assertSame($muscles, array_values(array_unique($muscles)), "Muscles of {$exercise['key']}");
        }
    }

    public function test_stretches_tag_only_the_stretched_muscles(): void
    {
        foreach ($this->exercises as $exercise) {
            if ($exercise['category'] === ExerciseCategory::Stretching->value) {
                $this->assertSame([], $exercise['secondary'], "Secondary of {$exercise['key']}");
            }
        }
    }

    public function test_photo_entries_are_well_formed_and_credited(): void
    {
        foreach ($this->exercises as $exercise) {
            $key = $exercise['key'];

            $this->assertLessThanOrEqual(3, count($exercise['photos']), "Photo entries of {$key}");

            if ($exercise['photos'] !== [] && $exercise['source_id'] !== null) {
                $this->assertSame(['source' => 'free_exercise_db', 'id' => $exercise['source_id']], $exercise['photos'][0], "Own photos of {$key} come first");
            }

            foreach ($exercise['photos'] as $photo) {
                $source = PhotoSource::tryFrom($photo['source']);
                $this->assertNotNull($source, "Photo source of {$key}");

                if ($source === PhotoSource::FreeExerciseDb) {
                    $this->assertMatchesRegularExpression('/^[A-Za-z0-9_\-]+$/', $photo['id'], "Photo id of {$key}");

                    continue;
                }

                $this->assertMatchesRegularExpression('/^(CC-BY-SA|CC0)\b/', $photo['license'], "wger license of {$key}");
                $this->assertNotEmpty($photo['author'], "wger author of {$key}");
                $this->assertStringStartsWith('https://wger.de/', $photo['credit_url'], "wger credit URL of {$key}");
                $this->assertNotEmpty($photo['path'], "wger path of {$key}");
                $this->assertNotEmpty($photo['caption'], "wger caption of {$key}");
            }
        }
    }

    public function test_every_muscle_has_a_primary_exercise_in_each_covered_group(): void
    {
        $groupOf = Equipment::with('group')->get()->mapWithKeys(fn (Equipment $item) => [$item->key => $item->group->key]);

        // A stretch doesn't train the muscle it stretches (SPEC 09)
        $covered = [];
        foreach ($this->exercises as $exercise) {
            if ($exercise['category'] === ExerciseCategory::Stretching->value) {
                continue;
            }

            foreach ($exercise['primary'] as $muscle) {
                $covered["{$muscle}:{$groupOf[$exercise['equipment']]}"] = true;
            }
        }

        $exceptions = array_map(fn (array $pair) => implode(':', $pair), self::COVERAGE_EXCEPTIONS);

        $missing = [];
        foreach (Muscle::orderBy('id')->pluck('key') as $muscle) {
            foreach (self::COVERED_GROUPS as $group) {
                $pair = "{$muscle}:{$group}";
                if (! isset($covered[$pair]) && ! in_array($pair, $exceptions, true)) {
                    $missing[] = $pair;
                }
            }
        }

        $this->assertSame([], $missing, 'Muscle and group pairs with no primary exercise');

        foreach ($exceptions as $pair) {
            $this->assertArrayNotHasKey($pair, $covered, "{$pair} is covered now; remove it from the exceptions");
        }
    }

    public function test_every_muscle_has_a_stretch(): void
    {
        $stretched = [];
        foreach ($this->exercises as $exercise) {
            if ($exercise['category'] === ExerciseCategory::Stretching->value) {
                foreach ($exercise['primary'] as $muscle) {
                    $stretched[$muscle] = true;
                }
            }
        }

        $missing = Muscle::orderBy('id')->pluck('key')
            ->reject(fn (string $muscle) => isset($stretched[$muscle]) || in_array($muscle, self::STRETCH_EXCEPTIONS, true))
            ->values()->all();

        $this->assertSame([], $missing, 'Muscles with no stretch');

        foreach (self::STRETCH_EXCEPTIONS as $muscle) {
            $this->assertArrayNotHasKey($muscle, $stretched, "{$muscle} has a stretch now; remove it from the exceptions");
        }
    }

    public function test_the_spec_06_review_fixes_are_in(): void
    {
        $this->assertSame(['lower_chest', 'upper_chest'], $this->exercise('dumbbell_fly')['primary']);

        foreach (['incline_barbell_press', 'incline_dumbbell_press'] as $key) {
            $this->assertContains('triceps_lateral', $this->exercise($key)['secondary'], $key);
            $this->assertContains('triceps_long', $this->exercise($key)['secondary'], $key);
        }

        foreach (['hammer_curl', 'cable_reverse_curl', 'reverse_dumbbell_curl'] as $key) {
            $this->assertContains('forearm_flexors', $this->exercise($key)['secondary'], $key);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function exercise(string $key): array
    {
        $exercise = collect($this->exercises)->firstWhere('key', $key);
        $this->assertNotNull($exercise, "Exercise {$key} exists");

        return $exercise;
    }
}
