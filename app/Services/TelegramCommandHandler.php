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
        // Supervisor commands take priority
        if ($user->isSupervisor() && $this->handleSupervisorCommands($chatId, $text)) {
            return; // Command was handled
        }

        switch ($text) {
            case '/start':
                $this->lunchQueueService->handleStartCommand($chatId);
                break;
            case '/queue':
                $this->lunchQueueService->handleQueueCommand($chatId, $user);
                break;
            case '/status':
                 // Status is a supervisor-only command, so we check again here.
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

        return false; // Not a supervisor command
    }
}
