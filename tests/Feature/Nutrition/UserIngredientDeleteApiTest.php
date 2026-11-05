<?php

namespace Tests\Feature\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Models\IngredientPortion;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nutrition\Concerns\ReferencesCatalogRows;
use Tests\Feature\Nutrition\Concerns\SeedsIngredientFixtures;
use Tests\TestCase;

class UserIngredientDeleteApiTest extends TestCase
{
    use ReferencesCatalogRows;
    use SeedsIngredientFixtures;

    private User $owner;

    private Ingredient $mine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->mine = $this->userIngredient($this->owner, 'Granola, homemade', [
            'portions' => [['p_aaaaaaaa', '1 cup', 120], ['p_bbbbbbbb', '1 tbsp', 8]],
        ]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->deleteJson("/api/v1/nutrition/ingredients/{$this->mine->key}")
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertNotNull($this->mine->fresh());
    }

    public function test_a_catalog_ingredient_gives_403(): void
    {
        $this->seedIngredients([$this->egg()]);

        $this->destroy('egg_whole_raw')->assertForbidden();

        $this->assertNotNull(Ingredient::where('key', 'egg_whole_raw')->first());
    }

    public function test_another_users_ingredient_gives_404(): void
    {
        $theirs = $this->userIngredient(User::factory()->create(), 'Granola, theirs');

        $this->destroy($theirs->key)->assertNotFound()->assertJsonMissingPath('data');

        $this->assertNotNull($theirs->fresh());
    }

    public function test_an_unknown_key_gives_404(): void
    {
        $this->destroy('u_unknown')->assertNotFound();
    }

    public function test_it_returns_204_and_hard_deletes_the_ingredient_and_its_portions(): void
    {
        $this->destroy($this->mine->key)->assertNoContent();

        $this->assertNull(Ingredient::withTrashed()->find($this->mine->id));
        $this->assertSame(0, IngredientPortion::withTrashed()->where('ingredient_id', $this->mine->id)->count());
    }

    public function test_a_deleted_ingredient_is_gone_from_every_endpoint(): void
    {
        $this->destroy($this->mine->key)->assertNoContent();

        $this->assertGoneEverywhere();
    }

    public function test_a_referenced_ingredient_is_soft_deleted_and_still_gives_404(): void
    {
        $this->reference(['ingredient_id' => $this->mine->id]);

        $this->destroy($this->mine->key)->assertNoContent();

        $row = Ingredient::withTrashed()->find($this->mine->id);
        $this->assertNotNull($row);
        $this->assertTrue($row->trashed());

        $this->assertGoneEverywhere();
        $this->destroy($this->mine->key)->assertNotFound();
    }

    private function assertGoneEverywhere(): void
    {
        $this->actingAs($this->owner)->getJson("/api/v1/nutrition/ingredients/{$this->mine->key}")->assertNotFound();
        $this->actingAs($this->owner)->patchJson("/api/v1/nutrition/ingredients/{$this->mine->key}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/v1/nutrition/ingredients?search=granola')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->owner)->getJson('/api/v1/nutrition/my-ingredients')->assertOk()->assertJsonCount(0, 'data');
    }

    private function destroy(string $key): TestResponse
    {
        return $this->actingAs($this->owner)->deleteJson("/api/v1/nutrition/ingredients/{$key}");
    }
}
