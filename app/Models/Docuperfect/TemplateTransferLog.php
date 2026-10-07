<?php

declare(strict_types=1);

namespace App\Models\Docuperfect;

use DomainException;
use Illuminate\Database\Eloquent\Model;

/**
 * Insert-only audit row for every e-sign template package export / import.
 * Spec: .ai/specs/esign-template-transfer.md §7.
 *
 * Platform-level (owner) record — deliberately not BelongsToAgency: it spans
 * agencies by design and is only ever read through the owner-only screen.
 */
class TemplateTransferLog extends Model
{
    protected $table = 'template_transfer_log';

    public $timestamps = false;

    protected $fillable = [
        'direction', 'outcome', 'actor_user_id', 'actor_name',
        'source_agency_id', 'target_agency_id', 'target_agency_name',
        'template_name', 'template_id', 'package_checksum', 'format_version',
        'source_label', 'name_clash_choice', 'warnings', 'failure_reason', 'created_at',
    ];

    protected $casts = [
        'warnings'   => 'array',
        'created_at' => 'datetime',
    ];

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new DomainException('template_transfer_log rows are insert-only.');
        }
        if ($this->created_at === null) {
            $this->created_at = now();
        }

        return parent::save($options);
    }
}
