<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Events\SellerOutreach\TemplateConfigured;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use App\Services\SellerOutreach\SellerOutreachTemplateValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AT-47 — HFC's two approved consent-request WhatsApp templates.
 *
 * Idempotent: updateOrCreate keyed on (agency_id, channel, name). Runs the SAME
 * validation (SellerOutreachTemplateValidator) and audit (TemplateConfigured
 * event) path as the admin UI — NOT a back-door insert. Both templates carry no
 * live-demand tracking link (include_tracking_link = false, AT-46) and end with
 * the mandatory STOP opt-out clause.
 *
 * AT-48 — the footer now carries the company FFC ({agency_ffc}), the sending
 * agent's FFC ({agent_ffc}, optional {?agent_ffc}...{/agent_ffc} segment that
 * collapses when blank) and the branch-then-company tel ({branch_or_company_tel}),
 * replacing the PPRA-reg / public-contact pairing shipped in AT-46/AT-47.
 *
 * AT-49 — the footer now leads with {opt_out_link}, the per-send one-tap
 * self-service marketing opt-out (mandatory on every outreach template; the
 * real URL is substituted by SellerOutreachSenderService at send time). The
 * STOP keyword is retained alongside it.
 *
 * Depends on AT-46 (include_tracking_link column + agency merge fields).
 *
 * Run: php artisan db:seed --class=HfcConsentTemplatesSeeder
 */
final class HfcConsentTemplatesSeeder extends Seeder
{
    private const AGENCY_ID = 1; // Home Finders Coastal

    /**
     * AT-47c — seed BOTH channels. The email variant carries the SAME consent
     * copy (with "via WhatsApp" → "via email") plus a Subject, so toggling the
     * composer to Email pre-fills a populated, branded message. This only adds
     * the template ROWS — the single email SEND path stays the OutreachEmail
     * Mailable (this seeder does not touch delivery).
     */
    private const CHANNELS = [
        SellerOutreachTemplate::CHANNEL_WHATSAPP,
        SellerOutreachTemplate::CHANNEL_EMAIL,
    ];

    public function run(): void
    {
        if (! DB::table('agencies')->where('id', self::AGENCY_ID)->exists()) {
            $this->command?->warn('Agency ' . self::AGENCY_ID . ' (HFC) not found — skipping consent templates seeder.');
            return;
        }

        $validator = new SellerOutreachTemplateValidator();

        // System actor for the audit trail — agency owner/admin if one exists, else null.
        $actorUserId = User::withoutGlobalScopes()
            ->where('agency_id', self::AGENCY_ID)
            ->whereIn('role', ['owner', 'super_admin'])
            ->orderBy('id')
            ->value('id');

        foreach ($this->templates() as $tpl) {
            foreach (self::CHANNELS as $channel) {
                $isEmail = $channel === SellerOutreachTemplate::CHANNEL_EMAIL;

                // Bodies now name the channels inline ("WhatsApp, SMS or email"),
                // so the copy is channel-neutral — email reuses the SAME body and
                // only adds a Subject. (The old "via WhatsApp"→"via email" swap is
                // retired; there is no per-channel word to substitute anymore.)
                $body    = $tpl['body'];
                $subject = $isEmail ? $tpl['email_subject'] : null;

                // SAME validation the admin UI runs. include_tracking_link = false →
                // {tracking_link} not required; {opt_out_link} + STOP stay mandatory,
                // and email additionally requires a non-empty subject.
                $result = $validator->validate($channel, $subject, $body, includeTrackingLink: false);
                if ($result->fails()) {
                    throw new \RuntimeException(
                        "HFC consent template '{$tpl['name']}' ({$channel}) failed validation: " . json_encode($result->errors)
                    );
                }

                // Keep the admin UI's one-default-per-channel invariant (per channel).
                if ($tpl['is_default_for_channel']) {
                    SellerOutreachTemplate::withoutGlobalScopes()
                        ->where('agency_id', self::AGENCY_ID)
                        ->where('channel', $channel)
                        ->where('is_default_for_channel', true)
                        ->where('name', '!=', $tpl['name'])
                        ->update(['is_default_for_channel' => false]);
                }

                // withoutGlobalScopes() also drops the SoftDeletes scope, so a prior
                // soft-deleted row is matched and reactivated rather than duplicated.
                $template = SellerOutreachTemplate::withoutGlobalScopes()->updateOrCreate(
                    [
                        'agency_id' => self::AGENCY_ID,
                        'channel'   => $channel,
                        'name'      => $tpl['name'],
                    ],
                    [
                        'subject'                => $subject,
                        'body'                   => $body,
                        'description'            => $tpl['description'],
                        'is_active'              => $tpl['is_active'],
                        'is_default_for_channel' => $tpl['is_default_for_channel'],
                        'include_tracking_link'  => false,
                    ]
                );

                if ($template->trashed()) {
                    $template->restore();
                }

                // SAME audit event the admin create/update path fires.
                event(new TemplateConfigured(
                    template:    $template,
                    action:      $template->wasRecentlyCreated ? TemplateConfigured::ACTION_CREATED : TemplateConfigured::ACTION_UPDATED,
                    actorUserId: $actorUserId,
                    agencyId:    self::AGENCY_ID,
                ));

                $this->command?->info(
                    ($template->wasRecentlyCreated ? 'Created' : 'Updated')
                    . " {$channel} template '{$template->name}' (id {$template->id})."
                );
            }
        }
    }

