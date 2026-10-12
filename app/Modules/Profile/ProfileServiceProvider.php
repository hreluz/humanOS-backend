<?php

namespace App\Modules\Profile;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProfileServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Profile module.
     */
    public function boot(): void
    {
        Route::prefix('api/v1/profile')
            ->middleware(['api', 'auth:sanctum'])
            ->group(__DIR__.'/routes.php');
    }
}
