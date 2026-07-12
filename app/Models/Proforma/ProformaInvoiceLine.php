<?php

namespace App\Models\Proforma;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A proforma line. `commission` is the system line locked to the deal's truth
 * (agents/BMs cannot edit). `adjustment` lines are admin-added.
 */
class ProformaInvoiceLine extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'proforma_invoice_id', 'agency_id', 'description',
        'amount_excl', 'vat_amount', 'amount_incl',
        'kind', 'is_locked', 'created_by_id', 'sort_order',
    ];

    protected $casts = [
        'amount_excl' => 'decimal:2',
        'vat_amount'  => 'decimal:2',
        'amount_incl' => 'decimal:2',
        'is_locked'   => 'boolean',
        'sort_order'  => 'integer',
    ];

    public const KIND_COMMISSION = 'commission';
    public const KIND_ADJUSTMENT = 'adjustment';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class, 'proforma_invoice_id');
    }
}
