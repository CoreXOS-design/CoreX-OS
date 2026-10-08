<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md §17.31 — a supplier's invoice (the document, its number, date and amount) filed
 * against a work order. Evidence for the cost / who-pays record, never a payment: the amount feeds the Complete form's
 * cost field as a suggestion and is shown beside the recorded cost; nothing here writes `cost_amount`. Soft delete only
 * (archive/restore); a replaced file stays on disk, listed in `superseded_documents`.
 *
 * Visibility: office staff through the work order's own scope; the property's OWNER on the portal only while
 * `share_with_owner` is true; the tenant never.
 */
class RentalWorkOrderInvoice extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'agency_service_provider_id',
        'supplier_name',
        'invoice_number',
        'invoice_date',
        'amount',
        'document_storage_path',
        'document_original_name',
        'document_mime',
        'document_size',
        'superseded_documents',
        'share_with_owner',
        'shared_at',
        'uploaded_by_user_id',
        'archived_by_user_id',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'amount' => 'decimal:2',
        'document_size' => 'integer',
        'superseded_documents' => 'array',
        'share_with_owner' => 'boolean',
        'shared_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4): an archived supplier still names its old invoices. */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(\App\Models\DealV2\AgencyServiceProvider::class, 'agency_service_provider_id')->withTrashed();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** The supplier's name as the screen should print it: the directory entry, else the typed name. */
    public function supplierLabel(): ?string
    {
        return $this->supplier?->name ?: ($this->supplier_name ?: null);
    }

    /** One line for the history: "Invoice INV-114 · R1,500.00 · 2026-10-02". */
    public function describe(): string
    {
        return 'Invoice ' . $this->invoice_number . ' · R' . number_format((float) $this->amount, 2) . ' · ' . $this->invoice_date?->format('Y-m-d');
    }
}
