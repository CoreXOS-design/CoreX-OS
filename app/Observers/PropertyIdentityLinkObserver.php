<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Deal;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\P24Listing;
use App\Models\PresentationSoldComp;
use App\Models\Property;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Support\Property\PortalRef;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Attaches rows that had no property-file link to their property file the moment they are saved
 * (.ai/specs/property-identity.md §7). One choke point per table instead of a hook in every controller:
 * every Eloquent create path — web form, wizard, mobile API, pull from portal, CSV and sold imports,
 * other-agency-stock import, MIC listing promote, clone — goes through it.
 *
 *  - Property (own stock): find-or-CREATE the property file, save the link + confidence on the property.
 *  - CMA comparable rows, presentation sold comps, P24 e-mail alerts, deals: FIND only (a comp or an alert is
 *    evidence about a property; it does not mint a property file of its own). No match = no link.
 *
 * Failure-isolated: a resolver failure is logged and never blocks the save. Off when
 * config('property-identity.link_on_create') is false (the test environment; the feature's own tests turn it on).
 */
final class PropertyIdentityLinkObserver
{
    /** Set by a caller that has already decided the property file (promoteToStock). */
    public static bool $suppressed = false;

    /** Run $fn with the create hook off (a caller that has already decided the property file); always restored. */
    public static function suppress(callable $fn): mixed
    {
        $before = self::$suppressed;
        self::$suppressed = true;
        try {
            return $fn();
        } finally {
            self::$suppressed = $before;
        }
    }

    public function propertyCreated(Property $property): void
    {
        $this->guard(fn () => app(PropertyIdentityResolver::class)->linkProperty($property, true), 'property', $property->getKey());
    }

    public function compRowCreated(MarketReportCompRow $row): void
    {
        $this->guard(function () use ($row) {
            $address = trim((string) $row->address);
            if ($address === '' || ! $row->agency_id) {
                return;
            }
            $line = \App\Services\Address\AddressLineFacts::split($address, (string) $row->suburb_normalised) ?? [];
            $facts = array_filter([
                'address'       => $address,
                'street_number' => $line['street_number'] ?? null,
                'street_name'   => $line['street_name'] ?? null,
                'unit_number'   => $line['unit_number'] ?? ($row->flat_number ?: null),
                'complex_name'  => $line['complex_name'] ?? ($row->scheme_name ?: null),
                'section_number' => $row->section_number ?: null,
                'suburb'        => $row->suburb_normalised,
                'latitude'      => null, // comp pins are approximate; a comp never links on GPS
            ], fn ($v) => $v !== null && $v !== '');
            $this->attach('market_report_comp_rows', (int) $row->id, (int) $row->agency_id, $facts, ['type' => 'cma_comp', 'ref' => 'comp:' . $row->id]);
        }, 'cma comp row', $row->getKey());
    }

    public function soldCompCreated(PresentationSoldComp $comp): void
    {
        $this->guard(function () use ($comp) {
            $raw = is_array($comp->raw_row_json) ? $comp->raw_row_json : (json_decode((string) $comp->raw_row_json, true) ?: []);
            $address = trim((string) ($raw['address'] ?? ''));
            if (($address === '' && empty($raw['erf_no'])) || ! $comp->agency_id) {
                return;
            }
            $line = \App\Services\Address\AddressLineFacts::split($address, (string) $comp->suburb) ?? [];
            $facts = array_filter([
                'address'       => $address ?: null,
                'street_number' => $line['street_number'] ?? null,
                'street_name'   => $line['street_name'] ?? null,
                'unit_number'   => $line['unit_number'] ?? null,
                'complex_name'  => $line['complex_name'] ?? null,
                'suburb'        => $comp->suburb,
                'erf_number'    => $raw['erf_no'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            $this->attach('presentation_sold_comps', (int) $comp->id, (int) $comp->agency_id, $facts, ['type' => 'presentation_comp', 'ref' => 'comp:' . $comp->id]);
        }, 'presentation sold comp', $comp->getKey());
    }

    public function alertSaved(P24Listing $alert): void
    {
        $this->guard(function () use ($alert) {
            $canonical = PortalRef::normalise($alert->p24_listing_number, PortalRef::P24);
            if ($canonical === null || ! $alert->agency_id || $alert->tracked_property_id) {
                return;
            }
            // An alert carries a suburb and an advert number, no address: it attaches by advert number only.
            $row = DB::table('tracked_property_external_refs')
                ->where('agency_id', $alert->agency_id)->where('canonical_ref', $canonical)->whereNull('deleted_at')
                ->orderBy('id')->first();
            if ($row !== null) {
                DB::table('p24_listings')->where('id', $alert->id)->update([
                    'tracked_property_id' => $row->tracked_property_id, 'identity_confidence' => 'exact', 'identity_method' => 'portal_ref',
                ]);
            }
        }, 'p24 alert', $alert->getKey());
    }

    public function dealSaved(Deal $deal): void
    {
        $this->guard(function () use ($deal) {
            if (! $deal->property_id || $deal->tracked_property_id) {
                return;
            }
            $link = DB::table('properties')->where('id', $deal->property_id)->first(['tracked_property_id', 'identity_confidence']);
            if ($link && $link->tracked_property_id) {
                DB::table('deals')->where('id', $deal->id)->update([
                    'tracked_property_id' => $link->tracked_property_id, 'identity_confidence' => $link->identity_confidence, 'identity_method' => 'via_property',
                ]);
            }
        }, 'deal', $deal->getKey());
    }

    /** @param array<string,mixed> $facts */
    private function attach(string $table, int $id, int $agencyId, array $facts, array $source): void
    {
        $r = app(PropertyIdentityResolver::class)->find($agencyId, $facts, $source);
        if ($r->trackedProperty !== null) {
            DB::table($table)->where('id', $id)->update([
                'tracked_property_id' => $r->trackedProperty->id, 'identity_confidence' => $r->confidence, 'identity_method' => $r->method,
            ]);
        }
    }

    private function guard(callable $fn, string $what, mixed $id): void
    {
        if (self::$suppressed || ! config('property-identity.link_on_create')) {
            return;
        }
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Property identity link failed', ['what' => $what, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }
}
