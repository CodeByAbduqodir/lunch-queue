<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;
use App\Services\LunchQueueService;
use App\Models\LunchSession;
use App\Models\LunchQueue;

class StartLunchQueue extends Command
{
    protected $signature = 'lunch:start-queue';
    protected $description = 'Start sending people to lunch manually';

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
            $session = LunchSession::where('date', today())
                ->where('status', 'collecting')
                ->latest()
                ->first();

            if (!$session) {
                $this->error("❌ No active session for today");
                return;
            }

            $session->update(['status' => 'active']);
            $this->info("✅ Session activated");

            $waitingUsers = $session->lunchQueues()
                ->where('status', 'waiting')
                ->with('user')
                ->orderBy('position')
                ->get();

            if ($waitingUsers->isEmpty()) {
                $this->info("ℹ️ No people in the queue");
                return;
            }

            $this->info("👥 Found {$waitingUsers->count()} people in the queue");

            foreach ($waitingUsers as $queueRecord) {
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

            $this->info("✅ All notifications sent");

        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
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