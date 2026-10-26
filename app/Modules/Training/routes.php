<?php

use App\Modules\Training\Http\Controllers\EquipmentGroupController;
use App\Modules\Training\Http\Controllers\MuscleAreaController;
use Illuminate\Support\Facades\Route;

// Routes are prefixed with /api/v1/training and require auth:sanctum (TrainingServiceProvider).

Route::get('/muscle-areas', MuscleAreaController::class);
Route::get('/equipment-groups', EquipmentGroupController::class);
