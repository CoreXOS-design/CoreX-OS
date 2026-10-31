<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\P24Listing;
use App\Models\PpAlertListing;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How the agency knows about a property (property-identity.md section 15, "How we know this property"): which doors it came in by - e-mail
 * alert, browser extension capture, deeds, manual entry, import - which portals, the advert refs, first and last seen. Screens only: nothing
 * here matches, resolves or merges. Every read names the agency, so another agency's alert for the same advert can never count.
 */
final class PropertySourceFacts
{
    public const EMAIL = 'email';
    public const EXTENSION = 'extension';
    public const DEEDS = 'deeds';

    /** Filter value => label, in the order the filter rail shows them. */
    public const FILTERS = [self::EMAIL => 'Email alert', self::EXTENSION => 'Extension only', self::DEEDS => 'Deeds'];

    /** Source type on an external ref => the door it stands for. Adverts (p24/pp) are placed by the alert / listing tables, not by this map. */
    private const REF_DOORS = [
        'chrome_capture' => 'Extension',
        'deeds_capture' => 'Deeds',
        'manual_prospect_entry' => 'Manual',
        'map_prospect_launch' => 'Manual',
        'cmainfo' => 'Import',
        'property24' => 'Import',
        'private_property' => 'Import',
    ];

    /**
     * One property file: ['sources' => [..labels], 'portals' => ['P24','PP'], 'refs' => [..advert refs], 'first_seen' => ?string, 'last_seen' => ?string].
     *
     * @param  list<array{portal:string, number:string, first_seen:?string, last_seen:?string}>  $alerts  PropertyAlertFacts::for() rows
     * @param  iterable<object>  $linkedListings  the property's captured portal listings (portal_source, portal_ref, first_seen_at)
     * @param  iterable<\App\Models\Prospecting\TrackedPropertyExternalRef>  $externalRefs
     * @return array{sources:list<string>, portals:list<string>, refs:list<string>, first_seen:?string, last_seen:?string}
     */
    public function forProperty(array $alerts, iterable $linkedListings, iterable $externalRefs): array
    {
        $sources = [];
        $portals = [];
        $refs = [];
        $first = [];
        $last = [];

        if ($alerts) {
            $sources['Email alert'] = true;
        }
        foreach ($alerts as $a) {
            $portals[$a['portal'] === 'Property24' ? 'P24' : 'PP'] = true;
            $refs[(string) $a['number']] = true;
        }

        $captured = false;
        foreach ($linkedListings as $l) {
            $captured = true;
            $portals[$l->portal_source === 'pp' ? 'PP' : 'P24'] = true;
            $refs[(string) $l->portal_ref] = true;
            if ($l->first_seen_at) {
                $first[] = strtotime((string) $l->first_seen_at);
            }
        }

        foreach ($externalRefs as $r) {
            $type = (string) $r->source_type;
            if (isset(self::REF_DOORS[$type])) {
                $sources[self::REF_DOORS[$type]] = true;
            }
            if (str_starts_with((string) $r->canonical_ref, 'p24:')) {
                $portals['P24'] = true;
                $refs[(string) $r->source_ref] = true;
            } elseif (str_starts_with((string) $r->canonical_ref, 'pp:')) {
                $portals['PP'] = true;
                $refs[(string) $r->source_ref] = true;
            }
            if ($r->first_seen_at) {
                $first[] = $r->first_seen_at->getTimestamp();
            }
            if ($r->last_seen_at) {
                $last[] = $r->last_seen_at->getTimestamp();
            }
        }
        foreach ($alerts as $a) {
            if (! empty($a['first_seen'])) {
                $first[] = strtotime($a['first_seen']);
            }
            if (! empty($a['last_seen'])) {
                $last[] = strtotime($a['last_seen']);
            }
        }
        if ($captured) {
            $sources['Extension'] = true;
        }

        $order = ['Email alert', 'Extension', 'Deeds', 'Manual', 'Import'];
        $sorted = array_values(array_filter($order, fn ($s) => isset($sources[$s])));
        $portalOrder = array_values(array_filter(['P24', 'PP'], fn ($p) => isset($portals[$p])));

        return [
            'sources' => $sorted,
            'portals' => $portalOrder,
            'refs' => array_keys($refs),
            'first_seen' => $first ? date('j M Y', min($first)) : null,
            'last_seen' => $last ? date('j M Y', max($last)) : null,
        ];
    }

