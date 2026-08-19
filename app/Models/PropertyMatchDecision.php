<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CX-102 part 2 (2026-08-19, Johan) — "the system must show its working and
 * let the agent overrule it." One row per "CoreX decided these two records
 * are the same property" — why, and (if rejected) by whom and when.
 *
 * subject_type / subject_key is the CALLER's own stable identity for the
 * incoming side of the match — this table does not assume deeds-capture or
 * MIC. Known shapes today:
 *   - 'deeds_capture'  subject_key = "{source_type}:{source_ref}" from
 *                      TrackedPropertyMatchOrCreateService's own $source
 *                      array, e.g. "deeds_capture:cmainfo:n0et012...".
 *   - 'mic_claim'      subject_key = "listing:{prospecting_listing_id}".
 *
 * matched_type / matched_id is polymorphic by plain columns (not Eloquent
 * morphs) since the two kinds in play — tracked_properties, properties —
 * are both simple integer-keyed models; a future caller can introduce a
 * third kind without a migration.
 *
 * See App\Services\Prospecting\PropertyMatchDecisionService — the only
 * place that should read/write this model. Never written directly.
 */
class PropertyMatchDecision extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'subject_type',
        'subject_key',
        'matched_type',
        'matched_id',
        'strategy',
        'reason',
        'candidates',
        'decided_at',
        'rejected_at',
        'rejected_by_user_id',
        'rejected_reason',
        'resolved_matched_type',
        'resolved_matched_id',
    ];

    protected $casts = [
        'candidates'  => 'array',
        'decided_at'  => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    public function rejectedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }
}
