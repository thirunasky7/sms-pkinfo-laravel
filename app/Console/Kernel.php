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
        $schedule->command('sms:dispatch-scheduled')->everyMinute();
        $schedule->command('whatsapp:dispatch-due')->everyMinute()->withoutOverlapping();
        $schedule->command('whatsapp:monitor-devices')->everyMinute()->withoutOverlapping();
        $schedule->command('whatsapp:refresh-tokens')->daily();
        $schedule->command('whatsapp:sync-templates')->hourly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
