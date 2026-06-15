<?php

namespace App\Models\Communications;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Per-agent, per-identifier triage decision (AT-36, addendum §4.2). Stores the
 * decision + accountability only — never message body/subject.
 */
class CommunicationFlag extends Model
{
    use SoftDeletes, BelongsToAgency;

    const FLAG_NOT_REAL_ESTATE = 'not_real_estate';
    const FLAG_REAL_ESTATE     = 'real_estate';

    const REVIEW_OPEN     = 'open';
    const REVIEW_REVIEWED = 'reviewed';
    const REVIEW_ACTIONED = 'actioned';

    protected $fillable = [
        'agency_id', 'identifier', 'identifier_name', 'user_id', 'flag',
        'ai_is_real_estate', 'ai_confidence', 'message_external_id', 'flagged_at',
        'contradicted_at', 'contradicted_by_user_id', 'review_status',
    ];

    protected $casts = [
        'ai_is_real_estate' => 'boolean',
        'ai_confidence'     => 'decimal:3',
        'flagged_at'        => 'datetime',
        'contradicted_at'   => 'datetime',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contradictedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contradicted_by_user_id');
    }

    // ── Scopes ──

    public function scopeForIdentifier($query, string $identifier)
    {
        return $query->where('identifier', $identifier);
    }

    public function scopeNotRealEstate($query)
    {
        return $query->where('flag', self::FLAG_NOT_REAL_ESTATE);
    }

    public function scopeRealEstate($query)
    {
        return $query->where('flag', self::FLAG_REAL_ESTATE);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