    /**
     * @return array<int, array{name: string, is_active: bool, is_default_for_channel: bool, description: string, email_subject: string, body: string}>
     */
    private function templates(): array
    {
        return [
            // ── The two ORIGINAL consent templates, EDITED ───────────────────
            // (a) channels named "WhatsApp, SMS or email"; (b) OPT IN / OPT OUT
            // only — "just ignore this" non-response wording removed; the STOP
            // keyword dropped in favour of OPT OUT (the validator accepts both);
            // (c) no emojis/hashtags. The {opt_out_link} one-tap link (AT-49,
            // POPIA) is retained. Same name → updateOrCreate edits in place; the
            // TemplateConfigured UPDATED event is the audit record of the change,
            // and every prior SEND keeps its own immutable body_snapshot.
            [
                'name' => 'General Marketing — Area Updates',
                'is_active' => true,
                'is_default_for_channel' => true,
                'description' => 'Consent request — invite the seller to receive area market + buyer-demand updates.',
                'email_subject' => '{property_suburb} market & buyer-demand updates from {agency_name}',
                'body' => <<<'TXT'
Hi {seller_name}, this is {agent_name} from {agency_name} — a registered estate agency on the KZN South Coast.
We track live buyer demand, recent sales and property values for {property_suburb}. With your permission, we'd send you these area updates so you always know what your property could be worth and who's looking to buy near you.
May we contact you with {property_suburb} market and buyer-demand updates by WhatsApp, SMS or email?
- Reply OPT IN and we'll be in touch
- Reply OPT OUT and we won't contact you again
Manage your preferences or opt out anytime: {opt_out_link}.
{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.
TXT,
            ],
            [
                'name' => 'Buyer Demand Marketing',
                'is_active' => true,
                'is_default_for_channel' => false,
                // AT-145 — the buyer claim is no longer hardcoded prose. It now
                // states the REAL canonical count of active buyers matching this
                // property via the {?matching_buyer_count}…{/matching_buyer_count}
                // conditional token; the composer's no_buyers send-gate blocks the
                // send when that count is 0, and the segment collapses in
                // address-only mode (no per-property claim). Subject kept
                // demand-neutral so it never over-claims when the segment collapses.
                'description' => 'Consent request — buyer demand. States the REAL canonical count of active buyers matching this property ({matching_buyer_count} token, AT-145); the composer blocks the send at zero (no_buyers).',
                'email_subject' => 'About your {property_suburb} property — {agency_name}',
                'body' => <<<'TXT'
Hi {seller_name}, I saw your property in {property_suburb} on the market. I'm {agent_name} from {agency_name} — a registered estate agency.
{?matching_buyer_count}I have {matching_buyer_count} active buyer(s) currently looking for a property like yours in {property_suburb}, and your property may suit them. {/matching_buyer_count}With your permission, I'd like to send you the details and discuss your property with you.
May I contact you about this by WhatsApp, SMS or email?
- Reply OPT IN and I'll share what buyers near you are looking for
- Reply OPT OUT and I won't contact you again
Manage your preferences or opt out anytime: {opt_out_link}.
{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.
TXT,
            ],

            // ── 5 NEW agent-selectable variations ─────────────────────────────
            // Johan's copy, {{tokens}} translated to CoreX syntax: {{title}}
            // dropped (no salutation column) → greet by {seller_surname};
            // {{agent_name}}→{agent_name}; {{property.address}}→{property_address}
            // (the mandatory linked-property anchor — the blank-address gate in
            // SellerOutreachComposerService blocks any send where this is empty);
            // {{suburb}}→{property_suburb}. AT-142: the hardcoded "Full Status
            // Property Practitioner" is replaced by {agent_designation} (admin-
            // managed users.designation) so the message states the agent's REAL
            // designation truthfully; the composer blocks a send when that
            // designation is blank (no_designation) or, if the agency restricts
            // consent outreach to full-status, when the agent is not full-status.
            // Each carries the mandatory {opt_out_link} footer (AT-49). A, B, D, E
            // ship ACTIVE; C (buyer-led) ships INACTIVE pending a buyer-feed audit.
            [
                'name' => 'Complimentary Services — Homeowner Intro',
                'is_active' => true,
                'is_default_for_channel' => false,
                'description' => 'Consent request (A) — complimentary services intro for homeowners in the suburb.',
                'email_subject' => 'Complimentary property services in {property_suburb}',
                'body' => <<<'TXT'
Good day, {seller_surname}.

I hope you're doing well. My name is {agent_name}, a {agent_designation} (PPRA registered) with {agency_name}. I'm reaching out regarding your property at {property_address}.

From time to time I share complimentary property-related services with homeowners in {property_suburb} — market insights, a market-related valuation, professional photography and video, and guidance on selling or renting in the current market.

If you'd be happy for me to be in touch by WhatsApp, SMS or email, simply reply OPT IN and I'll reach out when it's relevant. If you'd prefer not to be contacted, reply OPT OUT and I'll respect that.

Wishing you a wonderful day.

Kind regards,
{agent_name}
{agent_designation} | PPRA Registered
{agency_name}
Manage your preferences or opt out anytime: {opt_out_link}
TXT,
            ],
            [
                'name' => 'Soft Introduction — Future Seller',
                'is_active' => true,
                'is_default_for_channel' => false,
                'description' => 'Consent request (B) — soft introduction, future-seller framing (no obligation).',
                'email_subject' => 'A note about your {property_suburb} property',
                'body' => <<<'TXT'
Good day, {seller_surname}. I hope you're well.

My name is {agent_name} from {agency_name}, a PPRA-registered agency on the KZN South Coast. I'm reaching out regarding your property at {property_address}, to introduce our services should you ever consider selling — now or down the line.

There's no obligation at all. It would simply be my pleasure to offer a complimentary, market-related valuation and share what's happening in your local market.

If you'd like me to be in touch by WhatsApp, SMS or email, reply OPT IN. If you'd rather not, reply OPT OUT and I won't contact you about this again.

Kind regards,
{agent_name}
{agent_designation} | PPRA Registered
{agency_name}
Manage your preferences or opt out anytime: {opt_out_link}
TXT,
            ],
            [
                // C — BUYER-LED. Ships DISABLED (is_active=false). The AT-144
                // buyer-feed audit is now done and AT-145 rewired the buyer claim
                // to the canonical count via the {?matching_buyer_count} token
                // (blocked at zero by the composer's no_buyers gate; collapses in
                // address-only mode). Johan RE-ENABLES manually after reviewing
                // AT-145 — do NOT flip is_active here.
                'name' => 'Buyer-Led — Active Buyer Match (DISABLED)',
                'is_active' => false,
                'is_default_for_channel' => false,
                'description' => 'Consent request (C) — buyer-led. States the REAL canonical active-buyer count for the property ({matching_buyer_count} token, AT-145; blocked at zero). DISABLED — Johan re-enables after AT-145 review.',
                'email_subject' => 'About your {property_suburb} property',
                'body' => <<<'TXT'
Good day, {seller_surname}. I hope you're doing well.

My name is {agent_name}, a {agent_designation} (PPRA registered) with {agency_name}. I'm reaching out regarding your property at {property_address}.{?matching_buyer_count} We're currently working with {matching_buyer_count} active buyer(s) looking for a property like yours in {property_suburb} — and your home is the kind they have in mind.{/matching_buyer_count}

With your permission, I'd love to share what buyers are looking for near you, along with a market-related valuation and our marketing, photography and video services.

If you'd be happy for me to be in touch by WhatsApp, SMS or email, reply OPT IN. If you'd prefer not to be contacted, reply OPT OUT.

Kind regards,
{agent_name}
{agent_designation} | PPRA Registered
{agency_name}
Manage your preferences or opt out anytime: {opt_out_link}
TXT,
            ],
            [
                // AT-144 (Johan 2026-07-17) — the direct "buyers for YOUR property"
                // pitch he asked for: lead with the REAL canonical per-property
                // active-buyer count via the {?matching_buyer_count} token. The
                // composer's no_buyers send-gate blocks the send when the true count
                // is 0, and the segment collapses in address-only mode, so the number
                // is stated ONLY when it is true and >= 1. The EXACT matched buyers
                // (contact_ids + tier + engine + gate) are frozen into every send's
                // facts_snapshot.matched_buyer_basis, so a seller challenge is
                // answerable with facts. Ships DISABLED — Johan does a one-pass
                // wording pick (subject option + body), then enables. Do NOT flip
                // is_active here. Subject note: the count-forward subject below is
                // truthful with a linked property (the gate guarantees >= 1); for
                // address-only sends prefer a demand-neutral subject (see the READY
                // report's option B).
                'name' => 'Active Buyer Match — Your Property (DISABLED)',
                'is_active' => false,
                'is_default_for_channel' => false,
                'description' => 'Buyer-demand pitch (AT-144) — states the REAL canonical count of active buyers matching THIS property ({matching_buyer_count} token; blocked at zero by no_buyers; the matched-buyer basis is snapshotted per send for auditability). DISABLED — Johan re-enables after his wording pick.',
                'email_subject' => 'Buyers looking for a home like yours in {property_suburb}',
                'body' => <<<'TXT'
Hi {seller_name}, I'm {agent_name} from {agency_name} — a registered estate agency on the KZN South Coast.
{?matching_buyer_count}We currently have {matching_buyer_count} active buyer(s) on our books looking for a property like yours in {property_suburb}. {/matching_buyer_count}If you've ever considered selling, I'd be glad to tell you what they're looking for — and what your home could achieve in today's market.
May I share the details with you by WhatsApp, SMS or email?
- Reply OPT IN and I'll send what buyers near you are looking for
- Reply OPT OUT and I won't contact you again
Manage your preferences or opt out anytime: {opt_out_link}.
{agency_name} · FFC {agency_ffc}{?agent_ffc} · Agent FFC {agent_ffc}{/agent_ffc} · {branch_or_company_tel}.
TXT,
            ],
            [
                'name' => 'Short Introduction — Services Overview',
                'is_active' => true,
                'is_default_for_channel' => false,
                'description' => 'Consent request (D) — short services-overview introduction.',
                'email_subject' => 'A quick note about your property',
                'body' => <<<'TXT'
Good day, {seller_surname}.

{agent_name} here, from {agency_name} (PPRA registered). I'm reaching out regarding your property at {property_address}.

I'd love to share complimentary market insights and a valuation for your area, plus our sales, rentals, photography and marketing services.

Happy to hear from me by WhatsApp, SMS or email? Reply OPT IN. Rather not? Reply OPT OUT — no problem at all.

Kind regards,
{agent_name} | {agency_name}
Manage your preferences or opt out anytime: {opt_out_link}
TXT,
            ],
            [
                'name' => 'Area Updates — Market & Buyer Demand',
                'is_active' => true,
                'is_default_for_channel' => false,
                'description' => 'Consent request (E) — area market & buyer-demand updates.',
                'email_subject' => '{property_suburb} market & buyer-demand updates',
                'body' => <<<'TXT'
Good day, {seller_surname}. I hope you're well.

I'm {agent_name} from {agency_name}, a PPRA-registered agency on the KZN South Coast. I'm reaching out regarding your property at {property_address}.

I keep a close eye on {property_suburb} — recent sales, live buyer demand and what homes are currently worth. With your permission, I'd send you these area updates so you always have a feel for what your property could fetch and who's looking to buy nearby, along with our valuation, photography and marketing services should you ever need them.

Happy to receive these by WhatsApp, SMS or email? Reply OPT IN. Prefer not to? Reply OPT OUT.

Kind regards,
{agent_name}
{agent_designation} | PPRA Registered
{agency_name}
Manage your preferences or opt out anytime: {opt_out_link}
TXT,
            ],

            // ── AT-263 — Johan's prospecting introduction ─────────────────────
            // His copy, verbatim, with his placeholders mapped to the REAL merge
            // fields. No new merge field was needed — every token he wrote already
            // has a live data source:
            //
            //   {first_name}   → {seller_name}            (contact first name)
            //   {ffc_number}   → {agent_ffc}              (users.ffc_number — the
            //        SENDING AGENT's Fidelity Fund Certificate, which is what he
            //        asked for; NOT {agency_ffc} and NOT {agency_ppra_no}. Wrapped
            //        in the optional segment so an agent without one on file reads
            //        "(registered with the PPRA)" rather than a dangling "FFC ".)
            //   {area}         → {property_suburb}        (as every other template)
            //   {property_ref} → {property_address}       (there is no listing-ref
            //        token, and a prospecting target is not our listing, so it HAS
            //        no reference — the thing he means by "your property at …" is
            //        the street address, which is also the anchor the blank-address
            //        send-gate already enforces)
            //   {agency_phone} → {branch_or_company_tel}  (AT-48 branch-then-company)
            //
            // Two compliance additions his draft could not have known about: the
            // {opt_out_link} one-tap link is MANDATORY on every outreach template
            // (AT-49/POPIA — the validator hard-blocks without it), so it joins his
            // STOP sentence rather than replacing it; and include_tracking_link is
            // false because a cold introduction carries no live-demand link.
            //
            // Ships ACTIVE, and NOT default-for-channel — the standing defaults stay
            // where Johan put them.
            [
                'name' => 'Prospecting Introduction — Sales & Rentals',
                'is_active' => true,
                'is_default_for_channel' => false,
                'description' => "Prospecting introduction (AT-263, Johan's copy) — introduces the agent and agency to a property owner and asks for a short call about marketing their property. Carries the agent's own FFC.",
                'email_subject' => 'Marketing your property in {property_suburb} — a short call?',
                'body' => <<<'TXT'
Good day {seller_name},

My name is {agent_name} from {agency_name} (registered with the PPRA{?agent_ffc}, FFC {agent_ffc}{/agent_ffc}). We assist property owners in {property_suburb} with sales and rentals, and I would like to discuss marketing your property at {property_address}.

When would be a good time for a short call?

If you would prefer not to receive marketing messages from us, simply reply STOP and we will remove you from our list immediately — or opt out here: {opt_out_link}

Kind regards,
{agent_name} | {agency_name} | {branch_or_company_tel}
TXT,
            ],
        ];
    }
}
