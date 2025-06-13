<?php
// app/Console/Commands/SendLunchAnnouncement.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;
use App\Services\LunchQueueService;

class SendLunchAnnouncement extends Command
{
    protected $signature = 'lunch:announce';
    protected $description = 'Send lunch announcement to the group';

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
            $session = $this->queueService->createLunchSession();
            
            $groupChatId = config('services.telegram.group_chat_id');
            
            $message = "🍽️ <b>Announce the lunch collection!</b>\n\n";
            $message .= "⏰ Time: 13:00 - 13:30\n";
            $message .= "👥 Simultaneously: up to 3 people\n";
            $message .= "📝 For registration, click the button below or write /queue";
            
            $keyboard = $this->telegram->createInlineKeyboard(
                $this->telegram->createJoinQueueButton($session->id)
            );
            
            $success = $this->telegram->sendMessage($groupChatId, $message, $keyboard);
            
            if ($success) {
                $this->info("✅ Announcement sent to the group!");
            } else {
                $this->error("❌ Error sending announcement");
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}

