<?php
// app/Services/TelegramService.php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private $client;
    private $botToken;
    private $baseUrl;

    public function __construct()
    {
        $this->client = new Client();
        $this->botToken = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$this->botToken}/";
    }

    // Отправить сообщение пользователю
    public function sendMessage(string $chatId, string $text, array $keyboard = null): bool
    {
        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML'
            ];

            if ($keyboard) {
                $payload['reply_markup'] = json_encode($keyboard);
            }

            $response = $this->client->post($this->baseUrl . 'sendMessage', [
                'json' => $payload
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            Log::error('Telegram send message error: ' . $e->getMessage());
            return false;
        }
    }

    // Создать инлайн клавиатуру (кнопки)
    public function createInlineKeyboard(array $buttons): array
    {
        return [
            'inline_keyboard' => $buttons
        ];
    }

    // Создать кнопку для записи в очередь
    public function createJoinQueueButton(int $sessionId): array
    {
        return [
            [
                [
                    'text' => '🍽️ Join lunch queue',
                    'callback_data' => "join_queue_{$sessionId}"
                ]
            ]
        ];
    }

    // Создать кнопки для подтверждения обеда
    public function createLunchConfirmationButtons(int $queueId): array
    {
        return [
            [
                [
                    'text' => '✅ Gone to lunch',
                    'callback_data' => "start_lunch_{$queueId}"
                ]
            ]
        ];
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): bool
    {
        try {
            $payload = ['callback_query_id' => $callbackQueryId];
            if ($text) {
                $payload['text'] = $text;
            }

            $response = $this->client->post($this->baseUrl . 'answerCallbackQuery', [
                'json' => $payload
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            Log::error('Telegram answer callback query error: ' . $e->getMessage());
            return false;
        }
    }
}