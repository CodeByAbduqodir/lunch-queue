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

    // Получить текущую очередь (отсортированную по позиции)
    public function getCurrentQueue()
    {
        return $this->lunchQueues()
            ->with('user')
            ->orderBy('position')
            ->get();
    }

    // Сколько человек сейчас на обеде?
    public function getUsersAtLunch()
    {
        return $this->lunchQueues()
            ->where('status', 'at_lunch')
            ->count();
    }
}

// app/Models/LunchQueue.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LunchQueue extends Model
{
    protected $fillable = [
        'lunch_session_id',
        'user_id',
        'position',
        'status',
        'notified_at',
        'lunch_started_at',
        'lunch_finished_at'
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'lunch_started_at' => 'datetime',
        'lunch_finished_at' => 'datetime',
    ];

    // Связь: запись принадлежит пользователю
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Связь: запись принадлежит сессии
    public function lunchSession(): BelongsTo
    {
        return $this->belongsTo(LunchSession::class);
    }

    // Время оставшееся до конца обеда (в минутах)
    public function getRemainingLunchTime(): int
    {
        if (!$this->lunch_started_at) {
            return 0;
        }
        
        $endTime = $this->lunch_started_at->addMinutes(30); // 30 минут на обед
        $now = now();
        
        return $now->diffInMinutes($endTime, false); // false = может быть отрицательным
    }
}