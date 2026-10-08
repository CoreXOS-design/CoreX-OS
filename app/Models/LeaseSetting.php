<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/leases.md §5.2 / conductor ruling 2026-09-15 — the expiry-notice
 * window is an agency-configurable setting like every other threshold in
 * CoreX, never hardcoded. Johan's standing rule: a threshold ships with a
 * sensible default and its own control from day one — an agency should
 * never have to ask for it. Default 60 days, changeable per agency at any
 * time. His compliance officer's eventual CPA figure only changes what an
 * agency's default STARTS at — it does not change the design; the control
 * already exists.
 *
 * Pattern mirrors RentalApplicationApprovalEmailSetting /
 * RentalApplicationQualifyingSetting: a per-agency row, never written on
 * read, a static lookup that returns the constant default when no row
 * exists yet.
 */
class LeaseSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS = 60;

    // Johan, 2026-09-22 — "the freaking lease type is showing here again...
    // hide it, dont remove it." Agency-configurable, sensible default
    // HIDDEN, drives the Lease Type control on both the lease screen and
    // the property Rental tab. See resources/views/corex/leases/show.blade.php
    // and resources/views/corex/properties/show.blade.php.
    public const DEFAULT_SHOW_LEASE_TYPE_FIELD = false;

    // Johan, 2026-09-22 (property 4283) — "when a property has no deposit
    // amount, default it to a configurable multiple of the monthly rent...
    // one month is the obvious default for South Africa." Confirmed against
    // .ai/specs/rental-property-tab.md's own scraped listing example
    // ("Deposit R4710 One Month's rental R1500 Once off" — deposit = 1x
    // rent), so 1.0 matches what the codebase already assumed informally.
    // Consumed by PropertyController::applyDepositDefault().
    public const DEFAULT_DEPOSIT_MONTHS = 1.0;

    // .ai/specs/rental-renewals.md §2 — SA residential-lease convention, not a
    // legal minimum CoreX enforces; an agency-configurable sensible default.
    public const DEFAULT_TENANT_NOTICE_PERIOD_DAYS = 30;

    // .ai/specs/leases.md §18.2 — the agency's DEFAULT notice / early-cancellation terms, written onto a new lease (editable
    // per lease) and onto old leases by `leases:backfill-notice-terms`. Neutral, configurable, nothing hardcoded elsewhere.
    public const NOTICE_UNITS = ['days', 'weeks', 'months'];
    public const DEFAULT_TENANT_NOTICE_PERIOD_UNIT = 'days';
    /** null = no "not before" rule: notice may be given from the start of the lease. */
    public const DEFAULT_EARLIEST_NOTICE_MONTHS = null;
    /** 'yes' | 'no'. Early cancellation (cancelling a fixed term before it ends, with notice) is allowed unless the agency says otherwise. */
    public const DEFAULT_EARLY_CANCELLATION_ALLOWED = 'yes';
    /** null = the same notice as the ordinary notice period. */
    public const DEFAULT_EARLY_CANCELLATION_NOTICE = null;
    /** null = no penalty wording. */
    public const DEFAULT_EARLY_CANCELLATION_PENALTY = null;

    /** Johan, 7 Oct 2026 — a lease goes month-to-month this many days after its end date (1 = the day after). */
    public const DEFAULT_MONTH_TO_MONTH_AFTER_END_DAYS = 1;

    // .ai/specs/rental-renewals.md §15 (GATE 2, approved 2026-10-04) — each
    // transition is independently toggle-able, default ON.
    public const DEFAULT_AUTO_READVERTISE_ON_NOTICE = true;
    public const DEFAULT_AUTO_RESTORE_STATUS_ON_LEASE_ENDED = true;
    public const DEFAULT_AUTO_RESTORE_STATUS_ON_LEASE_CANCELLED = true;
    // "The agency's existing on-market rental status" (conductor's own
    // wording, GATE 2) — row 2 ALWAYS uses this value; rows 6/7 use it only
    // as the FALLBACK when status_before_letting was never captured. Must
    // genuinely be on-market: 'active' is in Property::systemStatuses()
    // (Property.php:1776) — hardcoded always-allowed for every agency,
    // and NOT in OFF_MARKET_STATUSES — so this is safe cross-agency without
    // depending on any agency having configured a 'to_let'-style item.
    public const DEFAULT_PRE_LET_STATUS = 'active';

    protected $fillable = [
        'agency_id',
        'expiry_notice_window_days',
        'show_lease_type_field',
        'default_deposit_months',
        'tenant_notice_period_days',
        'tenant_notice_period_unit',
        'default_earliest_notice_months',
        'default_early_cancellation_allowed',
        'default_early_cancellation_notice',
        'default_early_cancellation_notice_unit',
        'default_early_cancellation_penalty',
        'month_to_month_after_end_days',
        'auto_readvertise_on_notice',
        'auto_restore_status_on_lease_ended',
        'auto_restore_status_on_lease_cancelled',
        'default_pre_let_status',
        'active_rental_statuses',
    ];

    protected $casts = [
        'expiry_notice_window_days' => 'integer',
        'show_lease_type_field' => 'boolean',
        'default_deposit_months' => 'decimal:2',
        'tenant_notice_period_days' => 'integer',
        'default_earliest_notice_months' => 'integer',
        'default_early_cancellation_notice' => 'integer',
        'month_to_month_after_end_days' => 'integer',
        'auto_readvertise_on_notice' => 'boolean',
        'auto_restore_status_on_lease_ended' => 'boolean',
        'auto_restore_status_on_lease_cancelled' => 'boolean',
        'active_rental_statuses' => 'array',
    ];

    public static function expiryNoticeWindowDaysFor(?int $agencyId): int
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->expiry_notice_window_days ?? self::DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS;
    }

    public static function showLeaseTypeFieldFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_SHOW_LEASE_TYPE_FIELD;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->show_lease_type_field ?? self::DEFAULT_SHOW_LEASE_TYPE_FIELD;
    }

    public static function defaultDepositMonthsFor(?int $agencyId): float
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_DEPOSIT_MONTHS;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->default_deposit_months !== null ? (float) $row->default_deposit_months : self::DEFAULT_DEPOSIT_MONTHS;
    }

    public static function tenantNoticePeriodDaysFor(?int $agencyId): int
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_TENANT_NOTICE_PERIOD_DAYS;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->tenant_notice_period_days ?? self::DEFAULT_TENANT_NOTICE_PERIOD_DAYS;
    }

    /** Days after a lease's end date before it goes month-to-month on its own (leases.md §5.3). */
    public static function monthToMonthAfterEndDaysFor(?int $agencyId): int
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_MONTH_TO_MONTH_AFTER_END_DAYS;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->month_to_month_after_end_days ?? self::DEFAULT_MONTH_TO_MONTH_AFTER_END_DAYS;
    }

    public static function autoReadvertiseOnNoticeFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_AUTO_READVERTISE_ON_NOTICE;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->auto_readvertise_on_notice ?? self::DEFAULT_AUTO_READVERTISE_ON_NOTICE;
    }

    public static function autoRestoreStatusOnLeaseEndedFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_AUTO_RESTORE_STATUS_ON_LEASE_ENDED;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->auto_restore_status_on_lease_ended ?? self::DEFAULT_AUTO_RESTORE_STATUS_ON_LEASE_ENDED;
    }

    public static function autoRestoreStatusOnLeaseCancelledFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_AUTO_RESTORE_STATUS_ON_LEASE_CANCELLED;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->auto_restore_status_on_lease_cancelled ?? self::DEFAULT_AUTO_RESTORE_STATUS_ON_LEASE_CANCELLED;
    }

    public static function defaultPreLetStatusFor(?int $agencyId): string
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_PRE_LET_STATUS;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->default_pre_let_status ?: self::DEFAULT_PRE_LET_STATUS;
    }

    /**
     * Command Centre "Unoccupied" tile (2026-10-05, Johan) — was counting
     * EVERY rental property with no active lease, including withdrawn/
     * expired/draft/prospecting/sold/let-out-elsewhere ones. Restricted to
     * properties whose status this agency considers active rental stock.
     *
     * Default = Property::systemStatuses() minus Property::OFF_MARKET_STATUSES
     * — i.e. 'active', 'for_sale', 'to_let', 'under_offer',
     * 'other_agency_stock' — the SAME on-market definition
     * Property::scopeOnMarket()/isOnMarket() already use everywhere else in
     * CoreX, not a second, rental-specific guess at the same question.
     * ('for_sale'/'under_offer' are harmless to include even though a pure
     * rental listing won't normally carry them.)
     */
    public static function defaultActiveRentalStatuses(): array
    {
        return array_values(array_diff(Property::systemStatuses(), Property::OFF_MARKET_STATUSES));
    }

    public static function activeRentalStatusesFor(?int $agencyId): array
    {
        if ($agencyId && $agencyId > 0) {
            $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
            if ($row?->active_rental_statuses) {
                return $row->active_rental_statuses;
            }
        }

        return self::defaultActiveRentalStatuses();
    }

    // ── leases.md §18.2 — notice / early-cancellation defaults ──────────────────────────────

    private static function noticeRow(?int $agencyId): ?self
    {
        return $agencyId && $agencyId > 0 ? self::withoutGlobalScopes()->where('agency_id', $agencyId)->first() : null;
    }

    public static function tenantNoticePeriodUnitFor(?int $agencyId): string
    {
        $v = self::noticeRow($agencyId)?->tenant_notice_period_unit;

        return in_array($v, self::NOTICE_UNITS, true) ? $v : self::DEFAULT_TENANT_NOTICE_PERIOD_UNIT;
    }

    /** Months into a lease before notice may be given; null = no such rule. */
    public static function earliestNoticeMonthsFor(?int $agencyId): ?int
    {
        $v = self::noticeRow($agencyId)?->default_earliest_notice_months;

        return $v !== null ? (int) $v : self::DEFAULT_EARLIEST_NOTICE_MONTHS;
    }

    public static function earlyCancellationAllowedFor(?int $agencyId): string
    {
        $v = self::noticeRow($agencyId)?->default_early_cancellation_allowed;

        return in_array($v, ['yes', 'no'], true) ? $v : self::DEFAULT_EARLY_CANCELLATION_ALLOWED;
    }

    /** Notice needed to cancel early, length; null = the same as the ordinary notice period. */
    public static function earlyCancellationNoticeFor(?int $agencyId): ?int
    {
        $v = self::noticeRow($agencyId)?->default_early_cancellation_notice;

        return $v !== null ? (int) $v : self::DEFAULT_EARLY_CANCELLATION_NOTICE;
    }

    public static function earlyCancellationNoticeUnitFor(?int $agencyId): ?string
    {
        $v = self::noticeRow($agencyId)?->default_early_cancellation_notice_unit;

        return in_array($v, self::NOTICE_UNITS, true) ? $v : null;
    }

    public static function earlyCancellationPenaltyFor(?int $agencyId): ?string
    {
        $v = self::noticeRow($agencyId)?->default_early_cancellation_penalty;

        return is_string($v) && trim($v) !== '' ? trim($v) : self::DEFAULT_EARLY_CANCELLATION_PENALTY;
    }
}
