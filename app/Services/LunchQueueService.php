<?php

// app/Services/LunchQueueService.php

namespace App\Services;

use App\Models\User;
use App\Models\LunchSession;
use App\Models\LunchQueue;
use Carbon\Carbon;

class LunchQueueService
{
    private $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }
    public function createLunchSession(string $date = null, string $announcementTime = '12:00'): LunchSession
    {
        $date = $date ?? today()->toDateString();
        
        return LunchSession::create([
            'date' => $date,
            'announcement_time' => $announcementTime,
            'start_time' => '13:00', 
            'max_concurrent_users' => 3, 
            'status' => 'collecting'
        ]);
    }

    public function addUserToQueue(User $user, LunchSession $session): bool
    {
        if ($user->wasInQueueToday()) {
            return false;
        }

        if ($session->status !== 'collecting') {
            return false;
        }
        $position = $session->lunchQueues()->count() + 1;

        LunchQueue::create([
            'lunch_session_id' => $session->id,
            'user_id' => $user->id,
            'position' => $position,
            'status' => 'waiting'
        ]);

        return true;
    }

    public function getNextUsersToNotify(LunchSession $session): array
    {
        $usersAtLunch = $session->getUsersAtLunch();
        $maxConcurrent = $session->max_concurrent_users;
        $canGoToLunch = $maxConcurrent - $usersAtLunch;

        if ($canGoToLunch <= 0) {
            return [];
        }

        return $session->lunchQueues()
            ->where('status', 'waiting')
            ->with('user')
            ->orderBy('position')
            ->take($canGoToLunch)
            ->get()
            ->toArray();
    }

    public function startUserLunch(LunchQueue $queueRecord): bool
    {
        if ($queueRecord->status !== 'notified') {
            return false;
        }

        $queueRecord->update([
            'status' => 'at_lunch',
            'lunch_started_at' => now()
        ]);

        return true;
    }

    public function getUsersNeedingReminder(int $minutesBefore = 5): array
    {
        $reminderTime = now()->subMinutes(30 - $minutesBefore); 

        return LunchQueue::where('status', 'at_lunch')
            ->where('lunch_started_at', '<=', $reminderTime)
            ->with(['user', 'lunchSession'])
            ->get()
            ->toArray();
    }

    public function getSupervisorStats(LunchSession $session): array
    {
        $queue = $session->getCurrentQueue();
        
        return [
            'total_in_queue' => $queue->count(),
            'users_at_lunch' => $session->getUsersAtLunch(),
            'waiting' => $queue->where('status', 'waiting')->count(),
            'finished' => $queue->where('status', 'finished')->count(),
            'queue_list' => $queue->map(function ($item) {
                return [
                    'position' => $item->position,
                    'name' => $item->user->first_name,
                    'status' => $item->status,
                    'started_at' => $item->lunch_started_at?->format('H:i')
                ];
            })->toArray()
        ];
    }

    public function handleCallbackQuery(User $user, string $chatId, string $data, string $callbackQueryId): void
    {
        if (strpos($data, 'join_queue_') === 0) {
            $sessionId = (int) str_replace('join_queue_', '', $data);
            $this->handleJoinQueue($chatId, $user, $sessionId);
        } elseif (strpos($data, 'start_lunch_') === 0) {
            $queueId = (int) str_replace('start_lunch_', '', $data);
            $this->handleStartLunch($chatId, $user, $queueId);
        }
        $this->telegramService->answerCallbackQuery($callbackQueryId);
    }

    private function handleJoinQueue(string $chatId, User $user, int $sessionId): void
    {
        $session = LunchSession::where('id', $sessionId)->first();
        if (!$session) {
            $this->telegramService->sendMessage($chatId, '❌ Session not found.');
            return;
        }

        $success = $this->addUserToQueue($user, $session);

        if ($success) {
            $position = $session->lunchQueues()->where('user_id', $user->id)->first()->position;
            $message = "✅ You have been successfully added to the queue! Your number: {$position}";
        } else {
            $message = "⚠️ You are already in the queue today or the collection is over.";
        }
        $this->telegramService->sendMessage($chatId, $message);
    }

    private function handleStartLunch(string $chatId, User $user, int $queueId): void
    {
        $queueRecord = LunchQueue::where('id', $queueId)->where('user_id', $user->id)->first();
        if (!$queueRecord) {
            $this->telegramService->sendMessage($chatId, "❌ Record not found.");
            return;
        }

        $success = $this->startUserLunch($queueRecord);

        if ($success) {
            $message = "🍽️ Enjoy your lunch! Don't forget to return in 30 minutes! ⏰";
        } else {
            $message = "❌ Error confirming lunch. Maybe your queue hasn't arrived yet.";
        }
        $this->telegramService->sendMessage($chatId, $message);
    }

    public function handleStartCommand(string $chatId): void
    {
        $message = "Hello! I am a bot for lunch registration 🍽️.\n\n" .
                   "Wait for the announcement at 12:00 to register.";
        $this->telegramService->sendMessage($chatId, $message);
    }

    public function handleStatusCommand(string $chatId): void
    {
        $session = LunchSession::where('date', today())->latest()->first();
        if (!$session) {
            $this->telegramService->sendMessage($chatId, "ℹ️ No sessions today.");
            return;
        }

        $stats = $this->getSupervisorStats($session);
        $message = "📊 <b>Status on ".today()->format('d.m.Y')."</b>\n\n";
        $message .= "Total in queue: {$stats['total_in_queue']}\n";
        $message .= "At lunch: {$stats['users_at_lunch']} / {$session->max_concurrent_users}\n";
        $message .= "Waiting: {$stats['waiting']}\n";
        $message .= "Finished: {$stats['finished']}\n\n";
        $message .= "<b>Queue list:</b>\n";

        if (empty($stats['queue_list'])) {
            $message .= "Queue is empty.";
        } else {
            foreach ($stats['queue_list'] as $item) {
                $statusEmoji = $this->getStatusEmoji($item['status']);
                $message .= "{$item['position']}. {$item['name']} {$statusEmoji}\n";
            }
        }
        $this->telegramService->sendMessage($chatId, $message);
    }

    public function handleQueueCommand(string $chatId, User $user): void
    {
        $queueRecord = LunchQueue::where('user_id', $user->id)
            ->whereHas('lunchSession', fn ($q) => $q->where('date', today()))
            ->first();

        if ($queueRecord) {
            $message = "📍 <b>Your status:</b>\n";
            $message .= "Queue number: {$queueRecord->position}\n";
            $message .= "Status: " . $this->translateStatus($queueRecord->status) . " " . $this->getStatusEmoji($queueRecord->status);
        } else {
            $message = "ℹ️ You are not in the queue today.";
        }
        $this->telegramService->sendMessage($chatId, $message);
    }

    public function updateConcurrentLimit(string $chatId, int $limit): void
    {
        $session = LunchSession::where('date', today())->where('status', '!=', 'finished')->latest()->first();
        if ($session) {
            $session->update(['max_concurrent_users' => $limit]);
            $message = "✅ Limit changed to: <b>{$limit}</b>";
        } else {
            $message = "❌ No active session.";
        }
        $this->telegramService->sendMessage($chatId, $message);
    }

    public function cancelCurrentSession(string $chatId): void
    {
        $session = LunchSession::where('date', today())->where('status', 'collecting')->latest()->first();
        if ($session) {
            $session->update(['status' => 'finished']);
            $queueUsers = $session->lunchQueues()->with('user')->get();
            foreach ($queueUsers as $queueRecord) {
                $this->telegramService->sendMessage($queueRecord->user->telegram_id, "❌ Lunch collection canceled.");
            }
            $message = "✅ Current session canceled. Notified: " . $queueUsers->count();
        } else {
            $message = "❌ No active session to cancel.";
        }
        $this->telegramService->sendMessage($chatId, $message);
    }

    public function showSupervisorHelp(string $chatId): void
    {
        $message = "👨‍💼 <b>Supervisor commands:</b>\n\n";
        $message .= "/status - current queue status\n";
        $message .= "/setlimit 3 - set limit\n";
        $message .= "/cancel - cancel collection\n";
        $message .= "/help - this help";
        $this->telegramService->sendMessage($chatId, $message);
    }

    private function getStatusEmoji(string $status): string
    {
        return match($status) {
            'waiting' => '⏳',
            'notified' => '🔔',
            'at_lunch' => '🍽️',
            'finished' => '✅',
            default => '❓'
        };
    }

    private function translateStatus(string $status): string
    {
        return match($status) {
            'waiting' => 'Waiting',
            'notified' => 'Notified',
            'at_lunch' => 'At lunch',
            'finished' => 'Finished',
            default => 'Unknown'
        };
    }
}