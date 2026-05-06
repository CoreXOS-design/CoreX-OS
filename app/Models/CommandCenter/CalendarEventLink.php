<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CalendarEventLink extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'calendar_event_id',
        'linkable_type',
        'linkable_id',
        'role',
        'created_by_user_id',
    ];

    public const ROLE_SUBJECT_PROPERTY = 'subject_property';
    public const ROLE_ATTENDEE         = 'attendee';
    public const ROLE_RELATED_DEAL     = 'related_deal';

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
