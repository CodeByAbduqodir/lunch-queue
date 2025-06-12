<?php
// app/Http/Controllers/TelegramWebhookController.php

namespace App\Http\Controllers;

use App\Services\TelegramService;
use App\Services\TelegramUpdateHandler;
use App\Services\LunchQueueService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    private $updateHandler;

    public function __construct(TelegramUpdateHandler $updateHandler)
    {
        $this->updateHandler = $updateHandler;
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            $this->updateHandler->handle($request->all());
            return response()->json(['ok' => true]);
        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['ok' => false], 500);
        }
    }
}