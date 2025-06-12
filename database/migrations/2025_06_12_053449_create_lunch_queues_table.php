<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
// database/migrations/2024_01_01_000003_create_lunch_queues_table.php

return new class extends Migration
{
    public function up()
    {
        Schema::create('lunch_queues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lunch_session_id'); // К какой сессии относится
            $table->unsignedBigInteger('user_id'); // Кто в очереди
            $table->integer('position'); // Позиция в очереди (1, 2, 3...)
            $table->enum('status', ['waiting', 'notified', 'at_lunch', 'finished'])->default('waiting');
            $table->timestamp('notified_at')->nullable(); // Когда уведомили
            $table->timestamp('lunch_started_at')->nullable(); // Когда пошел на обед
            $table->timestamp('lunch_finished_at')->nullable(); // Когда вернулся
            $table->timestamps();
            
            $table->foreign('lunch_session_id')->references('id')->on('lunch_sessions')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['lunch_session_id', 'user_id']); // Один человек = одна запись в сессии
        });
    }

    public function down()
    {
        Schema::dropIfExists('lunch_queues');
    }
};