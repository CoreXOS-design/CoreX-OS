<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * E-Sign V3 (ES-9) — audit of a strikethrough override.
 *
 * When a signing party clicks a printed clause and proposes an override,
 * a row in this table records the original text + the proposed replacement
 * + agent review state. The replacement content auto-routes to the
 * Other Conditions block (signature_templates.other_conditions_text plus
 * a new DocumentCondition row tagged with is_override = true).
 *
 * Status updates allowed via the agent review surface; the row itself is
 * not soft-deletable — strikethrough proposals are permanent legal record.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.5, §7.5.10
 */
class DocumentClauseStrikethrough extends Model
{
    protected $table = 'document_clause_strikethroughs';

    protected $fillable = [
        'signature_template_id',
        'agency_id',
        'clause_ref',
        'clause_original_text',
        'replacement_condition_id',
        'proposed_by_user_id',
        'proposed_by_party_id',
        'amendment_id',
        'status',
        'approved_by_agent_at',
        'rejected_by_agent_at',
        'rejection_reason',
    ];

    protected $casts = [
        'approved_by_agent_at' => 'datetime',
        'rejected_by_agent_at' => 'datetime',
    ];

    public const STATUS_PROPOSED   = 'proposed';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_REJECTED   = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';

    public function signatureTemplate(): BelongsTo
    {
        return $this->belongsTo(SignatureTemplate::class);
    }

    public function proposedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by_user_id');
    }

    public function replacementCondition(): BelongsTo
    {
        return $this->belongsTo(DocumentCondition::class, 'replacement_condition_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(DocumentAmendment::class, 'amendment_id');
    }

    public function initials(): MorphMany
    {
        return $this->morphMany(ConditionInitial::class, 'initialable');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PROPOSED);
    }
}
