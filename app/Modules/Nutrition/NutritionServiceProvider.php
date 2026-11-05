<?php

namespace App\Modules\Nutrition;

use App\Modules\Nutrition\Console\CheckIngredientPhotosCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NutritionServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Nutrition module.
     */
    public function boot(): void
    {
        $this->commands([CheckIngredientPhotosCommand::class]);

        Route::prefix('api/v1/nutrition')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/routes.php');
    }
}
