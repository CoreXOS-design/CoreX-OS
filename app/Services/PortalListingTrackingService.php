<?php

namespace App\Services;

use App\Models\PortalCapture;
use App\Models\PortalListing;
use App\Models\PortalListingObservation;

/**
 * Upserts portal listing identity records and tracks field changes over time.
 *
 * Called after extraction yields items from a portal capture.
 * Deterministic, no AI. All computed values are auditable via capture_id.
 */
class PortalListingTrackingService
{
    /** Fields we track for change detection. */
    private const TRACKED_FIELDS = ['price', 'beds', 'baths', 'parking', 'size_m2', 'erf_m2', 'title'];

    /**
     * Process extracted items from a portal capture.
     *
     * @param  PortalCapture  $capture    The capture record
     * @param  string         $sourceSite e.g. 'www.property24.com'
     * @param  array          $items      Extracted items array
     * @return array Summary: ['processed' => int, 'new' => int, 'updated' => int, 'price_changes' => int]
     */
    public function processItems(PortalCapture $capture, string $sourceSite, array $items): array
    {
        $summary = [
            'processed'     => 0,
            'new'           => 0,
            'updated'       => 0,
            'price_changes' => 0,
        ];

        foreach ($items as $item) {
            $portalListingId = $item['portal_listing_id'] ?? null;
            if ($portalListingId === null || $portalListingId === '') {
                continue;
            }

            // Normalize: strip any leading non-numeric characters (P prefix on sponsored copies)
            $portalListingId = preg_replace('/^[^0-9]+/', '', (string) $portalListingId);
            if ($portalListingId === '') {
                continue;
            }

            $result = $this->upsertListing($capture, $sourceSite, $portalListingId, $item);

            $summary['processed']++;
            if ($result['is_new']) {
                $summary['new']++;
            } else {
                $summary['updated']++;
            }
            if ($result['price_changed']) {
                $summary['price_changes']++;
            }
        }

        return $summary;
    }

    /**
     * Upsert a single portal listing and create an observation.
     *
     * @return array ['is_new' => bool, 'price_changed' => bool, 'portal_listing_id' => int]
     */
    private function upsertListing(
        PortalCapture $capture,
        string $sourceSite,
        string $portalListingId,
        array $item
    ): array {
        $observedAt = $capture->captured_at ?? now();

        // Build the observed fields (only non-null values from the item)
        $observedFields = $this->buildObservedFields($item);

        // Find or create the portal listing
        // The capture's own agency, explicitly: this also runs in queued jobs where no agency scope applies.
        $agencyId = (int) $capture->agency_id;
        $listing = PortalListing::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
            ->where('agency_id', $agencyId)
            ->where('source_site', $sourceSite)
            ->where('portal_listing_id', $portalListingId)
            ->first();

        $isNew = $listing === null;
        $changedFields = null;
        $priceChanged = false;

        // Extract image URL from item (not a tracked/delta field, but stored on the listing)
        $imageUrl = $item['image'] ?? null;

        if ($isNew) {
            // New listing
            $listing = PortalListing::create([
                'agency_id'           => $agencyId,
                'source_site'         => $sourceSite,
                'portal_listing_id'   => $portalListingId,
                'canonical_url'       => $item['url'] ?? null,
                'first_seen_at'       => $observedAt,
                'last_seen_at'        => $observedAt,
                'last_capture_id'     => $capture->id,
                'current_fields_json' => $observedFields,
                'primary_image_url'   => $imageUrl,
            ]);
        } else {
            // Existing listing — compute deltas
            $currentFields = $listing->current_fields_json ?? [];
            $changedFields = $this->computeDeltas($currentFields, $observedFields);

            if (isset($changedFields['price'])) {
                $priceChanged = true;
            }

            // Merge: only overwrite fields with non-null new values
            $mergedFields = $this->mergeFields($currentFields, $observedFields);

            $updateData = [
                'last_seen_at'        => $observedAt,
                'last_capture_id'     => $capture->id,
                'current_fields_json' => $mergedFields,
                'canonical_url'       => $item['url'] ?? $listing->canonical_url,
            ];

            // Set primary_image_url if not already set
            if ($imageUrl && !$listing->primary_image_url) {
                $updateData['primary_image_url'] = $imageUrl;
            }

            $listing->update($updateData);
        }

        // Always create an observation record (for audit trail)
        PortalListingObservation::create([
            'agency_id'            => $agencyId,
            'portal_listing_id'    => $listing->id,
            'capture_id'           => $capture->id,
            'observed_at'          => $observedAt,
            'observed_fields_json' => $observedFields,
            'changed_fields_json'  => !empty($changedFields) ? $changedFields : null,
            'created_at'           => now(),
        ]);

        return [
            'is_new'            => $isNew,
            'price_changed'     => $priceChanged,
            'portal_listing_id' => $listing->id,
        ];
    }

    /**
     * Extract tracked fields from an item, keeping only non-null values.
     */
    private function buildObservedFields(array $item): array
    {
        $fields = [];
        foreach (self::TRACKED_FIELDS as $key) {
            if (isset($item[$key]) && $item[$key] !== null) {
                $fields[$key] = $item[$key];
            }
        }
        return $fields;
    }

    /**
     * Compute field deltas between current stored fields and newly observed fields.
     * Returns only fields that changed, with old/new values.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function computeDeltas(array $currentFields, array $observedFields): array
    {
        $deltas = [];

        foreach (self::TRACKED_FIELDS as $key) {
            $oldVal = $currentFields[$key] ?? null;
            $newVal = $observedFields[$key] ?? null;

            // Only detect change when new value is non-null and differs from old
            if ($newVal !== null && $oldVal !== null && $newVal != $oldVal) {
                $deltas[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                ];
            }
        }

        return $deltas;
    }

    /**
     * Merge observed fields into current fields.
     * Only overwrites when new value is non-null.
     */
    private function mergeFields(array $currentFields, array $observedFields): array
    {
        $merged = $currentFields;
        foreach ($observedFields as $key => $value) {
            if ($value !== null) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }
}
