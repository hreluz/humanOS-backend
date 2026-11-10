<?php

namespace App\Modules\Nutrition\Enums;

/**
 * Where an ingredient photo comes from (SPEC 17). The uploads spec adds `Upload`.
 */
enum IngredientPhotoSource: string
{
    case Commons = 'commons';
}
