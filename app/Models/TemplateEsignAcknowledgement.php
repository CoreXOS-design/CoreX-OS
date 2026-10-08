<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\StampsOnBehalfOf;
use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Insert-only audit trail of who switched e-signing on (or off) for a template whose document
 * type carries the legal warning, and exactly which wording they acknowledged.
 *
 * Same immutability pattern as LegalBlockAuditLog: save() throws on an existing row.
 * Spec: .ai/specs/ESIGN-CANON.md §7.
 */
class TemplateEsignAcknowledgement extends Model
{
    use BelongsToAgency, StampsOnBehalfOf;

    public const ACTION_ENABLED = 'enabled';
    public const ACTION_DISABLED = 'disabled';

    protected $table = 'template_esign_acknowledgements';

    public $timestamps = false;

    protected $fillable = [
        'agency_id',
        'template_id',
        'template_name',
        'document_type_slug',
        'action',
        'user_id',
        'user_name',
        'wording_version',
        'wording_snapshot',
        'request_context',
        'created_at',
    ];

    protected $casts = [
        'request_context' => 'array',
        'created_at'      => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new DomainException(
                'TemplateEsignAcknowledgement is insert-only. Existing rows cannot be modified.'
            );
        }

        return parent::save($options);
    }
}
