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
            ->weekdays() // Только в рабочие дни
            ->timezone('Asia/Tashkent'); // Твоя временная зона

        // Обрабатываем очередь каждые 2 минуты с 13:00 до 15:00
        $schedule->command('lunch:process')
            ->cron('*/2 13-15 * * 1-5') // Каждые 2 минуты, с 13 до 15, в рабочие дни
            ->timezone('Asia/Tashkent');

        // Отправляем напоминания каждую минуту в обеденное время
        $schedule->command('lunch:remind')
            ->cron('* 13-15 * * 1-5') // Каждую минуту, с 13 до 15, в рабочие дни
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

