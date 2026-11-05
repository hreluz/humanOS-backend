<?php

namespace Database\Seeders;

use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Enums\IngredientPhotoSource;
use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPhoto;
use App\Modules\Nutrition\Models\IngredientPortion;
use App\Modules\Nutrition\Models\Nutrient;
use App\Modules\Nutrition\Support\CatalogRemover;
use App\Modules\Nutrition\Support\IngredientPhotoLicense;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ingredient catalog, read from the files in `database/data/ingredients` (SPEC 15). Rules are in `docs/ingredient-catalog.md`.
 *
 * Every entry is validated before anything is written; an invalid one throws and writes nothing.
 * Ingredients are upserted by `key` and portions by ingredient + `key`, soft-deleted rows included, so ids stay
 * the same between runs and a key that comes back restores its old row. Nutrient values, translations, aliases
 * and portion translations are rebuilt from the files. Ingredients and portions no longer in the files are
 * hard-deleted, or soft-deleted when something references them (`CatalogRemover`).
 *
 * Only system ingredients (`user_id` `null`) are written or removed, and a key held by a user's ingredient throws.
 * A catalog entry always has `fiber` and `source_id`, never the `user` source, and no key starting with `u_` (SPEC 18).
 * Photos are rebuilt from each entry's `photos` list as `commons` photos, in list order (SPEC 17).
 */
class IngredientSeeder extends Seeder
{
    /**
     * Locales an ingredient or portion may be translated to. English is the main table's `name` or `label`.
     * Moves to `config('nutrition.locales')` in the language spec.
     *
     * @var list<string>
     */
    public const TRANSLATION_LOCALES = ['es'];

    /**
     * Locales an alias may be in.
     *
     * @var list<string>
     */
    public const ALIAS_LOCALES = ['en', 'es'];

    /**
     * @var list<string>
     */
    private const MACROS = ['kcal', 'protein', 'carbs', 'fat', 'fiber'];

    /**
     * The most photos an ingredient may have.
     */
    public const MAX_PHOTOS = 3;

    /**
     * The prefix of users' ingredient keys (SPEC 18), which a catalog key may not start with.
     */
    public const USER_KEY_PREFIX = 'u_';

    public function __construct(private ?string $directory = null) {}

    /**
     * Every entry in the catalog files, file by file in name order.
     *
     * @return list<array<string, mixed>>
     */
    public static function entries(?string $directory = null): array
    {
        $files = glob(($directory ?? database_path('data/ingredients')).'/*.json');
        sort($files);

        $entries = [];
        foreach ($files as $file) {
            array_push($entries, ...json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR));
        }

