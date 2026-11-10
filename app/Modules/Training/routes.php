<?php

use App\Modules\Training\Http\Controllers\EquipmentGroupController;
use App\Modules\Training\Http\Controllers\ExerciseController;
use App\Modules\Training\Http\Controllers\MuscleAreaController;
use App\Modules\Training\Http\Controllers\WorkoutLocationController;
use App\Modules\Training\Http\Controllers\WorkoutLocationExerciseController;
use Illuminate\Support\Facades\Route;

// Routes are prefixed with /api/v1/training and require auth:sanctum (TrainingServiceProvider).
// `{workoutLocation:key}` only resolves the caller's own locations (SPEC 19). The exercise routes opt out of
// scoped bindings: `{exercise:key}` is any catalog exercise, not only those already at the location.

Route::get('/muscle-areas', MuscleAreaController::class);
Route::get('/equipment-groups', EquipmentGroupController::class);
Route::get('/exercises', [ExerciseController::class, 'index']);
Route::get('/exercises/{exercise:key}', [ExerciseController::class, 'show']);

Route::get('/locations', [WorkoutLocationController::class, 'index']);
Route::post('/locations', [WorkoutLocationController::class, 'store']);
Route::get('/locations/{workoutLocation:key}', [WorkoutLocationController::class, 'show']);
Route::patch('/locations/{workoutLocation:key}', [WorkoutLocationController::class, 'update']);
Route::delete('/locations/{workoutLocation:key}', [WorkoutLocationController::class, 'destroy']);
Route::put('/locations/{workoutLocation:key}/exercises/{exercise:key}', [WorkoutLocationExerciseController::class, 'store'])
    ->withoutScopedBindings();
Route::delete('/locations/{workoutLocation:key}/exercises/{exercise:key}', [WorkoutLocationExerciseController::class, 'destroy'])
    ->withoutScopedBindings();
