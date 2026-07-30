<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-350 — one LOSS EVENT: a property we held that another agency sold.
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §4.2
 *
 * Not to be confused with PropertySoldRecord (property_sold_records), which is
 * the market fact ("a sale happened here", feeds CMA comps). This is the agency
 * fact: which competitor beat us, at what price against our asking, after how
 * many days, on what mandate — and why we lost.
 *
 * A property can accumulate several of these over its life (lost → re-listed →
 * lost again). `reverted_at IS NULL` identifies the OPEN one, i.e. the loss that
 * matches the property's current status.
 */
class PropertyThirdPartySale extends Model
{
    use BelongsToAgency, SoftDeletes;

    /**
     * Why we lost it.
     *
     * DELIBERATELY a code constant set, not a settings table — a declared
     * deviation from SYSTEM.md §3 (spec D5), mirroring the established sibling
     * PresentationOutcome::ALL_CANCELLATION_REASONS. Loss analytics are only
     * meaningful when the keys are stable across agencies and across time: a
     * per-agency free-text list makes "why do we lose listings?" unanswerable at
     * group level, and breaks historical comparison the moment an agency renames
     * an option.
     *
     * 'buyer_lost_to_competitor' is its own key on purpose — we had the buyer and
     * the other agency wrote the OTP. It is the most expensive loss an agency
     * makes, and rolling it into a generic "competitor" bucket would hide it.
     */
    public const LOSS_REASONS = [
        'competitor_had_buyer'     => 'Competitor already had the buyer',
        'priced_lower'             => 'Competitor priced it lower',
        'open_mandate_race'        => 'Open mandate — competitor got there first',
        'seller_relationship'      => 'Seller relationship with the other agent',
        'our_marketing'            => 'Our marketing / exposure fell short',
        'our_responsiveness'       => 'We were too slow to respond',
        'buyer_lost_to_competitor' => 'Our buyer bought it through the other agency',
        'unknown'                  => 'Unknown',
        'other'                    => 'Other',
    ];

    protected $fillable = [
        'property_id',
        'agency_id',
        'branch_id',
        'sold_by_agency',
        'sold_price',
        'sold_date',
        'our_listing_price',
        'our_mandate_type',
        'days_on_market',
        'loss_reason',
        'notes',
        'sold_record_id',
        'recorded_by_user_id',
        'recorded_at',
        'reverted_at',
    ];

    protected $casts = [
        'sold_price'        => 'decimal:2',
        'our_listing_price' => 'decimal:2',
        'sold_date'         => 'date',
        'days_on_market'    => 'integer',
        'recorded_at'       => 'datetime',
        'reverted_at'       => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────────────

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    /** The loss that matches the property's CURRENT status (not yet re-listed). */
    public function scopeOpen($query)
    {
        return $query->whereNull('reverted_at');
    }

    /** Losses the property has since recovered from (re-listed after the loss). */
    public function scopeReverted($query)
    {
        return $query->whereNotNull('reverted_at');
    }

    // ── Presentation ────────────────────────────────────────────────────────

    /**
     * Human label for the loss reason. Falls back to a de-underscored title-case
     * of the raw value so a key retired from LOSS_REASONS in a future build can
     * never render a client-facing screen as a raw DB string (BUILD_STANDARD §4,
     * STANDARDS F.8 — no developer jargon reaches a visible label).
     */
    public function lossReasonLabel(): ?string
    {
        if (empty($this->loss_reason)) {
            return null;
        }

        return self::LOSS_REASONS[$this->loss_reason]
            ?? ucfirst(str_replace('_', ' ', (string) $this->loss_reason));
    }

    /**
     * How far our asking price sat above (positive) or below (negative) what the
     * competitor actually achieved. NULL when either side is unknown — the whole
     * capture is optional by design, so "we don't know" must stay expressible.
     */
    public function priceGap(): ?float
    {
        if ($this->sold_price === null || $this->our_listing_price === null) {
            return null;
        }

        return (float) $this->our_listing_price - (float) $this->sold_price;
    }

    /**
     * True when this record carries enough to be a CMA comp. The same test the
     * service uses to decide whether to write a property_sold_records row, kept
     * here so the two can never drift (spec §4.4).
     */
    public function isComparable(): bool
    {
        return $this->sold_price !== null && $this->sold_date !== null;
    }
}
