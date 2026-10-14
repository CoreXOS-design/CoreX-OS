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

    // .ai/specs/lease-notices.md §7 - business days' written notice the Consumer Protection Act gives a tenant to cancel a fixed-term
    // lease early. An agency setting (the review flags read it); this is only the starting value.
    public const DEFAULT_CPA_EARLY_CANCEL_BUSINESS_DAYS = 20;

    // .ai/specs/rental-notices.md §5 - business days a breach notice gives to put the breach right before a notice to vacate is allowed
    // (the Consumer Protection Act's 20 business days). An agency setting; this is only the starting value.
    public const DEFAULT_BREACH_REMEDY_BUSINESS_DAYS = 20;

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

    // Rentals front-half decisions (8 Oct 2026) - per-agency settings, recommended value as the default.
    /** "Prepare for signing" needs an end date or an explicit month-to-month; "lease only" stays free. */
    public const DEFAULT_REQUIRE_END_OR_MONTH_TO_MONTH_FOR_SIGNING = true;
    /** Reversing month-to-month puts the lease's original end date back (when it is on record). */
    public const DEFAULT_RESTORE_END_DATE_ON_LEAVING_MONTH_TO_MONTH = true;
    /** One sentence added to the signed-copy mail when the lease cannot go live yet. Neutral, editable. */
    public const DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE = 'Your signed lease is attached. It is not active on our system yet - your agent will confirm when it is.';
    public const SIGNED_COPY_NOT_LIVE_NOTE_MAX = 500;

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

    /** rental-renewals.md §22.1 - testing knob (QA / Staging only): off unless an admin switches it on; never read on a production install. */
    public const DEFAULT_ALLOW_ADVANCE_AS_OF_DATE = false;

    protected $fillable = [
        'agency_id',
        'expiry_notice_window_days',
        'show_lease_type_field',
        'default_deposit_months',
        'tenant_notice_period_days',
        'cpa_early_cancel_business_days',
        'breach_remedy_business_days',
        'tenant_notice_period_unit',
        'default_earliest_notice_months',
        'default_early_cancellation_allowed',
        'default_early_cancellation_notice',
        'default_early_cancellation_notice_unit',
        'default_early_cancellation_penalty',
        'month_to_month_after_end_days',
        'require_end_or_month_to_month_for_signing',
        'restore_end_date_on_leaving_month_to_month',
        'signed_copy_not_live_note',
        'allow_advance_as_of_date',
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
        'cpa_early_cancel_business_days' => 'integer',
        'breach_remedy_business_days' => 'integer',
        'default_earliest_notice_months' => 'integer',
        'default_early_cancellation_notice' => 'integer',
        'month_to_month_after_end_days' => 'integer',
        'require_end_or_month_to_month_for_signing' => 'boolean',
        'restore_end_date_on_leaving_month_to_month' => 'boolean',
        'allow_advance_as_of_date' => 'boolean',
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

    /** Business days' notice a tenant may cancel a fixed-term lease on under the Consumer Protection Act (lease-notices.md §7). */
    public static function cpaEarlyCancelBusinessDaysFor(?int $agencyId): int
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_CPA_EARLY_CANCEL_BUSINESS_DAYS;
        }
        $v = self::withoutGlobalScopes()->where('agency_id', $agencyId)->value('cpa_early_cancel_business_days');

        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::DEFAULT_CPA_EARLY_CANCEL_BUSINESS_DAYS;
    }

    /** Business days a breach notice allows to remedy the breach before a notice to vacate may be issued (rental-notices.md §5). */
    public static function breachRemedyBusinessDaysFor(?int $agencyId): int
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_BREACH_REMEDY_BUSINESS_DAYS;
        }
        $v = self::withoutGlobalScopes()->where('agency_id', $agencyId)->value('breach_remedy_business_days');

        return is_numeric($v) && (int) $v > 0 ? (int) $v : self::DEFAULT_BREACH_REMEDY_BUSINESS_DAYS;
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

    public static function requireEndOrMonthToMonthForSigningFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_REQUIRE_END_OR_MONTH_TO_MONTH_FOR_SIGNING;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->require_end_or_month_to_month_for_signing ?? self::DEFAULT_REQUIRE_END_OR_MONTH_TO_MONTH_FOR_SIGNING;
    }

    public static function restoreEndDateOnLeavingMonthToMonthFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_RESTORE_END_DATE_ON_LEAVING_MONTH_TO_MONTH;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->restore_end_date_on_leaving_month_to_month ?? self::DEFAULT_RESTORE_END_DATE_ON_LEAVING_MONTH_TO_MONTH;
    }

    /** The raw setting (rental-renewals.md §22.1). Callers on a screen / route go through LeaseTermAdvanceService::asOfDateAllowed(), which also checks the environment. */
    public static function allowAdvanceAsOfDateFor(?int $agencyId): bool
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_ALLOW_ADVANCE_AS_OF_DATE;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->allow_advance_as_of_date ?? self::DEFAULT_ALLOW_ADVANCE_AS_OF_DATE;
    }

    /** The sentence for the signed-copy mail ('' = the agency chose to say nothing extra). */
    public static function signedCopyNotLiveNoteFor(?int $agencyId): string
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row && $row->signed_copy_not_live_note !== null ? (string) $row->signed_copy_not_live_note : self::DEFAULT_SIGNED_COPY_NOT_LIVE_NOTE;
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
