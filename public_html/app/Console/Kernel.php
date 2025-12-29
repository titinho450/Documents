<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Executar limpeza de tokens expirados diariamente às 2:00
        $schedule->command('investments:process-market-returns')->daily();
        $schedule->command('password:clean-tokens')
            ->daily()
            ->at('02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->emailOutputOnFailure('admin@seusite.com'); // Opcional

        // Ou executar a cada hora
        // $schedule->command('password:clean-tokens')->hourly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
