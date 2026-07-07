<?php

namespace App\Services\PrivateProperty;

use App\Events\Leads\NewPortalLeadReceived;
use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactSource;
use App\Models\ContactType;
use App\Models\PortalLead;
use App\Models\Property;
use App\Services\Buyers\BuyerLeadCascadeService;
use App\Services\ContactDuplicateService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingests Private Property buyer-enquiry leads via the Agency Feed Service
 * (SOAP) operation `ListingLeadDetailsFeed` and persists them to portal_leads
 * — the P24-parity intake channel. Mirrors P24LeadService one-for-one so PP
 * leads flow the SAME downstream loop (dedup → property match-or-create →
 * contact resolve/create-as-Buyer → BuyerLeadCascadeService → NewPortalLeadReceived).
 *
 * DORMANT BY DEFAULT: pullForAllAgencies() only touches agencies whose
 * `pp_lead_pull_enabled` flag is ON (and that have working PP credentials).
 * With the flag OFF the scheduler still ticks but this service is a no-op —
 * the whole blast-radius story. A SOAP fault logs + skips cleanly; it never
 * throws, so one bad agency never breaks the run or the P24 pull.
 *
 * Complies with CLAUDE.md rule #10 — every lead with a resolvable PP listing
 * reference is routed through TrackedPropertyMatchOrCreateService.
 */
