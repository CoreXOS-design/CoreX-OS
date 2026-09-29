<?php

namespace App\Services\Properties;

use App\Jobs\DownloadOtherAgencyStockGalleryJob;
use App\Models\OtherAgencyStockConsent;
use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\PropertyExternalSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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

            $property->fill([
                'agency_id'     => $agencyId,
                'agent_id'      => $agentId,
                'branch_id'     => $property->branch_id ?? $actor->branch_id,
                'status'        => Property::STATUS_OTHER_AGENCY_STOCK,
                'listing_type'  => $data['listing_type'] ?? $property->listing_type ?? 'sale',
                'property_type' => $data['property_type'] ?? $property->property_type ?? 'house',
                'price'         => $data['price'] ?? null,
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
                'suburb'        => $data['suburb'] ?? null,
                'city'          => $data['city'] ?? null,
                'province'      => $data['province'] ?? null,
                'address'       => $data['address'] ?? null,
                'latitude'      => $data['latitude'] ?? null,
                'longitude'     => $data['longitude'] ?? null,
                'title'         => $this->deriveTitle($data) ?: ($property->title ?? 'Other Agency Stock listing'), // title is NOT NULL
                'features_json' => ! empty($data['features']) ? array_values($data['features']) : ($property->features_json ?? null),
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

            if (! empty($data['photos'])) {
                // DownloadOtherAgencyStockGalleryJob uses saveQuietly() — it never
                // touches the content lock at all (no need for the transient
                // allowOtherAgencyStockContentWrite bypass on this async path).
                DownloadOtherAgencyStockGalleryJob::dispatch($property->id, array_values($data['photos']));
            }

            return $property->fresh();
        });
    }

    private function deriveTitle(array $data): ?string
    {
        $parts = array_filter([$data['street_number'] ?? null, $data['street_name'] ?? null, $data['suburb'] ?? null]);

        return $parts ? implode(' ', $parts) : ($data['address'] ?? null);
    }

    private function consentWordingFor(int $agencyId): string
    {
        $agency = \App\Models\Agency::withoutGlobalScopes()->find($agencyId);

        return $agency?->other_agency_stock_consent_wording ?: OtherAgencyStockConsent::DEFAULT_WORDING;
    }
}
