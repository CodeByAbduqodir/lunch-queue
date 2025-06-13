<?php

namespace App\Services;

class TelegramCommandHandler
{
    private $lunchQueueService;
    private $telegramService;

    public function __construct(LunchQueueService $lunchQueueService, TelegramService $telegramService)
    {
        $this->lunchQueueService = $lunchQueueService;
        $this->telegramService = $telegramService;
    }

    public function handle(\App\Models\User $user, string $chatId, string $text): void
    {
        if ($user->isSupervisor() && $this->handleSupervisorCommands($chatId, $text)) {
            return; 
        }

        switch ($text) {
            case '/start':
                if ($user->isSupervisor()) {
                    $message = "👋 Hello, {$user->first_name}!\n\n";
                    $message .= "You are a supervisor and the following commands are available to you:\n\n";
                    $message .= "/startsession - Start a new queue session\n";
                    $message .= "/status - Show the current queue status\n";
                    $message .= "/startlunch - Start the queue manually";
                    $message .= "/setlimit {number} - Set the limit of concurrent lunches\n";
                    $message .= "/cancel - Cancel the current session\n";
                    $message .= "/help - Show this message";
                    $this->telegramService->sendMessage($chatId, $message);
                } else {
                    $this->lunchQueueService->handleStartCommand($chatId);
                }
                break;
            case '/queue':
                $this->lunchQueueService->handleQueueCommand($chatId, $user);
                break;
            case '/status':
                if ($user->isSupervisor()) {
                    $this->lunchQueueService->handleStatusCommand($chatId);
                } else {
                    $this->telegramService->sendMessage($chatId, 'Unknown command. Use /start or /queue.');
                }
                break;
            default:
                $this->telegramService->sendMessage($chatId, 'Unknown command. Use /start or /queue.');
                break;
        }
    }

    private function handleSupervisorCommands(string $chatId, string $text): bool
    {
        if ($text === '/startsession') {
            $this->lunchQueueService->startNewSession($chatId);
            return true;
        }

        if ($text === '/startlunch') {
            $this->lunchQueueService->startQueueManually($chatId);
            return true;
        }

        if (strpos($text, '/setlimit') === 0) {
            $parts = explode(' ', $text);
            if (count($parts) >= 2 && is_numeric($parts[1])) {
                $this->lunchQueueService->updateConcurrentLimit($chatId, (int) $parts[1]);
            } else {
                $this->telegramService->sendMessage($chatId, "❌ Invalid format. Use: /setlimit <number>");
            }
            return true;
        }

        if (strpos($text, '/cancel') === 0) {
            $this->lunchQueueService->cancelCurrentSession($chatId);
            return true;
        }

        if (strpos($text, '/help') === 0) {
            $this->lunchQueueService->showSupervisorHelp($chatId);
            return true;
        }

        return false; 
    }
}
