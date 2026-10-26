<?php

namespace App\Modules\Training;

use App\Modules\Training\Console\CheckExercisePhotosCommand;
use Illuminate\Support\ServiceProvider;

class TrainingServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Training module.
     */
    public function boot(): void
    {
        $this->commands([CheckExercisePhotosCommand::class]);
    }
}
