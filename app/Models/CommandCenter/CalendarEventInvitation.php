<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventInvitation extends Model
{
    protected $fillable = [
        'event_id', 'invitee_user_id', 'inviter_user_id', 'status',
        'response_at', 'response_notes', 'conflict_at_invite', 'notified_at',
    ];

    protected $casts = [
        'response_at' => 'datetime',
        'notified_at' => 'datetime',
        'conflict_at_invite' => 'array',
    ];

    public function event(): BelongsTo { return $this->belongsTo(CalendarEvent::class, 'event_id'); }
    public function invitee(): BelongsTo { return $this->belongsTo(User::class, 'invitee_user_id'); }
    public function inviter(): BelongsTo { return $this->belongsTo(User::class, 'inviter_user_id'); }

    public function scopePending($q) { return $q->where('status', 'pending'); }
    public function scopeAccepted($q) { return $q->where('status', 'accepted'); }
    public function scopeForUser($q, int $userId) { return $q->where('invitee_user_id', $userId); }
}
