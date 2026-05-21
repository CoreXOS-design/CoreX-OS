<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * E-Sign V3 (ES-9) — a numbered item inside a non-other-conditions
 * insertable block on a signed document.
 *
 * The 'other_conditions' purpose stores its free-form text on
 * signature_templates.other_conditions_text instead (single contiguous
 * legal text block, see spec §7.5.10). This model carries the row-per-
 * condition variants (included_items, excluded_items, custom_named).
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.10
 */
class DocumentCondition extends Model
{
    use SoftDeletes;

    protected $table = 'document_conditions';

    protected $fillable = [
        'signature_template_id',
        'agency_id',
        'block_id',
        'block_purpose',
        'custom_label',
        'condition_number',
        'content',
        'is_locked',
        'is_override',
        'overrides_clause_ref',
        'added_by_user_id',
        'added_by_party_id',
        'added_via',
        'source',
        'library_clause_id',
        'amendment_id',
        'approved_by_agent_at',
        'approved_by_agent_user_id',
        'superseded_at',
        'superseded_by_condition_id',
    ];

    protected $casts = [
        'is_locked'            => 'boolean',
        'is_override'          => 'boolean',
        'condition_number'     => 'integer',
        'approved_by_agent_at' => 'datetime',
        'superseded_at'        => 'datetime',
    ];

    public function signatureTemplate(): BelongsTo
    {
        return $this->belongsTo(SignatureTemplate::class);
    }

    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    public function libraryClause(): BelongsTo
    {
        return $this->belongsTo(Clause::class, 'library_clause_id');
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(DocumentAmendment::class, 'amendment_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_condition_id');
    }

    public function approvedByAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_agent_user_id');
    }

    public function initials(): MorphMany
    {
        return $this->morphMany(ConditionInitial::class, 'initialable');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('superseded_at');
    }

    public function scopeForBlock($query, string $blockId)
    {
        return $query->where('block_id', $blockId);
    }

    public function scopeForTemplate($query, int $signatureTemplateId)
    {
        return $query->where('signature_template_id', $signatureTemplateId);
    }
}
