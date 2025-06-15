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
            'group_size' => 3, // Default group size
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
        $groupSize = $session->group_size;
        
        // If there are people at lunch, wait until they finish
        if ($usersAtLunch > 0) {
            return [];
        }

        return $session->lunchQueues()
            ->where('status', 'waiting')
            ->with('user')
            ->orderBy('position')
            ->take($groupSize)
            ->get()
            ->toArray();
    }

    public function notifyGroupForLunch(array $users, LunchSession $session): void
    {
        $message = "🍽️ <b>Your lunch group is ready!</b>\n\n";
        $message .= "Please confirm that you are ready to go to lunch by clicking the button below.\n";
        $message .= "Your group members:\n";
        
        foreach ($users as $user) {
            $message .= "👤 {$user['user']['first_name']}\n";
        }

        foreach ($users as $user) {
            $keyboard = $this->telegramService->createInlineKeyboard([
                [
                    ['text' => '✅ I\'m Ready', 'callback_data' => "confirm_lunch_{$user['id']}"]
                ]
            ]);

            $this->telegramService->sendMessage(
                $user['user']['chat_id'],
                $message,
                $keyboard
            );

            // Update status to 'notified'
            LunchQueue::where('id', $user['id'])->update(['status' => 'notified']);
        }
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

        // Schedule reminder for 5 minutes before lunch ends
        $this->scheduleLunchEndReminder($queueRecord);

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
        } elseif (strpos($data, 'confirm_lunch_') === 0) {
            $queueId = (int) str_replace('confirm_lunch_', '', $data);
            $this->handleConfirmLunch($chatId, $user, $queueId);
        } elseif (strpos($data, 'start_lunch_') === 0) {
            $queueId = (int) str_replace('start_lunch_', '', $data);
            $this->handleStartLunch($chatId, $user, $queueId);
        } elseif (strpos($data, 'return_lunch_') === 0) {
            $queueId = (int) str_replace('return_lunch_', '', $data);
            $this->handleReturnFromLunch($chatId, $user, $queueId);
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

    private function handleConfirmLunch(string $chatId, User $user, int $queueId): void
    {
        $queueRecord = LunchQueue::where('id', $queueId)
            ->where('user_id', $user->id)
            ->with(['lunchSession', 'user'])
            ->first();

        if (!$queueRecord) {
            $this->telegramService->sendMessage($chatId, "❌ Record not found.");
            return;
        }

        if ($queueRecord->status !== 'notified') {
            $this->telegramService->sendMessage($chatId, "❌ Your queue is not ready for confirmation.");
            return;
        }

        // Update status to 'ready'
        $queueRecord->update(['status' => 'ready']);

        // Check if all group members are ready
        $groupMembers = LunchQueue::where('lunch_session_id', $queueRecord->lunch_session_id)
            ->where('status', 'notified')
            ->orWhere('status', 'ready')
            ->orderBy('position')
            ->take($queueRecord->lunchSession->group_size)
            ->get();

        $allReady = $groupMembers->every(function ($member) {
            return $member->status === 'ready';
        });

        if ($allReady) {
            // All group members are ready, send start lunch buttons
            foreach ($groupMembers as $member) {
                $keyboard = $this->telegramService->createInlineKeyboard([
                    [
                        ['text' => '🍽️ Start Lunch', 'callback_data' => "start_lunch_{$member->id}"]
                    ]
                ]);

                $message = "🎉 Everyone in your group is ready!\n";
                $message .= "Click the button below when you actually start your lunch.";

                $this->telegramService->sendMessage(
                    $member->user->chat_id,
                    $message,
                    $keyboard
                );
            }
        } else {
            // Not everyone is ready yet
            $readyCount = $groupMembers->where('status', 'ready')->count();
            $totalCount = $groupMembers->count();
            
            $message = "✅ You confirmed you're ready!\n";
            $message .= "Waiting for others: {$readyCount}/{$totalCount}";
            
            $this->telegramService->sendMessage($chatId, $message);
        }
    }

    private function handleStartLunch(string $chatId, User $user, int $queueId): void
    {
        $queueRecord = LunchQueue::where('id', $queueId)
            ->where('user_id', $user->id)
            ->with('lunchSession')
            ->first();

        if (!$queueRecord) {
            $this->telegramService->sendMessage($chatId, "❌ Record not found.");
            return;
        }

        if ($queueRecord->status !== 'ready') {
            $this->telegramService->sendMessage($chatId, "❌ Your queue is not ready to start lunch.");
            return;
        }

        $success = $this->startUserLunch($queueRecord);

        if ($success) {
            $message = "🍽️ Enjoy your lunch! Don't forget to return in 30 minutes! ⏰";
            $this->telegramService->sendMessage($chatId, $message);
        } else {
            $message = "❌ Error starting lunch. Please try again.";
            $this->telegramService->sendMessage($chatId, $message);
        }
    }

    private function handleReturnFromLunch(string $chatId, User $user, int $queueId): void
    {
        $queueRecord = LunchQueue::where('id', $queueId)
            ->where('user_id', $user->id)
            ->where('status', 'at_lunch')
            ->first();

        if (!$queueRecord) {
            $this->telegramService->sendMessage($chatId, "❌ Record not found or you have already returned from lunch.");
            return;
        }

        $queueRecord->update([
            'status' => 'finished',
            'lunch_finished_at' => now()
        ]);

        $message = "✅ Thank you for confirming your return!";
        $this->telegramService->sendMessage($chatId, $message);

        $this->notifySupervisorsAboutReturn($user);
    }

    private function scheduleLunchEndReminder(LunchQueue $queueRecord): void
    {
        $reminderTime = now()->addMinutes(25); // 5 minutes before 30-minute lunch ends
        
        // Schedule the reminder
        dispatch(function () use ($queueRecord) {
            $this->sendLunchEndReminder($queueRecord);
        })->delay($reminderTime);
    }

    private function sendLunchEndReminder(LunchQueue $queueRecord): void
    {
        $user = $queueRecord->user;
        $message = "⚠️ Attention! Your lunch break will end in 5 minutes. Please finish your meal and return to work soon!";
        $this->telegramService->sendMessage($user->chat_id, $message);
    }

    private function notifySupervisorsAboutReturn(User $user): void
    {
        $supervisors = User::where('role', 'supervisor')->get();
        
        foreach ($supervisors as $supervisor) {
            $message = "👨‍💼 <b>Notification to supervisor</b>\n\n";
            $message .= "✅ {$user->first_name} returned from lunch\n";
            $message .= "⏰ Time: " . now()->format('H:i');
            
            $this->telegramService->sendMessage($supervisor->telegram_id, $message);
        }
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
        $session = LunchSession::where('date', today())
            ->where('status', 'collecting')
            ->latest()
            ->first();

        if (!$session) {
            $this->telegramService->sendMessage($chatId, "ℹ️ There is no active session for lunch registration.");
            return;
        }

        $queueRecord = LunchQueue::where('user_id', $user->id)
            ->whereHas('lunchSession', fn ($q) => $q->where('date', today()))
            ->first();

        if ($queueRecord) {
            $message = "📍 <b>Your status:</b>\n";
            $message .= "Queue number: {$queueRecord->position}\n";
            $message .= "Status: " . $this->translateStatus($queueRecord->status) . " " . $this->getStatusEmoji($queueRecord->status);
        } else {
            $success = $this->addUserToQueue($user, $session);
            
            if ($success) {
                $position = $session->lunchQueues()->where('user_id', $user->id)->first()->position;
                $message = "✅ You have been successfully added to the queue!\n";
                $message .= "Your number: {$position}";
            } else {
                $message = "⚠️ Unable to add you to the queue. Maybe you are already in the queue or the collection is over.";
            }
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
        $message .= "/startlunch - start queue manually\n";
        $message .= "/setlimit 3 - set limit\n";
        $message .= "/cancel - cancel collection\n";
        $message .= "/startsession - start new session\n";
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

    public function startNewSession(string $chatId): void
    {
        try {
            $existingSession = LunchSession::where('date', today())
                ->where('announcement_time', '12:00')
                ->first();

            if ($existingSession) {
                $this->telegramService->sendMessage($chatId, "⚠️ Session for today already exists!\n\n" .
                    "Use /status to view the current queue.");
                return;
            }

            $session = $this->createLunchSession();
            
            $message = "✅ New queue session created!\n\n";
            $message .= "⏰ Time: 13:00 - 13:30\n";
            $message .= "👥 Simultaneously: up to {$session->max_concurrent_users} people\n";
            $message .= "📝 Use the /queue command to register";
            
            $this->telegramService->sendMessage($chatId, $message);
            
            $groupChatId = config('services.telegram.group_chat_id');
            $announcement = "🍽️ <b>Announcement of lunch collection!</b>\n\n";
            $announcement .= "⏰ Time: 13:00 - 13:30\n";
            $announcement .= "👥 Simultaneously: up to {$session->max_concurrent_users} people\n";
            $message .= "📝 Use the /queue command to register";
            
            $this->telegramService->sendMessage($groupChatId, $announcement);
            
        } catch (\Exception $e) {
            $this->telegramService->sendMessage($chatId, "❌ Error creating session: " . $e->getMessage());
        }
    }

    public function startQueueManually(string $chatId): void
    {
        try {
            $session = LunchSession::where('date', today())
                ->where('status', 'collecting')
                ->latest()
                ->first();

            if (!$session) {
                $this->telegramService->sendMessage($chatId, "❌ No active session for today.");
                return;
            }

            $session->update(['status' => 'active']);
            
            $waitingUsers = $session->lunchQueues()
                ->where('status', 'waiting')
                ->with('user')
                ->orderBy('position')
                ->get();

            if ($waitingUsers->isEmpty()) {
                $this->telegramService->sendMessage($chatId, "ℹ️ There are no waiting people in the queue.");
                return;
            }

            $this->telegramService->sendMessage($chatId, "🚀 Start sending people to lunch!");

            foreach ($waitingUsers as $queueRecord) {
                $user = $queueRecord->user;
                
                $message = "🔔 <b>Your queue for lunch!</b>\n\n";
                $message .= "⏰ Time: " . now()->format('H:i') . "\n";
                $message .= "⏱️ Duration: 30 minutes\n\n";
                $message .= "Confirm that you are going to lunch:";
                
                $keyboard = $this->telegramService->createInlineKeyboard(
                    $this->telegramService->createLunchConfirmationButtons($queueRecord->id)
                );
                
                $result = $this->telegramService->sendMessage(
                    $user->telegram_id, 
                    $message, 
                    $keyboard
                );
                
                if ($result) {
                    $queueRecord->update([
                        'status' => 'notified',
                        'notified_at' => now(),
                        'message_id' => $result['message_id']
                    ]);
                    
                    $this->telegramService->sendMessage($chatId, "✅ Notification sent: {$user->first_name}");
                    
                    $this->notifySupervisorsAboutNotification($user);
                }
            }

            $this->telegramService->sendMessage($chatId, "✅ All notifications sent");

        } catch (\Exception $e) {
            $this->telegramService->sendMessage($chatId, "❌ Error: " . $e->getMessage());
        }
    }

    private function notifySupervisorsAboutNotification(User $user): void
    {
        $supervisors = User::where('role', 'supervisor')->get();
        
        foreach ($supervisors as $supervisor) {
            $message = "👨‍💼 <b>Notification to supervisor</b>\n\n";
            $message .= "🍽️ {$user->first_name} is going to lunch\n";
            $message .= "⏰ Time: " . now()->format('H:i');
            
            $this->telegramService->sendMessage($supervisor->telegram_id, $message);
        }
    }

    public function updateGroupLimit(string $chatId, int $limit): void
    {
        $session = $this->getActiveSession();
        if (!$session) {
            $this->telegramService->sendMessage($chatId, "❌ No active session found.");
            return;
        }

        $session->update(['group_size' => $limit]);
        $this->telegramService->sendMessage($chatId, "✅ Group size has been updated to {$limit} people.");
    }

    public function showGroups(string $chatId): void
    {
        $session = $this->getActiveSession();
        if (!$session) {
            $this->telegramService->sendMessage($chatId, "❌ No active session found.");
            return;
        }

        $queue = $session->lunchQueues()
            ->with('user')
            ->orderBy('position')
            ->get();

        if ($queue->isEmpty()) {
            $this->telegramService->sendMessage($chatId, "📝 No one is in the queue yet.");
            return;
        }

        $groupSize = $session->group_size;
        $groups = $queue->chunk($groupSize);
        $message = "📋 <b>Current Lunch Groups</b>\n\n";

        foreach ($groups as $index => $group) {
            $groupNumber = $index + 1;
            $status = $this->getGroupStatus($group);
            $message .= "👥 <b>Group {$groupNumber}</b> ({$status})\n";
            
            foreach ($group as $queueItem) {
                $emoji = $this->getStatusEmoji($queueItem->status);
                $message .= "{$emoji} {$queueItem->user->first_name} - {$this->translateStatus($queueItem->status)}\n";
            }
            $message .= "\n";
        }

        $message .= "\nTotal people in queue: {$queue->count()}\n";
        $message .= "Group size: {$groupSize} people";

        $this->telegramService->sendMessage($chatId, $message);
    }

    private function getGroupStatus($group): string
    {
        $statuses = $group->pluck('status')->unique();
        
        if ($statuses->contains('at_lunch')) {
            return '🟡 At Lunch';
        } elseif ($statuses->contains('finished')) {
            return '🟢 Finished';
        } elseif ($statuses->contains('notified')) {
            return '🔵 Ready to Start';
        } else {
            return '⚪️ Waiting';
        }
    }

    private function getActiveSession(): ?LunchSession
    {
        return LunchSession::where('date', today()->toDateString())
            ->whereIn('status', ['collecting', 'active'])
            ->latest()
            ->first();
    }
}