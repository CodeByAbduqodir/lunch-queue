<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'telegram_id',
        'first_name', 
        'last_name',
        'username',
        'role',
        'is_active'
    ];

    public function lunchQueues(): HasMany
    {
        return $this->hasMany(LunchQueue::class);
    }

    public function isSupervisor(): bool
    {
        return $this->role === 'supervisor';
    }

    public function wasInQueueToday(): bool
    {
        return $this->lunchQueues()
            ->whereHas('lunchSession', function ($query) {
                $query->where('date', today());
            })
            ->exists();
    }
}