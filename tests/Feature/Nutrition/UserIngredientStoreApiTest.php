<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Models\Ingredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserIngredientStoreApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_a_token(): void
    {
        $this->postJson('/api/v1/nutrition/ingredients', $this->body())
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertSame(0, Ingredient::count());
    }

    public function test_it_creates_the_ingredient_and_returns_the_detail_shape(): void
    {
        $data = $this->store($this->body([
            'portions' => [['label' => '1 bar', 'grams' => 45], ['label' => '2 bars', 'grams' => 90]],
        ]))->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^u_[0-9a-z]{26}$/', $data['key']);
        $this->assertSame(28, strlen($data['key']));
        $this->assertCount(2, $data['portions']);
        foreach ($data['portions'] as $portion) {
            $this->assertMatchesRegularExpression('/^p_[0-9a-z]{8}$/', $portion['key']);
        }
        $this->assertNotSame($data['portions'][0]['key'], $data['portions'][1]['key']);

        $this->assertEquals([
            'key' => $data['key'],
            'name' => 'Protein bar, chocolate',
            'category' => 'supplements',
            'is_mine' => true,
            'per_100g' => ['kcal' => 380, 'protein' => 33, 'carbs' => 38, 'fat' => 11, 'fiber' => 9],
            'portions' => [
                ['key' => $data['portions'][0]['key'], 'label' => '1 bar', 'grams' => 45],
                ['key' => $data['portions'][1]['key'], 'label' => '2 bars', 'grams' => 90],
            ],
            'photos' => [],
            'nutrients' => [],
            'source' => ['type' => 'user', 'id' => null],
        ], $data);
    }

    public function test_it_is_stored_as_the_callers_ingredient(): void
    {
        $user = User::factory()->create();

        $key = $this->store($this->body(['portions' => [['label' => '1 bar', 'grams' => 45]]]), $user)->json('data.key');

        $ingredient = Ingredient::where('key', $key)->firstOrFail();
        $this->assertSame($user->id, $ingredient->user_id);
        $this->assertSame(IngredientSource::User, $ingredient->source);
        $this->assertNull($ingredient->source_id);
        $this->assertSame([1], $ingredient->portions()->pluck('position')->all());
    }

    public function test_fiber_omitted_is_stored_and_returned_as_null(): void
    {
        $body = $this->body();
        unset($body['fiber']);

        $response = $this->store($body)->assertCreated();

        $this->assertArrayHasKey('fiber', $response->json('data.per_100g'));
        $this->assertNull($response->json('data.per_100g.fiber'));
        $this->assertNull(Ingredient::where('key', $response->json('data.key'))->value('fiber'));
    }

    public function test_portions_omitted_gives_an_empty_list(): void
    {
        $body = $this->body();
        unset($body['portions']);

        $this->assertSame([], $this->store($body)->assertCreated()->json('data.portions'));
    }

    public function test_trimmed_strings_are_stored(): void
    {
        $data = $this->store($this->body([
            'name' => '  Protein bar  ',
            'portions' => [['label' => '  1 bar ', 'grams' => 45]],
        ]))->assertCreated()->json('data');

        $this->assertSame('Protein bar', $data['name']);
        $this->assertSame('1 bar', $data['portions'][0]['label']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'missing name' => [['name' => null], 'name'],
            'missing category' => [['category' => null], 'category'],
            'missing kcal' => [['kcal' => null], 'kcal'],
            'missing protein' => [['protein' => null], 'protein'],
            'missing carbs' => [['carbs' => null], 'carbs'],
            'missing fat' => [['fat' => null], 'fat'],
            'unknown category' => [['category' => 'sweets'], 'category'],
            'name of 0 characters after trimming' => [['name' => '    '], 'name'],
            'name of 101 characters' => [['name' => str_repeat('é', 101)], 'name'],
            'protein below 0' => [['protein' => -0.1], 'protein'],
            'carbs over 100' => [['carbs' => 100.1, 'protein' => 0, 'fat' => 0], 'carbs'],
            'fat over 100' => [['fat' => 101, 'protein' => 0, 'carbs' => 0], 'fat'],
            'fiber over 100' => [['fiber' => 100.5], 'fiber'],
            'fiber below 0' => [['fiber' => -1], 'fiber'],
            'kcal below 0' => [['kcal' => -1], 'kcal'],
            'kcal over 900' => [['kcal' => 900.01], 'kcal'],
            'macro sum of 100.1' => [['protein' => 50, 'carbs' => 30, 'fat' => 20.1], 'fat'],
            'macro that is not a number' => [['protein' => 'lots'], 'protein'],
            '11 portions' => [['portions' => array_fill(0, 11, ['label' => '1 bar', 'grams' => 45])], 'portions'],
            'portions not a list' => [['portions' => ['a' => ['label' => '1 bar', 'grams' => 45]]], 'portions'],
            'portion without a label' => [['portions' => [['grams' => 45]]], 'portions.0.label'],
            'portion label of 0 characters after trimming' => [['portions' => [['label' => '  ', 'grams' => 45]]], 'portions.0.label'],
            'portion label of 51 characters' => [['portions' => [['label' => str_repeat('a', 51), 'grams' => 45]]], 'portions.0.label'],
            'portion without grams' => [['portions' => [['label' => '1 bar']]], 'portions.0.grams'],
            'portion grams of 0' => [['portions' => [['label' => '1 bar', 'grams' => 0]]], 'portions.0.grams'],
            'portion grams of 5001' => [['portions' => [['label' => '1 bar', 'grams' => 5001]]], 'portions.0.grams'],
            'portion with a key' => [['portions' => [['key' => 'p_8k2d4x7q', 'label' => '1 bar', 'grams' => 45]]], 'portions.0.key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_gives_422(array $overrides, string $field): void
    {
        $this->store($this->body($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertSame(0, Ingredient::count());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function boundaryBodies(): array
    {
        return [
            'name of 1 character' => [['name' => 'A']],
            'name of 100 characters' => [['name' => str_repeat('é', 100)]],
            'every macro 0' => [['kcal' => 0, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'fiber' => 0]],
            'kcal of 900 and a fat of 100' => [['kcal' => 900, 'protein' => 0, 'carbs' => 0, 'fat' => 100]],
            'macro sum of exactly 100' => [['protein' => 33.3, 'carbs' => 33.3, 'fat' => 33.4]],
            'fiber null' => [['fiber' => null]],
            '10 portions' => [['portions' => array_fill(0, 10, ['label' => '1 bar', 'grams' => 45])]],
            'portion label of 50 characters and grams of 5000' => [['portions' => [['label' => str_repeat('a', 50), 'grams' => 5000]]]],
            'portion grams of 0.01' => [['portions' => [['label' => 'a pinch', 'grams' => 0.01]]]],
            'empty portions' => [['portions' => []]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('boundaryBodies')]
    public function test_a_body_at_the_limits_is_accepted(array $overrides): void
    {
        $this->store($this->body($overrides))->assertCreated();
    }

    public function test_fiber_is_outside_the_macro_sum(): void
    {
        // Chia seeds, from a US label: 17 + 42 + 31 = 90, plus fiber 34 = 124.
        $this->store($this->body(['protein' => 17, 'carbs' => 42, 'fat' => 31, 'fiber' => 34]))
            ->assertCreated()
            ->assertJsonPath('data.per_100g.fiber', 34);
    }

    public function test_two_ingredients_with_the_same_name_are_both_created(): void
    {
        $user = User::factory()->create();

        $first = $this->store($this->body(), $user)->assertCreated()->json('data.key');
        $second = $this->store($this->body(), $user)->assertCreated()->json('data.key');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, Ingredient::ownedBy($user)->where('name', 'Protein bar, chocolate')->count());
    }

    /**
     * The spec's example body.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'name' => 'Protein bar, chocolate',
            'category' => 'supplements',
            'kcal' => 380,
            'protein' => 33,
            'carbs' => 38,
            'fat' => 11,
            'fiber' => 9,
            'portions' => [['label' => '1 bar', 'grams' => 45]],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function store(array $body, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->postJson('/api/v1/nutrition/ingredients', $body);
    }
}
