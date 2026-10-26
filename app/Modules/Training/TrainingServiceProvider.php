<?php

namespace App\Modules\Training;

use App\Modules\Training\Console\CheckExercisePhotosCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TrainingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Training module.
     */
    public function boot(): void
    {
        $this->commands([CheckExercisePhotosCommand::class]);

        Route::prefix('api/v1/training')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/routes.php');
    }
}
