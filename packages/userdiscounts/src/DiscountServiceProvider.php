<?php

namespace UserDiscounts;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;

class DiscountServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/discounts.php',
            'discounts'
        );

        $this->app->singleton(DiscountService::class, function ($app) {
            return new DiscountService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/discounts.php' => config_path('discounts.php'),
        ], 'discounts-config');
    }
}

