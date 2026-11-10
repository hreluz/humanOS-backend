<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPortion;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Nutrition\Concerns\ReferencesCatalogRows;
use Tests\Feature\Nutrition\Concerns\SeedsIngredientFixtures;
use Tests\TestCase;

class UserIngredientUpdateApiTest extends TestCase
{
    use ReferencesCatalogRows;
    use SeedsIngredientFixtures;

    private User $owner;

    private Ingredient $mine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->mine = $this->userIngredient($this->owner, 'Protein bar, chocolate', [
            'protein' => 30, 'carbs' => 38, 'fat' => 11, 'fiber' => 9,
            'portions' => [['p_aaaaaaaa', '1 bar', 45], ['p_bbbbbbbb', '2 bars', 90], ['p_cccccccc', 'half bar', 22.5]],
        ]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->patchJson("/api/v1/nutrition/ingredients/{$this->mine->key}", ['name' => 'Changed'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertSame('Protein bar, chocolate', $this->mine->fresh()->name);
    }

    public function test_a_catalog_ingredient_gives_403_even_with_an_invalid_body(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->update('egg_whole_raw', ['name' => 'Changed'])->assertForbidden();
        $this->update('egg_whole_raw', ['name' => null, 'fat' => 500, 'portions' => 'nope'])->assertForbidden();

        $this->assertSame('Egg, whole, raw', Ingredient::where('key', 'egg_whole_raw')->value('name'));
    }

    public function test_another_users_ingredient_gives_404(): void
    {
        $theirs = $this->userIngredient(User::factory()->create(), 'Their bar');

        $this->update($theirs->key, ['name' => 'Changed'])->assertNotFound()->assertJsonMissingPath('data');

        $this->assertSame('Their bar', $theirs->fresh()->name);
    }

    public function test_an_unknown_key_gives_404(): void
    {
        $this->update('u_unknown', ['name' => 'Changed'])->assertNotFound();
    }

    public function test_one_field_sent_changes_only_that_field(): void
    {
        $data = $this->update($this->mine->key, ['name' => 'Protein bar, dark chocolate'])->assertOk()->json('data');

        $this->assertEquals([
            'key' => $this->mine->key,
            'name' => 'Protein bar, dark chocolate',
            'category' => 'supplements',
            'is_mine' => true,
            'per_100g' => ['kcal' => 380, 'protein' => 30, 'carbs' => 38, 'fat' => 11, 'fiber' => 9],
            'portions' => [
                ['key' => 'p_aaaaaaaa', 'label' => '1 bar', 'grams' => 45],
                ['key' => 'p_bbbbbbbb', 'label' => '2 bars', 'grams' => 90],
                ['key' => 'p_cccccccc', 'label' => 'half bar', 'grams' => 22.5],
            ],
            'photos' => [],
            'nutrients' => [],
            'source' => ['type' => 'user', 'id' => null],
        ], $data);

        $this->update($this->mine->key, ['kcal' => 410, 'category' => 'other'])->assertOk();

        $fresh = $this->mine->fresh();
        $this->assertSame(['Protein bar, dark chocolate', 'other', 410.0, 30.0, 38.0, 11.0, 9.0], [
            $fresh->name, $fresh->category->value, $fresh->kcal, $fresh->protein, $fresh->carbs, $fresh->fat, $fresh->fiber,
        ]);
    }

    public function test_fiber_null_clears_it(): void
    {
        $this->update($this->mine->key, ['fiber' => null])
            ->assertOk()
            ->assertJsonPath('data.per_100g.fiber', null);

        $this->assertNull($this->mine->fresh()->fiber);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'null name' => [['name' => null], 'name'],
            'null category' => [['category' => null], 'category'],
            'null kcal' => [['kcal' => null], 'kcal'],
            'null protein' => [['protein' => null], 'protein'],
            'null carbs' => [['carbs' => null], 'carbs'],
            'null fat' => [['fat' => null], 'fat'],
            'name of 0 characters after trimming' => [['name' => '   '], 'name'],
            'name of 101 characters' => [['name' => str_repeat('a', 101)], 'name'],
            'unknown category' => [['category' => 'sweets'], 'category'],
            'kcal over 900' => [['kcal' => 901], 'kcal'],
            'fiber below 0' => [['fiber' => -1], 'fiber'],
            'macro sum over 100 with the values sent' => [['protein' => 50, 'carbs' => 40, 'fat' => 10.1], 'fat'],
            'macro sum over 100 with stored values' => [['fat' => 80], 'fat'],
            '11 portions' => [['portions' => array_fill(0, 11, ['label' => 'x', 'grams' => 1])], 'portions'],
            'portion label of 51 characters' => [['portions' => [['label' => str_repeat('a', 51), 'grams' => 1]]], 'portions.0.label'],
            'portion grams of 0' => [['portions' => [['label' => 'x', 'grams' => 0]]], 'portions.0.grams'],
            'portion grams of 5001' => [['portions' => [['label' => 'x', 'grams' => 5001]]], 'portions.0.grams'],
            'unknown portion key' => [['portions' => [['key' => 'p_zzzzzzzz', 'label' => 'x', 'grams' => 1]]], 'portions.0.key'],
            'same portion key twice' => [['portions' => [
                ['key' => 'p_aaaaaaaa', 'label' => 'x', 'grams' => 1],
                ['key' => 'p_aaaaaaaa', 'label' => 'y', 'grams' => 2],
            ]], 'portions.1.key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('invalidBodies')]
    public function test_an_invalid_body_gives_422_and_changes_nothing(array $body, string $field): void
    {
        $before = $this->snapshot();

        $this->update($this->mine->key, $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_the_sum_rule_accepts_a_merged_total_of_100(): void
    {
        // Stored protein 30 + carbs 38 = 68, so fat 32 makes exactly 100.
        $this->update($this->mine->key, ['fat' => 32])->assertOk()->assertJsonPath('data.per_100g.fat', 32);
    }

    public function test_portions_absent_leaves_them_unchanged(): void
    {
        $before = IngredientPortion::where('ingredient_id', $this->mine->id)->orderBy('position')->get(['id', 'key', 'label', 'grams', 'position'])->toArray();

        $this->update($this->mine->key, ['name' => 'Changed'])->assertOk();

        $this->assertSame($before, IngredientPortion::where('ingredient_id', $this->mine->id)->orderBy('position')->get(['id', 'key', 'label', 'grams', 'position'])->toArray());
    }

    public function test_portions_are_matched_by_key_created_without_one_and_removed_when_left_out(): void
    {
        $keptId = IngredientPortion::where('key', 'p_bbbbbbbb')->value('id');

        $portions = $this->update($this->mine->key, ['portions' => [
            ['label' => 'a handful', 'grams' => 30],
            ['key' => 'p_bbbbbbbb', 'label' => 'two bars', 'grams' => 92],
        ]])->assertOk()->json('data.portions');

        $this->assertCount(2, $portions);
        $this->assertMatchesRegularExpression('/^p_[0-9a-z]{8}$/', $portions[0]['key']);
        $this->assertNotContains($portions[0]['key'], ['p_aaaaaaaa', 'p_bbbbbbbb', 'p_cccccccc']);
        $this->assertSame(['label' => 'a handful', 'grams' => 30], array_diff_key($portions[0], ['key' => true]));
        $this->assertEquals(['key' => 'p_bbbbbbbb', 'label' => 'two bars', 'grams' => 92], $portions[1]);

        // The kept portion is the same row, and positions follow the list.
        $rows = IngredientPortion::withTrashed()->where('ingredient_id', $this->mine->id)->orderBy('position')->get();
        $this->assertSame([$portions[0]['key'], 'p_bbbbbbbb'], $rows->pluck('key')->all());
        $this->assertSame([1, 2], $rows->pluck('position')->all());
        $this->assertSame($keptId, $rows[1]->id);
    }

    public function test_an_empty_list_removes_every_portion(): void
    {
        $this->update($this->mine->key, ['portions' => []])->assertOk()->assertJsonPath('data.portions', []);

        $this->assertSame(0, IngredientPortion::withTrashed()->where('ingredient_id', $this->mine->id)->count());
    }

    public function test_a_portion_key_from_another_ingredient_gives_422(): void
    {
        $other = $this->userIngredient($this->owner, 'Other bar', ['portions' => [['p_otherkey', '1 bar', 40]]]);

        $this->update($this->mine->key, ['portions' => [['key' => 'p_otherkey', 'label' => 'x', 'grams' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['portions.0.key']);

        $this->assertSame(['p_otherkey'], $other->portions()->pluck('key')->all());
    }

    public function test_a_soft_deleted_portions_key_gives_422(): void
    {
        IngredientPortion::where('key', 'p_cccccccc')->firstOrFail()->delete();

        $this->update($this->mine->key, ['portions' => [['key' => 'p_cccccccc', 'label' => 'x', 'grams' => 1]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['portions.0.key']);
    }

    public function test_a_new_portion_never_reuses_a_soft_deleted_portions_key(): void
    {
        $this->reference(['ingredient_portion_id' => IngredientPortion::where('key', 'p_cccccccc')->value('id')]);
        $this->update($this->mine->key, ['portions' => []])->assertOk();

        $portions = $this->update($this->mine->key, ['portions' => array_fill(0, 10, ['label' => 'x', 'grams' => 1])])
            ->assertOk()
            ->json('data.portions');

        $keys = array_column($portions, 'key');
        $this->assertCount(10, array_unique($keys));
        $this->assertNotContains('p_cccccccc', $keys);
    }

    public function test_a_referenced_portion_left_out_is_soft_deleted(): void
    {
        $referenced = IngredientPortion::where('key', 'p_aaaaaaaa')->firstOrFail();
        $this->reference(['ingredient_portion_id' => $referenced->id]);

        $this->update($this->mine->key, ['portions' => [['key' => 'p_bbbbbbbb', 'label' => '2 bars', 'grams' => 90]]])
            ->assertOk()
            ->assertJsonPath('data.portions.0.key', 'p_bbbbbbbb')
            ->assertJsonCount(1, 'data.portions');

        $this->assertTrue($referenced->fresh()->trashed());
        $this->assertNull(IngredientPortion::withTrashed()->where('key', 'p_cccccccc')->first());
    }

    /**
     * The ingredient's row and portions, to check a failed update changed nothing.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'ingredient' => Ingredient::whereKey($this->mine->id)->first()->only(['name', 'category', 'kcal', 'protein', 'carbs', 'fat', 'fiber']),
            'portions' => IngredientPortion::withTrashed()->where('ingredient_id', $this->mine->id)->orderBy('id')->get(['key', 'label', 'grams', 'position', 'deleted_at'])->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function update(string $key, array $body): TestResponse
    {
        return $this->actingAs($this->owner)->patchJson("/api/v1/nutrition/ingredients/{$key}", $body);
    }
}
