<?php

namespace Tests\Feature\Training;

use App\Models\User;
use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\MuscleRole;
use App\Modules\Training\Enums\PhotoSource;
use App\Modules\Training\Models\Equipment;
use App\Modules\Training\Models\Exercise;
use App\Modules\Training\Models\Muscle;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\MuscleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExerciseListApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([MuscleSeeder::class, EquipmentSeeder::class]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/training/exercises')->assertUnauthorized();
    }

    public function test_the_default_page_has_50_items_sorted_by_name_then_key(): void
    {
        // Created in reverse, so only the sort can put them right. Two share a name, so only key breaks the tie.
        foreach (range(55, 1) as $number) {
            $this->createExercise(sprintf('exercise_%02d', $number), sprintf('Exercise %02d', $number));
        }
        $this->createExercise('a_same_name_b', 'A Same Name');
        $this->createExercise('a_same_name_a', 'A Same Name');

        $response = $this->list()
            ->assertOk()
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 57);

        $keys = array_column($response->json('data'), 'key');

        $this->assertCount(50, $keys);
        $this->assertSame(['a_same_name_a', 'a_same_name_b', 'exercise_01'], array_slice($keys, 0, 3));
        $this->assertSame('exercise_48', $keys[49]);
    }

    public function test_the_next_page_continues_the_list_and_links_keep_the_filters(): void
    {
        $this->createExercise('first', 'A First', level: ExerciseLevel::Beginner);
        $this->createExercise('second', 'B Second', level: ExerciseLevel::Beginner);

        $response = $this->list('per_page=1&page=2&level[]=beginner')->assertOk();

        $this->assertSame(['second'], array_column($response->json('data'), 'key'));
        $this->assertStringContainsString('level', urldecode((string) $response->json('links.first')));
        $this->assertStringContainsString('per_page=1', (string) $response->json('links.first'));
    }

    public function test_it_filters_by_equipment_group(): void
    {
        $this->createExercise('swing', 'Swing', 'kettlebell');
        $this->createExercise('band_row', 'Band Row', 'bands');
        $this->createExercise('bench', 'Bench', 'barbell');

        $this->assertKeys(['band_row', 'swing'], 'equipment_group[]=kettlebell&equipment_group[]=bands');
    }

    public function test_it_filters_by_equipment(): void
    {
        $this->createExercise('bench', 'Bench', 'barbell');
        $this->createExercise('cable_fly', 'Cable Fly', 'cable');
        $this->createExercise('leg_press', 'Leg Press', 'machine');

        $this->assertKeys(['bench', 'cable_fly'], 'equipment[]=barbell&equipment[]=cable');
    }

    public function test_it_filters_by_category(): void
    {
        $this->createExercise('bench', 'Bench', category: ExerciseCategory::Strength);
        $this->createExercise('run', 'Run', category: ExerciseCategory::Cardio);
        $this->createExercise('split', 'Split', category: ExerciseCategory::Stretching);

        $this->assertKeys(['run', 'split'], 'category[]=cardio&category[]=stretching');
    }

    public function test_it_filters_by_level(): void
    {
        $this->createExercise('easy', 'Easy', level: ExerciseLevel::Beginner);
        $this->createExercise('middle', 'Middle', level: ExerciseLevel::Intermediate);
        $this->createExercise('hard', 'Hard', level: ExerciseLevel::Expert);
        $this->createExercise('unrated', 'Unrated');

        $this->assertKeys(['easy', 'hard'], 'level[]=beginner&level[]=expert');
    }

    public function test_search_matches_anywhere_in_the_name_ignoring_case(): void
    {
        $this->createExercise('bench_press', 'Bench Press');
        $this->createExercise('incline_bench', 'Incline Bench');
        $this->createExercise('squat', 'Squat');

        $this->assertKeys(['bench_press', 'incline_bench'], 'search=BENCH');
    }

    public function test_search_treats_like_wildcards_as_plain_text(): void
    {
        $this->createExercise('bench_press', 'Bench Press');
        $this->createExercise('half_rep', '50% Rep');

        $this->assertKeys(['half_rep'], 'search='.urlencode('0%'));
        $this->assertKeys([], 'search='.urlencode('B_nch'));
    }

    public function test_filters_combine_with_and(): void
    {
        $this->createExercise('kb_press_easy', 'Kettlebell Press', 'kettlebell', level: ExerciseLevel::Beginner);
        $this->createExercise('kb_press_hard', 'Kettlebell Press Hard', 'kettlebell', level: ExerciseLevel::Expert);
        $this->createExercise('kb_swing_easy', 'Kettlebell Swing', 'kettlebell', level: ExerciseLevel::Beginner);
        $this->createExercise('bar_press_easy', 'Barbell Press', 'barbell', level: ExerciseLevel::Beginner);

        $this->assertKeys(['kb_press_easy'], 'equipment_group[]=kettlebell&level[]=beginner&search=press');
    }

    public function test_per_page_accepts_100(): void
    {
        $this->list('per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'one-character search' => ['search=a', 'search'],
            '101-character search' => ['search='.str_repeat('a', 101), 'search'],
            'per_page over the maximum' => ['per_page=101', 'per_page'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'page zero' => ['page=0', 'page'],
            'unknown equipment group' => ['equipment_group[]=nope', 'equipment_group.0'],
            'unknown equipment' => ['equipment[]=nope', 'equipment.0'],
            'unknown category' => ['category[]=nope', 'category.0'],
            'unknown level' => ['level[]=nope', 'level.0'],
            'filter that is not an array' => ['category=strength', 'category'],
            'unknown muscle' => ['muscle=nope', 'muscle'],
            'unknown role' => ['muscle=quads&role=nope', 'role'],
            'role without muscle' => ['role=primary', 'muscle'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_an_invalid_query_returns_422(string $query, string $field): void
    {
        $this->list($query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_items_have_no_detail_only_fields(): void
    {
        $this->createExercise('bench', 'Bench', attributes: [
            'instructions' => ['Lie back on a flat bench.'],
            'source_id' => 'Barbell_Bench_Press',
        ]);

        $item = $this->list()->assertOk()->json('data.0');

        $this->assertSame('bench', $item['key']);
        $this->assertArrayNotHasKey('instructions', $item);
        $this->assertArrayNotHasKey('source_id', $item);
    }

    public function test_the_query_count_does_not_depend_on_the_page_size(): void
    {
        $muscle = Muscle::where('key', 'lower_chest')->value('id');

        foreach (range(1, 30) as $number) {
            $equipment = $number % 2 === 0 ? 'barbell' : 'kettlebell';
            $exercise = $this->createExercise("exercise_{$number}", "Exercise {$number}", $equipment);
            $exercise->muscles()->attach($muscle, ['role' => MuscleRole::Primary->value, 'sort_order' => 0]);
            $exercise->photos()->create([
                'source' => PhotoSource::FreeExerciseDb,
                'path' => "Exercise/{$number}.jpg",
                'caption' => 'Start',
                'position' => 0,
                'license' => 'Unlicense',
            ]);
        }

        $small = $this->countQueries('per_page=10', 10);
        $large = $this->countQueries('per_page=100', 30);

        $this->assertSame($small, $large);
    }

    public function test_the_muscle_filter_lists_primaries_before_secondaries(): void
    {
        $this->createTaggedExercise('a_assists', 'A Assists', secondary: ['quads']);
        $this->createTaggedExercise('z_targets', 'Z Targets', primary: ['quads']);
        $this->createTaggedExercise('other', 'Other', primary: ['biceps']);

        $response = $this->list('muscle=quads')->assertOk();

        $this->assertSame(['z_targets', 'a_assists'], array_column($response->json('data'), 'key'));
        $this->assertSame(['quads'], $response->json('data.0.primary'));
        $this->assertSame(['quads'], $response->json('data.1.secondary'));
    }

    public function test_primaries_rank_by_position_then_fewest_primaries_then_name(): void
    {
        $this->createTaggedExercise('second_position', 'A Second Position', primary: ['glute_max', 'quads']);
        $this->createTaggedExercise('first_with_two', 'B First With Two', primary: ['quads', 'glute_max']);
        $this->createTaggedExercise('first_alone_z', 'Z First Alone', primary: ['quads']);
        $this->createTaggedExercise('first_alone_c', 'C First Alone', primary: ['quads']);

        $this->assertKeys(['first_alone_c', 'first_alone_z', 'first_with_two', 'second_position'], 'muscle=quads');
    }

    public function test_secondaries_are_sorted_by_name_only(): void
    {
        // Position and primary count would reverse this order if they applied to secondaries.
        $this->createTaggedExercise('a_late', 'A Late', primary: ['biceps', 'triceps_long'], secondary: ['glute_max', 'quads']);
        $this->createTaggedExercise('b_early', 'B Early', primary: ['biceps'], secondary: ['quads']);

        $this->assertKeys(['a_late', 'b_early'], 'muscle=quads');
    }

    public function test_role_narrows_the_list_to_one_group(): void
    {
        $this->createTaggedExercise('targets', 'Targets', primary: ['quads']);
        $this->createTaggedExercise('assists', 'Assists', secondary: ['quads']);

        $this->assertKeys(['targets'], 'muscle=quads&role=primary');
        $this->assertKeys(['assists'], 'muscle=quads&role=secondary');
    }

    public function test_the_muscle_filter_combines_with_the_other_filters(): void
    {
        $this->createTaggedExercise('kb_squat', 'Kettlebell Squat', primary: ['quads'], equipment: 'kettlebell');
        $this->createTaggedExercise('bar_squat', 'Barbell Squat', primary: ['quads'], equipment: 'barbell');
        $this->createTaggedExercise('kb_curl', 'Kettlebell Curl', primary: ['biceps'], equipment: 'kettlebell');

        $this->assertKeys(['kb_squat'], 'muscle=quads&equipment_group[]=kettlebell');
    }

    public function test_the_muscle_order_holds_across_pages(): void
    {
        // Same name, position and count across groups, so only the full order keeps pages stable.
        foreach (range(1, 20) as $number) {
            $this->createTaggedExercise(sprintf('primary_%02d', $number), 'Same Name', primary: $number % 2 === 0 ? ['quads'] : ['quads', 'glute_max']);
            $this->createTaggedExercise(sprintf('secondary_%02d', $number), 'Same Name', primary: ['biceps'], secondary: ['quads']);
        }

        $paged = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->list("muscle=quads&per_page=10&page={$page}")->assertOk();
            $paged = [...$paged, ...array_column($response->json('data'), 'key')];
        }

        $whole = array_column($this->list('muscle=quads&per_page=30')->assertOk()->json('data'), 'key');

        $this->assertCount(30, $paged);
        $this->assertSame($whole, $paged);
    }

    public function test_the_query_count_with_a_muscle_does_not_depend_on_the_page_size(): void
    {
        foreach (range(1, 30) as $number) {
            $this->createTaggedExercise("exercise_{$number}", "Exercise {$number}", primary: ['quads'], equipment: $number % 2 === 0 ? 'barbell' : 'kettlebell');
        }

        $small = $this->countQueries('muscle=quads&per_page=10', 10);
        $large = $this->countQueries('muscle=quads&per_page=100', 30);

        $this->assertSame($small, $large);
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
    private function assertKeys(array $expected, string $query): void
    {
        $response = $this->list($query)->assertOk();

        $this->assertSame($expected, array_column($response->json('data'), 'key'));
    }

    private function list(string $query = ''): TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/training/exercises?{$query}");
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createExercise(
        string $key,
        string $name,
        string $equipment = 'barbell',
        ExerciseCategory $category = ExerciseCategory::Strength,
        ?ExerciseLevel $level = null,
        array $attributes = [],
    ): Exercise {
        return Exercise::create([
            'key' => $key,
            'name' => $name,
            'equipment_id' => Equipment::where('key', $equipment)->value('id'),
            'category' => $category,
            'level' => $level,
            ...$attributes,
        ]);
    }
}
