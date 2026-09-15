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

    protected $fillable = [
        'agency_id',
        'expiry_notice_window_days',
    ];

    protected $casts = [
        'expiry_notice_window_days' => 'integer',
    ];

    public static function expiryNoticeWindowDaysFor(?int $agencyId): int
    {
        if (!$agencyId || $agencyId <= 0) {
            return self::DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS;
        }

        $row = self::withoutGlobalScopes()->where('agency_id', $agencyId)->first();

        return $row?->expiry_notice_window_days ?? self::DEFAULT_EXPIRY_NOTICE_WINDOW_DAYS;
    }
}
