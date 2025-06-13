<?php
// app/Console/Kernel.php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\SendLunchAnnouncement::class,
        Commands\ProcessLunchQueue::class,
        Commands\StartLunchQueue::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('lunch:announce')
            ->dailyAt('12:00')
            ->weekdays() 
            ->timezone('Asia/Tashkent'); 

        $schedule->command('lunch:process')
            ->cron('*/2 13-15 * * 1-5') 
            ->timezone('Asia/Tashkent');

        $schedule->command('lunch:remind')
            ->cron('* 13-15 * * 1-5') 
            ->timezone('Asia/Tashkent');
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

