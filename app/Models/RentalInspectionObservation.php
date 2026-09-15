<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * .ai/specs/rental-inspections.md §3.2 — a single, immutable fact. Never
 * edited, never deleted, not even soft-deleted (§3.3) — this is FICA/legal
 * evidence and no "delete" action exists anywhere for this record.
 * `UPDATED_AT = null` and there is genuinely no update path in the app —
 * an incorrect observation is corrected by recording a NEW one, never by
 * changing this one.
 */
class RentalInspectionObservation extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const CONDITION_GOOD = 'good';
    public const CONDITION_FAIR = 'fair';
    public const CONDITION_DAMAGED = 'damaged';
    public const CONDITION_NOT_WORKING = 'not_working';
    public const CONDITION_MISSING = 'missing';
    public const CONDITION_OTHER = 'other';

    public const SOURCE_IN_INSPECTION = 'in_inspection';
    public const SOURCE_TENANT_FAULT_REPORT = 'tenant_fault_report';
    public const SOURCE_OUT_INSPECTION = 'out_inspection';
    public const SOURCE_AD_HOC = 'ad_hoc';

    public const WINDOW_DECISION_ACCEPTED = 'accepted';
    public const WINDOW_DECISION_REJECTED = 'rejected';
    public const WINDOW_DECISION_DEFERRED = 'deferred';

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'rental_inspection_item_id',
        'observed_by_user_id',
        'observed_by_contact_id',
        'condition',
        'notes',
        'source',
        'reported_outside_window',
        'window_decision',
        'window_decision_note',
        'window_decision_by_user_id',
        'window_decision_at',
        'client_idempotency_key',
        'created_at',
    ];

    protected $casts = [
        'reported_outside_window' => 'boolean',
        'window_decision_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $observation) {
            if (empty($observation->client_idempotency_key)) {
                $observation->client_idempotency_key = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($observation->created_at)) {
                $observation->created_at = now();
            }
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function observedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'observed_by_user_id');
    }

    public function observedByContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'observed_by_contact_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(RentalInspectionPhoto::class);
    }

    /** §0.3 — a bad rating needs a reason on record. */
    public function requiresNotes(): bool
    {
        return $this->condition !== self::CONDITION_GOOD;
    }
}
