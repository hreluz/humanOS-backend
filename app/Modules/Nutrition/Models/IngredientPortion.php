<?php

namespace App\Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A measure of an ingredient and its weight, e.g. `1 large egg` = 50 g.
 */
#[Fillable(['ingredient_id', 'key', 'label', 'grams', 'position'])]
class IngredientPortion extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grams' => 'float',
        ];
    }

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * The label in every language but English, which is `label`.
     *
     * @return HasMany<IngredientPortionTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(IngredientPortionTranslation::class);
    }
}
