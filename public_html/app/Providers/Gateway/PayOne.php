<?php

namespace App\Providers\Gateway;

use App\Services\PayOne\PayOneService;
use Illuminate\Support\ServiceProvider;

class PayOne extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PayOneService::class, function () {
            return new PayOneService(
                config('services.payone.public_key'),
                config('services.payone.secret_key')
            );
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
