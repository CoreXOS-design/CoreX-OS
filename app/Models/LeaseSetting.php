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
        'auto_readvertise_on_notice',
        'auto_restore_status_on_lease_ended',
        'auto_restore_status_on_lease_cancelled',
        'default_pre_let_status',
    ];

    protected $casts = [
        'expiry_notice_window_days' => 'integer',
        'show_lease_type_field' => 'boolean',
        'default_deposit_months' => 'decimal:2',
        'tenant_notice_period_days' => 'integer',
        'auto_readvertise_on_notice' => 'boolean',
        'auto_restore_status_on_lease_ended' => 'boolean',
        'auto_restore_status_on_lease_cancelled' => 'boolean',
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
}
