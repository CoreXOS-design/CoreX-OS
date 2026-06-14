<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BM contradiction alert (AT-36, addendum §4.3). Raised when a discard is
 * contradicted by another agent (agent_vs_agent) or, in Phase B, the AI verdict
 * (agent_vs_ai).
 */
class CommunicationFlagAlert extends Model
{
    use SoftDeletes, BelongsToAgency;

    const TYPE_AGENT_VS_AGENT = 'agent_vs_agent';
    const TYPE_AGENT_VS_AI    = 'agent_vs_ai';

    const STATUS_OPEN      = 'open';
    const STATUS_REVIEWED  = 'reviewed';
    const STATUS_DISMISSED = 'dismissed';
    const STATUS_ACTIONED  = 'actioned';

    protected $fillable = [
        'agency_id', 'identifier', 'original_flag_id', 'contradicting_flag_id',
        'alert_type', 'status', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    // ── Relationships ──

    public function originalFlag(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlag::class, 'original_flag_id');
    }

    public function contradictingFlag(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlag::class, 'contradicting_flag_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ── Scopes ──

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
