<?php

use App\Modules\Nutrition\Http\Controllers\IngredientController;
use Illuminate\Support\Facades\Route;

// Routes are prefixed with /api/v1/nutrition and require auth:sanctum (NutritionServiceProvider).
// `{ingredient:key}` only resolves what the caller can see: the catalog and their own (SPEC 18).

Route::get('/ingredients', [IngredientController::class, 'index']);
Route::post('/ingredients', [IngredientController::class, 'store']);
Route::get('/ingredients/{ingredient:key}', [IngredientController::class, 'show']);
Route::patch('/ingredients/{ingredient:key}', [IngredientController::class, 'update']);
Route::delete('/ingredients/{ingredient:key}', [IngredientController::class, 'destroy']);
Route::get('/my-ingredients', [IngredientController::class, 'mine']);
