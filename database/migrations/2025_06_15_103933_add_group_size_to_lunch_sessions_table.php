<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lunch_sessions', function (Blueprint $table) {
            $table->integer('group_size')->default(3)->after('max_concurrent_users');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lunch_sessions', function (Blueprint $table) {
            $table->dropColumn('group_size');
        });
    }
};
