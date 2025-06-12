<?php
// database/migrations/2024_01_01_000001_create_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// database/migrations/2024_01_01_000002_create_lunch_sessions_table.php

return new class extends Migration
{
    public function up()
    {
        Schema::create('lunch_sessions', function (Blueprint $table) {
            $table->id();
            $table->date('date'); // Дата сессии (например, 2024-01-15)
            $table->time('announcement_time'); // Время объявления (12:00)
            $table->time('start_time'); // Время начала обедов (13:00)
            $table->integer('max_concurrent_users')->default(3); // Сколько человек одновременно на обеде
            $table->enum('status', ['collecting', 'active', 'finished'])->default('collecting');
            $table->timestamps();
            
            $table->unique(['date', 'announcement_time']); // Одна сессия на дату и время
        });
    }

    public function down()
    {
        Schema::dropIfExists('lunch_sessions');
    }
};