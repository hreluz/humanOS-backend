<?php

namespace App\Modules\Auth;

use App\Modules\Auth\Console\PromoteUserCommand;
use App\Modules\Auth\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Auth module.
     */
    public function boot(): void
    {
        $this->commands([PromoteUserCommand::class]);

        Route::aliasMiddleware('admin', EnsureUserIsAdmin::class);

        RateLimiter::for('auth-login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });

        VerifyEmail::createUrlUsing(function ($notifiable) {
            $apiUrl = URL::temporarySignedRoute(
                'auth.verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
            );

            parse_str((string) parse_url($apiUrl, PHP_URL_QUERY), $query);

            return config('app.frontend_url').'/verify-email?'.http_build_query([
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
                'expires' => $query['expires'],
                'signature' => $query['signature'],
            ]);
        });

        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            return config('app.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });

        Route::prefix('api/v1/auth')
            ->middleware('api')
            ->group(__DIR__.'/routes.php');
    }
}
