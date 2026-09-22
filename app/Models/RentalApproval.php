<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §3.4a, settled 2026-09-25 — a single,
 * shared, append-only evidence log for an owner's approval decision.
 * Immutable: no updated_at, no deleted_at. A wrong entry is corrected by a
 * NEW row, never edited or removed, same evidence-integrity reasoning as
 * every other log/photo table in this feature family.
 *
 */
class RentalApproval extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const DECISION_APPROVED = 'approved';
    public const DECISION_DECLINED = 'declined';

    public const EVIDENCE_WHATSAPP = 'whatsapp';
    public const EVIDENCE_EMAIL = 'email';
    public const EVIDENCE_VERBAL_NOTE = 'verbal_note';

    protected $fillable = [
        'agency_id',
        'rental_fault_report_id',
        'rental_work_order_id',
        'decision',
        'approval_route',
        'evidence_type',
        'evidence_text',
        'evidence_file_path',
        'decided_at',
        'recorded_by_user_id',
        'created_at',
        // 2026-09-22, Johan — an unanchored "approved" says nothing about
        // what it was for. quote_id_at_decision is identity-only (never
        // joined for display — RentalWorkOrder::selectQuote()'s supersession
        // check is the only reader). quote_amount_at_decision and
        // quote_supplier_name_at_decision are the actual record: a SNAPSHOT
        // taken once, here, never re-derived from the live quote/supplier
        // row, so a later edit or archive of either can never rewrite what
        // was actually decided. All three nullable — an approval recorded
        // with no quote selected (or any row from before this column
        // existed) has none of them, and the screen/PDF must say nothing
        // rather than imply a snapshot that was never taken.
        'quote_id_at_decision',
        'quote_amount_at_decision',
        'quote_supplier_name_at_decision',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'created_at' => 'datetime',
        'quote_amount_at_decision' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $approval) {
            if (empty($approval->created_at)) {
                $approval->created_at = now();
            }
        });
    }

    public function faultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id');
    }

    /** Stage 4 — App\Models\RentalWorkOrder now exists. */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    public function recordedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
