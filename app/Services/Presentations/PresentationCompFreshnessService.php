<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationSnapshot;
use App\Models\PresentationVersion;
use Illuminate\Support\Facades\Log;

/**
 * AT-27 C1a (option 1) — keep a presentation's comparables in sync with the
 * PROPERTY record, which is the single source of truth.
 *
 * The presentation never edits the subject; the property is edited on its own
 * screen. So the ONLY trigger for a comp re-hydration is a property change.
 * On returning to the presentation, if the linked property has been edited
 * since the comps were last hydrated, this:
 *   1. re-hydrates the comps from current property data (MicSnapshotHydrator),
 *   2. resets the draft version's curation (built for the OLD property data),
 *   3. advances the hydration marker (a fresh snapshot), and
 *   4. flashes the "comparable set refreshed" notice for the Review banner.
 *
 * The "last hydrated at" marker is the latest PresentationSnapshot.generated_at
 * (the generator stamps one right after hydration), so no extra column is
 * needed. Only fires for an unconfirmed draft — a published version is frozen
 * (the agent re-opens to refresh).
 */
class PresentationCompFreshnessService
{
    /** @return bool true when a refresh happened (caller should redirect). */
    public function refreshIfStale(Presentation $presentation): bool
    {
        $property = $presentation->property;
        if (!$property || $property->updated_at === null) {
            return false;
        }

        $version = $presentation->versions()->latest('compiled_at')->first();
        if (!$version || $version->review_status === PresentationVersion::REVIEW_PUBLISHED) {
            return false;
        }

        $lastHydrated = $presentation->snapshots()->latest('generated_at')->first()?->generated_at;
        if ($lastHydrated !== null && $property->updated_at->lessThanOrEqualTo($lastHydrated)) {
            return false; // comps already reflect the current property
        }

        // 1. re-hydrate from the current (single-source) property data
        try {
            app(MicSnapshotHydrator::class)->hydrateForPresentation($presentation);
        } catch (\Throwable $e) {
            Log::warning('Comp freshness re-hydration failed', [
                'presentation_id' => $presentation->id,
                'error'           => $e->getMessage(),
            ]);
        }

        // 2. reset curation — the prior selections were for the old property data
        $version->forceFill([
            'included_comp_ids_json'       => null,
            'included_competitor_ids_json' => null,
        ])->save();

        // 3. advance the hydration marker past the property's updated_at
        $presentation->refresh();
        PresentationSnapshot::create([
            'presentation_id'      => $presentation->id,
            'generated_by_user_id' => optional(auth()->user())->id,
            'created_by_user_id'   => optional(auth()->user())->id,
            'computed_json'        => json_encode(
                (new AnalysisDataService())->compile($presentation),
                JSON_THROW_ON_ERROR
            ),
            'snapshot_json'        => '{}',
            'generated_at'         => now(),
        ]);

        // 4. notice for the Review banner (shown on the redirected request)
        session()->flash(
            'subject_refreshed',
            'Property details changed — the comparable set was refreshed from the property record. Re-check your curation, then Continue to Analysis.'
        );

        return true;
    }
}
