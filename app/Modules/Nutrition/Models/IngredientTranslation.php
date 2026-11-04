<?php

namespace App\Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An ingredient's display name in one language other than English.
 */
#[Fillable(['ingredient_id', 'locale', 'name'])]
class IngredientTranslation extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
