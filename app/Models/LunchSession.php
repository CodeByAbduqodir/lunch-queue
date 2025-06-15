<?php
// app/Models/LunchSession.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LunchSession extends Model
{
    protected $fillable = [
        'date',
        'announcement_time',
        'start_time',
        'max_concurrent_users',
        'group_size',
        'status'
    ];

    protected $casts = [
        'date' => 'date',
        'announcement_time' => 'datetime:H:i',
        'start_time' => 'datetime:H:i',
    ];

    public function lunchQueues(): HasMany
    {
        return $this->hasMany(LunchQueue::class);
    }

    public function getCurrentQueue()
    {
        return $this->lunchQueues()
            ->with('user')
            ->orderBy('position')
            ->get();
    }

    public function getUsersAtLunch()
    {
        return $this->lunchQueues()
            ->where('status', 'at_lunch')
            ->count();
    }
}