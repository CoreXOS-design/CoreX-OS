<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-392 Phase 2 — Johan: "qualifying formula - agency can set this."
 * Deliberately follows STANDARDS.md Rule 17's safe pattern: forAgency()
 * NEVER creates a row on read, only returns a sensible in-memory default
 * when the agency has never opened the settings screen. A row is only
 * ever written when the agency explicitly saves.
 *
 * 2026-09-08 — replaced income_to_rent_multiplier (a multiplier of rent,
 * ~3x, worked out to roughly 33% of income by coincidence of arithmetic)
 * with the actual legal figure: rent must not exceed 30% of GROSS
 * income (Johan, from his own reading of the law). The law sets a
 * CEILING, not a fixed number — an agency may set a STRICTER (lower)
 * figure, but the figure it applies to (gross income) is never itself
 * configurable. No real agency had ever configured the old column at
 * the point of this migration (the one row that existed was a leftover
 * test artifact from an earlier round, cleaned up separately) — a clean
 * replace, not a second column living alongside the first.
 */
class RentalApplicationQualifyingSetting extends Model
{
    use BelongsToAgency;

    public const DEFAULT_MAX_RENT_PERCENT = 30.00;

    /** The legal guideline itself — used to warn, never to block, when an agency sets higher. */
    public const LEGAL_CEILING_PERCENT = 30.00;

    /**
     * Reopen/resubmit, 2026-09-08 — same window the original send() invite
     * link already uses (hardcoded there as 14, pre-existing, out of this
     * build's scope to change — reported separately). A reopened link is a
     * genuinely new window this build introduces, so it gets its own
     * agency-configurable default rather than a second hardcoded 14.
     */
    public const DEFAULT_REOPEN_LINK_EXPIRY_DAYS = 14;

    protected $fillable = ['agency_id', 'max_rent_percent_of_gross_income', 'reopen_link_expiry_days'];

    protected $casts = [
        'max_rent_percent_of_gross_income' => 'decimal:2',
        'reopen_link_expiry_days' => 'integer',
    ];

    public static function maxRentPercentFor(?int $agencyId): float
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_MAX_RENT_PERCENT;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row ? (float) $row->max_rent_percent_of_gross_income : self::DEFAULT_MAX_RENT_PERCENT;
    }

    /** True when a configured figure goes beyond the legal guideline — the settings screen must warn, never silently accept this as normal. */
    public static function exceedsLegalCeiling(float $percent): bool
    {
        return $percent > self::LEGAL_CEILING_PERCENT;
    }

    public static function reopenLinkExpiryDaysFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_REOPEN_LINK_EXPIRY_DAYS;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->reopen_link_expiry_days !== null
            ? (int) $row->reopen_link_expiry_days
            : self::DEFAULT_REOPEN_LINK_EXPIRY_DAYS;
    }
}
