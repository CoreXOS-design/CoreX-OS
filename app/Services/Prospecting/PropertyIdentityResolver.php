<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\Property;
use App\Models\PropertyMatchDecision;
use App\Support\Property\PortalRef;
use Illuminate\Support\Facades\Log;

/**
 * The door (.ai/specs/property-identity.md §5): the one place that answers "is this the same property?" for every
 * ingest path. TrackedPropertyMatchOrCreateService::matchOrCreate() delegates here, so every existing caller —
 * MIC extension, portal captures, deeds, CMA bulk import, rental take-on, Road to Sold, map, seller outreach, P24/PP
 * leads — goes through it without being edited.
 *
 * The engine (TrackedPropertyMatchOrCreateService::matchOrCreateEngine) does the matching: address history,
 * canonical advert reference, precise GPS, scored erf / scheme / address with the unit rule, fuzzy. This class adds:
 *   - the confidence (exact / high / probable / weak) and the key it was decided by, returned and SAVED on the link;
 *   - possible matches below the auto-merge line recorded for an agent to confirm (never merged silently);
 *   - pre-fill of a new property file from the shared pool of public advert facts, and contribution back to it
 *     (public facts only — see SharedPortalPool).
 */
final class PropertyIdentityResolver
{
    public const DECISION_SUBJECT = 'identity_possible';

    public function __construct(
        private readonly TrackedPropertyMatchOrCreateService $engine,
        private readonly SharedPortalPool $pool,
    ) {
    }

    /**
     * Find the agency's property file for these facts, or create one.
     *
     * @param  array<string,mixed>  $facts   see TrackedPropertyMatchOrCreateService::matchOrCreate(); may carry 'portal_refs'
     * @param  array{type?:string, ref?:string, payload?:array|null}  $source
     */
    public function resolve(int $agencyId, array $facts, array $source, ?int $actorUserId = null): IdentityResult
    {
        $canonical = $this->canonicalOf($source, $facts);

        // A new agency's first sight of an advert starts from what the pool already knows publicly — applied to a NEW
        // property file only (empty fields), never used to match.
        $prefill = [];
        if ($canonical !== null && ($known = $this->safePoolFind($canonical)) !== null) {
            $prefill = array_diff_key($known, ['portal_status' => 1]);
        }

        $tp = $this->engine->matchOrCreateEngine($agencyId, $facts, $source, $actorUserId, $prefill);
        $r = $this->engine->lastResolution() ?? ['linked' => false, 'created' => true, 'confidence' => null, 'method' => null, 'possible' => [], 'strategy' => null];

        $possible = array_values(array_filter($r['possible'], fn ($p) => (int) $p['id'] !== (int) $tp->id));
        $recorded = $possible ? $this->recordPossibles($agencyId, $tp, $possible, $source, $canonical, $facts) : [];

        if ($canonical !== null) {
            $this->safePoolContribute($canonical, $facts);
        }

        return new IdentityResult(
            trackedProperty: $tp,
            created: ! $r['linked'],
            confidence: $r['linked'] ? (string) $r['confidence'] : ($canonical !== null ? 'exact' : 'none'),
            method: $r['linked'] ? $r['method'] : ($canonical !== null ? 'portal_ref' : null),
            possible: $recorded,
            canonicalRef: $canonical,
            strategy: $r['strategy'],
        );
    }

    /**
     * Find only — never creates (spec §7: comps, alerts and deals attach to a property file that exists; they do not
     * mint new ones). The result's trackedProperty is null when nothing is certain enough to link.
     */
    public function find(int $agencyId, array $facts, array $source = []): IdentityResult
    {
        $canonical = $this->canonicalOf($source, $facts);
        $tp = $this->engine->findExistingMatch($agencyId, $facts, $source);
        $r = $this->engine->lastResolution() ?? ['linked' => false, 'confidence' => null, 'method' => null, 'possible' => [], 'strategy' => null];

        if ($tp === null) {
            return new IdentityResult(null, false, 'none', null, $r['possible'], $canonical, null);
        }

        return new IdentityResult($tp, false, (string) $r['confidence'], $r['method'], $r['possible'], $canonical, $r['strategy']);
    }

