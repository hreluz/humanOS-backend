<?php

namespace App\Modules\Nutrition\Enums;

/**
 * How a person shops for the food. The frontend's 13 keys (`app/types/food.ts`).
 */
enum IngredientCategory: string
{
    case Meat = 'meat';
    case Seafood = 'seafood';
    case Eggs = 'eggs';
    case PlantProtein = 'plant_protein';
    case Dairy = 'dairy';
    case Grains = 'grains';
    case Legumes = 'legumes';
    case Vegetables = 'vegetables';
    case Fruit = 'fruit';
    case Fats = 'fats';
    case Condiments = 'condiments';
    case Supplements = 'supplements';
    case Other = 'other';
}
