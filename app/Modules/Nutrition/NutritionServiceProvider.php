<?php

namespace App\Modules\Nutrition;

use App\Models\User;
use App\Modules\Nutrition\Console\CheckIngredientPhotosCommand;
use App\Modules\Nutrition\Models\Ingredient;
use App\Modules\Nutrition\Policies\IngredientPolicy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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

        // Policies under app/Modules aren't auto-discovered.
        Gate::policy(Ingredient::class, IngredientPolicy::class);

        // `{ingredient:key}` only finds what the authenticated user can see: the catalog and their own (SPEC 18).
        // Another user's ingredient, or a soft-deleted one, is a 404 before any controller or policy runs.
        Route::bind('ingredient', function (string $value): Ingredient {
            $user = Auth::user();

            if (! $user instanceof User) {
                throw (new ModelNotFoundException)->setModel(Ingredient::class, [$value]);
            }

            return Ingredient::visibleTo($user)->where('key', $value)->firstOrFail();
        });

        Route::prefix('api/v1/nutrition')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/routes.php');
    }
}