    /**
     * List rows: per listing id, the source tag and the alert marker.
     *
     * @param  iterable<\App\Models\ProspectingListing>  $listings
     * @return array<int, array{tag:string, alert:bool, change:?array{old:float,new:float,date:string}, last_seen:?string}>
     */
    public function forListings(int $agencyId, iterable $listings): array
    {
        $byPortal = ['p24' => [], 'pp' => []];
        $tpIds = [];
        foreach ($listings as $l) {
            if ($l->canonical_ref && isset($byPortal[$l->portal_source])) {
                $byPortal[$l->portal_source][$l->canonical_ref] = true;
            }
            if ($l->tracked_property_id) {
                $tpIds[(int) $l->tracked_property_id] = true;
            }
        }

        $alertByRef = [];
        foreach (AlertPropertyLinker::PORTALS as $portal => $cfg) {
            if (! $byPortal[$portal]) {
                continue;
            }
            $cfg['model']::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agencyId)->whereIn('canonical_ref', array_keys($byPortal[$portal]))
                ->with(['priceChanges' => fn ($q) => $q->orderByDesc('change_date')->orderByDesc('id')->limit(1)])
                ->get()->each(function ($a) use (&$alertByRef) {
                    $c = $a->priceChanges->first();
                    $alertByRef[$a->canonical_ref] = [
                        'change' => $c ? ['old' => (float) $c->old_price, 'new' => (float) $c->new_price, 'date' => $c->change_date->format('j M')] : null,
                        'last_seen' => $a->last_seen_date?->format('j M'),
                    ];
                });
        }

        $deedsTp = $tpIds ? DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('source_type', 'deeds_capture')
            ->whereNull('deleted_at')->whereIn('tracked_property_id', array_keys($tpIds))->pluck('tracked_property_id')->flip()->all() : [];

        $out = [];
        foreach ($listings as $l) {
            $alert = $alertByRef[$l->canonical_ref] ?? null;
            $deeds = $l->tracked_property_id && isset($deedsTp[(int) $l->tracked_property_id]);
            $out[(int) $l->id] = [
                'tag' => $alert ? 'Email alert' : ($deeds ? 'Deeds' : 'Extension'),
                'alert' => $alert !== null,
                'change' => $alert['change'] ?? null,
                'last_seen' => $alert['last_seen'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Source filter on the listings query (alias-free: the listings table's own columns). Agency named inside every subquery.
     */
    public function applyFilter(Builder $query, string $source, int $agencyId): void
    {
        $hasAlert = function ($q) use ($agencyId) {
            $q->where(function ($w) use ($agencyId) {
                foreach (AlertPropertyLinker::PORTALS as $portal => $cfg) {
                    $w->orWhere(function ($p) use ($portal, $cfg, $agencyId) {
                        $p->where('prospecting_listings.portal_source', $portal)->whereExists(function ($e) use ($cfg, $agencyId) {
                            $e->select(DB::raw(1))->from($cfg['table'])->where($cfg['table'] . '.agency_id', $agencyId)
                                ->whereColumn($cfg['table'] . '.canonical_ref', 'prospecting_listings.canonical_ref');
                        });
                    });
                }
            });
        };
        $hasDeeds = fn ($q) => $q->whereExists(function ($e) use ($agencyId) {
            $e->select(DB::raw(1))->from('tracked_property_external_refs as tper')->where('tper.agency_id', $agencyId)
                ->where('tper.source_type', 'deeds_capture')->whereNull('tper.deleted_at')
                ->whereColumn('tper.tracked_property_id', 'prospecting_listings.tracked_property_id');
        });

        match ($source) {
            self::EMAIL => $query->where($hasAlert),
            self::DEEDS => $query->where($hasDeeds)->whereNot($hasAlert),
            self::EXTENSION => $query->whereNot($hasAlert)->whereNot($hasDeeds),
            default => null,
        };
    }
}
