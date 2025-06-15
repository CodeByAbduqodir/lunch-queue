<?php

// app/Console/Commands/SetupWebhook.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TelegramService;
use GuzzleHttp\Client;

class SetupWebhook extends Command
{
    protected $signature = 'telegram:webhook {--delete}';
    protected $description = 'Setup webhook for Telegram bot';

    public function handle()
    {
        try {
            $client = new Client();
            $botToken = config('services.telegram.bot_token');
            
            if ($this->option('delete')) {
                $response = $client->post("https://api.telegram.org/bot$botToken/deleteWebhook");
                $this->info("✅ Webhook deleted");
                return;
            }

            $webhookUrl = config('app.url') . '/api/telegram/webhook';
            
            $response = $client->post("https://api.telegram.org/bot$botToken/setWebhook", [
                'json' => [
                    'url' => $webhookUrl,
                    'allowed_updates' => ['message', 'callback_query']
                ]
            ]);

            $result = json_decode($response->getBody(), true);
            
            if ($result['ok']) {
                $this->info("✅ Webhook set: {$webhookUrl}");
            } else {
                $this->error("❌ Error setting webhook: " . $result['description']);
            }
            
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
        }
    }
}
