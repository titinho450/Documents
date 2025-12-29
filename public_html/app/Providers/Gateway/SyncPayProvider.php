<?php

namespace App\Providers\Gateway;

use App\Services\SyncPay\SyncPay;
use Illuminate\Support\ServiceProvider;

class SyncPayProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SyncPay::class, function () {
            return new SyncPay(
                config('services.syncpay.api_url'),
                config('services.syncpay.api_key')
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
