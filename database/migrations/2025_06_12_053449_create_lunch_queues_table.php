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
            $table->unsignedBigInteger('lunch_session_id'); 
            $table->unsignedBigInteger('user_id'); 
            $table->integer('position'); 
            $table->enum('status', ['waiting', 'notified', 'at_lunch', 'finished'])->default('waiting');
            $table->timestamp('notified_at')->nullable(); 
            $table->timestamp('lunch_started_at')->nullable(); 
            $table->timestamp('lunch_finished_at')->nullable(); 
            $table->timestamps();
            
            $table->foreign('lunch_session_id')->references('id')->on('lunch_sessions')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->unique(['lunch_session_id', 'user_id']); 
        });
    }

    public function down()
    {
        Schema::dropIfExists('lunch_queues');
    }
};