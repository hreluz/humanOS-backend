<?php

namespace App\Modules\Nutrition\Models;

use App\Modules\Nutrition\Enums\NutrientGroup;
use App\Modules\Nutrition\Enums\NutrientUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A nutrient tracked beyond the five macro columns, loaded by `NutrientSeeder` (SPEC 15).
 */
#[Fillable(['key', 'label', 'group', 'unit', 'fdc_nutrient_id', 'sort_order'])]
class Nutrient extends Model
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
            'group' => NutrientGroup::class,
            'unit' => NutrientUnit::class,
        ];
    }

    /**
     * The label in every language but English, which is `label`.
     *
     * @return HasMany<NutrientTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(NutrientTranslation::class);
    }

    /**
     * @return BelongsToMany<Ingredient, $this>
     */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class)->withPivot('amount');
    }
}
