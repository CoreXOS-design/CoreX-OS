<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarUserPreference extends Model
{
    protected $fillable = [
        'user_id', 'default_view', 'working_hours_start', 'working_hours_end',
        'weekend_visible', 'ical_token', 'email_reminders', 'app_reminders', 'digest_email',
        // AT-164 — per-user calendar layout memory (Deck slots + layer toggles).
        'calendar_deck_layout', 'calendar_layers',
    ];

    protected $casts = [
        'weekend_visible'  => 'boolean',
        'email_reminders'  => 'boolean',
        'app_reminders'    => 'boolean',
        'calendar_deck_layout' => 'array',
        'calendar_layers'      => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
