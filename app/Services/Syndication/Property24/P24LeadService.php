<?php

namespace App\Services\Syndication\Property24;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests P24 buyer-enquiry leads via the v53 leads endpoint and persists
 * them to portal_leads, creating or matching the buyer contact as
 * appropriate. Complies with CLAUDE.md rule #10 — every lead with a
 * resolvable P24 listing reference is routed through
 * TrackedPropertyMatchOrCreateService.
 */
class P24LeadService
{
    private const CURSOR_CACHE_KEY = 'p24.leads.cursor.agency.';

    public function __construct(
        private readonly Property24ApiClient $api,
        private readonly TrackedPropertyMatchOrCreateService $matchOrCreate,
    ) {
    }

    /**
     * Pull leads for every agency that has P24 credentials configured.
     * Returns counts per agency for the calling job to log.
     */
    public function pullForAllAgencies(): array
    {
        $results = [];

        $agencies = Agency::query()->whereNotNull('p24_username')->where('p24_username', '!=', '')->get();
        if ($agencies->isEmpty()) {
            // Single-tenant / default-credential fallback — pull once with no agency override.
            $results['default'] = $this->pullLeads(null);
            return $results;
        }

        foreach ($agencies as $agency) {
            $results[$agency->id] = $this->pullLeads($agency);
        }

        return $results;
    }

    /**
     * Pull leads for one agency (or default credentials when $agency is null).
     */
    public function pullLeads(?Agency $agency): array
    {
        $api       = $agency ? new Property24ApiClient($agency) : $this->api;
        $cursorKey = self::CURSOR_CACHE_KEY . ($agency?->id ?? 'default');
        $after     = Cache::get($cursorKey);

        // First run (no cursor): reach back the FULL retained window so a newly-
        // onboarded agency captures every lead P24 still holds — not just the
        // last few days. P24 v53 rejects `after` older than 30 days, so the
        // configured window is clamped to [1, 29] to stay under that ceiling.
        // (The previous hardcoded 7-day default silently abandoned ~3 weeks of
        // still-available leads on the very first pull, and those leads then aged
        // out of the API permanently — AT lead-backfill.)
        if (!$after) {
            $firstPullDays = (int) config('services.property24_syndication.leads_first_pull_days', 28);
            $firstPullDays = max(1, min(29, $firstPullDays));
            $after = now()->subDays($firstPullDays)->toIso8601String();
        }

        $response = $api->getLeads($after);

        if (! ($response['success'] ?? false)) {
            Log::channel('property24')->warning('P24 leads pull failed', [
                'agency_id' => $agency?->id,
                'message'   => $response['message'] ?? null,
                'status'    => $response['status_code'] ?? null,
            ]);
            return ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'error' => $response['message'] ?? 'unknown'];
        }

        $payload = $response['data'] ?? [];
        $leads   = $this->extractLeads($payload);

        $inserted = 0;
        $skipped  = 0;
        $newestSeen = $after ? Carbon::parse($after) : null;

