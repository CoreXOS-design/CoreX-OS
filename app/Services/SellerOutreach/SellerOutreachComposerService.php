<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use App\Services\CandidatePractitionerService;
use App\Services\PropertyMatchScoringService;
use App\Services\Prospecting\ProspectingConfigurationService;
use App\Services\Prospecting\ProspectingIntelligenceService;
use App\Support\SellerOutreach\OutreachAddress;
use App\Support\SellerOutreach\OutreachContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the OutreachContext (merge fields, live demand stats, validation
 * status, cooldown signal, opt-out flag) for a contact+property pair, so the
 * composer can render a defensible preview before the agent sends.
 *
 * Multi-tenancy: composeContext() rejects mixed-agency contact/property pairs
 * up-front. All downstream queries are agency-scoped.
 */
final class SellerOutreachComposerService
{
    public function __construct(
        private readonly ProspectingIntelligenceService $intelligence,
        private readonly ProspectingConfigurationService $config,
        private readonly MarketingConsentService $marketingConsent,
        private readonly CandidatePractitionerService $practitioners,
        private readonly PropertyMatchScoringService $buyerDemand,
    ) {}

    public function composeContext(
        int $agencyId,
        Contact $contact,
        ?Property $property,
        string $channel,
        ?int $templateId = null,
        ?User $agent = null,
        ?string $bodyOverride = null,
        ?string $subjectOverride = null,
    ): OutreachContext {
        $this->assertSameAgency($agencyId, $contact, $property);

        // AT-61 — the address the pitch is composed against. A linked Property
        // is the richer source (carries property_type/beds/price → enables the
        // per-property matching claim); a contact's structured address (AT-60)
        // is the address-only source (area-level demand statement only, no
        // Property created).
        $address = $property !== null
            ? OutreachAddress::fromProperty($property)
            : OutreachAddress::fromContact($contact);

        $agent = $agent ?? Auth::user();
        if (!$agent instanceof User) {
            throw new \InvalidArgumentException('SellerOutreachComposerService requires an authenticated user or explicit agent.');
        }

        $template = $this->resolveTemplate($agencyId, $channel, $templateId);

        // Email shared-body fallback — when the agency has no Email template (the
        // HFC consent/default templates are WhatsApp-only), reuse a WhatsApp
        // template's body so the Email channel isn't blank. The body is
        // channel-neutral (merge fields + opt-out link); the e-sign wrapper adds
        // the email branding. Only applies with no explicit template/body chosen.
        if ($template === null && $channel === 'email' && $templateId === null && $bodyOverride === null) {
            $template = $this->resolveTemplate($agencyId, 'whatsapp', null)
                ?? SellerOutreachTemplate::withoutGlobalScopes()
                    ->where('agency_id', $agencyId)
                    ->where('channel', 'whatsapp')
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->orderByDesc('is_default_for_channel')
                    ->orderBy('id')
                    ->first();
        }

        $mergeFields = $this->buildMergeFields($agencyId, $contact, $address, $property, $agent);

        $bodyTemplate = $bodyOverride ?? ($template?->body ?? '');
        $subjectTemplate = $subjectOverride ?? ($template?->subject ?? '');

        $renderedBody = $this->renderBody($bodyTemplate, $mergeFields);
        $renderedSubject = $channel === 'email' ? $this->renderBody($subjectTemplate, $mergeFields) : null;

        $recipientPhone = $this->normalisePhone($contact);
        $recipientEmail = $this->resolveEmail($contact);

        // AT-46 — a template flagged include_tracking_link=false (e.g. a consent
        // request) is allowed to omit {tracking_link}; mirror the rule the
        // template-save validator already applies. Free-edited bodies with no
        // template default to requiring it (true).
        $includeTrackingLink = (bool) ($template?->include_tracking_link ?? true);
        $validationIssues = $this->buildValidationIssues($channel, $recipientPhone, $recipientEmail, $bodyTemplate, $includeTrackingLink);

        // Blank-address send-gate (BUILD_STANDARD whole-input-space; one check for
        // the whole template library, not a per-template patch). Every consent
        // template opens with "your property at {property_address}". A linked
        // Property (or a captured contact address) whose street/suburb columns are
        // all empty would otherwise render OutreachAddress' "(address unavailable)"
        // stand-in and send a pitch with a blank anchor. Refuse it here: this one
        // issue is honoured by the sender (isSendable()) AND by both controller
        // surfaces (submit()/queue() already hard-block on validationIssues), so
        // no send path can slip a blank/placeholder address through.
        //
        // AT-266 — the address must NAME the property, in either of the two shapes
        // South African stock actually comes in: full title (a street + suburb) or
        // sectional (a scheme name + unit + suburb — a unit in a complex has no
        // street of its own). The old check was isEmpty(), which only fired when
        // street AND suburb were both missing, so a property carrying nothing but a
        // suburb sailed through and rendered "your property at Uvongo" — a pitch
        // that names no address. Blocked only when NEITHER shape is present.
        // Preserves AT-61: the address may still come from a linked property or the
        // contact's structured AT-60 columns; it simply has to name a property.
        if ($address->isIncomplete()) {
            $validationIssues['no_address'] = 'This property cannot be named in the message — add a street (for a full-title property) or a complex name and unit number (for a sectional-title unit), plus the suburb, before sending.';
        }

        // Never send a consent message with a blank/impersonal greeting. The
        // {seller_surname} token falls back surname → first name; when BOTH are
        // empty the composer no longer invents a "there" — it BLOCKS, so no send
        // ever goes out addressed to nobody ("Good day, ."). Fires only for the
        // templates that actually greet by surname (the 5 new consent variations).
        if (str_contains($bodyTemplate, '{seller_surname}') && ($mergeFields['seller_surname'] ?? '') === '') {
            $validationIssues['no_recipient_name'] = 'This contact has no name captured — the greeting would be blank. Add a first name or surname before sending.';
        }

        // PPRA designation send-gate (compliance-critical, AT-142). The consent
        // templates state the agent's PPRA designation to the consumer via the
        // {agent_designation} token (admin-managed users.designation) — so the
        // message is always TRUTHFUL (it prints their real designation, not a
        // hardcoded "Full Status" claim). Two guards, both content-driven on the
        // token's presence (a template that omits it, e.g. D, is unaffected):
        //   1. Blank designation → BLOCK. Never send a registration statement with
        //      an empty/placeholder designation ("... , a  (PPRA registered) ...").
        //   2. Agency policy `restrict_consent_outreach_to_full_status` (default
        //      off) → when ON, only full-status practitioners/principals may send
        //      these; a candidate is blocked even though the token would render
        //      truthfully. Uses the canonical CandidatePractitionerService so it
        //      never drifts from the rest of the system's full-vs-candidate call.
        // Same mechanism as no_address — one validationIssue, honoured by the
        // sender (isSendable) AND both controller surfaces (submit/queue).
        if (str_contains($bodyTemplate, '{agent_designation}')) {
            if (($mergeFields['agent_designation'] ?? '') === '') {
                $validationIssues['no_designation'] = 'Your PPRA designation is not set on your profile — this template states your designation to the seller, so it cannot be sent blank. Ask an admin to set your designation (My Portal → Profile → Admin Managed → Designation).';
            } elseif ($this->agencyRestrictsToFullStatus($agencyId) && !$this->agentMayClaimFullStatus($agent)) {
                $validationIssues['designation_not_full_status'] = 'Your agency restricts consent outreach to full-status practitioners. Your designation is not full-status, so this template cannot be sent under your name — ask a full-status practitioner to send it.';
            }
        }

        // Zero-buyer send-gate (AT-145, audit AT-144). A template that makes a
        // per-property active-buyer claim carries the {matching_buyer_count}
        // token; that number is the CANONICAL countable active-buyer count for
        // the linked property (buildMergeFields). Never ship such a claim when
        // the true count is 0 — asserting a buyer who does not exist is a CPA
        // s41 misrepresentation (the exact live exposure the audit found on id 3).
        //
        // Fires ONLY in linked-property mode: in address-only mode
        // matching_buyer_count is '' (no property type/beds/price to match), the
        // {?matching_buyer_count} segment collapses, and no buyer claim is made —
        // so there is nothing false to block. Content-driven on the token's
        // presence (a template that omits it, e.g. the area-update variants, is
        // unaffected). Same mechanism as no_address / no_designation — one
        // validationIssue honoured by the sender (isSendable) AND both controller
        // surfaces (submit/queue).
        if ($property !== null
            && str_contains($bodyTemplate, '{matching_buyer_count}')
            && ($mergeFields['__matching_buyer_count'] ?? null) === 0) {
            $validationIssues['no_buyers'] = 'This template states you have active buyers matching this property, but there are currently 0 matching buyers for it. Choose an area-update template, or send once a buyer matches — an active-buyer claim cannot be sent when the true count is zero.';
        }

        // AT-49 — block on the opt-out flag OR an identifier-level suppression
        // (the latter catches a re-imported contact with no flag set yet).
        $optOutBlocks = $contact->messaging_opt_out_at !== null
            || $this->marketingConsent->isContactSuppressed($contact);
        // AT-81 — a consent-request already sent and awaiting a reply blocks a
        // re-blast until the contact engages or the no-response window lapses.
        $pendingBlocks = $contact->isOutreachPending();
        $cooldownSignal = $this->cooldownSignal($agencyId, $contact);

        $factsSnapshot = [
            'merge_fields' => $mergeFields,
            'property_segments' => [
                'town_id' => $mergeFields['__property_town_id'] ?? null,
                'property_type_option_id' => $mergeFields['__property_type_option_id'] ?? null,
                'bedroom_segment_id' => $mergeFields['__bedroom_segment_id'] ?? null,
                'price_band_id' => $mergeFields['__price_band_id'] ?? null,
            ],
            // AT-144 — the AUDITABLE basis behind the {matching_buyer_count} claim:
            // the EXACT matched-buyer contact_ids + score/tier, the engine, and the
            // countable/active gate, frozen at send time so a seller challenge is
            // answerable with the buyers actually held then (not a number that
            // re-derives differently months later). Null in address-only mode (no
            // per-property claim is made) — matches the collapsing display token.
            'matched_buyer_basis' => ($mergeFields['__matching_buyer_basis'] ?? null) === null ? null : [
                'count' => $mergeFields['__matching_buyer_basis']['count'],
                'contact_ids' => $mergeFields['__matching_buyer_basis']['contact_ids'],
                'buyers' => $mergeFields['__matching_buyer_basis']['buyers'],
                'engine' => 'PropertyMatchScoringService::countableActiveBuyerBasisForProperty (canonical MatchingService::matchesForProperty)',
                'gate' => 'countable (agency min_countable_criteria) + active buyer_state (new/warm) + score >= MIN_SCORE_TO_DISPLAY',
                'property_id' => $property?->id,
            ],
            'snapshot_taken_at' => now()->toIso8601String(),
        ];

        return new OutreachContext(
            contact: $contact,
            property: $property,
            address: $address,
            agent: $agent,
            agencyId: $agencyId,
            template: $template,
            channel: $channel,
            mergeFields: $mergeFields,
            factsSnapshot: $factsSnapshot,
            renderedSubject: $renderedSubject,
            renderedBody: $renderedBody,
            recipientPhone: $recipientPhone,
            recipientEmail: $recipientEmail,
            validationIssues: $validationIssues,
            optOutBlocks: $optOutBlocks,
            cooldownSignal: $cooldownSignal,
            pendingBlocks: $pendingBlocks,
        );
    }

