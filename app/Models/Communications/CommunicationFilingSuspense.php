<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-231 P2 — one parked inbound attorney email awaiting the agent's first-verify
 * (or manual link). The review queue is built from these; it is surfaced in both
 * the Deals and the Comms homes. See .ai/specs/at231-inbound-attorney-comms-filing.md §3.7.
 */
class CommunicationFilingSuspense extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'communication_filing_suspense';

    const CONF_HIGH   = 'high';
    const CONF_MEDIUM = 'medium';
    const CONF_LOW    = 'low';

    const STATUS_PENDING   = 'pending';
    const STATUS_VERIFIED  = 'verified';
    const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'agency_id', 'communication_id', 'channel', 'suggested_deal_id', 'confidence',
        'status', 'resolved_deal_id', 'resolved_by_user_id', 'resolved_at',
        'matched_signal_type', 'matched_signal_value',
        'attorney_provider_id', 'attorney_provider_contact_id', 'note',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }

    public function suggestedDeal(): BelongsTo
    {
        return $this->belongsTo(Deal::class, 'suggested_deal_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