        foreach ($leads as $raw) {
            // Advance the cursor from the raw payload timestamp regardless of
            // whether the lead is inserted or deduped — otherwise a batch where
            // every lead is a dedup-skip leaves the cursor pinned to the same
            // window and we re-fetch the same 5/7/N leads every cycle.
            $rawTs = $this->parseTimestamp($this->firstNonEmpty($raw, ['leadDateTime', 'receivedAt', 'createdAt', 'timestamp', 'date']));
            if ($rawTs && (!$newestSeen || $rawTs->gt($newestSeen))) {
                $newestSeen = $rawTs;
            }

            $portalLead = $this->processLead($raw, $agency);
            if ($portalLead) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        // Prefer P24's authoritative `nextAfter` pagination token when present;
        // otherwise fall back to last-seen lead timestamp + 1s.
        $nextCursor = $payload['nextAfter'] ?? null;
        if (!$nextCursor && $newestSeen) {
            $nextCursor = $newestSeen->copy()->addSecond()->toIso8601String();
        }
        if ($nextCursor) {
            Cache::put($cursorKey, $nextCursor, now()->addDays(30));
        }

        return ['fetched' => count($leads), 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Process a single raw P24 lead payload — dedupe, match property, match
     * or create contact, persist row, fire event. Returns the saved PortalLead
     * (or null if skipped).
     */
    public function processLead(array $raw, ?Agency $agency): ?PortalLead
    {
        $agencyId = $agency?->id ?? Agency::query()->orderBy('id')->value('id');
        if (!$agencyId) {
            Log::channel('property24')->error('P24 leads — no agency_id resolvable, cannot store lead');
            return null;
        }

        $listingRef = $this->firstNonEmpty($raw, ['listingNumber', 'listingReference', 'listingId', 'p24ListingNumber']);
        $name       = trim((string) $this->firstNonEmpty($raw, ['leadName', 'name', 'fullName', 'contactName'])) ?: 'Unknown';
        $email      = $this->firstNonEmpty($raw, ['leadEmail', 'email', 'emailAddress']);
        $phone      = $this->firstNonEmpty($raw, ['leadPhoneNumber', 'phone', 'phoneNumber', 'cellNumber', 'contactNumber']);
        $message    = $this->firstNonEmpty($raw, ['leadMessage', 'message', 'enquiry']);
        $leadType   = (string) ($this->firstNonEmpty($raw, ['leadType', 'type', 'enquiryType']) ?? 'Email');
        $isWhats    = (bool) ($raw['isWhatsApp'] ?? $raw['is_whatsapp'] ?? false);
        $receivedAt = $this->parseTimestamp($this->firstNonEmpty($raw, ['leadDateTime', 'receivedAt', 'createdAt', 'timestamp', 'date'])) ?? now();
        $leadId     = (string) ($this->firstNonEmpty($raw, ['leadId', 'id']) ?? '');

        // Dedupe — same portal + listing ref + (email|phone) + received_at
        if ($this->isDuplicate($agencyId, $listingRef, $email, $phone, $receivedAt)) {
            return null;
        }

        // Resolve the property via match-or-create (rule #10).
        $listingId = $this->resolveListingId($agencyId, $listingRef, $raw);

        // Look up listing agent (for ownership assignment if we create a contact).
        $listingAgentId = $listingId
            ? Property::query()->where('id', $listingId)->value('agent_id')
            : null;

        // Match-or-create contact.
        [$contact, $existed, $existingAgentId] = $this->resolveContact(
            $agencyId,
            $name,
            $email,
            $phone,
            $listingAgentId,
            (int) $listingId,
        );

        // Persist.
        $lead = new PortalLead([
            'agency_id'                => $agencyId,
            'portal'                   => PortalLead::PORTAL_P24,
            'lead_type'                => $leadType,
            'listing_id'               => $listingId,
            'listing_portal_ref'       => $listingRef ? (string) $listingRef : null,
            'contact_id'               => $contact?->id,
            'contact_exists'           => $existed,
            'existing_contact_agent_id'=> $existed ? $existingAgentId : null,
            'name'                     => $name,
            'email'                    => $email,
            'phone'                    => $phone,
            'message'                  => $message,
            'is_whatsapp'              => $isWhats,
            'lead_source_raw'          => $raw + ['__corex_lead_id' => $leadId],
            'received_at'              => $receivedAt,
        ]);
        $lead->agency_id = $agencyId;
        $lead->save();

        // Buyer-lifecycle loop — seed a criteria-bearing wishlist DERIVED from the
        // enquired property so the enquirer becomes a real pipeline buyer (auto-lands
        // 'New' via ContactMatchObserver) and feeds MIC demand. Pipeline owner = the
        // listing agent (even for existing contacts). Wrapped so a seed failure never
        // breaks lead ingestion.
        if ($contact && $listingId) {
            $this->seedBuyerFromLead($contact, (int) $listingId, $listingAgentId, $existingAgentId, $message);
        }

        event(new NewPortalLeadReceived($lead));

        return $lead;
    }

    /**
     * Route a P24 enquiry through the SHARED buyer cascade (same method the PP path and
     * manual capture use). Owner = listing agent, falling back to the contact's owner.
     */
    private function seedBuyerFromLead(Contact $contact, int $listingId, ?int $listingAgentId, ?int $existingAgentId, ?string $message): void
    {
        try {
            $property = Property::query()->withoutGlobalScopes()->find($listingId);
            if (!$property) {
                return;
            }
            $owner = $listingAgentId ?? $contact->created_by_user_id ?? $existingAgentId;
            if (!$owner) {
                return;
            }
            app(\App\Services\Buyers\BuyerLeadCascadeService::class)->seedFromListing(
                $contact,
                $property,
                (int) $owner,
                \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_PORTAL_P24,
                $message,
            );
        } catch (\Throwable $e) {
            Log::channel('property24')->warning('P24 buyer-seed failed: ' . $e->getMessage(), [
                'contact' => $contact->id,
                'listing' => $listingId,
            ]);
        }
    }

    private function isDuplicate(int $agencyId, ?string $listingRef, ?string $email, ?string $phone, Carbon $receivedAt): bool
    {
        return PortalLead::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal', PortalLead::PORTAL_P24)
            ->where('listing_portal_ref', $listingRef)
            ->where(function ($q) use ($email, $phone) {
                $q->when($email, fn ($qq) => $qq->orWhere('email', $email))
                  ->when($phone, fn ($qq) => $qq->orWhere('phone', $phone));
            })
            ->whereBetween('received_at', [$receivedAt->copy()->subMinute(), $receivedAt->copy()->addMinute()])
            ->exists();
    }

    private function resolveListingId(int $agencyId, ?string $listingRef, array $raw): ?int
    {
        if (!$listingRef) return null;

        // Strategy 1 — direct lookup on properties.p24_listing_number / p24_ref.
        // Cheapest and works immediately for every currently-syndicated stock listing.
        $direct = Property::query()->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where(function ($q) use ($listingRef) {
                $q->where('p24_listing_number', $listingRef)
                  ->orWhere('p24_ref', $listingRef);
            })
            ->value('id');
        if ($direct) return (int) $direct;

        // Strategy 2 — TrackedProperty via match-or-create (rule #10 compliant).
        // Resolves leads for properties that were syndicated AFTER the audit-chain
        // writeP24ExternalRef hook was added.
        try {
            $tracked = $this->matchOrCreate->matchOrCreate(
                agencyId: $agencyId,
                facts: array_filter([
                    'address'      => $raw['listingAddress'] ?? $raw['address'] ?? null,
                    'suburb'       => $raw['suburb'] ?? null,
                    'latitude'     => $raw['latitude'] ?? null,
                    'longitude'    => $raw['longitude'] ?? null,
                    'property_type'=> $raw['propertyType'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
                source: [
                    'type'    => 'property24',
                    'ref'     => (string) $listingRef,
                    'payload' => $raw,
                ],
                actorUserId: null,
            );
            if (!empty($tracked->promoted_to_property_id)) {
                return (int) $tracked->promoted_to_property_id;
            }
        } catch (\Throwable $e) {
            Log::channel('property24')->warning('P24 lead match-or-create failed', [
                'listing_ref' => $listingRef,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Returns [Contact|null, existed:bool, existingAgentId:?int]
     */
    private function resolveContact(int $agencyId, string $name, ?string $email, ?string $phone, ?int $listingAgentId, int $listingId): array
    {
        // Part 4 — hardened dedupe: normalised email/phone match via the canonical
        // ContactDuplicateService (SA phone → last 9 digits, email lower/trim) so
        // "+27 76…" vs "076…" and case-different emails resolve to ONE contact instead
        // of minting duplicate buyers. Single source of truth — no bespoke normaliser.
        $existing = null;
        if ($email || $phone) {
            $existing = app(\App\Services\ContactDuplicateService::class)
                ->findDuplicates(['email' => $email, 'phone' => $phone], $agencyId)
                ->first();
        }

        if ($existing) {
            return [$existing, true, $existing->created_by_user_id];
        }

        // Create new buyer contact assigned to the listing agent.
        $buyerTypeId = ContactType::query()->where('name', 'Buyer')->value('id')
                    ?? ContactType::query()->where('name', 'Lead')->value('id');
        $sourceId    = ContactSource::query()->where('name', 'Property24')->value('id');

        [$first, $last] = $this->splitName($name);

        $contact = DB::transaction(function () use ($agencyId, $first, $last, $email, $phone, $buyerTypeId, $sourceId, $listingAgentId, $listingId) {
            $c = new Contact([
                'first_name'         => $first,
                'last_name'          => $last,
                'email'              => $email,
                'phone'              => $phone,
                'contact_type_id'    => $buyerTypeId,
                'contact_source_id'  => $sourceId,
                'created_by_user_id' => $listingAgentId,
                'agency_id'          => $agencyId,
                'notes'              => "Auto-created from Property24 lead.",
            ]);
            $c->agency_id = $agencyId;
            $c->save();

            // Attach to property as a 'lead' role if we have one.
            if ($listingId) {
                $property = Property::query()->withoutGlobalScopes()->find($listingId);
                if ($property) {
                    $property->contacts()->syncWithoutDetaching([$c->id => ['role' => 'lead']]);
                }
            }
            return $c;
        });

        return [$contact, false, null];
    }

    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') return ['Unknown', 'Lead'];
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    private function firstNonEmpty(array $arr, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($arr[$k]) && $arr[$k] !== '' && $arr[$k] !== null) {
                return is_scalar($arr[$k]) ? (string) $arr[$k] : json_encode($arr[$k]);
            }
        }
        return null;
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if (!$value) return null;
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The v53 envelope may wrap leads under various keys depending on the
     * endpoint contract. Try the obvious ones, fall back to a flat list.
     */
    private function extractLeads(array $payload): array
    {
        foreach (['messages', 'leads', 'items', 'data', 'results'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }
        // If $payload looks like a single lead (associative), wrap it.
        if (!empty($payload) && array_keys($payload) !== range(0, count($payload) - 1)) {
            return [$payload];
        }
        return $payload;
    }
}
