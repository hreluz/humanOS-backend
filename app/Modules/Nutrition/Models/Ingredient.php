<?php

namespace App\Modules\Nutrition\Models;

use App\Models\User;
use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Http\Requests\SearchIngredientsRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An ingredient: a catalog one, loaded from `database/data/ingredients` by `IngredientSeeder` (SPEC 15), or a
 * user's private one (SPEC 18).
 *
 * Every value is per 100 g, and a `null` `fiber` means it isn't known. Soft-deleted ingredients were removed but
 * are still referenced, so they stay hidden. `user_id` is `null` for system ingredients, the catalog from the
 * files, and the seeder only touches those (SPEC 17). A user's ingredient has a `u_` key, the `user` source and
 * a `null` `source_id`, and only its owner sees it.
 */
#[Fillable(['user_id', 'key', 'name', 'category', 'kcal', 'protein', 'carbs', 'fat', 'fiber', 'source', 'source_id'])]
class Ingredient extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => IngredientCategory::class,
            'source' => IngredientSource::class,
            'kcal' => 'float',
            'protein' => 'float',
            'carbs' => 'float',
            'fat' => 'float',
            'fiber' => 'float',
        ];
    }

    /**
     * The user who owns the ingredient, or `null` for a system ingredient.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The ingredient's photos in display order. The first is the main photo.
     *
     * @return HasMany<IngredientPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(IngredientPhoto::class)->orderBy('position');
    }

    /**
     * System ingredients only: the catalog loaded from the data files.
     *
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->whereNull('ingredients.user_id');
    }

    /**
     * What a user can see: the catalog plus their own ingredients, never another user's (SPEC 18).
     *
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('ingredients.user_id')
            ->orWhere('ingredients.user_id', $user->getKey()));
    }

    /**
     * Only the user's own ingredients.
     *
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('ingredients.user_id', $user->getKey());
    }

    /**
     * Whether the user owns this ingredient. A system ingredient has no owner.
     */
    public function isOwnedBy(User $user): bool
    {
        return $this->user_id !== null && (int) $this->user_id === $user->getKey();
    }

    /**
     * A new key for a user's ingredient: `u_` and a lowercase ULID, 28 characters. Catalog keys are snake_case
     * words, and the seeder rejects `u_` keys, so the two can't clash.
     */
    public static function generateUserKey(): string
    {
        return 'u_'.Str::lower((string) Str::ulid());
    }

    /**
     * The measured nutrients beyond the macros, with their `amount` per 100 g. A nutrient not listed wasn't measured.
     *
     * @return BelongsToMany<Nutrient, $this>
     */
    public function nutrients(): BelongsToMany
    {
        return $this->belongsToMany(Nutrient::class)->withPivot('amount')->orderBy('sort_order');
    }

    /**
     * The ingredient's portions in display order.
     *
     * @return HasMany<IngredientPortion, $this>
     */
    public function portions(): HasMany
    {
        return $this->hasMany(IngredientPortion::class)->orderBy('position');
    }

    /**
     * The name in every language but English, which is `name`.
     *
     * @return HasMany<IngredientTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(IngredientTranslation::class);
    }

    /**
     * Other names to search by: regional words and English alternatives.
     *
     * @return HasMany<IngredientAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(IngredientAlias::class);
    }

    /**
     * The search query: what the request user can see, the category filter, a match anywhere in the English name
     * or an English alias (a user's ingredient matches on its name only, having no aliases), then
     * ranked by the name (exact, prefix, word prefix, the rest), then sorted by name and key.
     *
     * Case and accents are ignored through the `utf8mb4_unicode_ci` collation.
     *
     * @param  Builder<Ingredient>  $query
     * @return Builder<Ingredient>
     */
    public function scopeSearch(Builder $query, SearchIngredientsRequest $filters): Builder
    {
        $search = (string) $filters->validated('search');
        $term = addcslashes($search, '\\%_');

        return $query
            ->visibleTo($filters->user())
            ->when($filters->validated('category'), fn (Builder $query, array $categories) => $query
                ->whereIn('ingredients.category', $categories))
            ->where(fn (Builder $query) => $query
                ->whereLike('ingredients.name', "%{$term}%")
                ->orWhereHas('aliases', fn (Builder $query) => $query
                    ->where('ingredient_aliases.locale', 'en')
                    ->whereLike('ingredient_aliases.name', "%{$term}%")))
            ->orderByRaw(
                'CASE WHEN ingredients.name = ? THEN 0 WHEN ingredients.name LIKE ? THEN 1 WHEN ingredients.name LIKE ? THEN 2 ELSE 3 END',
                [$search, "{$term}%", "% {$term}%"],
            )
            ->orderBy('ingredients.name')
            ->orderBy('ingredients.key');
    }
}
