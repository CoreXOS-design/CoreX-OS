<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\P24Listing;
use App\Models\PpAlertListing;
use App\Support\Property\PortalRef;
use Illuminate\Support\Facades\DB;

/**
 * Ties an e-mail alert advert to its property file THROUGH THE DOOR (property-identity.md section 15, C1).
 *
 * An alert carries an advert number and a suburb, never an address, so the only key it can offer is the canonical advert reference. The
 * door (PropertyIdentityResolver::find) answers from that reference within the alert's own agency; this class saves the answer (property
 * file id + confidence + method) on the alert row. It never creates a property file and never merges one - an alert with no property file
 * yet stays unlinked and is picked up on the next pass (the importer, or the `alerts:link-properties` command).
 */
final class AlertPropertyLinker
{
    public const RESULT_LINKED = 'linked';
    public const RESULT_ALREADY = 'already';
    public const RESULT_NO_MATCH = 'no_match';
    public const RESULT_UNREADABLE = 'unreadable';

    /** portal => [table, advert-number column, model]. */
    public const PORTALS = [
        'p24' => ['table' => 'p24_listings', 'number' => 'p24_listing_number', 'model' => P24Listing::class],
        'pp'  => ['table' => 'pp_alert_listings', 'number' => 'pp_listing_number', 'model' => PpAlertListing::class],
    ];

    public function __construct(private readonly PropertyIdentityResolver $door)
    {
    }

    /**
     * @return array{result:string, tracked_property_id:?int, confidence:?string, method:?string, canonical_ref:?string}
     */
    public function link(string $portal, int $alertId, int $agencyId, ?string $number, ?int $currentTrackedPropertyId = null, bool $write = true): array
    {
        $cfg = self::PORTALS[$portal] ?? throw new \InvalidArgumentException("Unknown alert portal '{$portal}'.");
        $canonical = PortalRef::normalise($number, $portal);
        $out = ['result' => self::RESULT_UNREADABLE, 'tracked_property_id' => null, 'confidence' => null, 'method' => null, 'canonical_ref' => $canonical];

        if ($canonical === null || $agencyId <= 0) {
            return $out;
        }
        if ($currentTrackedPropertyId) {
            return ['result' => self::RESULT_ALREADY, 'tracked_property_id' => $currentTrackedPropertyId] + $out;
        }

        // the advert goes in as an extra reference and the source is the alert itself, so the door decides on the canonical reference (exact), not on a raw string
        $found = $this->door->find($agencyId, ['portal_refs' => [['type' => $portal, 'ref' => (string) $number]]], ['type' => 'email_alert', 'ref' => $canonical]);
        if ($found->trackedProperty === null) {
            return ['result' => self::RESULT_NO_MATCH] + $out;
        }

        $out = [
            'result' => self::RESULT_LINKED, 'tracked_property_id' => (int) $found->trackedProperty->id,
            'confidence' => $found->confidence, 'method' => $found->method, 'canonical_ref' => $canonical,
        ];
        if ($write) {
            DB::table($cfg['table'])->where('id', $alertId)->where('agency_id', $agencyId)->update([
                'tracked_property_id' => $out['tracked_property_id'], 'identity_confidence' => $out['confidence'], 'identity_method' => $out['method'],
            ]);
        }

        return $out;
    }

    /** Link one saved alert model (the importers call this after every create / update). Failure-isolated by the caller. */
    public function linkModel(P24Listing|PpAlertListing $alert): array
    {
        $portal = $alert instanceof P24Listing ? 'p24' : 'pp';
        $cfg = self::PORTALS[$portal];

        return $this->link($portal, (int) $alert->id, (int) $alert->agency_id, (string) $alert->{$cfg['number']}, $alert->tracked_property_id ? (int) $alert->tracked_property_id : null);
    }
}
