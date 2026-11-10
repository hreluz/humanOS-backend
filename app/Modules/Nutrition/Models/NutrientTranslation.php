<?php

namespace App\Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A nutrient's label in one language.
 */
#[Fillable(['nutrient_id', 'locale', 'label'])]
class NutrientTranslation extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Nutrient, $this>
     */
    public function nutrient(): BelongsTo
    {
        return $this->belongsTo(Nutrient::class);
    }
}