    /**
     * AT-61 — builds the merge-field map from an ADDRESS source plus an
     * OPTIONAL Property.
     *
     * - Area-level fields (address, suburb, town, {buyer_count}) come from the
     *   address DTO and work identically whether the address came from a linked
     *   Property or a contact's structured address.
     * - Per-property fields (property_type, property_beds, {matching_buyer_count})
     *   require a Property. In address-only mode ($property === null) they are
     *   blank and {matching_buyer_count} is NOT emitted — an address gives us a
     *   suburb, not a property's type/beds/price, so a "X buyers match THIS
     *   property" claim would be dishonest. The {?matching_buyer_count}…{/…}
     *   optional segment in the templates collapses on the empty value.
     */
    private function buildMergeFields(int $agencyId, Contact $contact, OutreachAddress $address, ?Property $property, User $agent): array
    {
        $propertyAddress = $address->displayAddress();
        $propertySuburb = (string) ($address->suburb ?? '');
        // Per-property attributes only exist when a Property is linked.
        // Properties table column is `beds`, not `bedrooms` — confirmed in pre-flight.
        $propertyType = $property?->property_type ?? null;
        $propertyBeds = $property?->beds ?? null;
        $propertyPrice = $property?->price ?? null;
        $listingType = $property?->listing_type ?? 'sale';

        $town = $address->town
            ?? ($propertySuburb !== '' ? $this->config->suburbToTown($agencyId, $propertySuburb) : null);
        $propertyTypeOpt = $propertyType
            ? $this->config->propertyTypes($agencyId, activeOnly: false)
                ->firstWhere('slug', Str::slug($propertyType))
            : null;
        $bedroomSeg = $propertyBeds !== null ? $this->config->bedroomBucketFor($agencyId, (int) $propertyBeds) : null;
        $priceBand = $propertyPrice !== null && (int) $propertyPrice > 0
            ? $this->config->classifyPrice($agencyId, $listingType, (int) $propertyPrice)
            : null;

        // Counts come from buyersForSegment() rather than snapshot()->activeBuyers
        // because ProspectingIntelligenceService::loadActiveBuyers() only narrows
        // by listing_type — town/property_type/bedroom/price_band filters do not
        // reduce the headline count. For pitch defensibility we need the actual
        // subset of contact_ids per dimension and intersect them.
        $baseFilters = ['listing_type' => $listingType];

        if ($town) {
            $townBuyerIds = $this->intelligence->buyersForSegment($agencyId, 'town', $town->id, $baseFilters);
            $buyerCount = $townBuyerIds->count();
        } else {
            // No mapped town for this property's suburb — fall back to the
            // agency-wide active-buyer headline for this listing type.
            $townBuyerIds = null;
            $buyerCount = $this->intelligence
                ->snapshot(['agency_id' => $agencyId] + $baseFilters)
                ->activeBuyers;
        }

        // AT-145 — the per-property active-buyer CLAIM count is now the
        // CANONICAL figure, NOT the town∩type∩beds∩price intersection this method
        // used to compute off ProspectingIntelligenceService. That parallel path
        // provably diverged from the buyer engine (AT-144 audit: prop #6018 read
        // matching=0 here vs matchesForProperty=1 on the canonical engine) and it
        // never applied the AT-71 ->countable() gate. We now source it from
        // PropertyMatchScoringService::countableActiveBuyerCountForProperty() —
        // the SAME engine the Core Matches tab and the seller panel use — so the
        // outreach claim equals what every other surface shows, is countable-
        // gated (honours the agency's min_countable_criteria, never hardcoded),
        // and stays active-state-only (buyer_state new/warm; cold/lost excluded).
        //
        // AT-61 — address-only mode ($property === null): there is no
        // property_type/beds/price to match against, so we make NO per-property
        // claim. null here → '' in the merge map → the {?matching_buyer_count}
        // segment collapses, leaving only the honest area-level statement. (The
        // town-level {buyer_count} above stays on the ProspectingIntelligence
        // area-demand path — only the per-property CLAIM moves to canonical.)
        // AT-144 — fetch the canonical count AND its auditable basis (the exact
        // matched-buyer contact_ids + score/tier) in one call, so composeContext can
        // freeze the buyers behind the claimed number into the immutable snapshot.
        $matchingBuyerBasis = $property === null
            ? null
            : $this->buyerDemand->countableActiveBuyerBasisForProperty($property);
        $matchingBuyerCount = $matchingBuyerBasis === null ? null : (int) $matchingBuyerBasis['count'];

        return [
            'seller_name' => $this->sellerDisplayName($contact),
            // Surname for the formal greeting ("Good day, {seller_surname}.").
            'seller_surname' => $this->sellerSurname($contact),
            'property_address' => $propertyAddress,
            'property_suburb' => $propertySuburb,
            'property_town' => $town?->name ?? ($propertySuburb !== '' ? $propertySuburb : 'your area'),
            // AT-61 — in address-only mode there is no property type/beds, so
            // these stay blank (and the per-property clause that uses them
            // collapses). In property mode keep the existing 'property' default.
            'property_type' => $property !== null ? ($propertyType ?? 'property') : '',
            'property_beds' => $propertyBeds !== null ? (string) $propertyBeds : '',
            'agent_name' => $this->agentDisplayName($agent),
            // AT-142 — admin-managed PPRA designation (users.designation), printed
            // truthfully in the consent templates. '' when unset → the send-gate
            // blocks (no_designation) so a blank designation never ships.
            'agent_designation' => $this->agentDesignation($agent),
            'agent_phone' => $this->agentDisplayPhone($agent) ?? '',
            'agency_name' => $this->agencyName($agencyId),
            'agency_ppra_no' => $this->agencyPpraNo($agencyId),
            'agency_contact' => $this->agencyContact($agencyId),
            // AT-48 footer fields — company FFC + sending-agent FFC + branch-then-company tel.
            'agency_ffc' => $this->agencyFfcNo($agencyId),
            'agent_ffc' => $this->agentFfcNumber($agent),
            'branch_or_company_tel' => $this->branchOrCompanyTel($agencyId, $agent),
            'buyer_count' => (string) $buyerCount,
            // AT-145 — the DISPLAY value is '' whenever there is no positive
            // per-property claim to make: address-only mode ($matchingBuyerCount
            // === null) AND a genuine zero ($matchingBuyerCount === 0). Both
            // collapse the {?matching_buyer_count} segment so no "0 active
            // buyer(s)" sentence ever renders — the claim only appears when the
            // real count is >=1. The raw count for the send-gate + facts_snapshot
            // is carried separately in the internal __matching_buyer_count key
            // (0 in property-mode-zero vs null in address-only lets the no_buyers
            // gate fire in the former and stay silent in the latter).
            'matching_buyer_count' => ($matchingBuyerCount === null || $matchingBuyerCount === 0)
                ? ''
                : (string) $matchingBuyerCount,
            '__matching_buyer_count' => $matchingBuyerCount,
            // AT-144 — internal, never rendered (renderBody skips `__` keys). The
            // auditable basis behind the claim; lifted into facts_snapshot below.
            '__matching_buyer_basis' => $matchingBuyerBasis,
            // `tracking_link` is intentionally NOT substituted into the body
            // here — `renderBody()` skips it so the agent sees the literal
            // `{tracking_link}` merge token in the composer's textarea
            // (matches what they see in the template editor). The sender
            // service substitutes the real URL when it records the send.
            'tracking_link' => '{tracking_link}',
            // `opt_out_link` (AT-49) is per-send — its token only exists once
            // the send is recorded — so it stays literal here too and the
            // sender service substitutes the real opt-out URL at send time.
            'opt_out_link' => '{opt_out_link}',
            // `opt_in_link` (AT-49) — optional re-consent link, same per-send
            // token, substituted by the sender at send time.
            'opt_in_link' => '{opt_in_link}',
            // Internal — not substituted into body; used to populate facts_snapshot.
            '__property_town_id' => $town?->id,
            '__property_type_option_id' => $propertyTypeOpt?->id,
            '__bedroom_segment_id' => $bedroomSeg?->id,
            '__price_band_id' => $priceBand?->id,
        ];
    }

