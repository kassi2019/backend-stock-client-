<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Le « temps réel » de l'app repose sur un polling 4-5 s (web et
            // mobile souvent ouverts en même temps) : limite généreuse pour
            // les utilisateurs connectés. Les invités (connexion) gardent une
            // limite stricte contre les tentatives de force brute.
            // NB : auth:sanctum tourne APRÈS le throttle, on résout donc
            // l'utilisateur directement via le guard sanctum.
            $user = $request->user('sanctum');
            if ($user) {
                return Limit::perMinute(300)->by($user->id);
            }
            return Limit::perMinute(30)->by($request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
