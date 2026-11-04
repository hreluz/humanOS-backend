<?php

namespace App\Modules\Nutrition\Models;

use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Enums\IngredientSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
}