    private function renderBody(string $template, array $mergeFields): string
    {
        $result = $this->collapseOptionalSegments($template, $mergeFields);
        foreach ($mergeFields as $key => $value) {
            if (str_starts_with($key, '__')) {
                continue;
            }
            $result = str_replace('{' . $key . '}', (string) $value, $result);
        }
        return $result;
    }

    /**
     * Optional-segment syntax: {?field}...{/field}.
     *
     * When the named merge field is blank/empty the WHOLE block — including
     * the leading separator and label it wraps — is dropped; otherwise the
     * inner content is kept and the normal token loop fills it in. This is how
     * the AT-48 footer renders "· Agent FFC {agent_ffc}" only when the sending
     * agent actually has an FFC number: no dangling label, no stray separator.
     *
     * It is a targeted, opt-in construct (a body must explicitly wrap a
     * segment) — NOT a blanket "drop any empty field" rule, which would mangle
     * other body text where an empty optional field is legitimately fine.
     */
    private function collapseOptionalSegments(string $template, array $mergeFields): string
    {
        return (string) preg_replace_callback(
            '/\{\?([a-z_]+)\}(.*?)\{\/\1\}/is',
            function (array $m) use ($mergeFields): string {
                $value = trim((string) ($mergeFields[$m[1]] ?? ''));
                return $value !== '' ? $m[2] : '';
            },
            $template
        );
    }

