<?php

use App\Modules\Profile\Http\Controllers\ProfileController;
use App\Modules\Profile\Http\Controllers\TargetWeightEntryController;
use App\Modules\Profile\Http\Controllers\WeightEntryController;
use Illuminate\Support\Facades\Route;

// Routes are prefixed with /api/v1/profile and require auth:sanctum (ProfileServiceProvider).

Route::get('/', [ProfileController::class, 'show']);
Route::put('/', [ProfileController::class, 'update']);

Route::get('/weight-entries', [WeightEntryController::class, 'index']);
Route::get('/target-weight-entries', [TargetWeightEntryController::class, 'index']);
