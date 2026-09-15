<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/leases.md §5.2 — the CPA expiry-notice obligation. Reserved,
 * not wired to any notification logic. Pattern mirrors
 * RentalApplicationApprovalEmailSetting / RentalApplicationQualifyingSetting:
 * a per-agency row, never written on read, a static lookup that returns
 * null (not a hardcoded number) when nothing has been configured yet.
 */
class LeaseSetting extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'expiry_notice_window_days',
    ];

    protected $casts = [
        'expiry_notice_window_days' => 'integer',
    ];

    /**
     * Returns null when unset — deliberately NOT defaulted to any number of
     * weeks/days. Johan's compliance officer has not yet confirmed the real
     * figure; encoding a guess here would be exactly what he told us not to
     * do.
     */
    public static function expiryNoticeWindowDaysFor(?int $agencyId): ?int
    {
        if (!$agencyId || $agencyId <= 0) {
            return null;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->expiry_notice_window_days;
    }
}
