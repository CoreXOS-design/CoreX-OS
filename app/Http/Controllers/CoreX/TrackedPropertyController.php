<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tracked Properties sub-menu under Prospecting.
 *
 * Surfaces the universe built by D.1 (foundation) + D.2 (ingest wiring).
 * Read-only list + detail; Promote-to-Stock is the only write action.
 *
 * Multi-tenancy: every query filters by the viewer's effective agency.
 * Detail-page cross-agency access returns 404.
 */
final class TrackedPropertyController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null) abort(403);

        $baseQuery = TrackedProperty::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at');

        // Composable filters — each preserved in the query string for paging.
        $filtered = (clone $baseQuery);

        if ($suburb = $request->query('suburb')) {
            $filtered->where('suburb_normalised', TrackedProperty::normaliseSuburb($suburb));
        }
        if ($status = $request->query('status')) {
            $filtered->where('status', $status);
        }
        if ($source = $request->query('source')) {
            $filtered->whereExists(function ($q) use ($source, $agencyId) {
                $q->select(DB::raw(1))
                  ->from('tracked_property_external_refs as tper')
                  ->whereColumn('tper.tracked_property_id', 'tracked_properties.id')
                  ->where('tper.agency_id', $agencyId)
                  ->where('tper.source_type', $source)
                  ->whereNull('tper.deleted_at');
            });
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $filtered->where(function ($q) use ($search) {
                $q->where('street_name', 'LIKE', "%{$search}%")
                  ->orWhere('suburb', 'LIKE', "%{$search}%")
                  ->orWhere('erf_number', 'LIKE', "%{$search}%")
                  ->orWhere('title_deed_number', 'LIKE', "%{$search}%")
                  ->orWhere('external_id', 'LIKE', "%{$search}%");
            });
        }

        $tps = $filtered
            ->orderByDesc('last_enriched_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Stats — always agency-wide totals, NOT filter-scoped, so the header is stable.
        $stats = [
            'total'      => (clone $baseQuery)->count(),
            'unpromoted' => (clone $baseQuery)->where('status', TrackedProperty::STATUS_ACTIVE)->count(),
            'promoted'   => (clone $baseQuery)->where('status', TrackedProperty::STATUS_PROMOTED)->count(),
        ];

        $sourceCounts = DB::table('tracked_property_external_refs')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->select('source_type', DB::raw('COUNT(DISTINCT tracked_property_id) as cnt'))
            ->groupBy('source_type')
            ->orderByDesc('cnt')
            ->get()
            ->keyBy('source_type');

        $suburbCounts = DB::table('tracked_properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotNull('suburb')
            ->where('suburb', '!=', '')
            ->select('suburb', DB::raw('COUNT(*) as cnt'))
            ->groupBy('suburb')
            ->orderByDesc('cnt')
            ->limit(30)
            ->get();

        return view('corex.tracked-properties.index', compact(
            'tps', 'stats', 'sourceCounts', 'suburbCounts'
        ));
    }

    public function show(Request $request, TrackedProperty $trackedProperty)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null || (int) $trackedProperty->agency_id !== (int) $agencyId) {
            abort(404);
        }

        $trackedProperty->load(['externalRefs', 'promotedProperty', 'promotedBy']);

        $intelligence = $this->buildIntelligence($trackedProperty, (int) $agencyId);

        return view('corex.tracked-properties.show', [
            'tp' => $trackedProperty,
            'intelligence' => $intelligence,
        ]);
    }

    public function promote(Request $request, TrackedProperty $trackedProperty)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId() ?? $user->agency_id;
        if ($agencyId === null || (int) $trackedProperty->agency_id !== (int) $agencyId) {
            abort(404);
        }

        if ($trackedProperty->isPromoted()) {
            return redirect()
                ->route('corex.properties.show', $trackedProperty->promoted_to_property_id)
                ->with('status', 'This property is already in agency stock.');
        }

        try {
            $property = app(TrackedPropertyMatchOrCreateService::class)
                ->promoteToStock(
                    trackedPropertyId: (int) $trackedProperty->id,
                    promotingUserId: (int) $user->id,
                );
        } catch (\DomainException $e) {
            // promoteToStock throws when the promoting user has no branch_id.
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('corex.properties.show', $property->id)
            ->with('status', 'Promoted to agency stock. Source chain preserved on the Tracked Property.');
    }

    /**
     * Aggregate everything CoreX knows about this Tracked Property for the detail view.
     * Mirrors the Property Intelligence Drawer pattern but reads from D.1 tables.
     */
    private function buildIntelligence(TrackedProperty $tp, int $agencyId): array
    {
        $linkedListings = DB::table('prospecting_listings')
            ->where('tracked_property_id', $tp->id)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->select(
                'id', 'portal_source', 'portal_ref', 'portal_url',
                'address', 'suburb', 'price', 'bedrooms', 'bathrooms',
                'property_type', 'first_seen_at', 'is_active'
            )
            ->orderByDesc('first_seen_at')
            ->get();

        $externalRefsBySource = $tp->externalRefs->groupBy('source_type');
        $chain = $tp->source_chain ?? [];

        return [
            'linked_listings'         => $linkedListings,
            'external_refs_by_source' => $externalRefsBySource,
            'linked_property'         => $tp->promotedProperty,
            'source_chain'            => $chain,
            'source_chain_count'      => count($chain),
        ];
    }
}
