<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-392 — Rental Application intake (Phase 1). Spec: .ai/specs/rental-applications.md
 *
 * Deliberately NOT routed through the e-sign wizard/SignatureTemplate machinery:
 * that pipeline structurally requires an agent-signing party (see the AT-332
 * investigation), which is overkill for a tenant intake form the agent never signs.
 */
class RentalApplication extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUSES = [
        'draft', 'sent', 'in_progress', 'returned', 'reopened', 'under_assessment', 'approved', 'declined', 'withdrawn',
    ];

    /**
     * Reopen/resubmit, 2026-09-08 — Johan: "after a rental application comes
     * back to the agent, the agent must be able to send it BACK to the
     * applicant so the applicant can reopen it, edit what they entered, and
     * re-sign it." 'reopened' is deliberately its OWN status, not a reuse
     * of 'sent'/'in_progress' (see the reopen-investigation report) — those
     * already carry distinct meaning ('in_progress' specifically means
     * "first document uploaded"), so overloading them would make "was this
     * ever originally sent, or is it a reopen" undiscoverable from status
     * alone.
     *
     * Only a RETURNED or ALREADY-UNDER-ASSESSMENT application can be
     * reopened by anyone with ordinary write access to it (an agent's own
     * judgement call before the authoriser has decided anything) — the
     * existing guardRentalApplication() ownership check alone is enough
     * there, unchanged.
     *
     * 2026-09-09 — Johan added DECLINED: "co should be able to reopen.
     * maybe declined and more evidence given so can work with it again?"
     * A declined application can now also be reopened, but ONLY by the
     * rental-application override tier (User::isRentalApplicationOverrideTier()
     * — a configured CO, or admin/super_admin), enforced in reopen()
     * itself, never by hiding the button alone. Deliberately still
     * excludes approved: approved is the other side of the same "an
     * authoriser already decided" line and reopening it isn't part of
     * this ask.
     *
     * 2026-09-12 — CORRECTION, WITHDRAWN ADDED. This constant used to
     * exclude withdrawn outright, on the stated reasoning that "withdrawn
     * is the applicant's own choice to walk away, not something the
     * agency reopens on their behalf." A second end-to-end walkthrough
     * found that reasoning doesn't hold in practice: there is no
     * applicant self-service withdraw anywhere in this module (see
     * `.ai/specs/rental-applications.md`'s "REGRESSION FIX (2026-09-12)"
     * entry) — every withdrawn record today is really "an agent recorded
     * that the applicant told them so." That means a wrong or reconsidered
     * withdrawal is a real, ordinary scenario, not a hypothetical one, and
     * the agency needs a legitimate way to correct it — but it must be an
     * explicit, audited action, never the silent status-dropdown hole this
     * same walkthrough also found (`RentalApplicationController::
     * updateStatus()` had no guard at all against leaving 'withdrawn').
     * withdrawn now reopens through the EXACT SAME override-tier-gated,
     * required-note, audited path as declined — see reopen()'s
     * `$isOverrideReopen` check. The generic status endpoint separately
     * refuses ALL transitions out of 'withdrawn', so this reopen() door is
     * now the ONLY way out.
     */
    public const REOPENABLE_STATUSES = [
        'returned', 'under_assessment', 'declined', 'withdrawn',
    ];

    /**
     * AT-392 — Johan, QA1: "theres no way to mark application status to
     * what it is?" Split deliberately: draft/sent/in_progress/returned are
     * FACTS the system records (nothing typed, sent, or submitted — never
     * hand-settable, or the "status lies" defect this feature already fixed
     * once comes back). under_assessment/withdrawn are the agent's own
     * judgement calls once an application has actually been returned.
     *
     * AT-392 authoriser flow, 2026-09-08 — approved/declined REMOVED from
     * this list. Johan, verbatim: "only the auth can accept / reject / ask
     * for more information etc." An agent may work an application
     * (highlight, assess, request more info from the applicant) and submit
     * it to the authoriser, but the accept/reject decision itself belongs
     * exclusively to whoever is configured as an authoriser
     * (User::isRentalApplicationAuthoriser()) — enforced server-side on the
     * authorisation controller's own actions, not by hiding a button here.
     * Agreed with cc4 (who built this constant): stays additive, no new
     * status enum value, just this one narrowing.
     */
    public const AGENT_SETTABLE_STATUSES = [
        'under_assessment', 'withdrawn',
    ];

    /**
     * 2026-09-12 — Johan (approved): "withdrawn" reads, everywhere it's
     * shown, as if the applicant acted for themselves. They didn't — there
     * is no applicant self-service withdraw anywhere in this module (that's
     * a real feature for later, deliberately not built this weekend). Every
     * withdrawn record today is an agent recording something the applicant
     * told them (a call, an email). The wording now says that plainly
     * instead of implying otherwise. Recording one requires a note (see
     * RentalApplicationController::updateStatus()'s validation) and is
     * captured in the audit trail exactly like every other status change —
     * see RentalApplicationStatusHistory::record()'s own who/when/note
     * columns, which already covered this before the wording did.
     *
     * 2026-09-13 — Johan, this round: the CONTROL that sets this status
     * (the list/detail screens' button) never said "applicant" anywhere an
     * agent would see it without hovering a tooltip — even though this
     * pill's own text already did. Renamed the button to "Mark applicant
     * withdrawn" and, per Johan's explicit instruction not to end up with
     * two vocabularies for one thing, shortened this pill/tile/audit-trail
     * text to match it exactly in wording (just past-tense, since a status
     * pill can't grammatically carry an imperative): "Applicant withdrawn".
     * Every surface that shows this status now shares the same two words,
     * varying only in mood (imperative on the button, plain state
     * everywhere else) — see the spec's "Withdraw control" section for the
     * full list of surfaces moved together in this same round.
     */
    public const WITHDRAWN_LABEL = 'Applicant withdrawn';

    /**
     * The one place a status's plain-language display label is decided —
     * every status badge on every rental-application screen calls this,
     * never re-derives its own text. Converted from a static
     * string-in-string-out helper to an instance method 2026-09-21 (Johan,
     * from his own live walk) specifically so it can check isTenanted():
     * an approved application linked to an active lease is a further
     * state, not a new status, and this is the single place that further
     * state has to be reflected for every one of its callers to stay in
     * sync automatically — the bug this exists to prevent is exactly what
     * was found building this: the list's tile said "tenanted" while this
     * SAME application's own status badge, driven by a different, un-
     * updated call site, still said "approved" two clicks later.
     */
    public function displayStatusLabel(): string
    {
        if ($this->isTenanted()) {
            return $this->tenantedLabel();
        }

        return $this->status === 'withdrawn' ? self::WITHDRAWN_LABEL : str_replace('_', ' ', ucfirst($this->status));
    }

    /** Statuses at/after which a hand-set judgement call makes sense. */
    public const POST_RETURN_STATUSES = [
        'returned', 'under_assessment', 'approved', 'declined', 'withdrawn',
    ];

    /**
     * Reopen/resubmit, 2026-09-08 — the agent's OWN edit form
     * (RentalApplicationController::update()) must stay blocked while an
     * application is 'reopened', same as it's blocked once returned — the
     * applicant is the one editing those fields right now; the agent
     * writing to the same fields at the same time is exactly the collision
     * this build exists to prevent. 'reopened' is deliberately NOT added to
     * POST_RETURN_STATUSES itself (that constant also gates the PUBLIC
     * applicant link's "already submitted, read-only" treatment, and a
     * reopened application must be the opposite of read-only there).
     */
    public const AGENT_EDIT_LOCKED_STATUSES = [
        'returned', 'under_assessment', 'approved', 'declined', 'withdrawn', 'reopened',
    ];

    public const EMPLOYMENT_TYPES = [
        'permanently_employed', 'business_owner_personal_account', 'business_owner_business_account',
    ];

    /**
     * Johan, verbatim: "rental application - current landlord - we need to
     * include a part here for a person who has sold his house and is going
     * to rent for the first time now." The "Current Landlord" section
     * assumed a landlord always exists — a real, common applicant (someone
     * who just sold, or is selling, their own home) had nowhere honest to
     * answer. `renting` is the pre-existing path (landlord fields apply);
     * every other value means the landlord fields don't apply to this
     * applicant at all. Wording is provisional — Johan's to approve, this
     * is HFC's voice to a prospective tenant.
     */
    public const CURRENT_LIVING_SITUATIONS = [
        'renting', 'owns_or_selling', 'living_with_family', 'other',
    ];

    /**
     * Submission hard floor, AT-392 round 5, 2026-09-13 — the three
     * conditional groups, fixed by the form's own logic (never agency-
     * configurable — only WHETHER a field is compulsory is; WHEN it applies
     * is not). See submissionFieldRegistry()/submissionGroupApplies().
     */
    public const SUBMISSION_FIELD_GROUP_EMPLOYED = [
        'employer_name', 'employer_position', 'employer_address', 'employer_tel', 'occupation_date',
    ];

    public const SUBMISSION_FIELD_GROUP_RENTING = [
        'current_landlord_name', 'current_landlord_tel', 'current_rental_amount',
        'current_rental_from', 'current_rental_to', 'current_rental_due_day',
    ];

    public const SUBMISSION_FIELD_GROUP_MARRIED = ['spouse_name', 'spouse_id'];

    public const CURRENT_LIVING_SITUATION_LABELS = [
        'renting' => 'Currently renting',
        'owns_or_selling' => 'Own my current home (selling or recently sold)',
        'living_with_family' => 'Living with family or friends',
        'other' => 'Other',
    ];

    /** Human label for a stored current_living_situation value — shared by the review screen (cc3's dl) and the PDF, so the two can never say something different. Null/unknown values return null, never a blank guess. */
    public static function currentLivingSituationLabel(?string $value): ?string
    {
        return self::CURRENT_LIVING_SITUATION_LABELS[$value] ?? null;
    }

    /**
     * ONE set of format rules for the V8 field list — shared by the agent-side
     * update (RentalApplicationController) and the public submit
     * (RentalApplicationSigningController), so the two never drift (BUILD_STANDARD
     * §6). Every field is `nullable` — nothing here may block a save — but a
     * MALFORMED value is rejected rather than allowed through to crash a
     * date/decimal cast at save() time (BUILD_STANDARD §2/§4).
     */
    /**
     * Every field validated as numeric/integer in fieldValidationRules()
     * below — kept as one list so sanitizeNumericInput() can never drift
     * out of sync with which fields actually need it.
     */
    public const NUMERIC_FIELDS = ['current_rental_amount', 'monthly_salary', 'adults', 'children'];

    /**
     * AT-392 — Johan, independent testing (cc5), RA-02: "a real person
     * types 15,000 because that is how South Africans write money, and
     * gets 'must be a number' with no explanation." Fixing the class, not
     * the instance: EVERY numeric field on both forms, not just the two
     * cc5 happened to hit. Strips thousand-separator commas, spaces
     * (either as a separator or from "R 15 000"), and a leading "R"
     * currency prefix before validation ever sees the value — a human
     * writing money the way South Africans actually write it must never
     * be told it's invalid.
     */
    /**
     * RA-02 follow-up, 2026-09-08 — cc5's re-test found the SAME defect on
     * fields this sweep never reached: the assessment panel's income/
     * expense fields (a different model, RentalApplicationAssessment), the
     * authoriser's approved_rental_amount, and the qualifying-formula
     * multiplier. `$fields` now defaults to `NUMERIC_FIELDS` (every
     * existing caller keeps working unchanged) but accepts an explicit
     * list so this one sanitizer serves every numeric money field on this
     * feature — no reason to duplicate the same three lines of string
     * cleanup in every controller that touches a rand amount.
     *
     * SECOND regression on this exact area, 2026-09-08 — Johan: "everyone
     * will enter amounts like 40638.40, the std that everyone uses...
     * hitting the . on an amount clears the values." The OLD rule (strip
     * every comma and space, leave dots alone) was blind to a genuine
     * ambiguity: "40638,40" (comma as decimal — some people do write it
     * that way) looks identical in shape to "40,638" (comma as a thousands
     * mark). Johan's own resolution rule, applied exactly as he stated it:
     * the LAST separator (comma, dot, OR space) is the decimal point ONLY
     * when it is followed by EXACTLY two digits and nothing else after —
     * every other separator, and a last separator not followed by exactly
     * two digits, is a thousands mark and is stripped. This is what makes
     * "40,638.40" (dot decimal), "40638,40" (comma decimal), and
     * "40 638.40"/"R40 638.40" (space thousands, dot decimal) all resolve
     * to the same 40638.40, while "40,638" (comma thousands, no decimal
     * at all) resolves to the whole number 40638, not 40.638 or 4063.8.
     *
     * Deliberately NOT applied to the qualifying-formula percentage field
     * (max_rent_percent_of_gross_income) — flagged to Johan rather than
     * silently decided: a percentage like "28.5" has only ONE digit after
     * its decimal point, so this money-shaped rule would misread it as a
     * thousands-separated whole number ("285"). Percentages never carry a
     * thousands separator in the first place, so that field's own
     * sanitisation stays a plain trim, unrelated to this method.
     */
    public static function sanitizeNumericInput(array $input, ?array $fields = null): array
    {
        foreach ($fields ?? self::NUMERIC_FIELDS as $field) {
            if (isset($input[$field]) && is_string($input[$field]) && $input[$field] !== '') {
                $input[$field] = self::disambiguateMoneyString($input[$field]);
            }
        }

        return $input;
    }

    private static function disambiguateMoneyString(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^R\s*/i', '', $value);

        // Last separator followed by exactly two digits and nothing else
        // = the decimal point; everything before it (once its own
        // separators are stripped) is the whole-rand part.
        if (preg_match('/^(.*)[,.\s](\d{2})$/', $value, $matches)) {
            $wholePart = preg_replace('/[,.\s]/', '', $matches[1]);

            return $wholePart . '.' . $matches[2];
        }

        // No separator, or the last one isn't followed by exactly two
        // digits — every separator present is a thousands mark.
        return preg_replace('/[,.\s]/', '', $value);
    }

    /**
     * AT-392 — Johan, QA1: "put a tick at to date to tick that states still
     * living in current premises. so theres no to date applicable?" Enforced
     * server-side, not just by disabling the date input in the browser — a
     * ticked "still living here" always wins over whatever a stale/crafted
     * current_rental_to value arrives with, so the two states this exists to
     * distinguish (still living there vs. we don't know the end date) can
     * never be corrupted into a bogus date under the checkbox.
     */
    public static function normalizeStillLiving(array $fields): array
    {
        if (! empty($fields['current_rental_still_living'])) {
            $fields['current_rental_to'] = null;
        }

        return $fields;
    }

    public static function fieldValidationRules(): array
    {
        return [
            'property_address_override' => ['nullable', 'string', 'max:500'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', 'max:100'],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'spouse_id' => ['nullable', 'string', 'max:50'],
            'citizenship' => ['nullable', 'string', 'max:100'],
            'current_residential_address' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'cell' => ['nullable', 'string', 'max:50'],
            'work_number' => ['nullable', 'string', 'max:50'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_cell' => ['nullable', 'string', 'max:50'],
            'emergency_contact_work' => ['nullable', 'string', 'max:50'],
            // Current-living-situation ruling, 2026-09-11 — "renting" is the
            // only value that makes the landlord fields below relevant; the
            // form hides them for every other value, but this rule is what
            // actually stops a bad/tampered value from reaching save(), same
            // "nullable, reject only malformed" posture as every other field
            // on this form (BUILD_STANDARD §2 — nothing here may block a
            // save on its own absence).
            'current_living_situation' => ['nullable', 'string', 'in:' . implode(',', self::CURRENT_LIVING_SITUATIONS)],
            'current_living_situation_notes' => ['nullable', 'string', 'max:4000'],
            'current_landlord_name' => ['nullable', 'string', 'max:255'],
            'current_landlord_tel' => ['nullable', 'string', 'max:50'],
            'current_rental_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'current_rental_from' => ['nullable', 'date'],
            // AT-392 applicant-surface audit, 2026-09-10 — a "moved out"
            // date before "moved in" saved silently with no error (found
            // live: from=2026-08-15, to=2026-01-01 both accepted). Pure
            // date-logic, not a business judgement call — no agency-config
            // knob needed. Never fires when "still living here" is ticked:
            // that checkbox disables (browser) and nulls (server,
            // normalizeStillLiving()) this field before it could conflict.
            'current_rental_to' => ['nullable', 'date', 'after_or_equal:current_rental_from'],
            'current_rental_still_living' => ['nullable', 'boolean'],
            // "Dates on entries" (Johan, 2026-09-10) — a recurring day of
            // the month, not a calendar date (a lease's rent obligation
            // repeats every month; "the 1st" means the 1st of every month,
            // not one specific date).
            'current_rental_due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'employer_position' => ['nullable', 'string', 'max:255'],
            'employer_address' => ['nullable', 'string', 'max:2000'],
            'employer_tel' => ['nullable', 'string', 'max:50'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employment_type' => ['nullable', 'in:' . implode(',', self::EMPLOYMENT_TYPES)],
            'occupation_date' => ['nullable', 'date'],
            'rental_terms' => ['nullable', 'string', 'max:255'],
            'rental_term_months' => ['nullable', 'integer', 'in:6,12,24'],
            'special_conditions' => ['nullable', 'string', 'max:2000'],
            'adults' => ['nullable', 'integer', 'min:0', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    /**
     * Submission hard floor, AT-392 round 5, 2026-09-13 — Johan, twice
     * ruled: every field on the applicant form is agency tick/untick, no
     * locked set. ONE registry drives both the settings checklist and
     * submit()'s validation, so they can never drift — see
     * RentalApplicationFieldRegistryCoverageTest, which fails the build if
     * a field is added to fieldValidationRules()/the public form without a
     * matching entry here.
     *
     * 'contact_method' and 'declaration_signature'/'tpn_consent_signature'
     * are not real columns validated by fieldValidationRules() — handled
     * separately in submissionValidationRules() below. 'email'/'cell' are
     * ALSO independently tickable (an agency can force one specific
     * channel) in addition to 'contact_method' (at least one of the two) —
     * both can be ticked together with no conflict.
     *
     * Deliberately EXCLUDED, flagged rather than silently dropped:
     * `current_rental_to` and `current_rental_still_living`. The still-
     * living checkbox is a modifier, not a fact to require, and
     * current_rental_to's own requiredness is intrinsically conditional on
     * that checkbox being false in a way a flat tick can't express (see
     * normalizeStillLiving() — the date is always nulled when the box is
     * checked, so a naive "required" tick would wrongly block an applicant
     * who correctly left it blank). Needs a 4th conditional group if this
     * turns out to matter in practice — not built speculatively here.
     * `property_address_override` — a read-only, disabled display field on
     * the public form (show.blade.php:135), never applicant-editable, so
     * it can never sensibly be "compulsory" from the applicant's side.
     * `rental_terms` — legacy free-text column superseded by
     * `rental_term_months`'s three-button picker; not rendered anywhere on
     * the current public form (only referenced, read-only, on the agent
     * review screen as a migration note for pre-existing records).
     *
     * Every label below was checked against the exact wording on
     * show.blade.php, not guessed from the column name — Johan, 2026-09-13:
     * "a wrong label is worse than a missing entry, because a missing entry
     * fails the build and a wrong label ships silently."
     */
    /**
     * Johan, 2026-09-20 — the ONE place the credit bureau's display name
     * gets turned into user-facing text, so every consumer (this
     * registry's default label, the PDF's own heading, the settings
     * screen's section heading) reads the identical computed string
     * rather than re-deriving their own. Null (unconfigured, or an agency
     * that genuinely runs no bureau check) reads as generic wording —
     * never a blank gap in a sentence.
     */
    public static function creditBureauConsentLabel(?string $bureauName): string
    {
        return $bureauName ? "{$bureauName} Consent" : 'Credit Bureau Consent';
    }

    /**
     * Takes an already-RESOLVED label (agency label override already
     * applied, or historical field_config_snapshot value for a submitted
     * application via displayFieldConfig()) rather than re-deriving from
     * today's live bureau setting — so an old application's PDF keeps
     * naming whichever bureau it actually disclosed at submission time,
     * same historical-integrity rule as every other frozen field.
     */
    public static function creditBureauConsentCaption(string $resolvedLabel): string
    {
        return 'Applicant Signature — ' . $resolvedLabel;
    }

    public static function submissionFieldRegistry(?int $agencyId = null): array
    {
        $labels = [
            'full_name' => 'Full name and surname',
            'id_number' => 'ID number',
            'marital_status' => 'Marital status',
            'spouse_name' => 'Spouse full name',
            'spouse_id' => 'Spouse ID number',
            'citizenship' => 'Citizenship',
            'current_residential_address' => 'Current residential address',
            'contact_method' => 'Email or cell number (at least one)',
            'email' => 'Email address',
            'cell' => 'Cell number',
            'work_number' => 'Work number',
            'emergency_contact_name' => 'Emergency contact name',
            'emergency_contact_cell' => 'Emergency contact cell',
            'emergency_contact_work' => 'Emergency contact work number',
            'current_living_situation' => 'Current living situation',
            'current_living_situation_notes' => 'Current living situation notes',
            'current_landlord_name' => 'Current landlord name',
            'current_landlord_tel' => 'Current landlord telephone',
            'current_rental_amount' => 'Current rental amount',
            'current_rental_from' => 'Current rental start date',
            'current_rental_due_day' => 'Rent due day of the month',
            'employer_name' => 'Employer name',
            'employer_position' => 'Employer position',
            'employer_address' => 'Employer address',
            'employer_tel' => 'Employer telephone',
            'monthly_salary' => 'Gross monthly income, before deductions',
            'employment_type' => 'Employment type',
            'occupation_date' => 'Effective date of occupation',
            'rental_term_months' => 'Rental term (6, 12 or 24 months)',
            'special_conditions' => 'Special conditions',
            'adults' => 'Number of adults',
            'children' => 'Number of children',
            'declaration_signature' => 'Declaration signature',
            'tpn_consent_signature' => self::creditBureauConsentLabel(
                \App\Models\RentalApplicationQualifyingSetting::creditBureauNameFor($agencyId)
            ),
        ];

        $registry = [];
        foreach ($labels as $key => $label) {
            $registry[] = ['key' => $key, 'label' => $label, 'group' => self::submissionFieldGroupOf($key)];
        }

        return $registry;
    }

    public static function submissionFieldGroupOf(string $key): ?string
    {
        return match (true) {
            in_array($key, self::SUBMISSION_FIELD_GROUP_EMPLOYED, true) => 'employed',
            in_array($key, self::SUBMISSION_FIELD_GROUP_RENTING, true) => 'renting',
            in_array($key, self::SUBMISSION_FIELD_GROUP_MARRIED, true) => 'married',
            default => null,
        };
    }

    /**
     * Whether a conditional group's trigger condition is currently true,
     * given the raw submitted data. Groups themselves are fixed by the
     * form's own logic — never agency-configurable — only whether a field
     * within a group is compulsory is.
     */
    public static function submissionGroupApplies(?string $group, array $data, ?int $agencyId): bool
    {
        return match ($group) {
            'employed' => ($data['employment_type'] ?? null) === 'permanently_employed',
            'renting' => ($data['current_living_situation'] ?? null) === 'renting',
            'married' => \App\Models\RentalApplicationQualifyingSetting::maritalStatusImpliesSpouseFor($agencyId, $data['marital_status'] ?? null),
            default => true,
        };
    }

    /**
     * .ai/specs/rental-application-field-config.md — the FORM SECTION a
     * field lives in (matches show.blade.php's own <section
     * data-progress-section="..."> boundaries exactly), never confused
     * with submissionFieldGroupOf()'s conditional-requirement GROUP
     * (employed/renting/married) above, which is a different axis. Section
     * is the boundary within-section ordering (§ below) respects — moving
     * a field between sections is deliberately out of scope for this
     * build, see the migration's own docblock.
     */
    public const SUBMISSION_FIELD_SECTIONS = [
        'Personal Details' => [
            'full_name', 'id_number', 'marital_status', 'spouse_name', 'spouse_id', 'citizenship',
            'contact_method', 'email', 'cell', 'work_number', 'current_residential_address',
        ],
        'Emergency Contact' => [
            'emergency_contact_name', 'emergency_contact_cell', 'emergency_contact_work',
        ],
        'Current Living Situation' => [
            'current_living_situation', 'current_landlord_name', 'current_landlord_tel',
            'current_rental_amount', 'current_rental_due_day', 'current_rental_from',
            'current_living_situation_notes',
        ],
        'Employment' => [
            'employment_type', 'employer_name', 'employer_position', 'employer_tel',
            'monthly_salary', 'employer_address',
        ],
        'Lease Requirement' => [
            'occupation_date', 'rental_term_months', 'adults', 'children', 'special_conditions',
        ],
        'Declaration' => ['declaration_signature'],
        'Tenant Profile Network Consent' => ['tpn_consent_signature'],
    ];

    public static function submissionFieldSectionOf(string $key): ?string
    {
        foreach (self::SUBMISSION_FIELD_SECTIONS as $section => $keys) {
            if (in_array($key, $keys, true)) {
                return $section;
            }
        }

        return null;
    }

    /**
     * THE one canonical resolver — .ai/specs/rental-application-field-config.md
     * §6: "there is exactly ONE resolver every consumer goes through. No
     * consumer is ever allowed its own field-iteration logic." Every
     * registry field, decorated with its resolved label/help text/shown/
     * required/within-section order for this agency. Built directly on
     * submissionFieldRegistry() (existence/default label/group) and
     * RentalApplicationQualifyingSetting's four new columns (this build)
     * plus its existing required_field_keys (unchanged, untouched).
     *
     * Within-section order resolution for a partial field_order: named
     * keys (that belong to this section) come first, in the given order;
     * every unnamed key in the section keeps its shipped registry order,
     * appended after.
     *
     * @return array<string, array{key: string, label: string, help_text: ?string, shown: bool, required: bool, order: int, group: ?string, section: ?string}>
     */
    public static function resolvedFieldConfigFor(?int $agencyId): array
    {
        $hiddenKeys = \App\Models\RentalApplicationQualifyingSetting::hiddenFieldKeysFor($agencyId);
        $requiredKeys = \App\Models\RentalApplicationQualifyingSetting::requiredFieldKeysFor($agencyId);
        $labelOverrides = \App\Models\RentalApplicationQualifyingSetting::fieldLabelOverridesFor($agencyId);
        $helpOverrides = \App\Models\RentalApplicationQualifyingSetting::fieldHelpTextOverridesFor($agencyId);
        $orderedKeys = \App\Models\RentalApplicationQualifyingSetting::fieldOrderFor($agencyId);

        // Within-section order, computed once, per §6's own docblock above.
        $orderIndex = [];
        foreach (self::SUBMISSION_FIELD_SECTIONS as $section => $sectionKeys) {
            $named = array_values(array_intersect($orderedKeys, $sectionKeys));
            $unnamed = array_values(array_diff($sectionKeys, $named));
            $resolvedSectionOrder = array_merge($named, $unnamed);
            foreach ($resolvedSectionOrder as $i => $key) {
                $orderIndex[$key] = $i;
            }
        }

        $config = [];
        foreach (self::submissionFieldRegistry($agencyId) as $field) {
            $key = $field['key'];
            $config[$key] = [
                'key' => $key,
                'label' => $labelOverrides[$key] ?? $field['label'],
                'help_text' => $helpOverrides[$key] ?? null,
                'shown' => ! in_array($key, $hiddenKeys, true),
                'required' => in_array($key, $requiredKeys, true),
                'order' => $orderIndex[$key] ?? 999,
                'group' => $field['group'],
                'section' => self::submissionFieldSectionOf($key),
                'is_custom' => false,
                'field_type' => null,
                'options' => null,
            ];
        }

        // .ai/specs/rental-application-field-config.md §7, piece (c)(2) —
        // custom fields go through this SAME resolver, merged into the
        // SAME flat array, never a parallel field-listing mechanism. All
        // under one dedicated section ('Additional Questions', not one of
        // SUBMISSION_FIELD_SECTIONS — custom fields aren't grouped under
        // any shipped section). `order` is the row's own sort_order
        // directly — no named/unnamed reconciliation needed, since every
        // custom field always has an explicit position (set on creation,
        // adjustable via reorder()), unlike a shipped field's order which
        // can be left unconfigured.
        if ($agencyId !== null && $agencyId > 0) {
            foreach (\App\Models\RentalApplicationCustomField::activeFor($agencyId) as $customField) {
                $config[$customField->key] = [
                    'key' => $customField->key,
                    'label' => $customField->label,
                    'help_text' => $customField->help_text,
                    'shown' => true, // activeFor() already excludes shown=false and retired
                    'required' => $customField->required,
                    'order' => $customField->sort_order,
                    'group' => null,
                    'section' => 'Additional Questions',
                    'is_custom' => true,
                    'field_type' => $customField->field_type,
                    'options' => $customField->options,
                ];
            }
        }

        return $config;
    }

    /**
     * RA-02's "a real person types 15,000... gets 'must be a number'" fix
     * applies just as much to a custom number-type field as to
     * monthly_salary — the keys (unprefixed, e.g. 'pet_deposit_amount')
     * sanitizeNumericInput() needs to run against $application->custom_field_values,
     * not the top-level request.
     */
    public static function customNumberFieldKeysFor(?int $agencyId): array
    {
        if ($agencyId === null || $agencyId <= 0) {
            return [];
        }

        return \App\Models\RentalApplicationCustomField::activeFor($agencyId)
            ->where('field_type', \App\Models\RentalApplicationCustomField::TYPE_NUMBER)
            ->pluck('key')
            ->all();
    }

    /**
     * §3 — historical integrity. Called exactly once, at the moment of
     * submission (never at creation, never on every autosave) — see the
     * migration's own docblock for the argument on why submission, not
     * creation, is the right freeze point.
     */
    public function snapshotFieldConfig(): void
    {
        $this->field_config_snapshot = self::resolvedFieldConfigFor($this->agency_id);
    }

    /**
     * .ai/specs/rental-application-field-config.md, staff-facing follow-up
     * 2026-09-20 — the ONE resolver every AGENT-facing consumer of THIS
     * application's own answers goes through (review.blade.php's summary,
     * the editable pre-submission capture form) — parallel to
     * resolvedFieldConfigFor() for the applicant-facing form, but aware of
     * whether THIS specific record has actually been submitted:
     *
     * - Submitted, with a frozen snapshot: the snapshot, always — never
     *   today's live settings. A config change after submission must never
     *   silently reach backward into an already-signed record.
     * - Submitted, but no snapshot (a record from before this column
     *   existed): registry defaults (resolvedFieldConfigFor(null)), not
     *   today's live agency settings either — a legacy record is rendered
     *   as it always was (no hide/label/order applied), never retroactively
     *   reshaped by config that didn't exist yet at its own submission.
     * - Not yet submitted: today's live settings — nothing is historical
     *   yet, so an agent capturing/viewing a draft sees exactly what the
     *   applicant would see right now.
     */
    public function displayFieldConfig(): array
    {
        if ($this->isSubmitted()) {
            return $this->field_config_snapshot ?? self::resolvedFieldConfigFor(null);
        }

        return self::resolvedFieldConfigFor($this->agency_id);
    }

    /**
     * Builds submit()'s full validation rule set from the agency's saved
     * $requiredKeys (RentalApplicationQualifyingSetting::requiredFieldKeysFor()).
     * A ticked field belonging to a conditional group is only enforced when
     * that group's trigger condition is true for THIS submission — a ticked
     * "Employer name" must never block a self-employed applicant. Returns
     * [rules, attributes] — attributes feeds validate()'s custom attribute
     * names so a failure reads "The Full name field is required." not "The
     * full_name field is required."
     */
    public static function submissionValidationRules(array $requiredKeys, array $data, ?int $agencyId, ?int $applicationId = null): array
    {
        $rules = self::fieldValidationRules();
        $attributes = [];

        foreach (self::submissionFieldRegistry() as $field) {
            $key = $field['key'];
            $attributes[$key] = $field['label'];

            if (in_array($key, ['contact_method', 'declaration_signature', 'tpn_consent_signature'], true)) {
                continue;
            }

            if (! isset($rules[$key]) || ! in_array($key, $requiredKeys, true)) {
                continue;
            }

            $applies = self::submissionGroupApplies($field['group'], $data, $agencyId);
            $rules[$key] = self::withRequiredIf($rules[$key], $applies);
        }

        // 'contact_method' — at least one of email/cell, when ticked. Stacks
        // with either field's OWN independent tick (both can be required
        // together with no conflict — the stricter of the two always wins
        // because Laravel evaluates every rule in the array).
        if (in_array('contact_method', $requiredKeys, true)) {
            $emailPresent = ! empty($data['email'] ?? null);
            $cellPresent = ! empty($data['cell'] ?? null);
            $rules['email'] = self::withRequiredIf($rules['email'], ! $cellPresent && ! $emailPresent);
            $rules['cell'] = self::withRequiredIf($rules['cell'], ! $emailPresent && ! $cellPresent);
        }

        // Signatures aren't in fieldValidationRules() (they're never draft-
        // saved) — built directly here. PRESENCE is settings-gated per
        // Johan's ruling (no locked set). WELL-FORMEDNESS is NOT — Johan,
        // explicit: "that one is a correctness bug and is NOT a settings
        // question." Whenever a signature value is actually present, it
        // must be a genuine, decodable, non-blank PNG regardless of
        // whether this build made its presence optional — storing garbage
        // serves nobody either way. Previously this check lived inside
        // storeSignature() and silently no-op'd on failure (the exact
        // defect this closes); now it's a real validation failure the
        // applicant sees, before anything is saved.
        $rules['declaration_signature'] = array_merge(
            in_array('declaration_signature', $requiredKeys, true) ? ['required', 'string'] : ['nullable', 'string'],
            [self::signatureWellFormedRule()],
        );
        $rules['tpn_consent_signature'] = array_merge(
            in_array('tpn_consent_signature', $requiredKeys, true) ? ['required', 'string'] : ['nullable', 'string'],
            [self::signatureWellFormedRule()],
        );

        // .ai/specs/rental-application-field-config.md §7, piece (c)(2) —
        // custom fields validate through this SAME method, never a second
        // rule-building path. required is the field's own `required`
        // column directly (no cross-mechanism reconciliation needed like
        // shipped fields' required_field_keys vs hidden_field_keys — a
        // custom field's shown and required live on the SAME row, so
        // activeFor() already excludes anything not shown before this
        // loop ever sees it: a hidden custom field can never reach here
        // as "required").
        if ($agencyId !== null && $agencyId > 0) {
            foreach (\App\Models\RentalApplicationCustomField::activeFor($agencyId) as $customField) {
                $fieldKey = 'custom_field_values.' . $customField->key;
                $attributes[$fieldKey] = $customField->label;
                $rules[$fieldKey] = self::customFieldValidationRule($customField, applicationId: $applicationId);
            }
        }

        return [$rules, $attributes];
    }

    /**
     * One rule set per RentalApplicationCustomField::FIELD_TYPES value —
     * the single place a custom field's own validation shape is decided.
     * $forceNullable — autosave() never enforces `required` (a partial,
     * still-in-progress draft must always be saveable), same posture as
     * every shipped field on that same route; true there, false at submit().
     *
     * §7, piece (c)(4) — TYPE_FILE never accepts free-typed input at all:
     * a file custom field's ONLY legitimate value is a Document id placed
     * there by uploadCustomFieldDocument()/replaceCustomFieldDocument()
     * (the main form never renders an editable input for it — see
     * show.blade.php's own file-type branch). $applicationId scopes the
     * check so a hand-crafted submission can't borrow an unrelated
     * document id and have it accepted as "the file for this question" —
     * same "never trust blindly" reasoning as every other cross-tenant
     * scoping fix already made in this module (property_id resolution in
     * RentalApplicationController::update(), the exact same bug class).
     */
    public static function customFieldValidationRule(\App\Models\RentalApplicationCustomField $customField, bool $forceNullable = false, ?int $applicationId = null): array
    {
        $requiredOrNullable = ($customField->required && ! $forceNullable) ? 'required' : 'nullable';

        return match ($customField->field_type) {
            \App\Models\RentalApplicationCustomField::TYPE_NUMBER => [$requiredOrNullable, 'numeric'],
            \App\Models\RentalApplicationCustomField::TYPE_DATE => [$requiredOrNullable, 'date'],
            \App\Models\RentalApplicationCustomField::TYPE_YES_NO => [$requiredOrNullable, 'boolean'],
            \App\Models\RentalApplicationCustomField::TYPE_CHOICE_LIST => [$requiredOrNullable, 'string', \Illuminate\Validation\Rule::in($customField->options ?? [])],
            \App\Models\RentalApplicationCustomField::TYPE_FILE => [$requiredOrNullable, function (string $attribute, $value, \Closure $fail) use ($customField, $applicationId) {
                if ($value === null || $value === '') {
                    return;
                }
                $exists = $applicationId !== null && \App\Models\Document::where('id', $value)
                    ->where('source_type', 'rental_application')
                    ->where('source_id', $applicationId)
                    ->where('custom_field_key', $customField->key)
                    ->exists();
                if (! $exists) {
                    $fail('Please upload a file for this question.');
                }
            }],
            default => [$requiredOrNullable, 'string', 'max:2000'],
        };
    }

    /** Nullable-only custom-field rules for autosave() — same field shapes, never a required gate. */
    public static function customFieldAutosaveRulesFor(?int $agencyId, ?int $applicationId = null): array
    {
        $rules = [];
        if ($agencyId !== null && $agencyId > 0) {
            foreach (\App\Models\RentalApplicationCustomField::activeFor($agencyId) as $customField) {
                $rules['custom_field_values.' . $customField->key] = self::customFieldValidationRule($customField, forceNullable: true, applicationId: $applicationId);
            }
        }

        return $rules;
    }

    private static function signatureWellFormedRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            if ($value === null || $value === '') {
                return; // absence is governed by the required/nullable rule above, not this one
            }

            $binary = self::signatureDecodedBinary($value);
            if ($binary === null) {
                $fail('Please provide a valid signature.');

                return;
            }

            if (! self::signatureHasInk($binary)) {
                $fail('Please sign before submitting — the signature pad appears to be empty.');
            }
        };
    }

    /** Decodes a signature pad's data: URI to raw PNG bytes, or null if the format/encoding is invalid. */
    public static function signatureDecodedBinary(string $dataUrl): ?string
    {
        if (! preg_match('/^data:image\/png;base64,(.+)$/', $dataUrl, $m)) {
            return null;
        }

        $binary = base64_decode($m[1], true);

        return $binary === false ? null : $binary;
    }

    /**
     * Mirrors the signature pad's own client-side check (fica/form.blade.php
     * submitForm(): any pixel with non-zero canvas alpha counts as ink) —
     * same concept, translated to GD's inverted alpha scale (0 = opaque,
     * 127 = fully transparent), so a technically-valid but never-drawn-on
     * canvas can't pass server-side just because a direct POST bypassed the
     * browser's own check entirely.
     */
    public static function signatureHasInk(string $binary): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            \Illuminate\Support\Facades\Log::warning('AT-392 signature ink check skipped — GD extension unavailable on this host, format check only.');

            return true;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return false;
        }

        imagesavealpha($image, true);
        $width = imagesx($image);
        $height = imagesy($image);
        $hasInk = false;

        for ($y = 0; $y < $height && ! $hasInk; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                if ($alpha < 127) {
                    $hasInk = true;
                    break;
                }
            }
        }

        imagedestroy($image);

        return $hasInk;
    }

    /** Swaps a base rule set's 'nullable' for a conditional 'required', preserving every other (format) rule unchanged. */
    private static function withRequiredIf(array $baseRules, bool $required): array
    {
        $rules = array_values(array_filter($baseRules, fn ($rule) => $rule !== 'nullable'));
        array_unshift($rules, $required ? 'required' : 'nullable');

        return $rules;
    }

    protected $fillable = [
        'agency_id', 'branch_id', 'contact_id', 'property_id', 'created_by_user_id',
        'status', 'delivery_mode', 'token', 'token_expires_at', 'submitted_at', 'draft_saved_at', 'submitted_for_approval_at', 'approved_rental_amount',
        // Johan, 2026-09-22 (property 4283) — same shape as
        // approved_rental_amount, captured optionally alongside it in
        // RentalApplicationAuthorisationController::approve(). Nullable:
        // the tenant-link precedence chain (view-readonly.blade.php) falls
        // back to the property's own deposit_amount when this is absent.
        'approved_deposit_amount',
        'current_generation', 'reopened_at', 'reopened_by_user_id', 'reopened_note',
        // Submission identity gate, 2026-09-13 — safe to mass-assign despite
        // being internal-tracking columns: the public form's own fill($fields)
        // call sites (submit()/autosave()) only ever pass keys drawn from
        // fieldValidationRules(), which never includes these two — an
        // applicant POSTing either field has zero effect regardless of
        // $fillable. Needed here so direct-construction test fixtures
        // (RentalApplication::create([...])) can set them without forceFill().
        'identity_verified_at', 'identity_gate_unreachable',
        'property_address_override',
        'full_name', 'id_number', 'marital_status', 'spouse_name', 'spouse_id', 'citizenship',
        'current_residential_address', 'email', 'cell', 'work_number',
        'emergency_contact_name', 'emergency_contact_cell', 'emergency_contact_work',
        'current_living_situation', 'current_living_situation_notes',
        'current_landlord_name', 'current_landlord_tel', 'current_rental_amount',
        'current_rental_from', 'current_rental_to', 'current_rental_still_living', 'current_rental_due_day',
        'employer_name', 'employer_position', 'employer_address', 'employer_tel',
        'monthly_salary', 'employment_type',
        'occupation_date', 'rental_terms', 'rental_term_months', 'special_conditions', 'adults', 'children',
        'field_config_snapshot', 'custom_field_values',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'checklist_snapshotted_at' => 'datetime',
        'submitted_at' => 'datetime',
        'identity_verified_at' => 'datetime',
        'identity_gate_unreachable' => 'boolean',
        'draft_saved_at' => 'datetime',
        'submitted_for_approval_at' => 'datetime',
        'reopened_at' => 'datetime',
        'applicant_notified_at' => 'datetime',
        'current_generation' => 'integer',
        'approved_rental_amount' => 'decimal:2',
        'approved_deposit_amount' => 'decimal:2',
        'current_rental_amount' => 'decimal:2',
        'monthly_salary' => 'decimal:2',
        'current_rental_from' => 'date',
        'current_rental_to' => 'date',
        'current_rental_still_living' => 'boolean',
        'current_rental_due_day' => 'integer',
        'occupation_date' => 'date',
        'rental_term_months' => 'integer',
        'adults' => 'integer',
        'children' => 'integer',
        'field_config_snapshot' => 'array',
        'custom_field_values' => 'array',
    ];

    /**
     * AT-392 authoriser flow — the agent has handed this off; awaiting the
     * authoriser's decision. Deliberately NOT a status value (see
     * AGENT_SETTABLE_STATUSES comment) — status stays 'under_assessment',
     * this marker is what actually distinguishes the two states.
     */
    public function isPendingAuthorisation(): bool
    {
        return $this->status === 'under_assessment' && $this->submitted_for_approval_at !== null;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(RentalApplicationSignature::class);
    }

    /** Reopen/resubmit — the sealed, read-only history of every submission round, newest first. */
    public function generations(): HasMany
    {
        return $this->hasMany(RentalApplicationGeneration::class)->orderByDesc('generation');
    }

    /**
     * Reopen/resubmit — signatures belonging to the CURRENT generation only.
     * Johan: "the applicant's earlier signatures do not carry over — if
     * declared content changes, they must sign the new declaration." A
     * signature from a superseded generation still exists in the database
     * (never overwritten, never deleted — see the signatures-table
     * migration), it simply stops being "the" signature the moment a newer
     * generation exists. Every place that shows "has the applicant signed
     * this" reads through here, never the raw signatures() relation, so
     * that rule can't drift out of sync between call sites.
     */
    public function currentSignatures()
    {
        return $this->signatures()->where('generation', $this->current_generation);
    }

    /**
     * Supporting documents filed through the SHARED documents table
     * (source_type='rental_application', source_id=$this->id) — the same
     * convention every other filed document uses. No parallel documents table.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'source_id')->where('source_type', 'rental_application');
    }

    /**
     * Johan, from his own live walk, 2026-09-21 — an approved application
     * linked to a property and a lease still read as merely "Approved"
     * everywhere. The fact already exists in the data (Lease::
     * rental_application_id); nothing new needed inventing there.
     *
     * Deliberately NOT a new value on `status` — approved is the decision,
     * made once, and every historical field_config_snapshot/generation
     * record this whole module builds on depends on a past decision never
     * being reinterpreted by a later, unrelated fact. This is a further
     * state the application has reached, derived fresh from Lease.status
     * every time it's asked, never cached, never a flag that has to be
     * remembered and cleared — which is exactly how the module got here:
     * Contact::rental_application_status IS a cache (RecomputeRental
     * ApplicationStatus), and nothing tells it a lease happened, so it
     * never moves off "approved". Deriving live means a lease ending,
     * being cancelled, or a tenant being replaced (Lease::previous_lease_id/
     * renewed_lease_id — a new lease, new row, own status) all correctly
     * fall back out of "tenanted" the moment Lease.status leaves 'active',
     * with nothing to unset by hand anywhere.
     */
    public function activeLease(): HasOne
    {
        return $this->hasOne(Lease::class, 'rental_application_id')->where('status', Lease::STATUS_ACTIVE);
    }

    /** True only for an approved application currently linked to an active lease — see activeLease(). */
    public function isTenanted(): bool
    {
        return $this->status === 'approved' && $this->activeLease()->exists();
    }

    /** The one agency-configurable label used identically everywhere this state is shown. */
    public function tenantedLabel(): string
    {
        return \App\Models\RentalApplicationQualifyingSetting::tenantedLabelFor($this->agency_id);
    }

    /**
     * AT-392 — "pull from contact": a document already on file against the
     * applicant's contact (FICA, a prior application) attached here WITHOUT
     * re-filing it — source_type/source_id stays wherever the document was
     * originally filed. Mirrors Document::contacts()/properties() exactly.
     */
    public function referencedDocuments(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'rental_application_document')
            ->withPivot('attached_by')
            ->withTimestamps();
    }

    /** AT-392 — the status change trail, newest first. */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(RentalApplicationStatusHistory::class)->latest('created_at');
    }

    /** AT-392 authoriser flow — the fuller who/what/when/why/override audit trail, newest first. */
    public function auditLog(): HasMany
    {
        return $this->hasMany(RentalApplicationAuditLog::class)->latest('created_at');
    }

    /**
     * Reopen/resubmit — filtered to the CURRENT generation (see
     * currentSignatures()). Was `$this->signatures->firstWhere(...)` against
     * the whole relation; now that a reopened-and-resubmitted application
     * can carry more than one signature per kind (one per generation), that
     * would risk showing a stale, superseded signature as if it were current.
     */
    public function declarationSignature(): ?RentalApplicationSignature
    {
        return $this->currentSignatures()->get()->firstWhere('kind', 'declaration');
    }

    public function tpnConsentSignature(): ?RentalApplicationSignature
    {
        return $this->currentSignatures()->get()->firstWhere('kind', 'tpn_consent');
    }

    public function isFullySigned(): bool
    {
        return $this->currentSignatures()->whereIn('kind', ['declaration', 'tpn_consent'])->count() === 2;
    }

    /**
     * Johan, QA1 bug — "moans no email correctly, but adding and saving do
     * not persist." Root cause: two separate email values existed on one
     * screen. This field (`rental_applications.email`) is the ONLY one the
     * agent can see or edit (show.blade.php's "Email address" field), and
     * it was saving correctly all along. send()/sendInvite() instead always
     * read `contact->email` — a different column the agent has no way to
     * touch from this screen — so an agent who typed a correction here saw
     * it "not stick" because nothing that could actually send an email ever
     * consulted this field. Single choke point so send() and the mailer can
     * never independently drift back out of sync with each other.
     */
    public function recipientEmail(): ?string
    {
        return $this->email ?: $this->contact?->email;
    }

    /**
     * Johan, 2026-09-07 — "submitted docs are submitted. they can add, but
     * not replace or remove." This is the single source of truth for "has
     * this application already been submitted" — every submitted-lock check
     * (document replace/remove, the UI copy explaining why) reads through
     * here so the rule can't drift between call sites in a later refactor.
     * `submitted_at` is set exactly once, in submit()'s transaction, and
     * never cleared — equivalent to (and simpler than) re-checking the
     * status enum's "returned or later" set used elsewhere in this flow.
     */
    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    /**
     * AT-392 round 2, 2026-09-13 — cc3's finding while investigating the
     * withdraw control: uploadDocuments()/removeDocument()/
     * replaceDocument() never checked status at all, so a withdrawn or
     * declined application's public link kept accepting files
     * indefinitely — a public, unauthenticated write into agency storage
     * with no closing condition. Johan's ruling: these two are ALWAYS
     * closed, no agency override — reopen() (same gate as "Send back to
     * applicant") is the only door back in, exactly as designed for
     * these two statuses already.
     */
    public const DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES = ['withdrawn', 'declined'];

    /**
     * Approved is deliberately NOT in the always-closed list above —
     * Johan: "an agency may legitimately ask an approved tenant for one
     * more document," so this stays an agency setting (default open) via
     * RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor().
     */
    public function documentUploadsOpen(): bool
    {
        if (in_array($this->status, self::DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES, true)) {
            return false;
        }

        if ($this->status === 'approved') {
            return RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor((int) $this->agency_id);
        }

        return true;
    }

    /**
     * Johan, verbatim: "it must not read as a dead end... does not imply
     * they have done something wrong." Deliberately the SAME wording for
     * withdrawn and declined (no blame language either way) and names
     * the actual way back — reopen() — rather than leaving the applicant
     * with nowhere to go. Null when uploads are open (nothing to say).
     */
    public function documentUploadsClosedMessage(): ?string
    {
        if (in_array($this->status, self::DOCUMENT_UPLOADS_ALWAYS_CLOSED_STATUSES, true)) {
            return "This application is currently closed and not accepting new documents. If you have something to add, please contact your agent — they're able to reopen your application for you.";
        }

        if ($this->status === 'approved' && ! RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor((int) $this->agency_id)) {
            return 'Your application has been approved and is no longer accepting new documents. If you need to send something else, please contact your agent.';
        }

        return null;
    }

    /**
     * FICA-mandatory, AT-392 round 3, 2026-09-13 — reads the SAME status
     * `Contact::ficaStatus()` already computes and the SAME badge the
     * Contact page already shows (Complete/Expiring/Incomplete) — no new
     * expiry policy invented for rentals, no second FICA anything. A
     * repeat tenant with a complete, unexpired FICA on file from any past
     * transaction reads as already done here too (that table has never
     * been scoped to a single deal/transaction — checked, not assumed).
     */
    public function ficaOutstanding(): bool
    {
        return $this->contact?->ficaStatus() !== 'complete';
    }

    /**
     * ficaOutstanding() alone can't tell "never started" from "submitted,
     * waiting on our own staff to review it" — Contact::ficaStatus() only
     * has three buckets (complete/expiring/incomplete) and every
     * not-yet-approved FicaSubmission status falls into 'incomplete', by
     * design, everywhere else in the app too (checked, not assumed — this
     * is not a rentals-specific gap). For the APPLICANT-facing message
     * specifically, telling someone who already submitted their FICA form
     * to "contact your agent" would be actively wrong — the ball is in
     * OUR court at that point, not theirs. This reads the actual
     * FicaSubmission row (not just the collapsed badge) to tell those two
     * cases apart in the one place it actually changes what an applicant
     * should be told to do.
     */
    public function ficaAwaitingApplicantAction(): bool
    {
        if (! $this->ficaOutstanding()) {
            return false;
        }

        $latest = $this->latestFicaSubmission();

        return $latest === null || in_array($latest->status, ['draft', 'rejected', 'corrections_requested'], true);
    }

    /**
     * Return leg, AT-392 round 5, 2026-09-13 — the applicant's own
     * already-submitted page needs the actual FicaSubmission (not just a
     * bool) to hand back a working "continue FICA" link — the same lookup
     * ficaAwaitingApplicantAction() already does, exposed so the controller
     * doesn't run it twice.
     */
    public function latestFicaSubmission(): ?\App\Models\FicaSubmission
    {
        if ($this->contact_id === null) {
            return null;
        }

        return \App\Models\FicaSubmission::where('contact_id', $this->contact_id)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /**
     * Submission identity gate, 2026-09-13 — mirrors ficaAwaitingApplicantAction()'s
     * own shape deliberately (Johan: "reusing the existing shape means the
     * agent learns one pattern, not two"). True only in the narrow window
     * between a first-ever submission firing the gate and the applicant
     * actually resolving it — never true once identity_verified_at is set,
     * never true when the gate found nothing to check against
     * (identity_gate_unreachable — see identityVerificationUnreachable()
     * below, a DIFFERENT agent-facing state with a different action), and
     * never true for an agency that has the gate switched off.
     */
    public function identityVerificationAwaitingApplicantAction(): bool
    {
        return $this->isSubmitted()
            && $this->identity_verified_at === null
            && ! $this->identity_gate_unreachable
            && \App\Models\RentalApplicationQualifyingSetting::identityGateEnabledFor($this->agency_id);
    }

    /**
     * Johan's ruling, 2026-09-13: an applicant with neither email nor an ID
     * number on file (both legitimately agency-optional) is let THROUGH,
     * never blocked — "the person who gets stopped is the one who cannot
     * fix it." This is the agent's own signal to chase it themselves.
     */
    public function identityVerificationUnreachable(): bool
    {
        return (bool) $this->identity_gate_unreachable;
    }

    /**
     * Reopen/resubmit — Johan: "agent has review screen open, applicant
     * resubmits mid-review... reuse the exact 409-conflict pattern you
     * already built and shipped for document marks." A review screen loads
     * showing `current_generation` at that moment; every agent-side write
     * that could act on stale content passes it back as $expectedGeneration
     * and calls this first. Mismatch (the applicant reopened-and-resubmitted
     * since the page loaded) throws, caught by the controller into the same
     * 409 JSON shape HandlesRentalApplicationDocumentMarks::applyHighlight()
     * already returns for a marks-version conflict.
     */
    public function assertGenerationMatches(?int $expectedGeneration): void
    {
        if ($expectedGeneration !== null && $expectedGeneration !== $this->current_generation) {
            throw new \App\Exceptions\RentalApplicationGenerationConflictException($this->current_generation);
        }
    }

    /**
     * Johan, 2026-09-07 — "we always need proper crud... search / sort /
     * own / branch / agency levels. that should be the design standard...
     * from the word go." Own/branch/agency visibility, enforced at the
     * query layer (never by hiding a link). Mirrors
     * Docuperfect\Document::scopeVisibleTo() EXACTLY — same
     * PermissionService::getDataScope() resolution, same three branches —
     * so LIST and single-record access (see
     * AuthorizesRentalApplicationAccess) can never disagree. 'own' here is
     * the CREATING agent (created_by_user_id), this module's equivalent of
     * Document's owner_id.
     *
     * 2026-09-08 — index-screen scope FILTER (own/branch/agency toggle) added
     * an optional $requestedScope, clamped to the user's actual permitted
     * ceiling via clampScope() below — copied verbatim from
     * DealV2::scopeVisibleTo()/clampScope(), the established pattern for
     * exactly this ("a user must not be able to widen scope by editing the
     * URL beyond their permission"). $requestedScope defaults to null, which
     * preserves every existing caller's behaviour unchanged (ceiling only,
     * no narrowing) — this is additive, not a behaviour change for anyone
     * who doesn't pass the new argument.
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $scope = self::clampScope($requestedScope, \App\Services\PermissionService::getDataScope($user, 'rental_applications'));

        return self::applyVisibilityScope($query, $scope, $user);
    }

    /**
     * AT-392 — the Contact page's Rental History tab. Johan: "agency
     * wide... add to role manager where this can be set." A genuinely
     * independent scope from scopeVisibleTo()'s own rental_applications.view
     * ceiling (see PermissionService::contactRentalHistoryScope()'s own
     * docblock for why they must not share a ceiling), reusing the exact
     * SAME filtering branches via applyVisibilityScope() below — never a
     * parallel filtering implementation.
     */
    public function scopeVisibleForContactHistory($query, User $user)
    {
        $scope = \App\Services\PermissionService::contactRentalHistoryScope($user);

        return self::applyVisibilityScope($query, $scope, $user);
    }

    /**
     * The three own/branch/all branches shared by every visibility scope
     * on this model — extracted out of scopeVisibleTo() so
     * scopeVisibleForContactHistory() reuses the identical filtering
     * logic rather than a second copy that could drift. Behaviour of
     * scopeVisibleTo() itself is unchanged by this extraction.
     */
    private static function applyVisibilityScope($query, string $scope, User $user)
    {
        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            // 2026-09-08, found while proving the sort columns over real HTTP
            // (not by reading the blade): unqualified 'branch_id' throws
            // SQLSTATE 1052 "ambiguous" the moment this query is combined
            // with a LEFT JOIN to any table that ALSO has a branch_id column
            // — contacts, users, AND properties all do. That is every join
            // the index screen's own sort-by-Applicant/Agent/Property
            // columns already add. Pre-existing for sort=contact/property;
            // this task's new sort=agent hit the exact same class. Table-
            // qualified here, once, fixes all of them.
            return $query->where('rental_applications.branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            // Same reasoning — contacts also has created_by_user_id.
            return $query->whereIn('rental_applications.created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Narrow the requested scope to at most the permitted scope. Ranking
     * own(1) < branch(2) < all(3); "agency" (the UI's label for this
     * screen's toggle) is an alias for "all". Returns the MIN of requested
     * and permitted, so a branch manager asking for "agency" via the URL
     * still gets "branch", and any junk/missing value falls back to
     * permitted. Copied from DealV2::clampScope() — same ranking, same
     * fallback shape — deliberately not extracted into a shared trait as
     * part of this narrowly-scoped build; a future consolidation is a
     * separate decision.
     */
    public static function clampScope(?string $requested, ?string $permitted): string
    {
        $rank = ['own' => 1, 'branch' => 2, 'all' => 3, 'agency' => 3, 'company' => 3];
        $permitted = $permitted ?: 'own';
        $permRank = $rank[$permitted] ?? 1;
        if ($requested === null || ! isset($rank[$requested])) {
            return in_array($permitted, ['agency', 'company'], true) ? 'all' : $permitted;
        }
        $effRank = min($rank[$requested], $permRank);

        return array_search($effRank, ['own' => 1, 'branch' => 2, 'all' => 3], true) ?: 'own';
    }
}
