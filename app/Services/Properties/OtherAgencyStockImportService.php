<?php

namespace App\Services\Properties;

use App\Jobs\DownloadOtherAgencyStockGalleryJob;
use App\Jobs\DownloadPortalPropertyImages;
use App\Models\OtherAgencyStockConsent;
use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\PropertyExternalSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * .ai/specs/other-agency-stock.md §3/§4 — import (or re-import) ONE
 * Property24/PrivateProperty listing that belongs to ANOTHER agency as
 * status Property::STATUS_OTHER_AGENCY_STOCK. Dedup key: (agency_id,
 * portal, listing_ref) via PropertyExternalSource's unique index — a
 * re-import UPDATES the existing Property + PropertyExternalSource row,
 * never creates a duplicate.
 */
class OtherAgencyStockImportService
{
    public function import(array $data, User $actor): Property
    {
        $agencyId = (int) $actor->effectiveAgencyId();

        return DB::transaction(function () use ($data, $actor, $agencyId) {
            $existingSource = PropertyExternalSource::withoutGlobalScopes()
                ->where('agency_id', $agencyId)
                ->where('portal', $data['portal'])
                ->where('listing_ref', $data['listing_ref'])
                ->first();

            $isReimport = (bool) $existingSource;
            $property = $isReimport
                ? Property::withoutGlobalScopes()->findOrFail($existingSource->property_id)
                : new Property();

            $property->allowOtherAgencyStockContentWrite = true;

            // .ai/specs/other-agency-stock.md §3b — the importing agent owns it
            // (drives OWN/BRANCH/AGENCY scoping as usual), on a fresh import AND
            // a re-import alike. AT-267 — an assistant is never a listing agent
            // (route already refuses an assistant via deny_assistant_property_write,
            // this mirrors that same resolution defensively).
            $agentId = $actor->isAssistant() ? ($actor->assignedAgent()?->id ?? $actor->id) : $actor->id;

            // .ai/specs/other-agency-stock.md §3/§4 — 2026-09-29 Pomona
            // field-mapping fix. property_type/category: an explicit
            // $data['property_type'] (a hand-built payload, or a future
            // caller) always wins outright; otherwise derive both from the
            // portal's raw signals via the one shared mapper — never guess
            // ad hoc here, and never leave every Core-Match-relevant field
            // silently unset the way the old pull-from-portal path did.
            $typeMap = ($data['property_type'] ?? null)
                ? ['property_type' => $data['property_type'], 'category' => null]
                : \App\Services\Properties\OtherAgencyStockFieldMapper::mapPropertyType(
                    $data['portal'],
                    $data['property_type_raw'] ?? null,
                    $data['property_type_label_hint'] ?? null,
                );

            // Suburb/city/province/P24 location ids: P24's own external
            // suburb id (off the URL) is authoritative when present —
            // resolves the SAME p24_suburb_id/p24_city_id/p24_province_id
            // chain the manual property forms use (AppliesP24Location),
            // so this property is indistinguishable from a manually-linked
            // one for every P24-location-aware feature (maps, suburb
            // reports, etc.), not just Core Matches. Falls back to the
            // plain suburb/city/province text the payload already carries
            // (PP has no P24 suburb id at all) when there's nothing to
            // resolve.
            $p24Suburb = \App\Services\Properties\OtherAgencyStockFieldMapper::resolveP24Location(
                $data['p24_suburb_external_id'] ?? null
            );

            // 2026-09-30 field audit (property #21098, Norkem Park): P24's
            // Property Overview + features section carries levy/rates/zoning/
            // pets/parking/pool/kitchen/garden/security signals the extension
            // was never sending at all. "R 1 800" -> 1800 via the shared
            // currency parser; "General Residential" -> "Residential" via the
            // shared zoning map (leaves both null rather than storing an
            // unparseable/unmappable value — same never-guess rule as
            // mapPropertyType()).
            $zoneType = OtherAgencyStockFieldMapper::mapZoning($data['zone_type_raw'] ?? null);
            $spacesJson = OtherAgencyStockFieldMapper::buildSpacesJson([
                'parking_count'     => $data['parking_count'] ?? null,
                'pool'              => $data['pool'] ?? false,
                'kitchen_features'  => $data['kitchen_features'] ?? [],
                'garden_features'   => $data['garden_features'] ?? [],
                'security_features' => $data['security_features'] ?? [],
            ]);

            $property->fill([
                'agency_id'     => $agencyId,
                'agent_id'      => $agentId,
                'branch_id'     => $property->branch_id ?? $actor->branch_id,
                'status'        => Property::STATUS_OTHER_AGENCY_STOCK,
                'listing_type'  => $data['listing_type'] ?? $property->listing_type ?? 'sale',
                'property_type' => $typeMap['property_type'] ?? $property->property_type ?? 'house',
                'category'      => $typeMap['category'] ?? $property->category ?? null,
                // NOT NULL, same reasoning as beds/baths/garages below — a
                // POA/"price on application" listing has no numeric price
                // to send and must not 500 the import over it.
                'price'         => $data['price'] ?? 0,
                // beds/baths/garages are NOT NULL (DB default 0, but an explicit
                // NULL in the INSERT still violates it — Eloquent always sends the
                // key when it's in $fillable and was set, default or not).
                'beds'          => $data['beds'] ?? 0,
                'baths'         => $data['baths'] ?? 0,
                'garages'       => $data['garages'] ?? 0,
                'size_m2'       => $data['size_m2'] ?? null,
                'erf_size_m2'   => $data['erf_size_m2'] ?? null,
                'description'   => $data['description'] ?? null,
                'street_number' => $data['street_number'] ?? null,
                'street_name'   => $data['street_name'] ?? null,
                'suburb'        => $p24Suburb['suburb'] ?? $data['suburb'] ?? null,
                'city'          => $p24Suburb['city'] ?? $data['city'] ?? null,
                'province'      => $p24Suburb['province'] ?? $data['province'] ?? null,
                'town'          => $p24Suburb['town'] ?? $property->town ?? null,
                'p24_suburb_id'   => $p24Suburb['p24_suburb_id'] ?? $property->p24_suburb_id ?? null,
                'p24_city_id'     => $p24Suburb['p24_city_id'] ?? $property->p24_city_id ?? null,
                'p24_province_id' => $p24Suburb['p24_province_id'] ?? $property->p24_province_id ?? null,
                'address'       => $data['address'] ?? null,
                'region'        => $data['region'] ?? $property->region ?? null,
                'latitude'      => $data['latitude'] ?? null,
                'longitude'     => $data['longitude'] ?? null,
                'title'         => $this->deriveTitle($data) ?: ($property->title ?? 'Other Agency Stock listing'), // title is NOT NULL
                // .ai/specs/other-agency-stock.md §3/§4 — 2026-09-30 field-parity
                // fix: PropertyPullController sets excerpt + listed_date on every
                // pull; OAS import silently never had either. Same Str::limit
                // pattern as the pull path. listed_date only stamped on the
                // FIRST import — a reimport must not keep resetting "days on
                // market" to today.
                'excerpt'       => ! empty($data['description']) ? Str::limit(strip_tags($data['description']), 300) : ($property->excerpt ?? null),
                // .ai/specs/other-agency-stock.md §3/§4 — 2026-09-30 field
                // audit: prefer the portal's OWN declared listing date
                // (date_posted, already captured from JSON-LD/the overview
                // table but never used here) over "today" — only fall back
                // to now() when the portal genuinely didn't say, and even
                // then only on the FIRST import (a reimport must not keep
                // resetting "days on market").
                'listed_date'   => $data['date_posted'] ?? $property->listed_date ?? now()->toDateString(),
                'features_json' => ! empty($data['features']) ? array_values($data['features']) : ($property->features_json ?? null),
                'levy'          => OtherAgencyStockFieldMapper::parseCurrency($data['levy'] ?? null) ?? $property->levy ?? null,
                'rates_taxes'   => OtherAgencyStockFieldMapper::parseCurrency($data['rates_taxes'] ?? null) ?? $property->rates_taxes ?? null,
                'zone_type'     => $zoneType ?? $property->zone_type ?? null,
                'pet_friendly'  => array_key_exists('pets_allowed', $data) ? $data['pets_allowed'] : ($property->pet_friendly ?? null),
                'spaces_json'   => $spacesJson,
            ])->save();

            $source = PropertyExternalSource::updateOrCreate(
                ['agency_id' => $agencyId, 'portal' => $data['portal'], 'listing_ref' => $data['listing_ref']],
                [
                    'property_id'               => $property->id,
                    'listing_url'               => $data['listing_url'],
                    'source_agency_name'        => $data['source_agency_name'] ?? null,
                    'source_agent_name'         => $data['source_agent_name'] ?? null,
                    'source_agent_phone'        => $data['source_agent_phone'] ?? null,
                    'source_agent_email'        => $data['source_agent_email'] ?? null,
                    'source_agent_profile_url'  => $data['source_agent_profile_url'] ?? null,
                    'date_posted'               => $data['date_posted'] ?? null,
                    'imported_at'               => now(),
                    'imported_by_user_id'       => $actor->id,
                ]
            );

            // .ai/specs/other-agency-stock.md §3a — append-only consent evidence, EVERY (re-)import.
            OtherAgencyStockConsent::create([
                'agency_id'                => $agencyId,
                'property_id'              => $property->id,
                'user_id'                  => $actor->id,
                'consented_at'             => now(),
                'consent_wording'          => $this->consentWordingFor($agencyId),
                'consent_wording_version'  => OtherAgencyStockConsent::WORDING_VERSION,
                'portal'                   => $data['portal'],
                'listing_ref'              => $data['listing_ref'],
                'listing_url'              => $data['listing_url'],
                'source_agency_name'       => $data['source_agency_name'] ?? null,
                'ip_address'               => request()?->ip(),
                'user_agent'               => request()?->userAgent(),
            ]);

            // .ai/specs/other-agency-stock.md §8a — a re-import re-locks (an
            // authorised user's earlier unlock does not survive fresh
            // content). A brand-new import starts locked by construction
            // (no rows yet => currentStateFor() already resolves 'locked'),
            // so no event is needed there.
            if ($isReimport) {
                OtherAgencyStockUnlock::create([
                    'agency_id'            => $agencyId,
                    'property_id'          => $property->id,
                    'event_type'           => OtherAgencyStockUnlock::EVENT_RELOCKED,
                    'relocked_by_user_id'  => $actor->id,
                ]);
            }

            // 2026-09-30 URGENT FIX #3 (Norkem Park, property #21098): OAS
            // import had NO photos — the Pull flow's images worked because it
            // dispatches DownloadPortalPropertyImages (P24's own sequential
            // image-id pattern: firstImageId..firstImageId+count-1, HTTP-pooled,
            // content-hash deduped). OAS instead sent a client-collected
            // photos[] URL array to a DIFFERENT job. Reuse the exact same
            // mechanism Pull already proved works, don't reimplement: P24
            // dispatches the SAME DownloadPortalPropertyImages job Pull uses,
            // driven by the SAME first_image_id/image_count signal
            // content-p24-detail.js already extracts. PP has no P24 sequential
            // id scheme, so it keeps sending a real photos[] URL array —
            // DownloadOtherAgencyStockGalleryJob (+ the agent/logo filter
            // below) stays, PP-only.
            if (! empty($data['first_image_id']) && ! empty($data['image_count'])) {
                DownloadPortalPropertyImages::dispatch($property->id, (int) $data['first_image_id'], (int) $data['image_count']);
            } else {
                $photos = $this->filterKnownNonGalleryPhotos($data['photos'] ?? [], $data, $property->id);
                if (! empty($photos)) {
                    // DownloadOtherAgencyStockGalleryJob uses saveQuietly() — it never
                    // touches the content lock at all (no need for the transient
                    // allowOtherAgencyStockContentWrite bypass on this async path).
                    DownloadOtherAgencyStockGalleryJob::dispatch($property->id, $photos);
                }
            }

            return $property->fresh();
        });
    }

