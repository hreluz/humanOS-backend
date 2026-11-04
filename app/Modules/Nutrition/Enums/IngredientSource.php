<?php

namespace App\Modules\Nutrition\Enums;

/**
 * The USDA FoodData Central dataset an ingredient's values come from (SPEC 15).
 */
enum IngredientSource: string
{
    case UsdaFoundation = 'usda_foundation';
    case UsdaSrLegacy = 'usda_sr_legacy';
}
