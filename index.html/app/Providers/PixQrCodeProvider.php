<?php

namespace App\Providers;

use App\Services\PixQrCodeService;
use Illuminate\Support\ServiceProvider;

class PixQrCodeProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PixQrCodeService::class, function ($app) {
            return new PixQrCodeService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
