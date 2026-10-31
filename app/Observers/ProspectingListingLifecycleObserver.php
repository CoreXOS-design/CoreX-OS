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
