<?php

namespace App\Modules\Nutrition\Models;

use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Enums\IngredientSource;
use App\Modules\Nutrition\Http\Requests\SearchIngredientsRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A catalog ingredient, loaded from `database/data/ingredients` by `IngredientSeeder` (SPEC 15).
 *
 * Every value is per 100 g. Soft-deleted ingredients left the files but are still referenced, so they stay hidden.
 */
#[Fillable(['key', 'name', 'category', 'kcal', 'protein', 'carbs', 'fat', 'fiber', 'source', 'source_id'])]
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
     * The search query: the category filter, a match anywhere in the English name or an English alias, then
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
