<?php

namespace App\Models\CommandCenter;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\InheritsBranchFromParent;

class CalendarEventFeedback extends Model
{
    use BelongsToBranch, InheritsBranchFromParent, SoftDeletes, BelongsToAgency;

    /** A child's branch is its parent's branch, never the acting user's. */
    protected function branchParent(): array
    {
        return [\App\Models\CommandCenter\CalendarEvent::class, 'calendar_event_id'];
    }

    protected $table = 'calendar_event_feedback';

    protected $fillable = [
        'calendar_event_id', 'contact_id', 'property_id',
        'feedback_kind', 'visibility',
        'outcome_option_id', 'concern_option_ids',
        'seller_visible_notes', 'internal_notes', 'next_action_notes',
        'kind_specific_data',
        'captured_by_user_id', 'captured_at',
        'agency_id', 'branch_id',
    ];

    protected $casts = [
        'concern_option_ids'  => 'array',
        'kind_specific_data'  => 'array',
        'captured_at'         => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(AgencyFeedbackOption::class, 'outcome_option_id');
    }

    public function capturer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by_user_id');
    }
}
