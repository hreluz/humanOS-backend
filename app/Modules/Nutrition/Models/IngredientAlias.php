<?php

namespace App\Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Another name to find an ingredient by, in one language.
 */
#[Fillable(['ingredient_id', 'locale', 'name'])]
class IngredientAlias extends Model
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
