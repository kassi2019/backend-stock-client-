<?php

namespace App\Providers;

use App\Observers\NotificationPushObserver;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Chaque notification enregistrée est aussi envoyée en push (FCM)
        DatabaseNotification::observe(NotificationPushObserver::class);
    }
}