    private function resolveTemplate(int $agencyId, string $channel, ?int $templateId): ?SellerOutreachTemplate
    {
        if ($templateId) {
            return SellerOutreachTemplate::withoutGlobalScopes()
                ->where('id', $templateId)
                ->where('agency_id', $agencyId)
                ->where('channel', $channel)
                ->whereNull('deleted_at')
                ->first();
        }
        return SellerOutreachTemplate::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->where('is_default_for_channel', true)
            ->whereNull('deleted_at')
            ->first();
    }

    private function sellerDisplayName(Contact $contact): string
    {
        $first = trim((string) ($contact->first_name ?? ''));
        if ($first !== '') {
            return $first;
        }
        $full = trim(((string) ($contact->first_name ?? '')) . ' ' . ((string) ($contact->last_name ?? '')));
        return $full !== '' ? $full : 'there';
    }

    /**
     * Surname for the formal greeting ("Good day, {seller_surname}."). There is
     * no salutation/title column on contacts, so the templates greet by surname
     * only. Fallback chain: surname → first name → '' (EMPTY). It deliberately
     * does NOT invent a "there": an empty return signals "no name", which the
     * composeContext gate turns into a hard block (no_recipient_name) so a
     * consent message never goes out with a blank greeting ("Good day, .").
     */
    private function sellerSurname(Contact $contact): string
    {
        $last = trim((string) ($contact->last_name ?? ''));
        if ($last !== '') {
            return $last;
        }
        // First-name fallback; '' when neither is captured → gate blocks the send.
        return trim((string) ($contact->first_name ?? ''));
    }

