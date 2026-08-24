<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * What one agency has agreed to pay CoreX.
 *
 * Spec: .ai/specs/agency-billing.md §5.3  (AT-11)
 *
 * Holds ONLY the commercial decision — the plan we last reconciled them onto,
 * an optional custom amount, an optional discount. The seat count and the
 * price are NOT stored: they are computed live by SubscriptionPricingService,
 * so they cannot go stale no matter what changed a user.
 *
 * INVARIANT (spec §3 D5): custom_amount_zar and discount_percent are never
 * both set. See hasCustomAmount()/discountIsActive() — custom always wins on
 * read, so even a row hand-edited in SQL to hold both yields a correct number
 * rather than a crash.
 */
class AgencySubscription extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const PLAN_TEAM   = 'team';
    public const PLAN_AGENCY = 'agency';

    protected $fillable = [
        'agency_id',
        'plan',
        'plan_changed_at',
        'custom_amount_zar',
        'custom_amount_note',
        'discount_percent',
        'discount_months',
        'discount_starts_on',
        'discount_note',
        'notes',
    ];

    protected $casts = [
        'plan_changed_at'    => 'datetime',
        'custom_amount_zar'  => 'decimal:2',
        'discount_percent'   => 'decimal:2',
        'discount_months'    => 'integer',
        'discount_starts_on' => 'date',
    ];

    /**
     * The agency's subscription, created with defaults on first read.
     *
     * Guarded `<= 0` per STANDARDS Rule 17: owner / console / job / webhook
     * contexts have NO agency (effectiveAgencyId() is null). Those callers get
     * an UNSAVED in-memory default rather than an FK-1452 on a bogus insert.
     *
     * withoutGlobalScopes() because this is called from owner-context pages
     * that legitimately read across tenants; the CALLER is responsible for
     * having established which agency it is asking about.
     */
    public static function forAgency(int $agencyId): self
    {
        if ($agencyId <= 0) {
            return (new self())->forceFill([
                'agency_id' => null,
                'plan'      => self::PLAN_TEAM,
            ]);
        }

        return static::withoutGlobalScopes()->firstOrCreate(
            ['agency_id' => $agencyId],
            ['plan' => self::PLAN_TEAM, 'plan_changed_at' => now()],
        );
    }

    /** A custom amount is set — it is the price, and it beats any discount (D5). */
    public function hasCustomAmount(): bool
    {
        return $this->custom_amount_zar !== null;
    }

    /**
     * The last day the discount applies: start + N months, minus a day.
     * DERIVED, never stored — so the discount expires by arithmetic and no
     * scheduled job can forget to end it.
     */
    public function discountEndsOn(): ?Carbon
    {
        if ($this->discount_percent === null
            || $this->discount_months === null
            || $this->discount_starts_on === null) {
            return null;
        }

        return $this->discount_starts_on->copy()
            ->addMonthsNoOverflow((int) $this->discount_months)
            ->subDay()
            ->endOfDay();
    }

    /**
     * Is the discount live today?
     *
     * False when a custom amount is set — the invariant says they never
     * coexist, and this is the read-side ABSORB that makes a row holding both
     * (hand-edited in SQL, say) still produce a correct price instead of
     * double-discounting.
     */
    public function discountIsActive(?Carbon $on = null): bool
    {
        if ($this->hasCustomAmount()) {
            return false;
        }

        $endsOn = $this->discountEndsOn();
        if ($endsOn === null) {
            return false;
        }

        $on = $on ?? Carbon::now();

        // A start date in the future means the discount is agreed but not live yet.
        return $on->betweenIncluded($this->discount_starts_on->copy()->startOfDay(), $endsOn);
    }

    /**
     * Whole months of discount still to run, counting the current one.
     * 0 once expired. Never negative — this is what the agency page shows as
     * "4 months remaining".
     */
    public function discountMonthsRemaining(?Carbon $on = null): int
    {
        if (! $this->discountIsActive($on)) {
            return 0;
        }

        $on = $on ?? Carbon::now();

        // diffInMonths floors; the partial month the agency is currently in
        // still counts as one remaining, hence +1.
        return max(0, (int) $on->diffInMonths($this->discountEndsOn()) + 1);
    }
}
