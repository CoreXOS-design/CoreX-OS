<?php

namespace App\Http\Controllers;

use App\Models\DocumentFiling;
use App\Models\Branch;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\Filing\FilingPropertyLinker;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class DocumentFilingController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'filing');
        $isAdmin = $scope === 'all';
        $isBM = $scope === 'branch';
        $branchId = $user->effectiveBranchId();

        // Build query with permission-based scoping
        $query = DocumentFiling::with(['agent', 'branch', 'capturedBy', 'property', 'sellerContact'])
            ->visibleTo($user);

        // Additional branch filter for users with 'all' scope
        if ($isAdmin && $request->filled('branch_id')) {
            $query->forBranch($request->branch_id);
            $branchId = $request->branch_id;
        }

        // Agent filter
        if ($request->filled('agent_id')) {
            $query->forAgent($request->agent_id);
        }

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Document type filter
        if ($request->filled('document_type') && $request->document_type !== 'All') {
            $query->where('document_type', $request->document_type);
        }

        // Status filter
        $showArchived = false;
        if ($request->filled('status') && $request->status !== 'All') {
            if ($request->status === 'Archived') {
                // Switch to soft-deleted records only
                $showArchived = true;
                $query = DocumentFiling::onlyTrashed()
                    ->with(['agent', 'branch', 'capturedBy', 'property', 'sellerContact'])
                    ->visibleTo($user);
                if ($isAdmin && $request->filled('branch_id')) {
                    $query->forBranch($request->branch_id);
                }
                if ($request->filled('agent_id')) {
                    $query->forAgent($request->agent_id);
                }
                if ($request->filled('search')) {
                    $query->search($request->search);
                }
                if ($request->filled('document_type') && $request->document_type !== 'All') {
                    $query->where('document_type', $request->document_type);
                }
            } elseif ($request->status === 'Active') {
                $query->where(function ($q) {
                    $q->whereNull('expiry_date')
                      ->orWhere('expiry_date', '>', Carbon::today()->addDays(30));
                });
            } elseif ($request->status === 'Expiring') {
                $query->expiringSoon();
            } elseif ($request->status === 'Expired') {
                $query->expired();
            }
        }

        // Load-limited on entry (Johan): paginate instead of rendering all ~2000 rows.
        // Page size is agency-configurable via PerformanceSetting (same pattern as
        // contacts/properties per-page), default 50, clamped to a sane range.
        $perPage = max(10, min(200, (int) PerformanceSetting::get('filing_register_page_size', 50)));
        $filings = $query->orderBy('created_at', 'desc')->paginate($perPage)->withQueryString();

        // Summary counts (scoped to same visibility) — SQL COUNTs, not load-all-then-
        // filter-in-PHP. Mirrors the status buckets: active = no/far expiry;
        // expiring = within 30 days; expired = past.
        $countQuery = DocumentFiling::visibleTo($user);
        if ($isAdmin && $request->filled('branch_id')) {
            $countQuery->forBranch($request->branch_id);
        }

        $totalCount    = (clone $countQuery)->count();
        $activeCount   = (clone $countQuery)->where(function ($q) {
            $q->whereNull('expiry_date')->orWhere('expiry_date', '>', Carbon::today()->addDays(30));
        })->count();
        $expiringCount = (clone $countQuery)->expiringSoon()->count();
        $expiredCount  = (clone $countQuery)->expired()->count();

        // Data for dropdowns
        $branches = $isAdmin ? Branch::orderBy('name')->get() : Branch::where('id', $user->effectiveBranchId())->get();
        $agentsQuery = User::where('is_active', 1)->orderBy('name');
        if (!$isAdmin) {
            $agentsQuery->where('branch_id', $user->effectiveBranchId());
        } elseif ($request->filled('branch_id')) {
            $agentsQuery->where('branch_id', $request->branch_id);
        }
        $agents = $agentsQuery->get();

        // Branch name for header
        $branchName = $isAdmin && !$request->filled('branch_id')
            ? 'All Branches'
            // AT-253 (Rule 17) — `Branch::find()->name` 500s when the branch is soft-deleted or
            // out of scope: the `??` guarded the value, not the receiver.
            : ($branchId ? (Branch::find($branchId)?->name ?? 'Unknown') : 'Unknown');

        return view('filing-register.index', compact(
            'filings', 'branches', 'agents', 'branchName',
            'isAdmin', 'isBM', 'showArchived',
            'totalCount', 'activeCount', 'expiringCount', 'expiredCount'
        ));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('filing.create'), 403);

        $validated = $request->validate($this->rules());

        // Non-'all' scope users can only add to their own branch
        $scope = PermissionService::getDataScope($user, 'filing');
        if ($scope !== 'all') {
            $validated['branch_id'] = $user->effectiveBranchId();
        }

        $validated['captured_by'] = $user->id;

        DocumentFiling::create($this->withLink($validated, null));

        return redirect()->route('filing-register.index')
            ->with('success', 'Filing entry added.');
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('filing.edit'), 403);

        // AT-238 SECURITY FIX. This previously did a bare findOrFail(): any user holding
        // access_filing_register could edit ANY branch's filing row, and set branch_id to
        // anything they liked — while store() had scoped correctly all along. Editing is
        // now scoped exactly like reading and creating.
        $filing = DocumentFiling::visibleTo($user)->findOrFail($id);

        $validated = $request->validate($this->rules());

        $scope = PermissionService::getDataScope($user, 'filing');
        if ($scope !== 'all') {
            // ...and they cannot move a row into (or out of) another branch.
            $validated['branch_id'] = $user->effectiveBranchId();
        }

        $filing->update($this->withLink($validated, $filing));

        return redirect()->route('filing-register.index')
            ->with('success', 'Filing entry updated.');
    }

    public function destroy($id)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('filing.archive'), 403);

        // AT-238 SECURITY FIX — same hole as update(): archiving was unscoped and
        // unpermissioned. (Soft delete, per the no-hard-deletes doctrine; restore() exists.)
        $filing = DocumentFiling::visibleTo($user)->findOrFail($id);
        $filing->delete();

        return redirect()->route('filing-register.index')
            ->with('success', 'Filing entry archived.');
    }

    /** The one validation contract — store() and update() must never drift apart again. */
    private function rules(): array
    {
        return [
            // AT-238 (Johan's flow) — branch and agent are NO LONGER things the clerk must know.
            // They derive from the picked property's listing context, and fall back to the
            // clerk's own branch/agent when there is no property. They stay overridable, so they
            // are accepted-if-sent and resolved server-side when they are not: the browser is
            // never the only thing standing between a NOT-NULL column and a null.
            'branch_id'         => 'nullable|exists:branches,id',
            'agent_id'          => 'nullable|exists:users,id',
            'document_type'     => 'required|in:OA,EA,Other',
            'file_reference'    => 'required|string|max:255',
            'sequence_number'   => 'required|string|max:255',
            // AT-238 — free text REMAINS required. A filing row must always be able to say
            // what it is about, whether or not CoreX holds a matching property record.
            'property_address'  => 'required|string|max:255',
            'seller_name'       => 'nullable|string|max:255',
            'expiry_date'       => 'nullable|date',
            'notes'             => 'nullable|string|max:2000',
            // AT-238 — the links. Optional: the manual path stays first-class.
            'property_id'       => 'nullable|integer|exists:properties,id',
            'seller_contact_id' => 'nullable|integer|exists:contacts,id',
        ];
    }

    /**
     * AT-238 — normalise the links.
     *
     * A link exists because a human picked it in the form, or it does not exist: there is no
     * provenance to record because nothing else can create one (the historical rows were
     * deliberately never backfilled).
     *
     * ABSENT must become NULL explicitly. An omitted or blanked property_id means "unlink" —
     * leave the key out and Eloquent simply doesn't touch the column, so the row silently
     * keeps a link the user just removed.
     *
     * Expiry is NOT touched here. The form prefills it from the property when a link is made,
     * but whatever the user actually submits is what gets filed: an OA and an EA on the same
     * property can carry different expiry dates, and the register records what was filed, not
     * what the property currently says.
     */
    private function withLink(array $validated, ?DocumentFiling $existing): array
    {
        $validated['property_id']       = ($validated['property_id'] ?? null) ?: null;
        $validated['seller_contact_id'] = ($validated['seller_contact_id'] ?? null) ?: null;

        // ONE SELLER FACT, enforced HERE and not merely in the form.
        //
        // A seller is EITHER a linked contact OR a typed name — never both. When a contact is
        // linked, the free-text name is not stored at all: keeping a copy of the contact's name
        // alongside the link creates a second fact that drifts the moment the contact is renamed,
        // and it is what let a duplicate seller be captured (Johan, qa1). The linked contact is
        // the seller; `seller_display` reads through to it. The typed name exists for exactly one
        // case — a seller CoreX does not hold — and that case has no link.
        if ($validated['seller_contact_id']) {
            $validated['seller_name'] = null;
        }

        return $this->resolveBranchAndAgent($validated, $existing);
    }

    /**
     * AT-238 (Johan's flow) — the property answers for branch and agent; the clerk supplies
     * neither unless they want to override.
     *
     * Resolution order, most-specific first:
     *   1. what the user explicitly chose (an override is always honoured)
     *   2. the LINKED PROPERTY's own listing context — its branch, its agent
     *   3. the row's existing values (an edit must not silently re-home an old filing)
     *   4. the acting user's own branch / the acting user themselves
     *
     * Both columns are NOT NULL, so if all four come up empty we refuse with a message rather
     * than let the insert fail as a raw 500 — an owner/super-admin with no branch of their own,
     * filing against no property, genuinely has no branch to file under (STANDARDS Rule 17).
     */
    private function resolveBranchAndAgent(array $validated, ?DocumentFiling $existing): array
    {
        $user = auth()->user();

        $property = ! empty($validated['property_id'])
            ? Property::withoutGlobalScopes()->find($validated['property_id'])
            : null;

        $branchId = ($validated['branch_id'] ?? null)
            ?: $property?->branch_id
            ?: $existing?->branch_id
            ?: $user?->effectiveBranchId();

        $agentId = ($validated['agent_id'] ?? null)
            ?: $property?->agent_id
            ?: $existing?->agent_id
            ?: $user?->id;

        if (! $branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'This filing has no branch to sit under — pick a property, or choose a branch.',
            ]);
        }

        $validated['branch_id'] = $branchId;
        $validated['agent_id']  = $agentId;

        return $validated;
    }

    /**
     * AT-238 — the filing register's OWN property search.
     *
     * It deliberately does not reuse the DR2 endpoint: that one is gated on `create_deals`,
     * so a filing clerk would get a 403, and widening `create_deals` to fix a filing screen
     * would hand deal-capture rights to anyone who files paper. Same canonical primitives
     * (Property::scopeSearchAddress + toSearchResult), own permission gate.
     */
    public function searchProperties(Request $request, FilingPropertyLinker $linker)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('filing.view') || $user->hasPermission('access_filing_register'), 403);

        $q = trim((string) $request->get('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        // Visibility, NOT a hand-rolled tenant filter. This previously matched on the raw
        // $user->agency_id, so an owner/super-admin (agency_id NULL → 0) silently got zero
        // results and the picker looked broken. STANDARDS Rule 17.
        $results = $linker->candidates($q, $user)
            ->map(fn (Property $p) => $p->toSearchResult([
                'address'     => $p->buildDisplayAddress(),
                'expiry_date' => $p->expiry_date?->format('Y-m-d'),
                'seller'      => $p->sellerOwnerContact()?->full_name,
            ]))
            ->values();

        return response()->json(['results' => $results]);
    }

    /**
     * AT-238 — who CoreX already believes the seller is, for a picked property.
     * Sourced from the property-link roles (contact_property.role ∈ seller/owner), per the
     * standing doctrine that property-link roles source the parties. Plus the expiry to
     * prefill, so one call answers everything the form needs after a property is picked.
     */
    public function propertySuggestions(Request $request, Property $property, FilingPropertyLinker $linker)
    {
        $user = auth()->user();
        abort_unless($user->hasPermission('filing.view') || $user->hasPermission('access_filing_register'), 403);

        // Same Rule-17 trap as the search: comparing raw agency_ids 404s an owner/super-admin
        // (whose agency_id is NULL) out of a property they are perfectly entitled to see.
        // Ask the visibility scope instead.
        abort_unless($linker->isVisibleTo($property, $user), 404);

        $sellers = $linker->sellerCandidates($property)->map(fn ($c) => [
            'id'    => $c->id,
            'name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
            'role'  => $c->pivot->role ?? null,
            'phone' => $c->phone,
            'email' => $c->email,
        ])->values();

        return response()->json([
            'suggestions' => $linker->suggestionsFor($property),
            'sellers'     => $sellers,
        ]);
    }

    // ── Restore soft-deleted ──

    public function restore($id)
    {
        abort_unless(auth()->user()->hasPermission('filing.edit'), 403);
        $record = DocumentFiling::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}