    /**
     * The agent's admin-managed PPRA designation (users.designation), trimmed.
     * '' when unset — the {agent_designation} send-gate turns that into a block.
     */
    private function agentDesignation(User $agent): string
    {
        return trim((string) ($agent->designation ?? ''));
    }

    /**
     * True when the agent's PPRA designation is full-status — full-status
     * practitioners AND principals (a principal is a senior full-status
     * practitioner). Delegates to the canonical CandidatePractitionerService
     * (designation-based, on users.designation) so this gate never drifts from
     * the system's full-vs-candidate call.
     */
    private function agentMayClaimFullStatus(User $agent): bool
    {
        return $this->practitioners->isFullStatus($agent)
            || $this->practitioners->isPrincipal($agent);
    }

    /**
     * Agency policy — when on, consent outreach is restricted to full-status
     * practitioners/principals. Default off (column default false); read straight
     * off the agencies row (scope-free — the agency IS the tenant root).
     */
    private function agencyRestrictsToFullStatus(int $agencyId): bool
    {
        return (bool) DB::table('agencies')
            ->where('id', $agencyId)
            ->value('restrict_consent_outreach_to_full_status');
    }

    private function normalisePhone(Contact $contact): ?string
    {
        $raw = $contact->phone ?? $contact->cell_number ?? $contact->mobile ?? null;
        if (!$raw) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $raw);
        if (!$digits) {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '27' . substr($digits, 1);
        }
        return $digits;
    }

    private function resolveEmail(Contact $contact): ?string
    {
        $email = $contact->email ?? null;
        return $email ? strtolower(trim((string) $email)) : null;
    }

    private function buildValidationIssues(string $channel, ?string $phone, ?string $email, string $bodyTemplate, bool $includeTrackingLink = true): array
    {
        $issues = [];
        if ($channel === 'whatsapp' && !$phone) {
            $issues['no_phone'] = 'Contact has no phone number — cannot send WhatsApp.';
        }
        if ($channel === 'email' && !$email) {
            $issues['no_email'] = 'Contact has no email address — cannot send email.';
        }
        // AT-46 — only required when the template opts into click tracking. A
        // consent-request template (include_tracking_link=false) may omit it.
        // Mirrors SellerOutreachTemplateValidator::validate().
        if ($includeTrackingLink && !str_contains($bodyTemplate, '{tracking_link}')) {
            $issues['no_tracking_link'] = 'Body is missing {tracking_link} — cannot record opens.';
        }
        return $issues;
    }

    private function cooldownSignal(int $agencyId, Contact $contact): ?array
    {
        $recent = SellerOutreachSend::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('contact_id', $contact->id)
            ->whereNull('deleted_at')
            ->where('sent_at', '>=', now()->subDays(7))
            ->latest('sent_at')
            ->first();
        if (!$recent) {
            return null;
        }
        return [
            'last_sent_at' => $recent->sent_at?->toIso8601String(),
            'last_agent_id' => $recent->agent_id,
            'last_channel' => $recent->channel,
        ];
    }

    private function agentDisplayName(User $agent): string
    {
        // The users table holds a single `name` column. first_name/last_name
        // are not present — pre-flight confirmed.
        $name = trim((string) ($agent->name ?? ''));
        return $name !== '' ? $name : 'Your agent';
    }

    private function agentDisplayPhone(User $agent): ?string
    {
        return $agent->phone ?? $agent->cell ?? null;
    }

    private function agencyName(int $agencyId): string
    {
        $name = DB::table('agencies')->where('id', $agencyId)->value('name');
        return $name ? (string) $name : 'Our agency';
    }

    /** Agency PPRA registration number for the {agency_ppra_no} merge field. */
    private function agencyPpraNo(int $agencyId): string
    {
        $ppra = DB::table('agencies')->where('id', $agencyId)->value('ppra_number');
        return $ppra ? (string) $ppra : '';
    }

    /** Agency public contact (configurable) for the {agency_contact} merge field. */
    private function agencyContact(int $agencyId): string
    {
        $contact = DB::table('agencies')->where('id', $agencyId)->value('public_contact');
        return $contact ? (string) $contact : '';
    }

    /** Company Fidelity Fund Certificate number for the {agency_ffc} merge field. */
    private function agencyFfcNo(int $agencyId): string
    {
        $ffc = DB::table('agencies')->where('id', $agencyId)->value('ffc_no');
        return $ffc ? (string) $ffc : '';
    }

    /**
     * Sending agent's own FFC number for the {agent_ffc} merge field. Returns
     * '' when blank/null so the footer's optional {?agent_ffc} segment collapses
     * (a handful of HFC agents have no FFC number on file).
     */
    private function agentFfcNumber(User $agent): string
    {
        return trim((string) ($agent->ffc_number ?? ''));
    }

    /**
     * Branch-then-company telephone for the {branch_or_company_tel} merge field.
     *
     * Mirrors CoreX's standard blank-aware coalesce chain (e.g. the agent
     * cell→phone fallback used in syndication/signing): the sending agent's
     * branch landline is preferred, falling back to the agency landline when the
     * branch phone is blank/NULL — which is the case for all HFC branches today.
     */
    private function branchOrCompanyTel(int $agencyId, User $agent): string
    {
        $branchPhone = trim((string) ($agent->branch?->phone ?? ''));
        if ($branchPhone !== '') {
            return $branchPhone;
        }
        $agencyPhone = DB::table('agencies')->where('id', $agencyId)->value('phone');
        return $agencyPhone ? (string) $agencyPhone : '';
    }

    private function assertSameAgency(int $agencyId, Contact $contact, ?Property $property): void
    {
        if ((int) $contact->agency_id !== $agencyId) {
            throw new \InvalidArgumentException("Contact {$contact->id} is not in agency {$agencyId}.");
        }
        // AT-61 — property is optional (address-only mode). Only assert when present.
        if ($property !== null && (int) $property->agency_id !== $agencyId) {
            throw new \InvalidArgumentException("Property {$property->id} is not in agency {$agencyId}.");
        }
    }
}