    /**
     * Attach an own-stock property to its property file (spec §7 routes 1-3, 5-8, 10). Creates the file when nothing
     * matches. Saves the link on the property (tracked_property_id + confidence + method) without touching
     * promoted_to_property_id — a claim is a separate act (promote), not a side effect of a match.
     */
    public function linkProperty(Property $property, bool $allowCreate = true): ?IdentityResult
    {
        if (! (int) $property->agency_id) {
            return null;
        }

        $facts = $this->factsFromProperty($property);
        $source = ['type' => 'agency_stock', 'ref' => 'property:' . $property->id, 'payload' => ['property_id' => $property->id]];

        $result = $allowCreate
            ? $this->resolve((int) $property->agency_id, $facts, $source, null)
            : $this->find((int) $property->agency_id, $facts, $source);

        if ($result->trackedProperty !== null) {
            Property::withoutEvents(function () use ($property, $result) {
                \Illuminate\Support\Facades\DB::table('properties')->where('id', $property->id)->update([
                    'tracked_property_id' => $result->trackedProperty->id,
                    'identity_confidence' => $result->created ? 'exact' : $result->confidence,
                    'identity_method'     => $result->created ? 'new_file' : $result->method,
                ]);
            });
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function factsFromProperty(Property $p): array
    {
        $refs = [];
        if (! empty($p->p24_ref)) {
            $refs[] = ['type' => 'property24', 'ref' => (string) $p->p24_ref];
        } elseif (! empty($p->p24_listing_number)) {
            $refs[] = ['type' => 'property24', 'ref' => (string) $p->p24_listing_number];
        }
        if (! empty($p->pp_ref)) {
            $refs[] = ['type' => 'private_property', 'ref' => (string) $p->pp_ref];
        }

        $line = \App\Services\Address\AddressLineFacts::split((string) ($p->address ?? ''), (string) $p->suburb) ?? [];

        return array_filter([
            'address'                 => $p->address,
            'street_number'           => $p->street_number ?: ($line['street_number'] ?? null),
            'street_name'             => $p->street_name ?: ($line['street_name'] ?? null),
            'unit_number'             => $p->unit_number ?: ($line['unit_number'] ?? null),
            'complex_name'            => $p->complex_name ?: ($line['complex_name'] ?? null),
            'suburb'                  => $p->suburb,
            'town'                    => $p->town ?: $p->city,
            'province'                => $p->province,
            'latitude'                => $p->latitude,
            'longitude'               => $p->longitude,
            'geo_confidence'          => $p->geo_confidence,
            'erf_number'              => $p->erf_number,
            'erf_portion'             => $p->erf_portion,
            'title_deed_number'       => $p->title_deed_number,
            'lpi_code'                => $p->lpi_code,
            'property_type'           => $p->property_type,
            'bedrooms'                => $p->beds,
            'bathrooms'               => $p->baths,
            'garages'                 => $p->garages,
            'last_known_asking_price' => $p->price,
            'portal_refs'             => $refs ?: null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /** The canonical advert reference of the source (or of the first extra ref), if any. */
    private function canonicalOf(array $source, array $facts): ?string
    {
        $candidates = [$source];
        foreach ((array) ($facts['portal_refs'] ?? []) as $r) {
            if (is_array($r)) {
                $candidates[] = $r;
            }
        }
        foreach ($candidates as $c) {
            if (! empty($c['type']) && ! empty($c['ref'])) {
                $canonical = TrackedPropertyExternalRef::canonicalFor((string) $c['type'], (string) $c['ref'], is_array($c['payload'] ?? null) ? $c['payload'] : null);
                if ($canonical !== null) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /**
     * Record each possible match for an agent to confirm (spec §5.3). One decision per (subject, candidate): not
     * repeated while pending, and never offered again once an agent said "not the same" (the veto).
     *
     * @param  list<array{id:int, confidence:string, method:string, reason:string, score:?int}>  $possible
     * @return list<array{id:int, confidence:string, method:string, reason:string, score:?int}>
     */
    private function recordPossibles(int $agencyId, TrackedProperty $tp, array $possible, array $source, ?string $canonical, array $facts): array
    {
        $subjectKey = $canonical
            ?? (! empty($source['type']) && ! empty($source['ref']) ? $source['type'] . ':' . $source['ref'] : 'tp:' . $tp->id);
        $decisions = app(PropertyMatchDecisionService::class);
        $candidates = array_map(fn ($p) => ['type' => 'tracked_property', 'id' => $p['id'], 'label' => '', 'confidence' => $p['confidence'], 'method' => $p['method']], $possible);
        $snapshot = array_diff_key($facts, ['portal_refs' => 1]);

        $out = [];
        foreach ($possible as $p) {
            try {
                if ($decisions->isRejected($agencyId, self::DECISION_SUBJECT, $subjectKey, 'tracked_property', (int) $p['id'])) {
                    continue;
                }
                $exists = PropertyMatchDecision::queryWithoutAgencyScope()
                    ->where('agency_id', $agencyId)
                    ->where('subject_type', self::DECISION_SUBJECT)
                    ->where('subject_key', $subjectKey)
                    ->where('matched_id', (int) $p['id'])
                    ->exists();
                if (! $exists) {
                    $decision = $decisions->record(
                        agencyId: $agencyId,
                        subjectType: self::DECISION_SUBJECT,
                        subjectKey: $subjectKey,
                        matchedType: 'tracked_property',
                        matchedId: (int) $p['id'],
                        strategy: (string) $p['method'],
                        reason: ucfirst((string) $p['confidence']) . ' match: ' . $p['reason'],
                        candidates: $candidates,
                        incomingFacts: ['facts' => $snapshot, 'incoming_tracked_property_id' => (int) $tp->id],
                        confidenceScore: $p['score'],
                    );
                    $decisions->recordOutcome($decision, 'pending');
                }
                $out[] = $p;
            } catch (\Throwable $e) {
                Log::warning('PropertyIdentityResolver: could not record a possible match', ['agency_id' => $agencyId, 'error' => $e->getMessage()]);
            }
        }

        return $out;
    }

    private function safePoolFind(string $canonical): ?array
    {
        try {
            return $this->pool->find($canonical);
        } catch (\Throwable $e) {
            Log::warning('SharedPortalPool read failed', ['canonical' => $canonical, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function safePoolContribute(string $canonical, array $facts): void
    {
        try {
            $this->pool->contribute($canonical, $facts);
        } catch (\Throwable $e) {
            Log::warning('SharedPortalPool write failed', ['canonical' => $canonical, 'error' => $e->getMessage()]);
        }
    }
}
