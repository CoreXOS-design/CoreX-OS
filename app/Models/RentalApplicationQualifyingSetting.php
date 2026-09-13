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

    /**
     * Document-upload volume cap, 2026-09-13 — Johan, live on QA1, blocked
     * before golf: the PRE-EXISTING `throttle:10,1` on the public document
     * upload/replace/remove routes is (a) keyed per-IP by Laravel's default
     * unauthenticated signature, meaning an entire shared-office or carrier-
     * grade-NAT mobile connection is ONE applicant as far as it's concerned
     * — the exact incident, reproduced live: five uploads in two seconds
     * from one IP hit it — and (b) sized for a task that routinely needs
     * more: a real applicant's file picker fires ONE POST PER FILE,
     * concurrently (`Promise.all` in show.blade.php's own JS), and a full
     * document set (ID, payslips, bank statements, a FICA proof) commonly
     * runs to 10+ phone photos selected in one action — before counting a
     * single retry from a slow response, which is exactly what Johan hit.
     * Sized against that realistic burst, not the typical one: up to 10
     * files in ONE multi-select (the hard array cap `supporting_files`
     * already enforces) plus a full second attempt if the first stalls,
     * repeated across a session that adds documents in more than one
     * batch (ID now, bank statements later) — comfortably inside 60 in 10
     * minutes with real headroom to spare.
     */
    public const DEFAULT_DOCUMENT_RATE_LIMIT_MAX = 60;

    /** Rolling window the cap above applies over. */
    public const DEFAULT_DOCUMENT_RATE_LIMIT_WINDOW_MINUTES = 10;

    /**
     * AT-392 round 2, 2026-09-13 — cc3's finding while investigating the
     * withdraw control: the public document upload/replace/remove routes
     * never checked application status at all, so a withdrawn or declined
     * application's link kept accepting files indefinitely — a public,
     * unauthenticated write into agency storage with no closing condition.
     * Johan's ruling: withdrawn/declined are ALWAYS closed (no setting —
     * see RentalApplication::DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES),
     * but approved stays open BY DEFAULT because an agency may
     * legitimately want one more document from an approved tenant. This
     * is the one setting that governs that case.
     */
    public const DEFAULT_DOCUMENT_UPLOADS_OPEN_AFTER_APPROVAL = true;

    /**
     * Route rate limits, AT-392 round 2, 2026-09-13 — the conductor's
     * sweep from the document-upload incident: five more public routes
     * carried Laravel's stock per-IP `throttle:N,1`, the exact defect
     * that incident closed for documents. Each moved to the same
     * per-application-token key (AppServiceProvider::boot()), each with
     * its own agency-configurable default here — never hardcoded, same
     * standing rule as every other threshold in this model. Conductor's
     * priority order (highest real-world risk first) preserved in this
     * file's own ordering: submit, show, pdf, document-view, autosave.
     *
     * SUBMIT — the single most expensive request in the whole journey to
     * lose (Johan: "several agents helping several applicants submit in
     * the same minute" from the one HFC office IP was the actual
     * incident this route was at risk of). Per-token removes that
     * collision entirely; sized for one real submission plus retries
     * within a session, not concurrent applicants.
     */
    public const DEFAULT_SUBMIT_RATE_LIMIT_MAX = 10;

    public const DEFAULT_SUBMIT_RATE_LIMIT_WINDOW_MINUTES = 10;

    /**
     * SHOW — a real applicant on bad mobile data reloading a slow page
     * repeatedly (the scenario Johan named) needs more headroom than a
     * single-minute window gives; sized generously above realistic
     * manual-reload behaviour.
     */
    public const DEFAULT_SHOW_RATE_LIMIT_MAX = 60;

    public const DEFAULT_SHOW_RATE_LIMIT_WINDOW_MINUTES = 5;

    /** PDF — read-only render, generous default, same window as documents for one consistent rule. */
    public const DEFAULT_PDF_RATE_LIMIT_MAX = 60;

    public const DEFAULT_PDF_RATE_LIMIT_WINDOW_MINUTES = 10;

    /** DOCUMENT VIEW — read-only render, same category and sizing as pdf above. */
    public const DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_MAX = 60;

    public const DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_WINDOW_MINUTES = 10;

    /**
     * AUTOSAVE (the outer request-level throttle, not the existing
     * autosave_rate_limit_max/window_minutes above — that pair governs a
     * SEPARATE, already-token-keyed per-application draft-save counter
     * inside the controller itself, sized for an hour-long typing
     * session. This pair governs only the outer middleware that used to
     * be `throttle:40,1` per-IP; kept last in the conductor's priority
     * order since the inner layer already protects this route — moved
     * anyway "so there is one consistent rule rather than a special case
     * somebody has to remember" (Johan, verbatim). Sized above the
     * inner layer's own worst-case-typing-rate math (30/min at the
     * settings screen's floor debounce of 2s) so this outer layer never
     * trips ahead of, or instead of, the inner one's own distinct
     * `{saved:false, rate_limited:true}` signal.
     */
    public const DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_MAX = 60;

    public const DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_WINDOW_MINUTES = 1;

    /**
     * FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan, a legal position:
     * "technically we not allowed to work with anyone if did not fica."
     * This gates only the AUTHORISER hand-off, never the application's own
     * receipt — see RentalApplication::ficaOutstanding() and
     * RentalApplicationSigningController::submit()'s FICA hand-off, which
     * always accepts the application regardless of this setting. Default
     * true: HFC's own answer is yes, per Johan's own words; another agency
     * may turn it off.
     */
    public const DEFAULT_REQUIRE_FICA_BEFORE_AUTHORISATION = true;

    /**
     * Return gate, AT-392 round 4, 2026-09-13 — Johan: "initial open is
     * not gated but if the applicant submits... after initial submission
     * we can gate on ID." Default is the applicant's own ID number
     * (already captured on the application) — a speed bump, not
     * authentication (an ID number is not a secret; it's on every
     * document that person has ever handed anyone). 'email_otp' is the
     * agency-configurable stronger option for agencies that want the gate
     * to actually hold, reusing CoreX's existing OtpService.
     */
    public const DEFAULT_RETURN_GATE_METHOD = 'id_number';

    public const RETURN_GATE_METHODS = ['id_number', 'email_otp'];

    /**
     * Failed attempts must be limited and must not become an oracle for
     * guessing an ID against a known application — capped tight, same
     * per-token limiter convention as every other rate limit this feature
     * carries (see AppServiceProvider::boot()'s rental-application-gate).
     */
    public const DEFAULT_RETURN_GATE_ATTEMPT_MAX = 5;

    public const DEFAULT_RETURN_GATE_ATTEMPT_WINDOW_MINUTES = 15;

    /**
     * Submission identity gate, 2026-09-13 — Johan walked the applicant
     * link himself, signed both pads, pressed submit, and landed straight
     * in FICA with no identity challenge at all: "ON SUBMISSION THE ID /
     * OTP GATE." Default ON — HFC's own instance is exactly the one this
     * closes a live hole for; an agency that genuinely doesn't want it can
     * turn it off, same as require_fica_before_authorisation's own default.
     * Channel (email OTP vs ID-number fallback) is chosen per applicant at
     * the moment of the gate, never a fixed agency setting — see
     * RentalApplicationSigningController::identityGateChannelFor().
     */
    public const DEFAULT_IDENTITY_GATE_ENABLED = true;

    /**
     * These four all fall through to App\Services\Otp\OtpService's own
     * config/otp.php defaults (6 digits / 10 min / 60s cooldown) when null
     * — the engine already supports per-call overrides for all of them
     * (length added to issue()/generateCode() specifically for this
     * feature, 2026-09-13; expires_minutes and cooldown_secs already
     * existed, unused by any consumer until now). attempt_max/
     * attempt_window_minutes are NOT engine settings — they size the
     * OUTER named rate limiter (rental-application-identity-gate,
     * AppServiceProvider::boot()), the same two-layer shape the Return
     * Gate already uses for its own attempt cap.
     */
    public const DEFAULT_IDENTITY_GATE_ATTEMPT_MAX = 5;

    public const DEFAULT_IDENTITY_GATE_ATTEMPT_WINDOW_MINUTES = 15;

    protected $fillable = [
        'agency_id', 'max_rent_percent_of_gross_income', 'reopen_link_expiry_days',
        'lock_property_after_submission', 'tag_contact_as_tenant_on_approval',
        'autosave_debounce_seconds', 'autosave_rate_limit_max', 'autosave_rate_limit_window_minutes',
        'document_rate_limit_max', 'document_rate_limit_window_minutes',
        'document_uploads_open_after_approval',
        'show_rate_limit_max', 'show_rate_limit_window_minutes',
        'submit_rate_limit_max', 'submit_rate_limit_window_minutes',
        'pdf_rate_limit_max', 'pdf_rate_limit_window_minutes',
        'document_view_rate_limit_max', 'document_view_rate_limit_window_minutes',
        'autosave_request_rate_limit_max', 'autosave_request_rate_limit_window_minutes',
        'require_fica_before_authorisation',
        'return_gate_method', 'return_gate_attempt_max', 'return_gate_attempt_window_minutes',
        'identity_gate_enabled', 'identity_gate_otp_length', 'identity_gate_otp_expiry_minutes',
        'identity_gate_attempt_max', 'identity_gate_attempt_window_minutes', 'identity_gate_resend_cooldown_seconds',
    ];

    protected $casts = [
        'max_rent_percent_of_gross_income' => 'decimal:2',
        'reopen_link_expiry_days' => 'integer',
        'lock_property_after_submission' => 'boolean',
        'tag_contact_as_tenant_on_approval' => 'boolean',
        'autosave_debounce_seconds' => 'integer',
        'autosave_rate_limit_max' => 'integer',
        'autosave_rate_limit_window_minutes' => 'integer',
        'document_rate_limit_max' => 'integer',
        'document_rate_limit_window_minutes' => 'integer',
        'document_uploads_open_after_approval' => 'boolean',
        'require_fica_before_authorisation' => 'boolean',
        'return_gate_attempt_max' => 'integer',
        'return_gate_attempt_window_minutes' => 'integer',
        'show_rate_limit_max' => 'integer',
        'show_rate_limit_window_minutes' => 'integer',
        'submit_rate_limit_max' => 'integer',
        'submit_rate_limit_window_minutes' => 'integer',
        'pdf_rate_limit_max' => 'integer',
        'pdf_rate_limit_window_minutes' => 'integer',
        'document_view_rate_limit_max' => 'integer',
        'document_view_rate_limit_window_minutes' => 'integer',
        'autosave_request_rate_limit_max' => 'integer',
        'autosave_request_rate_limit_window_minutes' => 'integer',
        'identity_gate_enabled' => 'boolean',
        'identity_gate_otp_length' => 'integer',
        'identity_gate_otp_expiry_minutes' => 'integer',
        'identity_gate_attempt_max' => 'integer',
        'identity_gate_attempt_window_minutes' => 'integer',
        'identity_gate_resend_cooldown_seconds' => 'integer',
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

    public static function documentRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_DOCUMENT_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->document_rate_limit_max !== null
            ? (int) $row->document_rate_limit_max
            : self::DEFAULT_DOCUMENT_RATE_LIMIT_MAX;
    }

    public static function documentRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_DOCUMENT_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->document_rate_limit_window_minutes !== null
            ? (int) $row->document_rate_limit_window_minutes
            : self::DEFAULT_DOCUMENT_RATE_LIMIT_WINDOW_MINUTES;
    }

    public static function documentUploadsOpenAfterApprovalFor(?int $agencyId): bool
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_DOCUMENT_UPLOADS_OPEN_AFTER_APPROVAL;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->document_uploads_open_after_approval !== null
            ? (bool) $row->document_uploads_open_after_approval
            : self::DEFAULT_DOCUMENT_UPLOADS_OPEN_AFTER_APPROVAL;
    }

    public static function showRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_SHOW_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->show_rate_limit_max !== null
            ? (int) $row->show_rate_limit_max
            : self::DEFAULT_SHOW_RATE_LIMIT_MAX;
    }

    public static function showRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_SHOW_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->show_rate_limit_window_minutes !== null
            ? (int) $row->show_rate_limit_window_minutes
            : self::DEFAULT_SHOW_RATE_LIMIT_WINDOW_MINUTES;
    }

    public static function submitRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_SUBMIT_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->submit_rate_limit_max !== null
            ? (int) $row->submit_rate_limit_max
            : self::DEFAULT_SUBMIT_RATE_LIMIT_MAX;
    }

    public static function submitRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_SUBMIT_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->submit_rate_limit_window_minutes !== null
            ? (int) $row->submit_rate_limit_window_minutes
            : self::DEFAULT_SUBMIT_RATE_LIMIT_WINDOW_MINUTES;
    }

    public static function pdfRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_PDF_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->pdf_rate_limit_max !== null
            ? (int) $row->pdf_rate_limit_max
            : self::DEFAULT_PDF_RATE_LIMIT_MAX;
    }

    public static function pdfRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_PDF_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->pdf_rate_limit_window_minutes !== null
            ? (int) $row->pdf_rate_limit_window_minutes
            : self::DEFAULT_PDF_RATE_LIMIT_WINDOW_MINUTES;
    }

    public static function documentViewRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->document_view_rate_limit_max !== null
            ? (int) $row->document_view_rate_limit_max
            : self::DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_MAX;
    }

    public static function documentViewRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->document_view_rate_limit_window_minutes !== null
            ? (int) $row->document_view_rate_limit_window_minutes
            : self::DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_WINDOW_MINUTES;
    }

    public static function autosaveRequestRateLimitMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->autosave_request_rate_limit_max !== null
            ? (int) $row->autosave_request_rate_limit_max
            : self::DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_MAX;
    }

    public static function autosaveRequestRateLimitWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->autosave_request_rate_limit_window_minutes !== null
            ? (int) $row->autosave_request_rate_limit_window_minutes
            : self::DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_WINDOW_MINUTES;
    }

    public static function requireFicaBeforeAuthorisationFor(?int $agencyId): bool
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_REQUIRE_FICA_BEFORE_AUTHORISATION;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->require_fica_before_authorisation !== null
            ? (bool) $row->require_fica_before_authorisation
            : self::DEFAULT_REQUIRE_FICA_BEFORE_AUTHORISATION;
    }

    public static function returnGateMethodFor(?int $agencyId): string
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_RETURN_GATE_METHOD;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->return_gate_method !== null && in_array($row->return_gate_method, self::RETURN_GATE_METHODS, true)
            ? $row->return_gate_method
            : self::DEFAULT_RETURN_GATE_METHOD;
    }

    public static function returnGateAttemptMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_RETURN_GATE_ATTEMPT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->return_gate_attempt_max !== null
            ? (int) $row->return_gate_attempt_max
            : self::DEFAULT_RETURN_GATE_ATTEMPT_MAX;
    }

    public static function returnGateAttemptWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_RETURN_GATE_ATTEMPT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->return_gate_attempt_window_minutes !== null
            ? (int) $row->return_gate_attempt_window_minutes
            : self::DEFAULT_RETURN_GATE_ATTEMPT_WINDOW_MINUTES;
    }

    public static function identityGateEnabledFor(?int $agencyId): bool
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_IDENTITY_GATE_ENABLED;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->identity_gate_enabled !== null
            ? (bool) $row->identity_gate_enabled
            : self::DEFAULT_IDENTITY_GATE_ENABLED;
    }

    /** null = fall through to OtpService's own config('otp.length') default. */
    public static function identityGateOtpLengthFor(?int $agencyId): ?int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return null;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->identity_gate_otp_length !== null ? (int) $row->identity_gate_otp_length : null;
    }

    /** null = fall through to OtpService's own config('otp.expires_minutes') default. */
    public static function identityGateOtpExpiryMinutesFor(?int $agencyId): ?int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return null;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->identity_gate_otp_expiry_minutes !== null ? (int) $row->identity_gate_otp_expiry_minutes : null;
    }

    /** null = fall through to OtpService's own config('otp.resend_cooldown_secs') default. */
    public static function identityGateResendCooldownSecondsFor(?int $agencyId): ?int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return null;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->identity_gate_resend_cooldown_seconds !== null ? (int) $row->identity_gate_resend_cooldown_seconds : null;
    }

    public static function identityGateAttemptMaxFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_IDENTITY_GATE_ATTEMPT_MAX;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->identity_gate_attempt_max !== null
            ? (int) $row->identity_gate_attempt_max
            : self::DEFAULT_IDENTITY_GATE_ATTEMPT_MAX;
    }

    public static function identityGateAttemptWindowMinutesFor(?int $agencyId): int
    {
        if ($agencyId === null || $agencyId <= 0) {
            return self::DEFAULT_IDENTITY_GATE_ATTEMPT_WINDOW_MINUTES;
        }

        $row = static::where('agency_id', $agencyId)->first();

        return $row && $row->identity_gate_attempt_window_minutes !== null
            ? (int) $row->identity_gate_attempt_window_minutes
            : self::DEFAULT_IDENTITY_GATE_ATTEMPT_WINDOW_MINUTES;
    }

    /**
     * Johan's ruling, 2026-09-13, verbatim: "if an agency has the identity
     * gate switched ON, and has ALSO unticked every field the gate could
     * use to reach an applicant, that is a configuration that cannot
     * work. Tell them so in the settings screen... do not block them from
     * saving it." Checked against cc6's per-field compulsory registry
     * (requiredFieldKeysFor()) — null means the shipped defaults, which
     * always include 'contact_method' (at least one of email/cell), so
     * only an agency's EXPLICIT saved set can ever trigger this warning.
     *
     * Defensive method_exists() guard: cc6's requiredFieldKeysFor() lands
     * in a separate worktree/branch (AT-410-adjacent compulsory-field
     * work, coordinated directly, no column collision — see spec). Until
     * that merges into this branch the method doesn't exist here yet;
     * degrading to "no warning" rather than a fatal error keeps this
     * feature shippable independently of merge order.
     */
    public static function identityGateUnreachableByDesign(?int $agencyId): bool
    {
        if (! self::identityGateEnabledFor($agencyId)) {
            return false;
        }

        if (! method_exists(self::class, 'requiredFieldKeysFor')) {
            return false;
        }

        $keys = self::requiredFieldKeysFor($agencyId);
        if ($keys === null) {
            return false;
        }

        return ! in_array('id_number', $keys, true)
            && ! in_array('email', $keys, true)
            && ! in_array('contact_method', $keys, true);
    }
}
