<?php

namespace App\Providers;

use App\Http\Controllers\SyncPay;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {


        $this->app->singleton(SyncPay::class, function ($app) {
            return new SyncPay();
        });
        date_default_timezone_set('America/Sao_Paulo');
        Carbon::setLocale('pt_BR');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::share('frontendVars', [
            'appUrl' => config('app.url'),
            'csrfToken' => csrf_token(),
        ]);
    }
}
