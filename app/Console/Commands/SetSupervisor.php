<?php
// app/Console/Commands/SetSupervisor.php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class SetSupervisor extends Command
{
    protected $signature = 'user:supervisor {telegram_id} {--remove}';
    protected $description = 'Assign or remove supervisor';

    public function handle()
    {
        $telegramId = $this->argument('telegram_id');
        $remove = $this->option('remove');

        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $user = User::create([
                'telegram_id' => $telegramId,
                'first_name' => 'Supervisor',
                'role' => 'supervisor'
            ]);
            $this->info("✅ Created new user with ID {$telegramId}");
        }

        $newRole = $remove ? 'operator' : 'supervisor';
        $user->update(['role' => $newRole]);

        $action = $remove ? 'removed from' : 'assigned as';
        $this->info("✅ {$user->first_name} {$action} supervisors");
    }
}