<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDailyBriefing extends Model
{
    use SoftDeletes;

    protected $table = 'ai_daily_briefings';

    protected $fillable = [
        'user_id',
        'briefing_date',
        'content',
        'data_snapshot',
        'is_read',
        'read_at',
    ];

    protected $casts = [
        'briefing_date' => 'date',
        'data_snapshot' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeForToday($query)
    {
        return $query->where('briefing_date', now()->toDateString());
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
