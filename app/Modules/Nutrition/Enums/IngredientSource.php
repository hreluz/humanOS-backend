<?php

namespace App\Modules\Nutrition\Enums;

/**
 * Where an ingredient's values come from: a USDA FoodData Central dataset (SPEC 15), or the user who typed
 * them in (SPEC 18). Catalog entries never use `user`.
 */
enum IngredientSource: string
{
    case UsdaFoundation = 'usda_foundation';
    case UsdaSrLegacy = 'usda_sr_legacy';
    case User = 'user';
}
