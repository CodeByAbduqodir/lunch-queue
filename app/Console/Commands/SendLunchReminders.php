<?php

// app/Console/Commands/SendLunchReminders.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;
use App\Services\LunchQueueService;

class SendLunchReminders extends Command
{
    protected $signature = 'lunch:remind';
    protected $description = 'Send reminders about the end of lunch';

    private $telegram;
    private $queueService;

    public function __construct(TelegramService $telegram, LunchQueueService $queueService)
    {
        parent::__construct();
        $this->telegram = $telegram;
        $this->queueService = $queueService;
    }

    public function handle()
    {
        try {
            $this->sendReminders(5);
            
            $this->finishOverdueLunches();
            
        } catch (\Exception $e) {
            $this->error("Error sending reminders: " . $e->getMessage());
        }
    }

    private function sendReminders(int $minutesBefore)
    {
        $users = $this->queueService->getUsersNeedingReminder($minutesBefore);
        
        foreach ($users as $userData) {
            $queueRecord = \App\Models\LunchQueue::find($userData['id']);
            $user = $queueRecord->user;
            $remainingTime = $queueRecord->getRemainingLunchTime();
            
            if ($remainingTime > 0 && $remainingTime <= $minutesBefore) {
                $message = "⏰ <b>Reminder about lunch</b>\n\n";
                $message .= "Remaining time: <b>{$remainingTime} min.</b>\n";
                $message .= "Don't forget to return to work! 🏃‍♂️";
                
                $this->telegram->sendMessage($user->telegram_id, $message);
                $this->info("⏰ Reminder sent: {$user->first_name}");
            }
        }
    }

    private function finishOverdueLunches()
    {
        $overdueUsers = \App\Models\LunchQueue::where('status', 'at_lunch')
            ->where('lunch_started_at', '<=', now()->subMinutes(30))
            ->with(['user', 'lunchSession'])
            ->get();

        foreach ($overdueUsers as $queueRecord) {
            $queueRecord->update([
                'status' => 'finished',
                'lunch_finished_at' => now()
            ]);
            
            $message = "🚨 <b>Lunch time is over!</b>\n\n";
            $message .= "Please return to work.";
            
            $this->telegram->sendMessage($queueRecord->user->telegram_id, $message);
            
            $this->notifySupervisorsAboutOverdue($queueRecord->user);
            
            $this->warn("⚠️ Lunch finished automatically: {$queueRecord->user->first_name}");
        }
    }

    private function notifySupervisorsAboutOverdue($user)
    {
        $supervisors = \App\Models\User::where('role', 'supervisor')->get();
        
        foreach ($supervisors as $supervisor) {
            $message = "🚨 <b>Lunch time exceeded</b>\n\n";
            $message .= "👤 {$user->first_name} exceeded lunch time\n";
            $message .= "⏰ Lunch finished automatically in " . now()->format('H:i');
            
            $this->telegram->sendMessage($supervisor->telegram_id, $message);
        }
    }
}