<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\StampsOnBehalfOf;
class CalendarEventAuditEntry extends Model
{
    use BelongsToAgency, StampsOnBehalfOf;

    protected $table = 'calendar_event_audit_log';

    protected $fillable = [
        'agency_id',
        'calendar_event_id', 'action',
        'old_values', 'new_values',
        'performed_by_user_id', 'performed_at',
        'notes',
    ];

    protected $casts = [
        'old_values'   => 'array',
        'new_values'   => 'array',
        'performed_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }
}
