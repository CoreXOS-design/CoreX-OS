<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * E-Sign V3 (ES-3/ES-9) — append-only per-party initials on amended
 * regions captured during the initialing cascade.
 *
 * Mirrors the LegalBlockAuditLog immutability pattern from ES-1: save()
 * throws DomainException if $this->exists. Initials are legal record;
 * they cannot be edited after creation.
 *
 * Polymorphic owner — either DocumentCondition or
 * DocumentClauseStrikethrough.
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §7.5.7, §7.5.10
 */
class ConditionInitial extends Model
{
    protected $table = 'condition_initials';

    public $timestamps = false;

    protected $fillable = [
        'initialable_type',
        'initialable_id',
        'party_key',
        'signature_request_id',
        'amendment_id',
        'initialed_at',
        'initial_image_path',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'initialed_at' => 'datetime',
    ];

    public function initialable(): MorphTo
    {
        return $this->morphTo();
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(DocumentAmendment::class, 'amendment_id');
    }

    /**
     * Block mutation on existing rows — initials are immutable legal record.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new DomainException(
                'ConditionInitial is insert-only. Existing initials cannot be modified.'
            );
        }

        return parent::save($options);
    }
}
