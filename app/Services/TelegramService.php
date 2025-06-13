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

    public function sendMessage(string $chatId, string $text, array $keyboard = null): ?array
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

            if ($response->getStatusCode() === 200) {
                $result = json_decode($response->getBody(), true);
                return $result['result'] ?? null;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Telegram send message error: ' . $e->getMessage());
            return null;
        }
    }

    public function createInlineKeyboard(array $buttons): array
    {
        return [
            'inline_keyboard' => $buttons
        ];
    }

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

    public function createReturnConfirmationButtons(int $queueId): array
    {
        return [
            [
                [
                    'text' => '✅ Returned from lunch',
                    'callback_data' => "return_lunch_{$queueId}"
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

    public function deleteMessage(string $chatId, int $messageId): bool
    {
        try {
            $response = $this->client->post($this->baseUrl . 'deleteMessage', [
                'json' => [
                    'chat_id' => $chatId,
                    'message_id' => $messageId
                ]
            ]);

            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            Log::error('Telegram delete message error: ' . $e->getMessage());
            return false;
        }
    }
}