<?php

namespace App\Services;

class TelegramUpdateHandler
{
    private $commandHandler;
    private $lunchQueueService;

    public function __construct(TelegramCommandHandler $commandHandler, LunchQueueService $lunchQueueService)
    {
        $this->commandHandler = $commandHandler;
        $this->lunchQueueService = $lunchQueueService;
    }

    public function handle(array $update): void
    {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }
    }

    private function handleMessage(array $message): void
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $from = $message['from'];

        $user = $this->createOrUpdateUser($from);

        $this->commandHandler->handle($user, $chatId, $text);
    }

    private function handleCallbackQuery(array $callbackQuery): void
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $data = $callbackQuery['data'];
        $from = $callbackQuery['from'];
        $callbackQueryId = $callbackQuery['id'];

        $user = $this->createOrUpdateUser($from);

        $this->lunchQueueService->handleCallbackQuery($user, $chatId, $data, $callbackQueryId);
    }

    private function createOrUpdateUser(array $telegramUser): \App\Models\User
    {
        return \App\Models\User::updateOrCreate(
            ['telegram_id' => $telegramUser['id']],
            [
                'first_name' => $telegramUser['first_name'] ?? 'User',
                'last_name' => $telegramUser['last_name'] ?? null,
                'username' => $telegramUser['username'] ?? null,
            ]
        );
    }
}
