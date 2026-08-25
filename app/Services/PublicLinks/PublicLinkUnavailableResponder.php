<?php

namespace App\Services\PublicLinks;

use App\Models\Agency;
use App\Models\User;

/**
 * The ONE shared "valid link, dead resource" response builder for every
 * public, unauthenticated, token-addressed page in CoreX (Johan, 2026-08-25
 * — "reuse it — one shared view/handler, not five copies"). Agency-branded,
 * names the real reason, always offers a route back to a human — built for
 * the seller live link, forward-ported from Staging (b8a34a6f6 / c6b3a2ebb)
 * to QA1 on 2026-08-25.
 *
 * Forward-port note: Staging's version constructor-injects
 * App\Services\Leads\SharedLinkReengagementService for its
 * agencyFallbackContact() helper — that service does not exist on QA1 and
 * porting it was out of scope for this change (single-file, single-purpose
 * fallback lookup, not otherwise depended on here). Its exact 2-line logic
 * (website_contact_phone/email, falling back to phone/email) is inlined
 * below instead of importing the class, so behaviour is identical without
 * adding an unrelated file to this change. Flagging in case Staging's
 * SharedLinkReengagementService ever needs porting for its OWN sake later —
 * this is not that.
 *
 * Deliberately NOT for the "genuinely unknown token" branch of the 3-branch
 * policy — that branch has no agency to resolve and must stay wording-generic
 * so a token-prober can't tell "wrong token" from "revoked token" apart. This
 * responder is for the OTHER branch: a real record resolved and is dead for
 * a reason worth naming (expired, revoked, sold, switched off, ...) — where
 * showing agency branding is safe because the record's own existence is what
 * makes the branding resolvable at all.
 */
class PublicLinkUnavailableResponder
{
    /**
     * @param int|null $agencyId Resolve branding/contact from this agency. Null
     *   renders the card with CoreX-neutral styling only — used when even the
     *   dead record itself carries no agency_id.
     * @param string $title Honest, mode-specific heading — never generic
     *   filler shared across reasons that mean different things.
     * @param string $body One or two sentences naming what actually happened.
     * @param User|null $agent A specific still-active agent to show as the
     *   contact, when one is resolvable and appropriate for this link. Falls
     *   back to the agency's own contact details when null or inactive.
     * @param array{label:string,url:string}|null $primaryAction An optional
     *   CTA above the contact row (e.g. "View current listings").
     * @param int $status HTTP status — 410 for "used to work, doesn't now"
     *   (the normal case here), 404 only when nothing about this resource
     *   was ever addressable at all.
     */
    public function respond(
        ?int $agencyId,
        string $title,
        string $body,
        ?User $agent = null,
        ?array $primaryAction = null,
        int $status = 410,
    ) {
        $agency = $agencyId ? Agency::withoutGlobalScopes()->find($agencyId) : null;

        $showAgent = $agent && $agent->is_active && $agent->deleted_at === null;
        $fallback = $agency
            ? ['phone' => $agency->website_contact_phone ?: $agency->phone, 'email' => $agency->website_contact_email ?: $agency->email]
            : ['phone' => null, 'email' => null];

        return response()->view('public.shared._link-unavailable', [
            'title'         => $title,
            'body'          => $body,
            'agency'        => $agency,
            'agent'         => $showAgent ? $agent : null,
            'fallbackPhone' => $fallback['phone'],
            'fallbackEmail' => $fallback['email'],
            'primaryAction' => $primaryAction,
        ], $status);
    }
}
