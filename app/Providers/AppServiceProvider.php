<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
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
        // Older shared-hosting MySQL / MariaDB versions can still enforce
        // shorter utf8mb4 index limits, so cap default indexed string length.
        Schema::defaultStringLength(191);
    }
}