    /**
     * .ai/specs/other-agency-stock.md §5 — 2026-09-29 gallery-filter fix.
     * Server-side belt-and-braces: drop any photo URL that matches the
     * agent's own photo or the agency's logo, exact-URL first and then by
     * numeric image id (P24 serves the SAME image at different size
     * suffixes — images.prop24.com/{id}/Ensure960x540 vs .../UpperCrop200x200
     * — so an exact-URL check alone would miss a same-photo-different-size
     * case). Independent of whatever exclusion the extension's own
     * client-side logic already did — a regressed extension build must
     * never be the only thing standing between an agent photo and the
     * imported gallery. PP is unaffected in practice (its gallery and
     * agent/agency images are served from different hosts entirely and were
     * confirmed never to overlap), but the same check runs uniformly for
     * both portals rather than special-casing one.
     */
    private function filterKnownNonGalleryPhotos(array $photos, array $data, int $propertyId): array
    {
        $excludeUrls = array_values(array_filter([
            $data['source_agent_image_url'] ?? null,
            $data['source_agency_logo_url'] ?? null,
        ]));
        if (empty($excludeUrls) || empty($photos)) {
            return array_values($photos);
        }

        $excludeIds = array_values(array_filter(array_map([$this, 'p24ImageId'], $excludeUrls)));

        $filtered = array_values(array_filter($photos, function ($url) use ($excludeUrls, $excludeIds) {
            if (in_array($url, $excludeUrls, true)) {
                return false;
            }
            $id = $this->p24ImageId($url);

            return ! ($id !== null && in_array($id, $excludeIds, true));
        }));

        $dropped = count($photos) - count($filtered);
        if ($dropped > 0) {
            Log::info('OtherAgencyStockImportService: dropped agent/agency image(s) from gallery payload', [
                'property_id' => $propertyId,
                'dropped'     => $dropped,
            ]);
        }

        return $filtered;
    }

    private function p24ImageId(?string $url): ?string
    {
        if ($url && preg_match('#images\.prop24\.com/(\d+)#', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    private function deriveTitle(array $data): ?string
    {
        // 2026-09-29 URGENT FIX #2 (Clayville, property #21095): the
        // listing's own real title (P24 JSON-LD name / PP page title) wins
        // outright when the extension sends it. street_number/street_name
        // are almost never present on a scraped-from-another-agency
        // listing, so the old address-parts fallback silently collapsed to
        // a bare suburb name ("Clayville") whenever they were empty.
        if (! empty($data['listing_title'])) {
            return $data['listing_title'];
        }

        $parts = array_filter([$data['street_number'] ?? null, $data['street_name'] ?? null, $data['suburb'] ?? null]);

        return $parts ? implode(' ', $parts) : ($data['address'] ?? null);
    }

    private function consentWordingFor(int $agencyId): string
    {
        $agency = \App\Models\Agency::withoutGlobalScopes()->find($agencyId);

        return $agency?->other_agency_stock_consent_wording ?: OtherAgencyStockConsent::DEFAULT_WORDING;
    }
}
