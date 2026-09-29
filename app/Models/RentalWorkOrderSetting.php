<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4b/§8 — one row per agency,
 * read-time default pattern matching RentalInspectionSetting: a null
 * column resolves to the DEFAULT_* constant, never written on read.
 */
class RentalWorkOrderSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_OVERDUE_REMINDER_DAYS = 3;
    public const DEFAULT_NO_APPROVAL_SPEND_THRESHOLD = 500.00;
    public const DEFAULT_COMPLETION_REQUIRES_PHOTO = false;

    protected $fillable = [
        'agency_id',
        'completion_requires_photo',
        'overdue_reminder_days',
        'no_approval_spend_threshold',
    ];

    protected $casts = [
        'completion_requires_photo' => 'boolean',
        'overdue_reminder_days' => 'integer',
        'no_approval_spend_threshold' => 'decimal:2',
    ];

    /**
     * §3.1/§3.4/§8 — CORRECTED 2026-09-21, Johan: "some repairs will not
     * carry photo evidence - broken gate motor... you cant have a tenant
     * or agent going and fiddling with a gate motor to take a pic of a
     * replaced pc board." Default is now off; an agency that wants photo
     * evidence on every job can still switch this on per-agency.
     */
    public static function completionRequiresPhotoFor(?int $agencyId): bool
    {
        if (! $agencyId) {
            return self::DEFAULT_COMPLETION_REQUIRES_PHOTO;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('completion_requires_photo');

        return $value !== null ? (bool) $value : self::DEFAULT_COMPLETION_REQUIRES_PHOTO;
    }

    public static function overdueReminderDaysFor(?int $agencyId): int
    {
        if (! $agencyId) {
            return self::DEFAULT_OVERDUE_REMINDER_DAYS;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('overdue_reminder_days');

        return $value !== null ? (int) $value : self::DEFAULT_OVERDUE_REMINDER_DAYS;
    }

    /**
     * §3.4b — the agency-level default, read-time, never written until an
     * agency actually sets one.
     */
    public static function spendThresholdFor(?int $agencyId): float
    {
        if (! $agencyId) {
            return self::DEFAULT_NO_APPROVAL_SPEND_THRESHOLD;
        }
        $value = static::withoutGlobalScopes()->where('agency_id', $agencyId)->value('no_approval_spend_threshold');

        return $value !== null ? (float) $value : self::DEFAULT_NO_APPROVAL_SPEND_THRESHOLD;
    }

    /**
     * §3.4b/§3.4c — settled 2026-09-29, superseding the 2026-09-26 lease
     * ruling. Johan, looking at the live lease screen: "per property,
     * populated to the leases screen." The PROPERTY is the single editable
     * source of truth for the override, with the agency default behind it —
     * this is the one resolver anything gating a spend decision should call,
     * never read either column directly. Consumed by
     * RentalWorkOrder::selectQuote() (§3.4c) against the SELECTED quote's
     * amount.
     */
    public static function thresholdFor(Property $property): float
    {
        if ($property->rental_no_approval_spend_threshold !== null) {
            return (float) $property->rental_no_approval_spend_threshold;
        }

        return self::spendThresholdFor($property->agency_id);
    }
}
