<?php

namespace App\Modules\Nutrition\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A measure of an ingredient and its weight, e.g. `1 large egg` = 50 g.
 *
 * Catalog portions have snake_case keys from the data files. A user's portions have generated `p_` keys that
 * never change, so references to them survive edits (SPEC 18).
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
     * A new key for a user's portion (SPEC 18): `p_` and 8 random lowercase letters and digits, e.g. `p_8k2d4x7q`.
     * Retries until the key isn't in `$taken`, which holds every key of the ingredient, soft-deleted ones included.
     *
     * @param  array<int, string>  $taken
     */
    public static function generateUserKey(array $taken): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';

        do {
            $key = 'p_';
            for ($i = 0; $i < 8; $i++) {
                $key .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (in_array($key, $taken, true));

        return $key;
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
