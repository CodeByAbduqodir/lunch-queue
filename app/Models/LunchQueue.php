<?php

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lunchSession(): BelongsTo
    {
        return $this->belongsTo(LunchSession::class);
    }

    public function getRemainingLunchTime(): int
    {
        if (!$this->lunch_started_at) {
            return 0;
        }
        
        $endTime = $this->lunch_started_at->addMinutes(30);
        $now = now();
        
        return $now->diffInMinutes($endTime, false);
    }
}