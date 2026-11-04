<?php

use App\Modules\Nutrition\Http\Controllers\IngredientController;
use Illuminate\Support\Facades\Route;

// Routes are prefixed with /api/v1/nutrition and require auth:sanctum (NutritionServiceProvider).

Route::get('/ingredients', [IngredientController::class, 'index']);
Route::get('/ingredients/{ingredient:key}', [IngredientController::class, 'show']);
