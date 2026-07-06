<?php

namespace App\Services\Website;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\AgencyApiKey;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Services\Buyers\BuyerLeadCascadeService;
use App\Services\ContactDuplicateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests inbound property enquiries from an agency's public website
 * (POST /api/v1/website/leads) into the SAME portal_leads pipeline as the
 * Property24 / Private Property lead paths — so a website lead is visible in
 * Real Estate → Portal Leads, on the mobile app, notifies the listing agent(s),
 * and seeds the buyer pipeline exactly like a portal lead.
 *
 * Anchoring is server-side and authoritative: the lead is tied to the CoreX
 * listing (resolved from listing_id, then listing_reference) and routed to that
 * listing's agent(s). The website-supplied agent_ids are recorded for audit but
 * are NOT trusted for routing (a website must not be able to redirect a lead to
 * an arbitrary agent).
 *
 * Contact match-or-create reuses ContactDuplicateService (the canonical
 * normaliser) so "+27 82…" vs "082…" and case-different emails resolve to ONE
 * contact rather than minting duplicates.
 *
 * Spec: .ai/specs/agency-public-api.md §9 (built).
 */
class WebsiteLeadService
{
    public function __construct(
        private readonly ContactDuplicateService $duplicates,
        private readonly BuyerLeadCascadeService $buyerCascade,
    ) {
    }

    /**
     * @param  array{listing_id?:int|null, listing_reference?:string|null, agent_ids?:array<int>, name:string, email?:string|null, phone?:string|null, message?:string|null, source?:string|null}  $data
     */
    public function capture(AgencyApiKey $key, array $data): PortalLead
    {
        $agencyId = (int) $key->agency_id;

        $name    = trim((string) ($data['name'] ?? '')) ?: 'Website enquiry';
        $email   = $this->cleanNullable($data['email'] ?? null);
        $phone   = $this->cleanNullable($data['phone'] ?? null);
        $message = $this->cleanNullable($data['message'] ?? null);

        $listing   = $this->resolveListing($agencyId, $data['listing_id'] ?? null, $data['listing_reference'] ?? null);
        $listingRef = $this->cleanNullable($data['listing_reference'] ?? null)
            ?? $listing?->external_id;

        $listingAgentId = $listing?->agent_id;

        // Match-or-create the enquirer as a Buyer contact.
        [$contact, $existed, $existingAgentId] = $this->resolveContact(
            $agencyId,
            $name,
            $email,
            $phone,
            $listingAgentId,
            $listing,
        );

        $receivedAt = now();

        // Guard double-submits / client retries: same site + listing + (email|phone)
        // inside a short window is the same enquiry.
        $duplicate = $this->findRecentDuplicate($agencyId, $listingRef, $email, $phone, $receivedAt);
        if ($duplicate) {
            return $duplicate;
        }

        $lead = new PortalLead([
            'agency_id'                 => $agencyId,
            'portal'                    => PortalLead::PORTAL_WEBSITE,
            'lead_type'                 => 'Enquiry',
            'listing_id'                => $listing?->id,
            'listing_portal_ref'        => $listingRef ? (string) $listingRef : null,
            'contact_id'                => $contact?->id,
            'contact_exists'            => $existed,
            'existing_contact_agent_id' => $existed ? $existingAgentId : null,
            'name'                      => $name,
            'email'                     => $email,
            'phone'                     => $phone,
            'message'                   => $message,
            'is_whatsapp'               => false,
            'lead_source_raw'           => [
                'source'               => $data['source'] ?? 'website',
                'website'              => $key->name,
                'agency_api_key_id'    => $key->id,
                'requested_listing_id' => $data['listing_id'] ?? null,
                'requested_reference'  => $data['listing_reference'] ?? null,
                'requested_agent_ids'  => array_values($data['agent_ids'] ?? []),
                'resolved_listing_id'  => $listing?->id,
                'listing_agent_ids'    => $listing ? $this->listingAgentIds($listing) : [],
            ],
            'received_at'               => $receivedAt,
        ]);
        $lead->agency_id = $agencyId;
        $lead->save();

        // Seed the buyer pipeline from the enquired listing (same shared cascade
        // the P24/PP paths use). Wrapped so a seed failure never fails the lead.
        if ($contact && $listing) {
            $this->seedBuyerFromLead($contact, $listing, $listingAgentId, $existingAgentId, $message);
        }

        event(new NewPortalLeadReceived($lead));

        return $lead;
    }

