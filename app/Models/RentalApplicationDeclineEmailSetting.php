<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * AT-392 authoriser flow — Johan: "each agency will want their own wording
 * on declined." Same Rule-17-safe shape as RentalApplicationQualifyingSetting
 * — forAgency() never writes on read, only returns the suggested default
 * text until an agency actually saves their own wording.
 *
 * Merge fields are deliberately limited to what a decline email can ALWAYS
 * honestly populate: the applicant's name, the agency's name, and
 * (optionally, 2026-09-08) which property the application was for — an
 * applicant may have several applications running at once and already
 * knows what they applied for, so this isn't internal information.
 *
 * AT-410b, 2026-09-15 — "no reasons" above is now superseded: Johan wants
 * the authoriser to pick a reason-plus-guidance template
 * (cc2's build — the template CRUD/model) at the moment of declining, and
 * that content now merges into THIS SAME envelope via two new placeholders
 * rather than forking a second wording system next to it. This class still
 * owns the agency's greeting/thanks/sign-off tone; it now also owns where
 * the picked reason and guidance land inside that tone. cc2's templates
 * are pure data — id/label/reason/guidance — read once by the authoriser's
 * decline() action and merged here; nothing about the template CRUD itself
 * lives in this file.
 */
class RentalApplicationDeclineEmailSetting extends Model
{
    use BelongsToAgency;

    protected $fillable = ['agency_id', 'subject', 'body'];

    /**
     * Suggested default — Johan asked for "plain, respectful and not cold."
     * Deliberately does NOT include "how to improve your history" guidance
     * — he was explicit that's still an open idea, not settled, not mine to
     * write. Coordinator-approved wording, 2026-09-08 — two rounds of
     * review: dropped "if you have any questions about this decision" (it
     * invites arguing the decision) and "this isn't a reflection of you
     * personally" (it plants the very doubt it's trying to avoid) — kept
     * only "we'd welcome an application from you again in future."
     */
    public const DEFAULT_SUBJECT = 'Your rental application — {{agency_name}}';

    /**
     * AT-410b — {{decline_guidance}} added between the "sorry" line and the
     * "welcome again" close, the exact spot Johan's own example ("on
     * affordability - to increase your affordability the general tips
     * are...") reads as a natural paragraph, not a bolted-on appendix.
     * cc2's own template content (RentalApplicationDeclineReasonTemplate::
     * $guidance) is written as ONE self-contained paragraph that already
     * covers both of Johan's "two parts" (why, then what helps) — e.g. "The
     * application didn't meet our affordability guideline this time.
     * General tips that help going forward: ..." — so only this one
     * placeholder sits in the default prose. {{decline_reason}} (the
     * template's short label, e.g. "Affordability") is NOT in the default
     * body — a short category label read mid-sentence ("...on this
     * occasion. Affordability") doesn't parse as English; it stays
     * available in render() below for an agency that wants to reference it
     * explicitly in their own custom wording (e.g. a "Reason: ..." line),
     * it's simply not forced into the suggested default. An agency saving
     * custom wording is free to move, reword around, or drop either
     * placeholder — render() only replaces what's present in whatever
     * template ends up stored.
     */
    public const DEFAULT_BODY = <<<'TEXT'
Dear {{applicant_name}},

{{property_reference}}Thank you for applying to rent through {{agency_name}}, and for the time you put into your application.

We're sorry to let you know that we won't be able to proceed with it on this occasion.

{{decline_guidance}}

We'd welcome an application from you again in future, should a suitable property come up.

We wish you well in your search for a home.

Kind regards,
{{agency_name}}
TEXT;

    /** @return array{subject: string, body: string} */
    public static function forAgency(?int $agencyId): array
    {
        $row = $agencyId ? static::where('agency_id', $agencyId)->first() : null;

        return [
            'subject' => $row?->subject ?: self::DEFAULT_SUBJECT,
            'body' => $row?->body ?: self::DEFAULT_BODY,
        ];
    }

    /**
     * Only fields this email can ALWAYS honestly populate — see class
     * docblock. $propertyReference is OPTIONAL (2026-09-08 — an applicant
     * may have several applications running at once and needs to know
     * which one this is about; that's information they already have, not
     * an internal detail). Rendered as a self-contained "Re:" line so the
     * default body reads correctly whether or not it's supplied — when
     * null/empty the placeholder resolves to nothing, not a dangling label
     * or blank line, and an agency's own custom wording is free to omit
     * the placeholder entirely.
     *
     * $declineReason/$declineGuidance (AT-410b) are the authoriser-picked
     * template's own two fields, already resolved to plain text by the
     * caller (RentalApplicationAuthorisationController::decline()) —
     * this method never reaches into cc2's template model itself, it only
     * splices whatever text it's handed into the two new placeholders.
     * Optional here for the same reason $propertyReference is: a decline
     * recorded before this feature existed, or an agency's own custom body
     * that omits one of the two placeholders, must still render cleanly.
     */
    public static function render(
        string $template,
        string $applicantName,
        string $agencyName,
        ?string $propertyReference = null,
        ?string $declineReason = null,
        ?string $declineGuidance = null,
    ): string {
        return strtr($template, [
            '{{applicant_name}}' => $applicantName,
            '{{agency_name}}' => $agencyName,
            '{{property_reference}}' => $propertyReference ? "Re: {$propertyReference}\n\n" : '',
            '{{decline_reason}}' => $declineReason ?? '',
            '{{decline_guidance}}' => $declineGuidance ?? '',
        ]);
    }

    /**
     * AT-410b — the single place that builds the full, merged, human-
     * editable decline-email draft (subject + body, every placeholder
     * resolved to real text). Called ONCE, at the moment the authoriser
     * declines (RentalApplicationAuthorisationController::decline()), and
     * stored on the application row (rental_applications.decline_email_
     * subject/body) for the agent to read and edit — never re-derived at
     * send time, so what the agent edits is exactly what was drafted from,
     * and what she sends is exactly what gets recorded as having gone out.
     *
     * @return array{subject: string, body: string}
     */
    public static function draftFor(RentalApplication $application, string $declineReason, string $declineGuidance): array
    {
        $applicantName = $application->contact->full_name ?: 'there';
        $agencyName = $application->agency->name ?? config('mail.from.name', 'CoreX OS');
        $propertyReference = $application->property?->buildDisplayAddress() ?: $application->property_address_override ?: null;

        $wording = self::forAgency((int) $application->agency_id);

        return [
            'subject' => self::render($wording['subject'], $applicantName, $agencyName, $propertyReference, $declineReason, $declineGuidance),
            'body' => self::render($wording['body'], $applicantName, $agencyName, $propertyReference, $declineReason, $declineGuidance),
        ];
    }
}
