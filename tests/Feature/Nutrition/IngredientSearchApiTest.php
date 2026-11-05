<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPortion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Nutrition\Concerns\SeedsIngredientFixtures;
use Tests\TestCase;

class IngredientSearchApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsIngredientFixtures;

    public function test_it_requires_a_token(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->getJson('/api/v1/nutrition/ingredients?search=egg')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueryProvider(): array
    {
        return [
            'missing search' => ['', 'search'],
            'search of 2 characters' => ['search=eg', 'search'],
            'search of 101 characters' => ['search='.str_repeat('a', 101), 'search'],
            'unknown category' => ['search=egg&category[]=sweets', 'category.0'],
        ];
    }

    #[DataProvider('invalidQueryProvider')]
    public function test_an_invalid_query_gives_422(string $query, string $field): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->search($query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_search_of_3_and_100_characters_is_accepted(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->search('search=egg')->assertOk();
        $this->search('search='.str_repeat('a', 100))->assertOk();
    }

    public function test_the_term_matches_anywhere_in_the_english_name(): void
    {
        $this->seedIngredients([
            $this->ingredient('peanut_butter', 'Peanut butter, smooth'),
            $this->ingredient('butter_salted', 'Butter, salted'),
            $this->ingredient('oats', 'Oats, rolled'),
        ]);

        $this->assertKeys(['butter_salted', 'peanut_butter'], 'search=butter');
        $this->assertKeys(['peanut_butter'], 'search=nut');
    }

    public function test_the_term_matches_through_an_english_alias(): void
    {
        $this->seedIngredients([
            $this->ingredient('eggplant_raw', 'Eggplant, raw', ['aliases' => ['en' => ['aubergine']]]),
            $this->ingredient('zucchini_raw', 'Zucchini, raw', ['aliases' => ['en' => ['courgette']]]),
        ]);

        $this->assertKeys(['eggplant_raw'], 'search=aubergine');
    }

    public function test_spanish_names_and_aliases_do_not_match(): void
    {
        $this->seedIngredients([
            $this->ingredient('avocado_raw', 'Avocado, raw', [
                'translations' => ['es' => 'Aguacate crudo'],
                'aliases' => ['es' => ['palta']],
            ]),
        ]);

        $this->search('search=palta')->assertOk()->assertExactJson(['data' => []]);
        $this->search('search=aguacate')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_matching_ignores_case_and_accents(): void
    {
        $this->seedIngredients([
            $this->ingredient('jalapeno_raw', 'Jalapeño peppers, raw'),
            $this->ingredient('bell_pepper_raw', 'Bell pepper, raw'),
        ]);

        $this->assertKeys(['jalapeno_raw'], 'search=JALAPENO');
        $this->assertKeys(['jalapeno_raw'], 'search=jalapeño');
    }

    public function test_percent_and_underscore_match_literally(): void
    {
        $this->seedIngredients([
            $this->ingredient('milk_2', 'Milk, 2% fat'),
            $this->ingredient('milk_20', 'Milk, 20 fat'),
            $this->ingredient('item_underscore', 'Item a_b'),
            $this->ingredient('item_letter', 'Item axb'),
        ]);

        $this->assertKeys(['milk_2'], 'search='.urlencode('2% f'));
        $this->assertKeys(['item_underscore'], 'search=a_b');
    }

    public function test_results_are_ranked_prefix_then_word_prefix_then_the_rest(): void
    {
        $this->seedIngredients([
            $this->ingredient('licorice_candy', 'Licorice candy'),
            $this->ingredient('white_rice_raw', 'White rice, long-grain, raw'),
            $this->ingredient('arborio_dry', 'Arborio, dry', ['aliases' => ['en' => ['risotto rice']]]),
            $this->ingredient('rice_noodles_dry', 'Rice noodles, dry'),
            $this->ingredient('brown_rice_cooked', 'Brown rice, long-grain, cooked'),
            $this->ingredient('rice_noodles_cooked', 'Rice noodles, cooked'),
            $this->ingredient('oats', 'Oats, rolled'),
        ]);

        $this->assertKeys([
            'rice_noodles_cooked',
            'rice_noodles_dry',
            'brown_rice_cooked',
            'white_rice_raw',
            'arborio_dry',
            'licorice_candy',
        ], 'search=rice');
    }

    public function test_an_exact_name_match_comes_first(): void
    {
        $this->seedIngredients([
            $this->ingredient('hass_avocado', 'Hass avocado'),
            $this->ingredient('avocado_oil', 'Avocado oil'),
            $this->ingredient('avocado', 'Avocado'),
        ]);

        $this->assertKeys(['avocado', 'avocado_oil', 'hass_avocado'], 'search=avocado');
    }

    public function test_ties_sort_by_name_then_key(): void
    {
        $this->seedIngredients([
            $this->ingredient('tofu_silken', 'Tofu, silken'),
            $this->ingredient('tofu_firm_b', 'Tofu, firm'),
            $this->ingredient('tofu_firm_a', 'Tofu, firm'),
        ]);

        $this->assertKeys(['tofu_firm_a', 'tofu_firm_b', 'tofu_silken'], 'search=tofu');
    }

    public function test_an_ingredient_matching_several_aliases_appears_once(): void
    {
        $this->seedIngredients([
            $this->ingredient('eggplant_raw', 'Eggplant, raw', ['aliases' => ['en' => ['aubergine', 'purple aubergine', 'aubergine, globe']]]),
        ]);

        $this->assertKeys(['eggplant_raw'], 'search=aubergine');
    }

    public function test_category_narrows_the_matches(): void
    {
        $this->seedIngredients([
            $this->ingredient('brown_rice_cooked', 'Brown rice, cooked', ['category' => 'grains']),
            $this->ingredient('rice_milk', 'Rice milk', ['category' => 'dairy']),
            $this->ingredient('rice_crackers', 'Rice crackers', ['category' => 'other']),
        ]);

        $this->assertKeys(['brown_rice_cooked'], 'search=rice&category[]=grains');
        $this->assertKeys(['rice_milk', 'brown_rice_cooked'], 'search=rice&category[]=grains&category[]=dairy');
        $this->assertKeys([], 'search=rice&category[]=fruit');
    }

    public function test_no_match_returns_an_empty_list(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->search('search=zzz')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_every_match_is_returned(): void
    {
        $this->seedIngredients(array_map(
            fn (int $n) => $this->ingredient("bulk_item_{$n}", sprintf('Bulk item %02d', $n)),
            range(1, 60),
        ));

        $this->search('search=bulk')->assertOk()->assertJsonCount(60, 'data');
    }

    public function test_items_carry_the_search_fields_only(): void
    {
        $this->seedIngredients([$this->egg()]);

        $item = $this->search('search=egg')->assertOk()->json('data.0');

        $this->assertEquals([
            'key' => 'egg_whole_raw',
            'name' => 'Egg, whole, raw',
            'category' => 'eggs',
            'is_mine' => false,
            'per_100g' => ['kcal' => 148, 'protein' => 12.4, 'carbs' => 0.96, 'fat' => 9.96, 'fiber' => 0],
            'portions' => [
                ['key' => 'large', 'label' => '1 large egg', 'grams' => 50],
                ['key' => 'medium', 'label' => '1 medium egg', 'grams' => 44],
            ],
            'photos' => [],
        ], $item);
        $this->assertArrayNotHasKey('nutrients', $item);
        $this->assertArrayNotHasKey('source', $item);
    }

    public function test_items_carry_their_photos_in_position_order(): void
    {
        $this->seedIngredients([$this->egg(['photos' => $this->twoPhotos()]), $this->ingredient('egg_white_raw', 'Egg, white, raw')]);
        $this->swapPhotoPositions('egg_whole_raw');

        $data = collect($this->search('search=egg')->assertOk()->json('data'))->keyBy('key');

        $this->assertSame($this->expectedTwoPhotos(swapped: true), $data['egg_whole_raw']['photos']);
        $this->assertSame([], $data['egg_white_raw']['photos']);
    }

    public function test_soft_deleted_ingredients_and_portions_are_left_out(): void
    {
        $this->seedIngredients([
            $this->egg(),
            $this->ingredient('egg_white_raw', 'Egg white, raw'),
        ]);

        Ingredient::where('key', 'egg_white_raw')->firstOrFail()->delete();
        IngredientPortion::where('key', 'medium')->firstOrFail()->delete();

        $data = $this->search('search=egg')->assertOk()->json('data');

        $this->assertSame(['egg_whole_raw'], array_column($data, 'key'));
        $this->assertSame(['large'], array_column($data[0]['portions'], 'key'));
    }

    public function test_the_callers_ingredients_are_found_and_another_users_are_not(): void
    {
        $this->seedIngredients([$this->ingredient('protein_powder_whey', 'Protein powder, whey')]);
        $caller = User::factory()->create();
        $mine = $this->userIngredient($caller, 'Protein bar, chocolate', ['portions' => [['p_8k2d4x7q', '1 bar', 45]]]);
        $this->userIngredient(User::factory()->create(), 'Protein bar, vanilla');

        $data = $this->actingAs($caller)
            ->getJson('/api/v1/nutrition/ingredients?search=protein')
            ->assertOk()
            ->json('data');

        // SPEC 16's ranking applies unchanged: the caller's own get no priority.
        $this->assertSame([$mine->key, 'protein_powder_whey'], array_column($data, 'key'));
        $this->assertSame([true, false], array_column($data, 'is_mine'));
        $this->assertEquals([
            'key' => $mine->key,
            'name' => 'Protein bar, chocolate',
            'category' => 'supplements',
            'is_mine' => true,
            'per_100g' => ['kcal' => 380, 'protein' => 33, 'carbs' => 38, 'fat' => 11, 'fiber' => null],
            'portions' => [['key' => 'p_8k2d4x7q', 'label' => '1 bar', 'grams' => 45]],
            'photos' => [],
        ], $data[0]);
    }

    public function test_another_users_ingredient_is_never_found(): void
    {
        $this->userIngredient(User::factory()->create(), 'Granola, homemade');

        $this->assertKeys([], 'search=granola');
    }

    public function test_the_category_filter_applies_to_the_callers_ingredients(): void
    {
        $caller = User::factory()->create();
        $this->userIngredient($caller, 'Granola, homemade', ['category' => 'grains']);

        $this->actingAs($caller)
            ->getJson('/api/v1/nutrition/ingredients?search=granola&category[]=fats')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_the_search_runs_three_queries_whatever_the_number_of_matches(): void
    {
        $children = [
            'portions' => [['key' => 'cup', 'label' => '1 cup', 'grams' => 100], ['key' => 'tbsp', 'label' => '1 tbsp', 'grams' => 15]],
            'photos' => $this->twoPhotos(),
        ];

        $this->seedIngredients([
            ...array_map(fn (int $n) => $this->ingredient("few_{$n}", "Few item {$n}", $children), range(1, 5)),
            ...array_map(fn (int $n) => $this->ingredient("many_{$n}", "Many item {$n}", $children), range(1, 15)),
        ]);

        // The ingredients, their portions and their photos.
        $this->assertSame(3, $this->countQueries('search=few', 5));
        $this->assertSame(3, $this->countQueries('search=many', 15));
    }

    private function countQueries(string $query, int $expectedItems): int
    {
        $user = User::factory()->create();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->getJson("/api/v1/nutrition/ingredients?{$query}")
            ->assertOk()
            ->assertJsonCount($expectedItems, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertKeys(array $expected, string $query): void
    {
        $this->assertSame($expected, array_column($this->search($query)->assertOk()->json('data'), 'key'));
    }

    private function search(string $query): TestResponse
    {
        return $this->actingAs(User::factory()->create())
            ->getJson("/api/v1/nutrition/ingredients?{$query}");
    }
}
