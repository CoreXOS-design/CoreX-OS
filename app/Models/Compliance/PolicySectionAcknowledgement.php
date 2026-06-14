<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicySectionAcknowledgement (AT-29) — the per-section tick within a
 * sign-off. Mirrors RmcpSectionAcknowledgement. No SoftDeletes (leaf child).
 */
class PolicySectionAcknowledgement extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'policy_acknowledgement_id',
        'policy_section_id',
        'acknowledged',
        'acknowledged_at',
        'acknowledgement_response',
        'ip_address',
    ];

    protected $casts = [
        'acknowledged'    => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function acknowledgement(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgement::class, 'policy_acknowledgement_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PolicySection::class, 'policy_section_id');
    }
}
