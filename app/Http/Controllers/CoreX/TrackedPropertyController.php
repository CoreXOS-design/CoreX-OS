<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Events\Prospecting\TrackedPropertyAddressVerified;
use App\Http\Controllers\Controller;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Http\RedirectResponse;
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

        // Phase C3 — eager-load addresses for the Address section.
        // Order: primary first, then by confidence DESC, then by recency.
        $trackedProperty->load([
            'externalRefs',
            'promotedProperty',
            'promotedBy',
            'addresses' => function ($q) {
                $q->orderByDesc('is_primary')
                  ->orderByRaw("FIELD(confidence, 'verified', 'high', 'medium', 'low')")
                  ->orderByDesc('last_seen_at');
            },
            'addresses.verifier',
            'primaryAddress',
            'primaryAddress.verifier',
        ]);

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
     * Phase C3 — Edit primary address.
     *
     * Demotes the current primary (via the observer's promote logic) and
     * inserts a new is_primary=true row with source_type=manual_agent,
     * confidence=verified, audit fields filled. The observer mirrors the
     * new primary onto the parent TP's address cache automatically.
     */
    public function editAddress(Request $request, TrackedProperty $trackedProperty): RedirectResponse
    {
        $this->assertOwnership($request, $trackedProperty);
        $data = $this->validateAddressPayload($request, notesRequired: true);

        $user = $request->user();

        DB::transaction(function () use ($trackedProperty, $data, $user) {
            $new = TrackedPropertyAddress::create(array_merge($data, [
                'agency_id'           => $trackedProperty->agency_id,
                'tracked_property_id' => $trackedProperty->id,
                'source_type'         => TrackedPropertyAddress::SOURCE_MANUAL_AGENT,
                'source_ref'          => 'user:' . $user->id,
                'confidence'          => TrackedPropertyAddress::CONFIDENCE_VERIFIED,
                'is_primary'          => true,
                'verified_by_user_id' => $user->id,
                'verified_at'         => now(),
                'first_seen_at'       => now(),
                'last_seen_at'        => now(),
            ]));

            // The observer fires TrackedPropertyAddressAdded (on create) and
            // TrackedPropertyAddressPrimaryChanged (because is_primary=true
            // demotes the previous primary). The Verified event, however, is
            // only fired by the observer on UPDATE when verified_at transitions
            // from null → set. New rows born already-verified don't trip that
            // path, so we emit it explicitly here per spec §5.4.5.
            event(new TrackedPropertyAddressVerified($new));
        });

        return back()->with('status', 'Address updated. Future captures matching this address will auto-link.');
    }

    /**
     * Phase C3 — Add an alternative address (does NOT demote the current
     * primary). Useful when a property has multiple legitimate addresses
     * (corner stand, side entrance, complex with multiple unit references).
     */
    public function addAlternativeAddress(Request $request, TrackedProperty $trackedProperty): RedirectResponse
    {
        $this->assertOwnership($request, $trackedProperty);
        $data = $this->validateAddressPayload($request, notesRequired: false);

        $user = $request->user();

        TrackedPropertyAddress::create(array_merge($data, [
            'agency_id'           => $trackedProperty->agency_id,
            'tracked_property_id' => $trackedProperty->id,
            'source_type'         => TrackedPropertyAddress::SOURCE_MANUAL_AGENT,
            'source_ref'          => 'user:' . $user->id,
            'confidence'          => TrackedPropertyAddress::CONFIDENCE_VERIFIED,
            'is_primary'          => false,
            'verified_by_user_id' => $user->id,
            'verified_at'         => now(),
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
        ]));

        return back()->with('status', 'Alternative address recorded. Both addresses will be matched against future ingestion.');
    }

    /**
     * Phase C3 — Promote a history row to primary. Observer demotes the
     * current primary, refreshes the cache, and fires PrimaryChanged. If
     * verified_at was null on the row, the observer also fires Verified.
     */
    public function setPrimaryAddress(
        Request $request,
        TrackedProperty $trackedProperty,
        TrackedPropertyAddress $address,
    ): RedirectResponse {
        $this->assertOwnership($request, $trackedProperty);
        if ((int) $address->tracked_property_id !== (int) $trackedProperty->id) {
            abort(404);
        }
        if ((int) $address->agency_id !== (int) $trackedProperty->agency_id) {
            abort(404);
        }

        $user = $request->user();

        $address->is_primary          = true;
        $address->verified_by_user_id = $user->id;
        if ($address->verified_at === null) {
            $address->verified_at = now();
        }
        $address->save();

        return back()->with('status', 'Primary address changed to: ' . ($address->formatted_address ?? 'updated address') . '.');
    }

    /**
     * Phase C3 stub — Merge Duplicate flow lands in Phase D. This stub gives
     * the permission gate (mic.merge_duplicates) a target to bind to, and
     * surfaces a placeholder UI listing same-suburb candidates so the
     * navigation path exists per CLAUDE.md non-negotiable #2.
     */
    public function stubMergeDuplicate(Request $request, TrackedProperty $trackedProperty)
    {
        $this->assertOwnership($request, $trackedProperty);

        // Coarse candidate set: same agency, same normalised suburb, different id.
        // Real ranking + side-by-side merge UI is Phase D scope.
        $candidates = TrackedProperty::query()
            ->withoutGlobalScopes()
            ->where('agency_id', $trackedProperty->agency_id)
            ->whereNull('deleted_at')
            ->where('id', '!=', $trackedProperty->id)
            ->when(
                !empty($trackedProperty->suburb_normalised),
                fn ($q) => $q->where('suburb_normalised', $trackedProperty->suburb_normalised),
            )
            ->orderByDesc('last_enriched_at')
            ->limit(25)
            ->get(['id', 'street_number', 'street_name', 'suburb', 'erf_number', 'last_enriched_at']);

        return view('corex.tracked-properties.merge-stub', [
            'tp'         => $trackedProperty,
            'candidates' => $candidates,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    private function assertOwnership(Request $request, TrackedProperty $tp): void
    {
        $user = $request->user();
        $agencyId = $user?->effectiveAgencyId() ?? $user?->agency_id;
        if ($agencyId === null || (int) $tp->agency_id !== (int) $agencyId) {
            abort(404);
        }
    }

    /**
     * Shared validation for Edit + Add Alternative. `notesRequired=true` for
     * Edit (agent must explain WHY they're correcting); optional for the Add
     * Alternative path.
     */
    private function validateAddressPayload(Request $request, bool $notesRequired): array
    {
        return $request->validate([
            'street_number' => ['nullable', 'string', 'max:50'],
            'street_name'   => ['required', 'string', 'max:200'],
            'unit_number'   => ['nullable', 'string', 'max:50'],
            'complex_name'  => ['nullable', 'string', 'max:200'],
            'suburb'        => ['required', 'string', 'max:100'],
            'town'          => ['nullable', 'string', 'max:100'],
            'province'      => ['nullable', 'string', 'max:100'],
            'postal_code'   => ['nullable', 'string', 'max:20'],
            'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
            'notes'         => [$notesRequired ? 'required' : 'nullable', 'string', 'max:1000'],
        ]);
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
