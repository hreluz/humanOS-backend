<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Models\IngredientPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Nutrition\Concerns\SeedsIngredientFixtures;
use Tests\TestCase;

class MyIngredientsApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsIngredientFixtures;

    public function test_it_requires_a_token(): void
    {
        $this->getJson('/api/v1/nutrition/my-ingredients')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_only_the_callers_ingredients_sorted_by_name_then_key(): void
    {
        $this->seedIngredients([$this->egg()]);
        $caller = User::factory()->create();
        $this->userIngredient(User::factory()->create(), 'Almond bar, theirs');

        $granola = $this->userIngredient($caller, 'Granola');
        $barB = $this->userIngredient($caller, 'Protein bar', ['key' => 'u_b']);
        $barA = $this->userIngredient($caller, 'Protein bar', ['key' => 'u_a']);
        $almond = $this->userIngredient($caller, 'almond milk');

        $data = $this->list($caller)->assertOk()->json('data');

        $this->assertSame([$almond->key, $granola->key, $barA->key, $barB->key], array_column($data, 'key'));
        $this->assertSame([true, true, true, true], array_column($data, 'is_mine'));
    }

    public function test_items_have_the_search_shape(): void
    {
        $caller = User::factory()->create();
        $mine = $this->userIngredient($caller, 'Protein bar', ['portions' => [['p_8k2d4x7q', '1 bar', 45]]]);

        $item = $this->list($caller)->assertOk()->json('data.0');

        $this->assertEquals([
            'key' => $mine->key,
            'name' => 'Protein bar',
            'category' => 'supplements',
            'is_mine' => true,
            'per_100g' => ['kcal' => 380, 'protein' => 33, 'carbs' => 38, 'fat' => 11, 'fiber' => null],
            'portions' => [['key' => 'p_8k2d4x7q', 'label' => '1 bar', 'grams' => 45]],
            'photos' => [],
        ], $item);
        $this->assertArrayNotHasKey('nutrients', $item);
        $this->assertArrayNotHasKey('source', $item);
    }

    public function test_the_default_page_has_50_items_and_meta_total_is_the_callers_count(): void
    {
        $caller = User::factory()->create();
        foreach (range(1, 55) as $n) {
            $this->userIngredient($caller, sprintf('Food %02d', $n));
        }
        $this->userIngredient(User::factory()->create(), 'Food of another user');

        $response = $this->list($caller)
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'per_page', 'total']])
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('meta.total', 55);

        $this->assertSame('Food 01', $response->json('data.0.name'));

        $this->list($caller, 'page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.name', 'Food 51');
    }

    public function test_per_page_accepts_100(): void
    {
        $this->list(User::factory()->create(), 'per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'per_page over the maximum' => ['per_page=101', 'per_page'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'page zero' => ['page=0', 'page'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_an_invalid_query_gives_422(string $query, string $field): void
    {
        $this->list(User::factory()->create(), $query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_a_user_with_none_gets_an_empty_list(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->list(User::factory()->create())
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_soft_deleted_ingredients_are_left_out(): void
    {
        $caller = User::factory()->create();
        $this->userIngredient($caller, 'Kept');
        $this->userIngredient($caller, 'Removed')->delete();

        $this->assertSame(['Kept'], array_column($this->list($caller)->json('data'), 'name'));
    }

    public function test_it_runs_four_queries_whatever_the_page_size(): void
    {
        $few = User::factory()->create();
        $many = User::factory()->create();

        foreach ([[$few, 5], [$many, 15]] as [$user, $count]) {
            foreach (range(1, $count) as $n) {
                $ingredient = $this->userIngredient($user, "Food {$n}", ['portions' => [["p_{$n}aaaaaaa", '1 cup', 100], ["p_{$n}bbbbbbb", '1 tbsp', 15]]]);
                // Photo uploads come in a later spec; a row is enough to check the eager load.
                IngredientPhoto::create(['ingredient_id' => $ingredient->id, 'source' => 'commons', 'path' => "Food_{$n}.jpg", 'license' => 'CC0 1.0', 'position' => 1]);
            }
        }

        // The count, the ingredients, their portions and their photos.
        $this->assertSame(4, $this->countQueries($few, 5));
        $this->assertSame(4, $this->countQueries($many, 15));
    }

    private function countQueries(User $user, int $expectedItems): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->list($user)->assertOk()->assertJsonCount($expectedItems, 'data');

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function list(User $user, string $query = ''): TestResponse
    {
        return $this->actingAs($user)->getJson("/api/v1/nutrition/my-ingredients?{$query}");
    }
}