    /**
     * Resolve the CoreX listing this enquiry is about. listing_id first (the
     * website got it from GET /website/listings), then listing_reference
     * (properties.external_id) as a fallback. Always agency-scoped — a listing
     * belonging to another tenant never resolves.
     */
    private function resolveListing(int $agencyId, $listingId, $reference): ?Property
    {
        if ($listingId) {
            $property = Property::query()->where('agency_id', $agencyId)->find((int) $listingId);
            if ($property) {
                return $property;
            }
        }

        $reference = $this->cleanNullable($reference);
        if ($reference) {
            return Property::query()
                ->where('agency_id', $agencyId)
                ->where('external_id', $reference)
                ->first();
        }

        return null;
    }

    /**
     * @return array{0:?Contact,1:bool,2:?int}  [contact, existed, existingAgentId]
     */
    private function resolveContact(int $agencyId, string $name, ?string $email, ?string $phone, ?int $listingAgentId, ?Property $listing): array
    {
        $existing = null;
        if ($email || $phone) {
            $existing = $this->duplicates
                ->findDuplicates(['email' => $email, 'phone' => $phone], $agencyId)
                ->first();
        }

        if ($existing) {
            return [$existing, true, $existing->created_by_user_id];
        }

        $buyerTypeId = ContactType::query()->where('name', 'Buyer')->value('id')
                    ?? ContactType::query()->where('name', 'Lead')->value('id');

        // "Website" contact source is per-agency (contact_sources is tenant-scoped);
        // create it once per agency on first website lead.
        $sourceId = ContactSource::firstOrCreate(
            ['agency_id' => $agencyId, 'name' => 'Website'],
            ['color' => '#0ea5e9', 'sort_order' => 21, 'is_active' => true],
        )->id;

        [$first, $last] = $this->splitName($name);

        $contact = DB::transaction(function () use ($agencyId, $first, $last, $email, $phone, $buyerTypeId, $sourceId, $listingAgentId, $listing) {
            $c = new Contact([
                'first_name'         => $first,
                'last_name'          => $last,
                'email'              => $email,
                'phone'              => $phone,
                'contact_type_id'    => $buyerTypeId,
                'contact_source_id'  => $sourceId,
                'created_by_user_id' => $listingAgentId,
                'agency_id'          => $agencyId,
                'notes'              => 'Auto-created from a website enquiry.',
            ]);
            $c->agency_id = $agencyId;
            $c->save();

            if ($listing) {
                $listing->contacts()->syncWithoutDetaching([$c->id => ['role' => 'lead']]);
            }

            return $c;
        });

        return [$contact, false, null];
    }

    private function seedBuyerFromLead(Contact $contact, Property $listing, ?int $listingAgentId, ?int $existingAgentId, ?string $message): void
    {
        try {
            $owner = $listingAgentId ?? $contact->created_by_user_id ?? $existingAgentId;
            if (! $owner) {
                return;
            }
            $this->buyerCascade->seedFromListing(
                $contact,
                $listing,
                (int) $owner,
                BuyerLeadCascadeService::SOURCE_PORTAL_WEBSITE,
                $message,
            );
        } catch (\Throwable $e) {
            Log::warning('Website lead buyer-seed failed: ' . $e->getMessage(), [
                'contact' => $contact->id,
                'listing' => $listing->id,
            ]);
        }
    }

    private function findRecentDuplicate(int $agencyId, ?string $listingRef, ?string $email, ?string $phone, Carbon $receivedAt): ?PortalLead
    {
        if (! $email && ! $phone) {
            return null;
        }

        return PortalLead::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal', PortalLead::PORTAL_WEBSITE)
            ->where('listing_portal_ref', $listingRef)
            ->where(function ($q) use ($email, $phone) {
                $q->when($email, fn ($qq) => $qq->orWhere('email', $email))
                  ->when($phone, fn ($qq) => $qq->orWhere('phone', $phone));
            })
            ->whereBetween('received_at', [$receivedAt->copy()->subMinutes(2), $receivedAt->copy()->addMinutes(2)])
            ->latest('received_at')
            ->first();
    }

    /** @return int[] listing agent(s), primary first, de-duped. */
    private function listingAgentIds(Property $listing): array
    {
        return array_values(array_unique(array_filter([
            $listing->agent_id,
            $listing->pp_second_agent_id,
        ])));
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Website', 'Enquiry'];
        }
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    private function cleanNullable($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
