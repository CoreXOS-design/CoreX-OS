<?php

namespace App\Models\Proforma;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only audit for every proforma action (who/when). Immutable — update()
 * and delete() throw. Mirrors DealDocumentAccessLog / CommsAccessAuditLog.
 * Write exclusively through record().
 */
class ProformaInvoiceAudit extends Model
{
    use BelongsToAgency;

    protected $table = 'proforma_invoice_audit';

    public $timestamps = false;

    protected $fillable = [
        'proforma_invoice_id', 'agency_id', 'event', 'actor_id', 'meta', 'created_at',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public const EVENT_GENERATED      = 'generated';
    public const EVENT_LINE_ADDED     = 'line_added';
    public const EVENT_LINE_REMOVED   = 'line_removed';
    public const EVENT_VOIDED         = 'voided';
    public const EVENT_REGENERATED    = 'regenerated';
    public const EVENT_NUMBER_CHANGED = 'number_changed';
    public const EVENT_EMAILED        = 'emailed';

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('proforma_invoice_audit is append-only and cannot be updated.');
        });
        static::deleting(function () {
            throw new \LogicException('proforma_invoice_audit is append-only and cannot be deleted.');
        });
    }

    /** The single write path. agency_id stamped explicitly (AT-203 landmine-safe). */
    public static function record(ProformaInvoice $invoice, string $event, ?int $actorId, array $meta = []): self
    {
        return static::create([
            'proforma_invoice_id' => $invoice->id,
            'agency_id'           => $invoice->agency_id,
            'event'               => $event,
            'actor_id'            => $actorId,
            'meta'                => $meta ?: null,
            'created_at'          => now(),
        ]);
    }
}
