<?php

namespace App\Modules\Nutrition;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NutritionServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Nutrition module.
     */
    public function boot(): void
    {
        Route::prefix('api/v1/nutrition')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/routes.php');
    }
}
