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
        // AT-164 cockpit v2 — adjustable arrangement (split height, tile ratios, collapse).
        'calendar_cockpit',
    ];

    protected $casts = [
        'weekend_visible'  => 'boolean',
        'email_reminders'  => 'boolean',
        'app_reminders'    => 'boolean',
        'calendar_deck_layout' => 'array',
        'calendar_layers'      => 'array',
        'calendar_cockpit'     => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