        return $entries;
    }

    public function run(): void
    {
        $entries = self::entries($this->directory);
        $nutrientIds = Nutrient::pluck('id', 'key')->all();

        $this->validate($entries, $nutrientIds);
        $this->rejectUserKeys(array_column($entries, 'key'));

        DB::transaction(function () use ($entries, $nutrientIds) {
            $now = now();

            DB::table('ingredients')->upsert(
                array_map(fn (array $entry) => [
                    'key' => $entry['key'],
                    'name' => $entry['name'],
                    'category' => $entry['category'],
                    ...array_intersect_key($entry, array_flip(self::MACROS)),
                    'source' => $entry['source'],
                    'source_id' => (string) $entry['source_id'],
                    'deleted_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $entries),
                ['key'],
                ['name', 'category', ...self::MACROS, 'source', 'source_id', 'deleted_at', 'updated_at'],
            );

            $keys = array_column($entries, 'key');
            $ingredientIds = Ingredient::system()->whereIn('key', $keys)->pluck('id', 'key')->all();

            $this->writePortions($entries, $ingredientIds);
            $this->writeChildren($entries, $ingredientIds, $nutrientIds);
            $this->writePhotos($entries, $ingredientIds);

            CatalogRemover::remove(Ingredient::system()->whereNotIn('key', $keys));
        });
    }

    /**
     * Upserts the portions, removes those no longer listed, and rebuilds their translations.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $ingredientIds
     */
    private function writePortions(array $entries, array $ingredientIds): void
    {
        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry['portions'] ?? [] as $i => $portion) {
                $rows[] = [
                    'ingredient_id' => $ingredientIds[$entry['key']],
                    'key' => $portion['key'],
                    'label' => $portion['label'],
                    'grams' => $portion['grams'],
                    'position' => $i + 1,
                    'deleted_at' => null,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ingredient_portions')->upsert($chunk, ['ingredient_id', 'key'], ['label', 'grams', 'position', 'deleted_at']);
        }

        // Portion ids by "ingredient id/key", soft-deleted ones included.
        $portionIds = [];
        foreach (IngredientPortion::withTrashed()->whereIn('ingredient_id', $ingredientIds)->get(['id', 'ingredient_id', 'key']) as $portion) {
            $portionIds["{$portion->ingredient_id}/{$portion->key}"] = $portion->id;
        }

        $kept = array_map(fn (array $row) => $portionIds["{$row['ingredient_id']}/{$row['key']}"], $rows);
        CatalogRemover::remove(IngredientPortion::whereIn('ingredient_id', $ingredientIds)->whereNotIn('id', $kept));

        $translations = [];
        foreach ($entries as $entry) {
            foreach ($entry['portions'] ?? [] as $portion) {
                $portionId = $portionIds["{$ingredientIds[$entry['key']]}/{$portion['key']}"];
                foreach ($portion['translations'] ?? [] as $locale => $label) {
                    $translations[] = ['ingredient_portion_id' => $portionId, 'locale' => $locale, 'label' => $label];
                }
            }
        }

        // Only the listed portions are rebuilt: a soft-deleted portion keeps its translations.
        foreach (array_chunk($kept, 1000) as $chunk) {
            DB::table('ingredient_portion_translations')->whereIn('ingredient_portion_id', $chunk)->delete();
        }
        foreach (array_chunk($translations, 500) as $chunk) {
            DB::table('ingredient_portion_translations')->insert($chunk);
        }
    }

    /**
     * Rebuilds the nutrient values, translations and aliases of the listed ingredients.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $ingredientIds
     * @param  array<string, int>  $nutrientIds
     */
    private function writeChildren(array $entries, array $ingredientIds, array $nutrientIds): void
    {
        $rows = ['ingredient_nutrient' => [], 'ingredient_translations' => [], 'ingredient_aliases' => []];

        foreach ($entries as $entry) {
            $id = $ingredientIds[$entry['key']];

            foreach ($entry['nutrients'] ?? [] as $key => $amount) {
                $rows['ingredient_nutrient'][] = ['ingredient_id' => $id, 'nutrient_id' => $nutrientIds[$key], 'amount' => $amount];
            }
            foreach ($entry['translations'] ?? [] as $locale => $name) {
                $rows['ingredient_translations'][] = ['ingredient_id' => $id, 'locale' => $locale, 'name' => $name];
            }
            foreach ($entry['aliases'] ?? [] as $locale => $names) {
                foreach ($names as $name) {
                    $rows['ingredient_aliases'][] = ['ingredient_id' => $id, 'locale' => $locale, 'name' => $name];
                }
            }
        }

        foreach ($rows as $table => $tableRows) {
            foreach (array_chunk(array_values($ingredientIds), 1000) as $chunk) {
                DB::table($table)->whereIn('ingredient_id', $chunk)->delete();
            }
            foreach (array_chunk($tableRows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
        }
    }

    /**
     * Rebuilds the photos of the listed ingredients. System ingredients only have `commons` photos, so all go.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $ingredientIds
     */
    private function writePhotos(array $entries, array $ingredientIds): void
    {
        $now = now();
        $rows = [];
        foreach ($entries as $entry) {
            foreach ($entry['photos'] ?? [] as $i => $photo) {
                $rows[] = [
                    'ingredient_id' => $ingredientIds[$entry['key']],
                    'source' => IngredientPhotoSource::Commons->value,
                    'path' => IngredientPhoto::canonicalFile($photo['file']),
                    'license' => $photo['license'],
                    'license_url' => ($photo['license_url'] ?? '') === '' ? null : $photo['license_url'],
                    'author' => ($photo['author'] ?? '') === '' ? null : $photo['author'],
                    'position' => $i + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk(array_values($ingredientIds), 1000) as $chunk) {
            DB::table('ingredient_photos')->whereIn('ingredient_id', $chunk)->delete();
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('ingredient_photos')->insert($chunk);
        }
    }

    /**
     * Throws when a key in the files belongs to a user's ingredient, so the upsert can't take that row over.
     *
     * @param  list<string>  $keys
     */
    private function rejectUserKeys(array $keys): void
    {
        $taken = Ingredient::withTrashed()->whereNotNull('user_id')->whereIn('key', $keys)->orderBy('key')->pluck('key')->all();

        if ($taken !== []) {
            throw new InvalidArgumentException('Ingredient key ['.implode('], [', $taken)."]: belongs to a user's ingredient.");
        }
    }

    /**
     * Checks every entry, failing on the first problem.
     *
     * @param  list<array<string, mixed>>  $entries
     * @param  array<string, int>  $nutrientIds  Nutrients that aren't soft-deleted, by key.
     */
    private function validate(array $entries, array $nutrientIds): void
    {
        foreach (['key' => 'key', 'source_id' => 'source_id'] as $field => $label) {
            $values = array_map(fn (array $entry) => isset($entry[$field]) ? (string) $entry[$field] : null, $entries);
            foreach (array_count_values(array_filter($values, fn ($value) => $value !== null)) as $value => $count) {
                if ($count > 1) {
                    throw new InvalidArgumentException("Ingredient {$label} [{$value}]: listed {$count} times.");
                }
            }
        }

        foreach ($entries as $entry) {
            $key = $entry['key'] ?? throw new InvalidArgumentException('An ingredient has no key.');
            $fail = fn (string $problem) => throw new InvalidArgumentException("Ingredient [{$key}]: {$problem}.");

            foreach (['name', 'source_id'] as $field) {
                if (! isset($entry[$field]) || $entry[$field] === '') {
                    $fail("missing {$field}");
                }
            }
            foreach (self::MACROS as $macro) {
                if (! isset($entry[$macro]) || ! is_numeric($entry[$macro])) {
                    $fail("missing macro [{$macro}]");
                }
            }

            if (str_starts_with($key, self::USER_KEY_PREFIX)) {
                $fail('key starts with ['.self::USER_KEY_PREFIX."], which is reserved for users' ingredients");
            }

            IngredientCategory::tryFrom($entry['category'] ?? '') ?? $fail('unknown category ['.($entry['category'] ?? '').']');
            $source = IngredientSource::tryFrom($entry['source'] ?? '') ?? $fail('unknown source ['.($entry['source'] ?? '').']');
            if ($source === IngredientSource::User) {
                $fail("source [user] is only for users' ingredients");
            }

            foreach ($entry['nutrients'] ?? [] as $nutrient => $amount) {
                if (! isset($nutrientIds[$nutrient])) {
                    $fail("unknown nutrient [{$nutrient}]");
                }
                if (! is_numeric($amount)) {
                    $fail("nutrient [{$nutrient}] is not a number");
                }
            }

            $this->validateLocales($entry['translations'] ?? [], self::TRANSLATION_LOCALES, 'translation', $fail);
            $this->validateLocales($entry['aliases'] ?? [], self::ALIAS_LOCALES, 'alias', $fail);

            $portionKeys = [];
            foreach ($entry['portions'] ?? [] as $portion) {
                $portionKey = $portion['key'] ?? $fail('a portion has no key');
                if (isset($portionKeys[$portionKey])) {
                    $fail("portion [{$portionKey}] listed twice");
                }
                $portionKeys[$portionKey] = true;

                if (! isset($portion['label'], $portion['grams']) || ! is_numeric($portion['grams'])) {
                    $fail("portion [{$portionKey}] needs a label and grams");
                }
                $this->validateLocales($portion['translations'] ?? [], self::TRANSLATION_LOCALES, "portion [{$portionKey}] translation", $fail);
            }

            $this->validatePhotos($entry['photos'] ?? [], $fail);
        }
    }

    /**
     * Checks an entry's `photos` list: at most `MAX_PHOTOS`, each with a file and an allowed license, the credit
     * the license requires, and no file twice (compared in canonical form).
     *
     * @param  callable(string): never  $fail
     */
    private function validatePhotos(mixed $photos, callable $fail): void
    {
        if (! is_array($photos) || ! array_is_list($photos)) {
            $fail('photos must be a list');
        }
        if (count($photos) > self::MAX_PHOTOS) {
            $fail('has '.count($photos).' photos, at most '.self::MAX_PHOTOS.' allowed');
        }

        $files = [];
        foreach ($photos as $i => $photo) {
            $n = $i + 1;
            $file = is_array($photo) && is_string($photo['file'] ?? null) ? IngredientPhoto::canonicalFile($photo['file']) : '';
            if ($file === '') {
                $fail("photo {$n} has no file");
            }
            if (isset($files[$file])) {
                $fail("photo [{$file}] listed twice");
            }
            $files[$file] = true;

            $license = $photo['license'] ?? null;
            if (! is_string($license) || $license === '') {
                $fail("photo [{$file}] has no license");
            }
            if (! IngredientPhotoLicense::isAllowed($license)) {
                $fail("photo [{$file}] has a license that isn't allowed [{$license}]");
            }

            $author = $photo['author'] ?? null;
            $licenseUrl = $photo['license_url'] ?? null;
            foreach (['author' => $author, 'license_url' => $licenseUrl] as $field => $value) {
                if ($value !== null && ! is_string($value)) {
                    $fail("photo [{$file}] {$field} is not a string");
                }
                if (($value ?? '') === '' && IngredientPhotoLicense::requiresAttribution($license)) {
                    $fail("photo [{$file}] needs {$field} under [{$license}]");
                }
            }

            if ($author !== null && mb_strlen($author) > 255) {
                $fail("photo [{$file}] author is longer than 255 characters");
            }
            // Stored as Commons returns it: many older licenses come back as `http://`.
            if (($licenseUrl ?? '') !== '' && (filter_var($licenseUrl, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($licenseUrl, PHP_URL_SCHEME), ['http', 'https'], true))) {
                $fail("photo [{$file}] license_url is not an http or https URL");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $byLocale
     * @param  list<string>  $allowed
     * @param  callable(string): never  $fail
     */
    private function validateLocales(array $byLocale, array $allowed, string $what, callable $fail): void
    {
        foreach (array_keys($byLocale) as $locale) {
            if (! in_array($locale, $allowed, true)) {
                $fail("unknown {$what} locale [{$locale}]");
            }
        }
    }
}
