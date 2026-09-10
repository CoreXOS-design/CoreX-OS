<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar-eve config sweep (2026-09-02) — Ad Manager's property picker
 * (`Tools\AdManagerController`) only shows properties with an active P24 or
 * PP syndication ref (or an enabled `property_website_syndication` row).
 * Demo had 0/0/0 across all three on every one of its 348 sale + 15 rental
 * properties, so the tool rendered with nothing to build an ad for — not
 * broken, just structurally empty, same class of gap as the document-types
 * catalogue. Refs are obviously-fake demo strings; syndication stays
 * confined to the sandbox hosts already configured in .env (P24_EXDEV_
 * BASE_URL / PP_WSDL both point at the portals' own test infrastructure —
 * setting these DB columns does not, by itself, cause any outbound call at
 * all; nothing here triggers a sync job).
 *
 * Idempotent: matches on property id, sets syndication columns unconditionally
 * (safe to re-run — same fictional ref every time).
 */
final class DemoPortalSyndicationSeeder
{
    public function run(int $agencyId): array
    {
        $propertyIds = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->where('listing_type', 'sale')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(12)
            ->pluck('id');

        if ($propertyIds->isEmpty()) {
            return ['updated' => 0, 'note' => "Skipped — agency {$agencyId} has no active sale properties."];
        }

        $updated = 0;
        foreach ($propertyIds as $i => $propertyId) {
            DB::table('properties')->where('id', $propertyId)->update([
                'p24_ref' => 'P24-DEMO-' . str_pad((string) $propertyId, 5, '0', STR_PAD_LEFT),
                'p24_syndication_enabled' => true,
                'p24_syndication_status' => 'active',
                'p24_activated_at' => now()->subDays(3 + $i),
                'p24_listing_number' => 100000 + $propertyId,
                'pp_ref' => 'PP-DEMO-' . str_pad((string) $propertyId, 5, '0', STR_PAD_LEFT),
                'pp_syndication_enabled' => true,
                'pp_syndication_status' => 'active',
                'pp_activated_at' => now()->subDays(3 + $i),
                'updated_at' => now(),
            ]);
            $updated++;
        }

        return ['updated' => $updated];
    }
}
