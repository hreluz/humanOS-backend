<?php

namespace App\Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A portion's label in one language other than English.
 */
#[Fillable(['ingredient_portion_id', 'locale', 'label'])]
class IngredientPortionTranslation extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<IngredientPortion, $this>
     */
    public function portion(): BelongsTo
    {
        return $this->belongsTo(IngredientPortion::class, 'ingredient_portion_id');
    }
}
