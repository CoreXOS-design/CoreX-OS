<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteApi\ListingResource;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public listings for an agency website. Returns only the properties whose
 * website-syndication portal is enabled for THIS key (the per-(property×website)
 * pivot). AgencyScope independently confines the query to the key's agency.
 *
 * index() and show() build on the SAME base query (publicListingsQuery) so their
 * WHERE clauses can never drift — a listing in the index is always fetchable by
 * detail and vice-versa.
 *
 * Spec: .ai/specs/agency-public-api.md §5, §6.5
 */
class ListingsController extends Controller
{
    /**
     * Statuses that must NEVER reach an agency website, even if a stale/legacy
     * syndication pivot is still enabled for the listing (belt-and-braces, NULL-safe).
     *
     *   - 'draft' is unpublished. The draft-syndication guard (commit "block draft
     *     listings from publishing", EnforcesMarketingReadiness::enforceListingNotDraft)
     *     stops NEW drafts being enabled; this guard also hides any draft whose
     *     pivot was enabled BEFORE that guard existed.
     *   - 'expired'/'withdrawn' are dead mandates.
     *
     * 'sold'/'let_out' are deliberately NOT here — agencies showcase sold stock
     * (bulkActivateSold). See audits mandate-expiry-desyndication and
     * syndication-bug-sweep (2026-06-20). A website narrows to live stock with
     * ?status=active; it is not forced here.
     */
    private const NEVER_PUBLIC_STATUSES = ['expired', 'withdrawn', 'draft'];

    /** Whitelisted public sort keys. Anything else falls back to -published_at. */
    private const SORTABLE = ['published_at', 'price', 'id', 'created_at', 'updated_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = max(1, min(50, (int) $request->integer('per_page', 20)));

        $query = $this->publicListingsQuery($request->user()->getKey());

        // ?status= — narrow to one or more lifecycle states (comma-separated,
        // e.g. ?status=active or ?status=active,sold). Whitelisted against the
        // never-public guard so a crafted value can't surface a draft/expired
        // listing. An all-unknown filter returns an empty page rather than
        // silently ignoring the filter and serving everything.
        if ($request->filled('status')) {
            $wanted = array_values(array_diff(
                array_filter(array_map('trim', explode(',', (string) $request->query('status')))),
                self::NEVER_PUBLIC_STATUSES
            ));
            $query->whereIn('status', $wanted ?: ['__no_match__']);
        }

        // ?listing_type= — sale vs rental/to-let etc (comma-separated).
        if ($request->filled('listing_type')) {
            $types = array_values(array_filter(array_map('trim', explode(',', (string) $request->query('listing_type')))));
            if ($types) {
                $query->whereIn('listing_type', $types);
            }
        }

        // Optional ?agent_id= — the agent's website profile pulls just their
        // listings. Matches EITHER the primary or the co-listing agent so a
        // co-listed property appears on both agents' profiles. Each listing
        // carries agents[].id (with is_primary) for linking back.
        if (($agentId = (int) $request->integer('agent_id')) > 0) {
            $query->where(fn ($q) => $q
                ->where('agent_id', $agentId)
                ->orWhere('pp_second_agent_id', $agentId));
        }

        // Optional ?branch_id= — a branch (office) page pulls just the listings
        // that sit under it. Pairs with /branches (branches:read).
        if (($branchId = (int) $request->integer('branch_id')) > 0) {
            $query->where('branch_id', $branchId);
        }

        $this->applySort($query, (string) $request->query('sort', '-published_at'));

        return ListingResource::collection(
            $query->paginate($perPage)->appends($request->query())
        );
    }

    public function show(Request $request, string $idOrRef): ListingResource
    {
        $listing = $this->publicListingsQuery($request->user()->getKey())
            ->where(fn ($q) => $q->where('id', $idOrRef)->orWhere('external_id', $idOrRef))
            ->first();

        abort_if($listing === null, 404, 'Listing not found.');

        return new ListingResource($listing);
    }

    /**
     * The single source of truth for "which listings this website key may see".
     * Confines to the key's enabled syndication pivot, drops the never-public
     * statuses (NULL-safe so unstatused legacy rows stay visible), and eager-loads
     * the relations the resource renders. Shared by index() and show() so the two
     * endpoints can never disagree about a listing's visibility.
     */
    private function publicListingsQuery(int $keyId): Builder
    {
        return Property::query()
            ->whereHas('websiteSyndication', fn ($q) => $q
                ->where('agency_api_key_id', $keyId)
                ->where('enabled', true))
            ->where(fn ($q) => $q
                ->whereNull('status')
                ->orWhereNotIn('status', self::NEVER_PUBLIC_STATUSES))
            ->with(['agent', 'secondAgent', 'activeShowdays']);
    }

    /**
     * Apply a deterministic ordering with a unique id tiebreaker.
     *
     * WHY THE TIEBREAKER IS MANDATORY: imported/promoted stock never stamps
     * published_at (only the publish-from-draft wizard does — see
     * PropertyWizardController), so for most agencies published_at is NULL across
     * the whole collection. Ordering by a non-unique key under LIMIT/OFFSET is
     * non-deterministic in MySQL: rows duplicate across pages while others land on
     * NO page at all — silently dropping live listings from the index (the
     * original "listing 4921 is missing despite matching every filter" bug).
     * Appending the unique id guarantees a TOTAL order, so pagination is stable
     * and every matching row is reachable on exactly one page.
     *
     * The default published_at sort COALESCEs to created_at so "newest first"
     * stays meaningful even when published_at is NULL.
     */
    private function applySort(Builder $query, string $sort): void
    {
        $desc = str_starts_with($sort, '-');
        $key  = ltrim($sort, '-');
        $dir  = $desc ? 'desc' : 'asc';

        if (! in_array($key, self::SORTABLE, true)) {
            $key = 'published_at';
            $dir = 'desc';
        }

        if ($key === 'id') {
            $query->orderBy('id', $dir); // id is itself the unique key — done.
            return;
        }

        if ($key === 'published_at') {
            // $dir is a literal 'asc'/'desc' derived above — never user text.
            $query->orderByRaw('COALESCE(published_at, created_at) ' . $dir);
        } else {
            $query->orderBy($key, $dir);
        }

        $query->orderByDesc('id'); // unique tiebreaker → deterministic pagination.
    }
}
