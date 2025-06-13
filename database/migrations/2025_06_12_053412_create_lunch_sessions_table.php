<?php

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
            $table->date('date'); 
            $table->time('announcement_time'); 
            $table->time('start_time'); 
            $table->integer('max_concurrent_users')->default(3); 
            $table->enum('status', ['collecting', 'active', 'finished'])->default('collecting');
            $table->timestamps();
            
            $table->unique(['date', 'announcement_time']); 
        });
    }

    public function down()
    {
        Schema::dropIfExists('lunch_sessions');
    }
};