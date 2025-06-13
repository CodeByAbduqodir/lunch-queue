<?php
// app/Console/Commands/ProcessLunchQueue.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;
use App\Services\LunchQueueService;
use App\Models\LunchSession;
use App\Models\LunchQueue;

class ProcessLunchQueue extends Command
{
    protected $signature = 'lunch:process';
    protected $description = 'Process lunch queue - notify the next';

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
            $sessions = LunchSession::where('date', today())
                ->where('status', 'active')
                ->get();

            foreach ($sessions as $session) {
                $this->processSession($session);
            }

            $this->startSessionsIfTime();
            
        } catch (\Exception $e) {
            $this->error("Error processing queue: " . $e->getMessage());
        }
    }

    private function processSession(LunchSession $session)
    {
        $usersToNotify = $this->queueService->getNextUsersToNotify($session);
        
        foreach ($usersToNotify as $userData) {
            $queueRecord = LunchQueue::find($userData['id']);
            $user = $queueRecord->user;
            
            $message = "🔔 <b>Your lunch queue!</b>\n\n";
            $message .= "Time: " . now()->format('H:i') . "\n";
            $message .= "Duration: 30 minutes\n\n";
            $message .= "Confirm that you are going to lunch:";
            
            $keyboard = $this->telegram->createInlineKeyboard(
                $this->telegram->createLunchConfirmationButtons($queueRecord->id)
            );
            
            $success = $this->telegram->sendMessage(
                $user->telegram_id, 
                $message, 
                $keyboard
            );
            
            if ($success) {
                $queueRecord->update([
                    'status' => 'notified',
                    'notified_at' => now()
                ]);
                
                $this->info("✅ Notified: {$user->first_name}");
                
                $this->notifySupervisors($user, $session);
            }
        }
    }

    private function startSessionsIfTime()
    {
        $sessionsToStart = LunchSession::where('date', today())
            ->where('status', 'collecting')
            ->where('start_time', '<=', now()->format('H:i'))
            ->get();

        foreach ($sessionsToStart as $session) {
            $session->update(['status' => 'active']);
            $this->info("🚀 Started session: " . $session->start_time);
        }
    }

    private function notifySupervisors($user, $session)
    {
        $supervisors = \App\Models\User::where('role', 'supervisor')->get();
        
        foreach ($supervisors as $supervisor) {
            $message = "👨‍💼 <b>Notification to supervisor</b>\n\n";
            $message .= "🍽️ {$user->first_name} is going to lunch\n";
            $message .= "⏰ Time: " . now()->format('H:i');
            
            $this->telegram->sendMessage($supervisor->telegram_id, $message);
        }
    }
}