<?php

declare(strict_types=1);

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\StampsOnBehalfOf;
/**
 * ES-1 — Insert-only forensic record of every legal-block trigger.
 *
 * Rows are immutable after creation: save() throws if $this->exists. This
 * preserves the legal-defensibility of the trail (auditors can rely on the
 * row never having been edited after the fact).
 *
 * Spec: .ai/specs/esign-v3-complete-spec.md §5.5
 */
class LegalBlockAuditLog extends Model
{
    use BelongsToAgency, StampsOnBehalfOf;

    protected $table = 'legal_block_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'template_id',
        'template_name',
        'document_type_slug',
        'user_id',
        'block_reason',
        'matched_pattern',
        'request_context',
        'created_at',
    ];

    protected $casts = [
        'request_context' => 'array',
        'created_at'      => 'datetime',
    ];

    /**
     * Block any mutation on existing rows. New inserts pass through normally.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new DomainException(
                'LegalBlockAuditLog is insert-only. Existing rows cannot be modified.'
            );
        }

        return parent::save($options);
    }
}
