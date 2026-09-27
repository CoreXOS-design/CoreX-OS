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
    /**
     * Johan, 2026-09-21, from Retha's real paper form: distinct from
     * MISSING — "should be here and isn't" (a deposit argument) — N/A
     * means "was never here" (not an argument at all). These constants are
     * the shipped DEFAULT vocabulary's keys (RentalInspectionSetting::
     * DEFAULT_CONDITION_STATES); the actual valid set for a given agency
     * is agency-configurable and resolved via conditionStatesFor(), never
     * hardcoded to this list at the point of use.
     */
    public const CONDITION_NA = 'n_a';

    /**
     * AT-433, 2026-09-26, Johan (property 5792, a staged photo silently lost
     * on reload) — "adding a photo uploads it. Immediately. Always... no
     * staging, no hidden dependency on a separate deliberate action." A
     * photo can now arrive before anyone has recorded a real condition for
     * its item, and every photo needs an observation row to hang off
     * (RentalInspectionPhoto::rental_inspection_observation_id). This is
     * that row's condition value when it exists ONLY to anchor a photo — an
     * empty string, never one of the agency's own configured condition
     * keys (storeObservation()'s Rule::in() already rejects it from the
     * real recording endpoint, so only the photo-upload path can produce
     * one), and never NULL (the column stays NOT NULL — no migration).
     *
     * NON-NEGOTIABLE INVARIANT: an observation with this condition MUST
     * NEVER be treated as "this item is recorded" anywhere — not in a
     * progress counter, not in "All Good"'s already-done check, not in
     * discrepancy detection, not in a printed form or the in/out
     * comparison. Every one of those reads observations through
     * scopeRecorded() below (or the equivalent client-side `?.condition`
     * truthiness check — empty string is already falsy, so JS needs no new
     * constant to know this). See .ai/specs/rental-inspections.md §20.22
     * for the full audit of every site this touches and why.
     */
    public const CONDITION_PENDING = '';

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

    /** AT-433, 2026-09-26 — true for a photo-anchor row with no real condition on it yet. See CONDITION_PENDING's own docblock. */
    public function isPending(): bool
    {
        return $this->condition === self::CONDITION_PENDING;
    }

    /**
     * AT-433, 2026-09-26 — the ONE filter every "is this item recorded"
     * query in the app must apply. Excludes photo-anchor rows
     * (CONDITION_PENDING) so a photo uploaded before anyone rated the item
     * can never count as that item being assessed — see CONDITION_PENDING's
     * own docblock for the full invariant and .ai/specs/rental-
     * inspections.md §20.22 for the audited call-site list.
     */
    public function scopeRecorded(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('condition', '!=', self::CONDITION_PENDING);
    }

    /**
     * §0.3 — does this condition need a reason on record. Delegates to the
     * agency's own configured vocabulary (RentalInspectionSetting::
     * conditionRequiresNotesFor()) rather than a hardcoded "anything but
     * Good" check — an agency's reduced/renamed condition set (Retha's
     * Good/OK/Bad, 2026-09-21) still expresses this rule correctly.
     */
    public function requiresNotes(): bool
    {
        return RentalInspectionSetting::conditionRequiresNotesFor($this->agency_id, $this->condition);
    }

    /**
     * §14.1 (mobile-foundation audit, fix 2) — the ONE way to record an
     * observation. Before this method existed, creating an observation and
     * detecting a discrepancy were two separate calls that only ever
     * happened together in test helper code — every real caller (the web
     * controller, a future API controller) would have had to remember both
     * steps, in the right order, independently. Wrapped in a transaction so
     * a failure detecting the discrepancy can never leave an observation
     * committed without the conflict it should have raised.
     */
    public static function record(array $attributes): self
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($attributes) {
            $observation = self::create($attributes);
            RentalInspectionDiscrepancy::detectFor($observation);

            return $observation;
        });
    }

    /**
     * §0.1/§11 — a tenant fault report made after the fault-report window
     * closed doesn't block anything on its own; the agent decides whether
     * to accept, reject, or defer it, and that decision is recorded here.
     * This is the one sanctioned exception to this model's immutability:
     * the underlying FACT (condition/notes/photos) is never touched, only
     * the agency's later decision about a report that arrived late — and
     * only once, since a decision already on record is not this method's
     * to revise.
     */
    public function recordWindowDecision(string $decision, User $decidedBy, ?string $note = null): void
    {
        if (! $this->reported_outside_window) {
            throw new \LogicException('recordWindowDecision() only applies to an observation reported outside the fault-report window.');
        }
        if ($this->window_decision !== null) {
            throw new \LogicException('This observation already has a window decision on record.');
        }

        $this->forceFill([
            'window_decision' => $decision,
            'window_decision_note' => $note,
            'window_decision_by_user_id' => $decidedBy->id,
            'window_decision_at' => now(),
        ])->save();
    }
}