class PpLeadService
{
    private const CURSOR_CACHE_KEY = 'pp.leads.cursor.agency.';

    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $matchOrCreate,
        private readonly ContactDuplicateService $duplicates,
    ) {
    }

    /**
     * Pull leads for every agency that has the PP lead-pull toggle ON and
     * usable PP credentials. Returns counts per agency for the calling job.
     * The toggle is the kill-switch: an agency with pp_lead_pull_enabled=false
     * is silently skipped.
     */
    public function pullForAllAgencies(): array
    {
        $results = [];

        $agencies = Agency::query()
            ->withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('pp_lead_pull_enabled', true)
            ->get();

        foreach ($agencies as $agency) {
            $cfg = PrivatePropertyConfig::for($agency);

            // Guard: toggle on but credentials missing → skip loudly-but-safely.
            if (empty($cfg['username']) || empty($cfg['password']) || empty($cfg['branch_guid'])) {
                Log::channel('private_property')->warning('PP lead pull: toggle on but PP credentials incomplete — skipping', [
                    'agency_id' => $agency->id,
                ]);
                $results[$agency->id] = ['skipped_no_creds' => true];
                continue;
            }

            $results[$agency->id] = $this->pullLeads($agency);
        }

        if (empty($results)) {
            // No agency has the toggle on — the dormant steady-state.
            return ['dormant' => true];
        }

        return $results;
    }

    /**
     * Pull leads for one agency. Failure-contained: a SOAP fault returns a
     * clean error result and NEVER throws.
     */
    public function pullLeads(Agency $agency): array
    {
        $cursorKey = self::CURSOR_CACHE_KEY . $agency->id;
        $after     = Cache::get($cursorKey);

        // First run (no cursor): default to 7 days back — recent leads without
        // an unbounded backfill flood.
        if (! $after) {
            $after = now()->subDays(7)->format('Y-m-d\TH:i:s');
        }

        try {
            $client   = app(PrivatePropertySoapClient::class)->forAgency($agency);
            $response = $client->listingLeadDetailsFeed($after);
        } catch (\Throwable $e) {
            // Belt-and-braces: the SOAP client already contains faults, but a
            // construction/config error must not escape and kill the run.
            Log::channel('private_property')->error('PP lead pull threw (contained)', [
                'agency_id' => $agency->id,
                'error'     => $e->getMessage(),
            ]);
            return ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'error' => $e->getMessage()];
        }

        // The client surfaces SOAP faults as ['error' => true, 'message' => ...].
        if (($response['error'] ?? false) === true) {
            Log::channel('private_property')->warning('PP lead pull SOAP fault (skipped)', [
                'agency_id' => $agency->id,
                'message'   => $response['message'] ?? null,
            ]);
            return ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'error' => $response['message'] ?? 'soap_fault'];
        }

        $leads = $this->extractLeads($response);

        $inserted   = 0;
        $skipped    = 0;
        $newestSeen = Carbon::parse($after);

        foreach ($leads as $raw) {
            // Advance the cursor from the lead's own timestamp regardless of
            // insert vs dedup-skip — otherwise an all-dedup batch pins the
            // cursor and we re-fetch the same window forever.
            $rawTs = $this->parseTimestamp($this->firstNonEmpty($raw, ['Date', 'LeadDate', 'date']));
            if ($rawTs && $rawTs->gt($newestSeen)) {
                $newestSeen = $rawTs;
            }

            if ($this->processLead($raw, $agency)) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        // Advance the cursor to just past the newest lead we saw. +1s so the
        // next pull's StartDate does not re-return the boundary lead.
        $nextCursor = $newestSeen->copy()->addSecond()->format('Y-m-d\TH:i:s');
        Cache::put($cursorKey, $nextCursor, now()->addDays(30));

        return ['fetched' => count($leads), 'inserted' => $inserted, 'skipped' => $skipped];
    }

    /**
     * Process one raw PP lead — STRICT dedup by PP LeadId, resolve property,
     * match/create contact, persist, seed the buyer loop, fire the event.
     * Returns the saved PortalLead or null if deduped/unstorable.
     */
    public function processLead(array $raw, Agency $agency): ?PortalLead
    {
        $agencyId = $agency->id;

        $leadId     = (string) ($this->firstNonEmpty($raw, ['LeadId', 'leadId', 'Id']) ?? '');
        $listingRef = $this->firstNonEmpty($raw, ['PPRef', 'UniqueListingId', 'ppRef', 'uniqueListingId']);
        $name       = trim((string) ($this->firstNonEmpty($raw, ['FromName', 'fromName', 'Name']) ?? '')) ?: 'Unknown';
        $email      = $this->firstNonEmpty($raw, ['FromEmail', 'fromEmail', 'Email']);
        $phone      = $this->firstNonEmpty($raw, ['FromContactNumber', 'fromContactNumber', 'ContactNumber', 'Phone']);
        $message    = $this->firstNonEmpty($raw, ['Message', 'message']);
        $receivedAt = $this->parseTimestamp($this->firstNonEmpty($raw, ['Date', 'LeadDate', 'date'])) ?? now();

        // STRICT dedup: PP LeadId is a stable, unique enquiry id. Re-pulls of an
        // overlapping window MUST create zero duplicates. When present, LeadId is
        // authoritative; only fall back to the composite key if PP omits it.
        if ($this->isDuplicate($agencyId, $leadId, $listingRef, $email, $phone, $receivedAt)) {
            return null;
        }

        // Resolve the CoreX property (rule #10).
        $listingId      = $this->resolveListingId($agencyId, $raw, $listingRef);
        $listingAgentId = $listingId
            ? Property::query()->withoutGlobalScopes()->where('id', $listingId)->value('agent_id')
            : null;

        [$contact, $existed, $existingAgentId] = $this->resolveContact(
            $agencyId, $name, $email, $phone, $listingAgentId, (int) $listingId
        );

        $lead = new PortalLead([
            'agency_id'                 => $agencyId,
            'portal'                    => PortalLead::PORTAL_PP,
            'lead_type'                 => (string) ($this->firstNonEmpty($raw, ['LeadType', 'leadType']) ?? 'Email'),
            'listing_id'                => $listingId,
            'listing_portal_ref'        => $listingRef ? (string) $listingRef : null,
            'contact_id'                => $contact?->id,
            'contact_exists'            => $existed,
            'existing_contact_agent_id' => $existed ? $existingAgentId : null,
            'name'                      => $name,
            'email'                     => $email,
            'phone'                     => $phone,
            'message'                   => $message,
            'is_whatsapp'               => false,
            // __corex_lead_id is the dedup key — always present so a re-pull is
            // idempotent even when the composite fields repeat.
            'lead_source_raw'           => $raw + ['__corex_lead_id' => $leadId],
            'received_at'               => $receivedAt,
        ]);
        $lead->agency_id = $agencyId;
        $lead->save();

        // Same buyer cascade the P24 + website + webhook paths use — identical
        // downstream behaviour, PP source label.
        if ($contact && $listingId) {
            $this->seedBuyerFromLead($contact, (int) $listingId, $listingAgentId, $existingAgentId, $message);
        }

        event(new NewPortalLeadReceived($lead));

        return $lead;
    }

    private function seedBuyerFromLead(Contact $contact, int $listingId, ?int $listingAgentId, ?int $existingAgentId, ?string $message): void
    {
        try {
            $property = Property::query()->withoutGlobalScopes()->find($listingId);
            if (! $property) {
                return;
            }
            $owner = $listingAgentId ?? $contact->created_by_user_id ?? $existingAgentId;
            if (! $owner) {
                return;
            }
            app(BuyerLeadCascadeService::class)->seedFromListing(
                $contact,
                $property,
                (int) $owner,
                BuyerLeadCascadeService::SOURCE_PORTAL_PP,
                $message,
            );
        } catch (\Throwable $e) {
            Log::channel('private_property')->warning('PP buyer-seed failed: ' . $e->getMessage(), [
                'contact' => $contact->id,
                'listing' => $listingId,
            ]);
        }
    }

    /**
     * STRICT dedup. LeadId is authoritative when present (matched inside the
     * JSON payload we persist). Composite fallback for the rare no-LeadId lead.
     */
    private function isDuplicate(int $agencyId, string $leadId, ?string $listingRef, ?string $email, ?string $phone, Carbon $receivedAt): bool
    {
        $base = PortalLead::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal', PortalLead::PORTAL_PP);

        if ($leadId !== '') {
            return (clone $base)
                ->where('lead_source_raw->__corex_lead_id', $leadId)
                ->exists();
        }

        return $base
            ->where('listing_portal_ref', $listingRef)
            ->where(function ($q) use ($email, $phone) {
                $q->when($email, fn ($qq) => $qq->orWhere('email', $email))
                  ->when($phone, fn ($qq) => $qq->orWhere('phone', $phone));
            })
            ->whereBetween('received_at', [$receivedAt->copy()->subMinute(), $receivedAt->copy()->addMinute()])
            ->exists();
    }

    /**
     * Resolve the CoreX property for a PP lead.
     *  1. UniqueListingId — the ListingFeedRef WE submitted (our property id).
     *  2. PPRef — PP's own T-number stored on properties.pp_ref.
     *  3. TrackedProperty match-or-create (rule #10) from any address facts.
     */
    private function resolveListingId(int $agencyId, array $raw, ?string $listingRef): ?int
    {
        $unique = $this->firstNonEmpty($raw, ['UniqueListingId', 'uniqueListingId']);
        $ppRef  = $this->firstNonEmpty($raw, ['PPRef', 'ppRef']);

        // Strategy 1 — UniqueListingId is our own property id (the feed ref).
        if ($unique !== null && is_numeric($unique)) {
            $direct = Property::query()->withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('id', (int) $unique)
                ->value('id');
            if ($direct) {
                return (int) $direct;
            }
        }

        // Strategy 2 — PPRef → properties.pp_ref / pp_listing_feed_ref.
        if ($ppRef) {
            $byRef = Property::query()->withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where(function ($q) use ($ppRef) {
                    $q->where('pp_ref', $ppRef)
                      ->orWhere('pp_listing_feed_ref', $ppRef);
                })
                ->value('id');
            if ($byRef) {
                return (int) $byRef;
            }
        }

        // Strategy 3 — match-or-create from address facts (rule #10).
        try {
            $facts = array_filter([
                'address'       => $raw['ListingAddress'] ?? $raw['Address'] ?? null,
                'suburb'        => $raw['Suburb'] ?? null,
                'property_type' => $raw['PropertyType'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            if (! empty($facts) || $listingRef) {
                $tracked = $this->matchOrCreate->matchOrCreate(
                    agencyId: $agencyId,
                    facts: $facts,
                    source: [
                        'type'    => 'private_property',
                        'ref'     => (string) ($listingRef ?? $ppRef ?? $unique ?? ''),
                        'payload' => $raw,
                    ],
                    actorUserId: null,
                );
                if (! empty($tracked->promoted_to_property_id)) {
                    return (int) $tracked->promoted_to_property_id;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('private_property')->warning('PP lead match-or-create failed', [
                'listing_ref' => $listingRef,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return array{0: ?Contact, 1: bool, 2: ?int}  [contact, existed, existingAgentId]
     */
    private function resolveContact(int $agencyId, string $name, ?string $email, ?string $phone, ?int $listingAgentId, int $listingId): array
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
        $sourceId    = ContactSource::query()->where('name', 'Private Property')->value('id');

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
                'notes'              => 'Auto-created from Private Property lead.',
            ]);
            $c->agency_id = $agencyId;
            $c->save();

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
        if ($name === '') {
            return ['Unknown', 'Lead'];
        }
        $parts = preg_split('/\s+/', $name, 2);
        return [$parts[0], $parts[1] ?? ''];
    }

    /**
     * Unwrap the PP SOAP envelope to a flat list of lead arrays. Handles the
     * result wrapper and the single-vs-array shape PHP's SoapClient produces
     * (one lead → assoc; many → list under ListingLeadDetail).
     */
    private function extractLeads(array $response): array
    {
        // Peel the *Result wrapper(s).
        $node = $response;
        foreach (['ListingLeadDetailsFeedResult', 'ListingLeadDetails', 'ArrayOfListingLeadDetail'] as $key) {
            if (isset($node[$key]) && is_array($node[$key])) {
                $node = $node[$key];
            }
        }

        // The lead collection.
        $list = $node['ListingLeadDetail'] ?? $node;

        if (! is_array($list) || empty($list)) {
            return [];
        }

        // Single lead → SoapClient returns an assoc array, not a list. Wrap it.
        if ($this->isAssoc($list)) {
            return [$list];
        }

        // Filter to array rows only (defensive against scalar noise).
        return array_values(array_filter($list, 'is_array'));
    }

    private function isAssoc(array $arr): bool
    {
        return array_keys($arr) !== range(0, count($arr) - 1);
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
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
