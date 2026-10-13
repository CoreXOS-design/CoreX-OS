<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.7 - "Query this": a question the owner put to their agent about what was authorised or arranged on
 * their behalf, and the agent's answer. Append-only; asking stops nothing.
 */
class RentalOwnerQuery extends Model
{
    use BelongsToAgency;

    public const VIA_LINK = 'link';
    public const VIA_PORTAL = 'portal';
    public const VIA_EARLIER = 'earlier';

    protected $fillable = [
        'agency_id', 'rental_fault_report_id', 'rental_work_order_id', 'asked_by_contact_id', 'asked_via', 'question', 'asked_at',
        'answered_by_user_id', 'answer', 'answered_at', 'answer_sent_at', 'legacy_update_id',
    ];

    protected $casts = ['asked_at' => 'datetime', 'answered_at' => 'datetime', 'answer_sent_at' => 'datetime'];

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'asked_by_contact_id')->withoutGlobalScopes();
    }

    public function answeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id')->withoutGlobalScopes();
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
