<?php

namespace App\Models\Proforma;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Deal;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A proforma invoice — the structured financial RECORD (the future ledger consumes
 * these; the PDF is only a rendering). Parties + figures are snapshotted at issue.
 * Split deals carry THIS agency's share only. Voided records are kept (no hard
 * delete); their number is never reused.
 */
class ProformaInvoice extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id', 'deal_id', 'number', 'sequence_no', 'status',
        'issued_to_contact_id', 'issued_to_name', 'care_of_provider_id', 'care_of_name',
        'reference', 'due_date', 'vat_registered', 'vat_rate',
        'subtotal_excl', 'vat_amount', 'total_incl',
        'document_id', 'communication_id',
        'created_by_id', 'voided_by_id', 'voided_at', 'void_reason',
    ];

    protected $casts = [
        'due_date'       => 'date',
        'voided_at'      => 'datetime',
        'vat_registered' => 'boolean',
        'vat_rate'       => 'decimal:2',
        'subtotal_excl'  => 'decimal:2',
        'vat_amount'     => 'decimal:2',
        'total_incl'     => 'decimal:2',
    ];

    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOIDED = 'voided';

    public function lines(): HasMany
    {
        return $this->hasMany(ProformaInvoiceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /** Recompute the header totals from the current (non-trashed) lines. */
    public function recalcTotals(): void
    {
        $lines = $this->lines()->get();
        $this->subtotal_excl = round((float) $lines->sum('amount_excl'), 2);
        $this->vat_amount    = round((float) $lines->sum('vat_amount'), 2);
        $this->total_incl    = round((float) $lines->sum('amount_incl'), 2);
    }
}
