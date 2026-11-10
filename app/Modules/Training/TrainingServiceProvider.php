<?php

namespace App\Modules\Training;

use App\Models\User;
use App\Modules\Training\Console\CheckExercisePhotosCommand;
use App\Modules\Training\Models\WorkoutLocation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
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

        // `{workoutLocation:key}` only finds the authenticated user's own locations (SPEC 19). Another user's
        // location is a 404 before any controller runs. `Route::bind` is global, hence the specific name.
        Route::bind('workoutLocation', function (string $value): WorkoutLocation {
            $user = Auth::user();

            if (! $user instanceof User) {
                throw (new ModelNotFoundException)->setModel(WorkoutLocation::class, [$value]);
            }

            return WorkoutLocation::ownedBy($user)->where('key', $value)->firstOrFail();
        });

        Route::prefix('api/v1/training')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/routes.php');
    }
}
