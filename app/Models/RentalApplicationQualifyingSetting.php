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

    /**
     * Johan, QA1 (item 2 follow-up, 2026-09-10) — reproduced on a real,
     * fully-approved application: the linked property could be swapped or
     * cleared at any point, including after the outcome email had already
     * gone out naming it, and — more dangerously — while an authoriser was
     * actively deciding against it. Default locked (true): the flow makes
     * the property load-bearing for a human decision the moment it's
     * submitted for authorisation, not just once approved, so that is
     * where the default draws the line. An agency that wants the old,
     * unrestricted behaviour can turn this off.
     */
    public const DEFAULT_LOCK_PROPERTY_AFTER_SUBMISSION = true;

    /** Statuses at/after which the property link is treated as load-bearing for a decision already made or in progress. */
    public const PROPERTY_LOCKED_STATUSES = ['under_assessment', 'approved', 'declined', 'withdrawn'];

    /**
     * Johan, contact-type ruling, 2026-09-11, verbatim: "contact type can be
     * added, not changed... the seller of unit a decides to rent but their
     * property has not sold yet. so that contact will be dealt with as a
     * seller on their property but also as a tenant inside rentals." Default
     * on: this is the behaviour he asked for; an agency that genuinely does
     * not want automatic tagging can turn it off. Only APPROVAL tags —
     * decline/withdrawal never do (nothing in this codebase's existing
     * auto-tag-on-link pattern ties a type to an outcome that didn't happen).
     */
    public const DEFAULT_TAG_CONTACT_AS_TENANT_ON_APPROVAL = true;

    /**
     * Applicant-side autosave, 2026-09-12 — Johan: "the debounce interval...
     * is an agency-configurable setting with a sensible default. Never
     * hardcoded." 5 seconds of no typing/no focus-change before the public
     * form saves in the background — long enough that it never fires on
     * every keystroke, short enough that a phone dropping signal mid-form
     * still has a very recent save to fall back on.
     */
    public const DEFAULT_AUTOSAVE_DEBOUNCE_SECONDS = 5;

    /**
     * Volume cap, 2026-09-12 — Johan: "any threshold, window or business
     * rule an agency-configurable setting with a sensible default. Getting
     * this wrong in the tight direction is worse than not having it: an
     * applicant locked out of their own half-finished application mid-
     * typing... is a far more damaging failure than a script writing too
     * many rows." Sized against the WORST-CASE legitimate rate, not the
     * typical one: the settings screen's own server-enforced floor on
     * autosave_debounce_seconds is 2 seconds, so a real applicant typing
     * continuously with zero pauses at that tightest-allowed setting
     * produces at most 3600/2 = 1,800 saves in an hour. The 3,000 default
     * here leaves ~67% headroom above that theoretical ceiling — no real
     * person, at any agency's configured debounce, will ever hit it. A
     * script sending thousands of writes an hour to one application, from
     * however many IPs, still will.
     */
    public const DEFAULT_AUTOSAVE_RATE_LIMIT_MAX = 3000;

    /** Rolling window the cap above applies over. */
    public const DEFAULT_AUTOSAVE_RATE_LIMIT_WINDOW_MINUTES = 60;

    protected $fillable = [
        'agency_id', 'max_rent_percent_of_gross_income', 'reopen_link_expiry_days',
        'lock_property_after_submission', 'tag_contact_as_tenant_on_approval',
        'autosave_debounce_seconds', 'autosave_rate_limit_max', 'autosave_rate_limit_window_minutes',
    ];

    protected $casts = [
        'max_rent_percent_of_gross_income' => 'decimal:2',
        'reopen_link_expiry_days' => 'integer',
        'lock_property_after_submission' => 'boolean',
        'tag_contact_as_tenant_on_approval' => 'boolean',
        'autosave_debounce_seconds' => 'integer',
        'autosave_rate_limit_max' => 'integer',
        'autosave_rate_limit_window_minutes' => 'integer',
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

    public static function lockPropertyAfterSubmissionFor(?int $agencyId): bool
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_LOCK_PROPERTY_AFTER_SUBMISSION;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->lock_property_after_submission !== null
            ? (bool) $row->lock_property_after_submission
            : self::DEFAULT_LOCK_PROPERTY_AFTER_SUBMISSION;
    }

    /** True when this application's current status is one the property-link lock treats as load-bearing. */
    public static function isPropertyLinkLockedFor(\App\Models\RentalApplication $rentalApplication): bool
    {
        return self::lockPropertyAfterSubmissionFor((int) $rentalApplication->agency_id)
            && in_array($rentalApplication->status, self::PROPERTY_LOCKED_STATUSES, true);
    }

    public static function tagContactAsTenantOnApprovalFor(?int $agencyId): bool
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_TAG_CONTACT_AS_TENANT_ON_APPROVAL;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->tag_contact_as_tenant_on_approval !== null
            ? (bool) $row->tag_contact_as_tenant_on_approval
            : self::DEFAULT_TAG_CONTACT_AS_TENANT_ON_APPROVAL;
    }

    public static function autosaveDebounceSecondsFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_AUTOSAVE_DEBOUNCE_SECONDS;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->autosave_debounce_seconds !== null
            ? (int) $row->autosave_debounce_seconds
            : self::DEFAULT_AUTOSAVE_DEBOUNCE_SECONDS;
    }

    public static function autosaveRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_AUTOSAVE_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->autosave_rate_limit_max !== null
            ? (int) $row->autosave_rate_limit_max
            : self::DEFAULT_AUTOSAVE_RATE_LIMIT_MAX;
    }

    public static function autosaveRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_AUTOSAVE_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->autosave_rate_limit_window_minutes !== null
            ? (int) $row->autosave_rate_limit_window_minutes
            : self::DEFAULT_AUTOSAVE_RATE_LIMIT_WINDOW_MINUTES;
    }
}
