<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProspectingListing;

/**
 * AT-520 4.3 (mic-work-tab.md 11.3.B): reappearing clears it. When the importer re-sights an advert (last_seen_at moves) and leaves it active
 * without setting a status itself, an old off-market label (`withdrawn`, `gone`, `probably_sold`) is removed - the advert is back on the portal.
 * This is the bug behind the 4,286 PP adverts stuck as `withdrawn` while `is_active = 1` (the importer re-activated them but kept the label).
 * An explicit status in the same save (the portal says withdrawn) is never touched.
 */
final class ProspectingListingLifecycleObserver
{
    /** AT-528: a snippet or description arriving (extension ingest, capture, back-fill through the model) is matched against the agency's mandate words at once. */
    public function saving(ProspectingListing $l): void
    {
        if (! $l->isDirty(['description_snippet', 'description'])) {
            return;
        }
        $l->sole_mandate_wording = \App\Services\Prospecting\MicWork\SoleMandateMatcher::matches($l->description_snippet, $l->description, (int) $l->agency_id, $l->agency_name);
        // the mandate start (listed date, else first seen; the capture date only moves it earlier); cleared when the wording goes, started again if it returns
        $l->sole_mandate_first_seen_at = $l->sole_mandate_wording
            ? \App\Services\Prospecting\MicWork\SoleMandateMatcher::mandateStart($l->sole_mandate_first_seen_at?->toDateTimeString(), null, $l->listed_date?->toDateTimeString(), $l->first_seen_at?->toDateTimeString())
            : null;
    }

    public function updating(ProspectingListing $l): void
    {
        if ($l->isDirty('portal_status') || ! $l->isDirty('last_seen_at') || $l->is_active !== true && (int) $l->is_active !== 1) {
            return;
        }
        if (! in_array((string) $l->portal_status, ProspectingListing::REAPPEARING_CLEARS, true)) {
            return;
        }
        $l->portal_status = ProspectingListing::PORTAL_STATUS_ACTIVE;
        $l->portal_status_changed_at = now();
        $l->off_market_at = null;
    }
}
