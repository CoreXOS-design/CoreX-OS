<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Services\Features\AgencyFeatureService;

/**
 * The Features page (.ai/specs/features-page.md §5) — per-agency module on/off, moved out of Settings into its
 * own page with its own sidebar entry. READ-ONLY: the form posts to FeatureSettingsController@update, the one
 * canonical saver shared with the onboarding wizard, so the write path is unchanged.
 */
class FeaturePageController extends Controller
{
    public function index(AgencyFeatureService $features)
    {
        // The route also carries permission:agency_features.manage + agency.required; re-checked here so the
        // page can never render for someone without it, however it is reached.
        abort_unless(auth()->user()?->hasPermission('agency_features.manage'), 403);

        $grouped   = $features->groupedForDisplay();
        $catalogue = $features->catalogue();

        // What the page's Alpine component needs to filter and track changes without losing any row from the form.
        $items = [];
        foreach ($grouped as $category => $rows) {
            foreach ($rows as $row) {
                $items[$row['key']] = [
                    'kind'       => $row['kind'],
                    'enabled'    => $row['enabled'],
                    'depends_on' => $row['depends_on'],
                    'blocked_by' => $row['blocked_by'],
                    'search'     => mb_strtolower($category . ' ' . $row['label'] . ' ' . $row['explain'] . ' ' . $row['affects']),
                    'parent_label' => $row['blocked_by'] ? ($catalogue[$row['blocked_by']]['label'] ?? $row['blocked_by']) : null,
                ];
            }
        }

        return view('corex.features.index', [
            'grouped'   => $grouped,
            'items'     => $items,
            'catalogue' => $catalogue,
        ]);
    }
}
