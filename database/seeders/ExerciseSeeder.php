<?php

namespace Database\Seeders;

use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseForce;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\ExerciseMechanic;
use App\Modules\Training\Enums\MuscleRole;
use App\Modules\Training\Enums\PhotoSource;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\Muscle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The exercise catalog, read from the files in `database/data/exercises` (SPEC 08, SPEC 09). Tagging rules are in `docs/exercise-tagging.md`.
 *
 * Exercises are upserted by `key`, so running it again updates them in place and keeps their ids.
 * Each exercise's muscle tags and photos are rebuilt from the files. It never deletes exercises.
 * All files are checked before anything is written; an unknown or duplicate key throws and writes nothing.
 */
class ExerciseSeeder extends Seeder
{
    /**
     * Lists the free-exercise-db exercises left out of the catalog on purpose. The seeder doesn't load it.
     */
    public const SKIPPED_FILE = 'skipped.json';

    public function __construct(private ?string $directory = null) {}

    /**
     * Every entry in the catalog files, file by file in name order.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(?string $directory = null): array
    {
        $files = glob(($directory ?? database_path('data/exercises')).'/*.json');
        sort($files);

        $entries = [];
        foreach ($files as $file) {
            if (basename($file) !== self::SKIPPED_FILE) {
                array_push($entries, ...json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
            }
        }

        return $entries;
    }

    public function run(): void
    {
        $exercises = $this->build(self::entries($this->directory));

        DB::transaction(function () use ($exercises) {
            foreach ($exercises as ['attributes' => $attributes, 'muscles' => $muscles, 'photos' => $photos]) {
                $exercise = Exercise::updateOrCreate(['key' => $attributes['key']], $attributes);

                $exercise->muscles()->sync($muscles);
                $exercise->photos()->delete();
                $exercise->photos()->createMany($photos);
            }
        });
    }

    /**
     * Turns the entries into rows, failing on a duplicate exercise key or any key the catalog doesn't know.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{attributes: array<string, mixed>, muscles: array<int, array{role: string, sort_order: int}>, photos: list<array<string, mixed>>}>
     */
    private function build(array $entries): array
    {
        $muscleIds = Muscle::pluck('id', 'key');
        $equipmentIds = Equipment::pluck('id', 'key');

        foreach (array_count_values(array_column($entries, 'key')) as $key => $count) {
            if ($count > 1) {
                throw new InvalidArgumentException("Exercise [{$key}]: listed {$count} times.");
            }
        }

        return array_map(function (array $entry) use ($muscleIds, $equipmentIds) {
            $key = $entry['key'];

            $equipmentId = $equipmentIds[$entry['equipment']]
                ?? throw new InvalidArgumentException("Exercise [{$key}]: unknown equipment [{$entry['equipment']}].");

            $muscles = [];
            foreach ([MuscleRole::Primary, MuscleRole::Secondary] as $role) {
                foreach ($entry[$role->value] as $i => $muscleKey) {
                    $muscleId = $muscleIds[$muscleKey]
                        ?? throw new InvalidArgumentException("Exercise [{$key}]: unknown muscle [{$muscleKey}].");
                    $muscles[$muscleId] = ['role' => $role->value, 'sort_order' => $i + 1];
                }
            }

            return [
                'attributes' => [
                    'key' => $key,
                    'name' => $entry['name'],
                    'equipment_id' => $equipmentId,
                    'category' => $this->enum(ExerciseCategory::class, $entry, 'category'),
                    'level' => $this->enum(ExerciseLevel::class, $entry, 'level'),
                    'mechanic' => $this->enum(ExerciseMechanic::class, $entry, 'mechanic'),
                    'force' => $this->enum(ExerciseForce::class, $entry, 'force'),
                    'cue' => $entry['cue'] ?? null,
                    'instructions' => $entry['instructions'] ?? null,
                    'source_id' => $entry['source_id'] ?? null,
                ],
                'muscles' => $muscles,
                'photos' => $this->photos($entry),
            ];
        }, $entries);
    }

    /**
     * The entry's value for `$field` as a `$enum` case, or null when the value is null.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @param  array<string, mixed>  $entry
     * @return T|null
     */
    private function enum(string $enum, array $entry, string $field): ?\BackedEnum
    {
        $value = $entry[$field] ?? null;

        return $value === null ? null : ($enum::tryFrom($value)
            ?? throw new InvalidArgumentException("Exercise [{$entry['key']}]: invalid {$field} [{$value}]."));
    }

    /**
     * Expands the photo entries into rows: a free-exercise-db id gives its start and end photos, a wger entry one photo.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array<string, mixed>>
     */
    private function photos(array $entry): array
    {
        $rows = [];
        foreach ($entry['photos'] as $photo) {
            $source = PhotoSource::tryFrom($photo['source'])
                ?? throw new InvalidArgumentException("Exercise [{$entry['key']}]: unknown photo source [{$photo['source']}].");

            if ($source === PhotoSource::Wger) {
                $rows[] = [
                    'source' => $source,
                    'path' => $photo['path'],
                    'caption' => $photo['caption'],
                    'license' => $photo['license'],
                    'author' => $photo['author'],
                    'credit_url' => $photo['credit_url'],
                ];

                continue;
            }

            $own = $photo['id'] === ($entry['source_id'] ?? null);
            // `Triceps_Pushdown_-_Rope_Attachment` → `Triceps Pushdown, Rope Attachment`
            $label = str_replace(['_-_', '_'], [', ', ' '], $photo['id']);

            foreach ([0 => 'start', 1 => 'end'] as $frame => $position) {
                $rows[] = [
                    'source' => $source,
                    'path' => "{$photo['id']}/{$frame}.jpg",
                    'caption' => $own ? ucfirst($position) : "{$label} · {$position}",
                    'license' => 'Unlicense',
                    'author' => null,
                    'credit_url' => null,
                ];
            }
        }

        return array_map(fn (array $row, int $i) => [...$row, 'position' => $i + 1], $rows, array_keys($rows));
    }
}
