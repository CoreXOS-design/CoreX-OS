<?php

namespace App\Services\Syndication\Website;

use App\Models\AgencyApiKey;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Carbon;

/**
 * Website syndication — the per-(property × website) portal. The website is a
 * Syndication Portal like P24/PP, but because an agency can run many websites
 * (many keys), state lives in the property_website_syndication pivot keyed by
 * (property_id, agency_api_key_id).
 *
 * Unlike P24/PP (async submit/activate), the website model is PULL + webhook:
 * enabling a row makes the listing immediately visible to GET /api/v1/website/
 * listings for that key, and (Phase 4) emits a domain event that fans a webhook
 * to the site. There is therefore no "pending/submitted" round-trip — enable =
 * active, disable = deactivated.
 *
 * Spec: .ai/specs/agency-public-api.md §6.5
 */
class WebsiteSyndicationService
{
    /**
     * Enable or disable a property on a specific website. Idempotent: returns
     * the (created or updated) pivot row. Phase 4 emits the listing.* webhook
     * event from here.
     */
    public function setEnabled(Property $property, AgencyApiKey $key, bool $enabled): PropertyWebsiteSyndication
    {
        $row = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->firstOrNew([
                'property_id'       => $property->id,
                'agency_api_key_id' => $key->id,
            ]);

        $row->agency_id = $key->agency_id;
        $row->enabled   = $enabled;
        $row->status    = $enabled ? PropertyWebsiteSyndication::STATUS_ACTIVE : PropertyWebsiteSyndication::STATUS_DEACTIVATED;
        if ($enabled) {
            $row->activated_at = $row->activated_at ?: Carbon::now();
            $row->last_submitted_at = Carbon::now();
            $row->last_error = null;
        }
        $row->save();

        event(new \App\Events\Website\ListingSyndicationChanged(
            $property,
            $enabled ? 'published' : 'removed',
            $key->id,
        ));

        return $row;
    }

    /**
     * Re-send the current listing to a website it's already live on (the
     * "Refresh" action) — fires a listing.updated webhook so the site re-pulls,
     * and stamps last_synced_at. Returns null if the listing isn't enabled on
     * that website.
     */
    public function resend(Property $property, AgencyApiKey $key): ?PropertyWebsiteSyndication
    {
        $row = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('property_id', $property->id)
            ->where('agency_api_key_id', $key->id)
            ->where('enabled', true)
            ->first();

        if (!$row) {
            return null;
        }

        $row->forceFill(['last_synced_at' => Carbon::now()])->save();

        event(new \App\Events\Website\ListingSyndicationChanged($property->loadMissing('agent'), 'updated', $key->id));

        return $row;
    }

    /**
     * "Add all Active listings" — enable the website for every property with
     * status = 'active' in the key's agency. Idempotent (already-enabled rows
     * are skipped). Returns a summary; no silent caps.
     *
     * @return array{enabled:int, already_live:int, scanned:int}
     */
    public function bulkActivateActive(AgencyApiKey $key): array
    {
        $enabled = 0;
        $alreadyLive = 0;
        $scanned = 0;

        // "Active" = currently marketable. Statuses vary across data
        // (active / for_sale / for_rent / to_let / on_show / under_offer …), so we
        // exclude the clearly off-market ones rather than whitelist a single value.
        Property::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $key->agency_id)
            ->whereNotIn('status', ['draft', 'sold', 'withdrawn'])
            ->with('agent')
            ->chunkById(200, function ($properties) use ($key, &$enabled, &$alreadyLive, &$scanned) {
                foreach ($properties as $property) {
                    $scanned++;
                    $row = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
                        ->firstOrNew(['property_id' => $property->id, 'agency_api_key_id' => $key->id]);

                    if ($row->exists && $row->enabled) {
                        $alreadyLive++;
                        continue;
                    }

                    $row->agency_id        = $key->agency_id;
                    $row->enabled          = true;
                    $row->status           = PropertyWebsiteSyndication::STATUS_ACTIVE;
                    $row->activated_at     = $row->activated_at ?: Carbon::now();
                    $row->last_submitted_at = Carbon::now();
                    $row->last_error       = null;
                    $row->save();
                    $enabled++;

                    event(new \App\Events\Website\ListingSyndicationChanged(
                        $property->loadMissing('agent'),
                        'published',
                        $key->id,
                    ));
                }
            });

        return ['enabled' => $enabled, 'already_live' => $alreadyLive, 'scanned' => $scanned];
    }
}
