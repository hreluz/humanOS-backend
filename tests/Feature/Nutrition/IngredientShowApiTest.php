<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPortion;
use App\Modules\Nutrition\Models\Nutrient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nutrition\Concerns\SeedsIngredientFixtures;
use Tests\TestCase;

class IngredientShowApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsIngredientFixtures;

    public function test_it_requires_a_token(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->getJson('/api/v1/nutrition/ingredients/egg_whole_raw')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_an_unknown_key_gives_404(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->show('unknown_key')
            ->assertNotFound()
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('data');
    }

    public function test_it_returns_the_ingredient_with_every_field(): void
    {
        $this->seedIngredients([$this->egg()]);

        // Swapped, so only position can put them right.
        IngredientPortion::where('key', 'large')->update(['position' => 2]);
        IngredientPortion::where('key', 'medium')->update(['position' => 1]);

        $this->assertEquals([
            'key' => 'egg_whole_raw',
            'name' => 'Egg, whole, raw',
            'category' => 'eggs',
            'is_mine' => false,
            'per_100g' => ['kcal' => 148, 'protein' => 12.4, 'carbs' => 0.96, 'fat' => 9.96, 'fiber' => 0],
            'portions' => [
                ['key' => 'medium', 'label' => '1 medium egg', 'grams' => 44],
                ['key' => 'large', 'label' => '1 large egg', 'grams' => 50],
            ],
            'photos' => [],
            'nutrients' => [
                ['key' => 'sugars', 'label' => 'Sugars', 'group' => 'fats_and_sugars', 'unit' => 'g', 'amount' => 0],
                ['key' => 'cholesterol', 'label' => 'Cholesterol', 'group' => 'fats_and_sugars', 'unit' => 'mg', 'amount' => 411],
                ['key' => 'selenium', 'label' => 'Selenium', 'group' => 'minerals', 'unit' => 'ug', 'amount' => 31.1],
            ],
            'source' => ['type' => 'usda_foundation', 'id' => '748967'],
        ], $this->show('egg_whole_raw')->assertOk()->json('data'));
    }

    public function test_every_number_is_a_json_number(): void
    {
        $this->seedIngredients([$this->egg()]);

        $data = $this->show('egg_whole_raw')->json('data');

        $numbers = [
            ...array_values($data['per_100g']),
            ...array_column($data['portions'], 'grams'),
            ...array_column($data['nutrients'], 'amount'),
        ];

        $this->assertCount(5 + 2 + 3, $numbers);
        foreach ($numbers as $number) {
            $this->assertTrue(is_int($number) || is_float($number), var_export($number, true).' is not a JSON number.');
        }
    }

    public function test_an_unmeasured_nutrient_is_absent_and_a_measured_zero_is_present(): void
    {
        $this->seedIngredients([$this->egg()]);

        $nutrients = collect($this->show('egg_whole_raw')->json('data.nutrients'))->keyBy('key');

        $this->assertArrayNotHasKey('vitamin_c', $nutrients);
        $this->assertEquals(0, $nutrients['sugars']['amount']);
        $this->assertSame(['sugars', 'cholesterol', 'selenium'], $nutrients->keys()->all());
    }

    public function test_an_ingredient_with_no_portions_returns_an_empty_list(): void
    {
        $this->seedIngredients([$this->egg(['portions' => []])]);

        $this->assertSame([], $this->show('egg_whole_raw')->json('data.portions'));
    }

    public function test_it_returns_the_photos_in_position_order(): void
    {
        $this->seedIngredients([$this->egg(['photos' => $this->twoPhotos()])]);

        $this->assertSame($this->expectedTwoPhotos(), $this->show('egg_whole_raw')->assertOk()->json('data.photos'));

        $this->swapPhotoPositions('egg_whole_raw');

        $this->assertSame($this->expectedTwoPhotos(swapped: true), $this->show('egg_whole_raw')->assertOk()->json('data.photos'));
    }

    public function test_the_photo_urls_follow_the_config(): void
    {
        config([
            'services.ingredient_photos.base_url' => 'https://images.example.test/files',
            'services.ingredient_photos.width' => 1280,
            'services.ingredient_photos.thumbnail_width' => 250,
        ]);
        $this->seedIngredients([$this->egg(['photos' => [$this->photo('Egg_cracked.jpg')]])]);

        $photo = $this->show('egg_whole_raw')->json('data.photos.0');

        $this->assertSame('https://images.example.test/files/Egg_cracked.jpg?width=1280', $photo['url']);
        $this->assertSame('https://images.example.test/files/Egg_cracked.jpg?width=250', $photo['thumbnail_url']);
    }

    public function test_an_ingredient_with_no_photos_returns_an_empty_list(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->assertSame([], $this->show('egg_whole_raw')->json('data.photos'));
    }

    public function test_a_soft_deleted_ingredient_gives_404(): void
    {
        $this->seedIngredients([$this->egg()]);

        Ingredient::where('key', 'egg_whole_raw')->firstOrFail()->delete();

        $this->show('egg_whole_raw')->assertNotFound();
    }

    public function test_a_soft_deleted_portion_or_nutrient_is_left_out(): void
    {
        $this->seedIngredients([$this->egg()]);

        IngredientPortion::where('key', 'medium')->firstOrFail()->delete();
        Nutrient::where('key', 'cholesterol')->firstOrFail()->delete();

        $data = $this->show('egg_whole_raw')->assertOk()->json('data');

        $this->assertSame(['large'], array_column($data['portions'], 'key'));
        $this->assertSame(['sugars', 'selenium'], array_column($data['nutrients'], 'key'));
    }

    public function test_it_returns_the_callers_own_ingredient(): void
    {
        $owner = User::factory()->create();
        $mine = $this->userIngredient($owner, 'Protein bar, chocolate', [
            'portions' => [['p_8k2d4x7q', '1 bar', 45], ['p_2bars000', '2 bars', 90]],
        ]);

        $this->assertEquals([
            'key' => $mine->key,
            'name' => 'Protein bar, chocolate',
            'category' => 'supplements',
            'is_mine' => true,
            'per_100g' => ['kcal' => 380, 'protein' => 33, 'carbs' => 38, 'fat' => 11, 'fiber' => null],
            'portions' => [
                ['key' => 'p_8k2d4x7q', 'label' => '1 bar', 'grams' => 45],
                ['key' => 'p_2bars000', 'label' => '2 bars', 'grams' => 90],
            ],
            'photos' => [],
            'nutrients' => [],
            'source' => ['type' => 'user', 'id' => null],
        ], $this->show($mine->key, $owner)->assertOk()->json('data'));
    }

    public function test_a_null_fiber_is_returned_as_null(): void
    {
        $owner = User::factory()->create();
        $mine = $this->userIngredient($owner, 'Label food');

        $response = $this->show($mine->key, $owner)->assertOk();

        $this->assertArrayHasKey('fiber', $response->json('data.per_100g'));
        $this->assertNull($response->json('data.per_100g.fiber'));
    }

    public function test_another_users_ingredient_gives_404(): void
    {
        $theirs = $this->userIngredient(User::factory()->create(), 'Protein bar, chocolate');

        $this->show($theirs->key)
            ->assertNotFound()
            ->assertJsonMissingPath('data');
    }

    private function show(string $key, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->create())
            ->getJson("/api/v1/nutrition/ingredients/{$key}");
    }
}
