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
    public const DEFAULT_COMPLETION_REQUIRES_PHOTO = true;

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
     * §3.4, Stage 4 — Johan's ruling: "photos of the work conducted." An
     * agency CAN turn this off; the default matches the ruling, not a
     * weaker posture.
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
     * §3.4b, settled 2026-09-26 — Johan's ruling: the override lives on the
     * LEASE, with the agency default behind it. This is the one resolver
     * anything gating a spend decision should call — never read either
     * column directly. Not consumed by any gate yet (Stage 4); the resolver
     * exists now so Stage 4 has one place to call, not two conventions to
     * pick between.
     */
    public static function thresholdFor(Lease $lease): float
    {
        if ($lease->rental_no_approval_spend_threshold !== null) {
            return (float) $lease->rental_no_approval_spend_threshold;
        }

        return self::spendThresholdFor($lease->agency_id);
    }
}
