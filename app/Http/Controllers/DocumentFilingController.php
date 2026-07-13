<?php

namespace App\Http\Controllers;

use App\Models\DocumentFiling;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Filing\FilingPropertyLinker;
use App\Services\PermissionService;
use Illuminate\Http\Request;
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

        $filings = $query->orderBy('created_at', 'desc')->get();

        // Summary counts (scoped to same visibility)
        $countQuery = DocumentFiling::visibleTo($user);
        if ($isAdmin && $request->filled('branch_id')) {
            $countQuery->forBranch($request->branch_id);
        }

        $allFilings = $countQuery->get();
        $totalCount = $allFilings->count();
        $activeCount = $allFilings->filter(fn($f) => $f->status === 'active')->count();
        $expiringCount = $allFilings->filter(fn($f) => $f->status === 'expiring')->count();
        $expiredCount = $allFilings->filter(fn($f) => $f->status === 'expired')->count();

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
            : ($branchId ? (Branch::find($branchId)->name ?? 'Unknown') : 'Unknown');

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
            'branch_id'         => 'required|exists:branches,id',
            'agent_id'          => 'required|exists:users,id',
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
     * AT-238 — stamp the link provenance when a human picks a property in the form.
     *
     * Expiry is NOT touched here. The form prefills it from the property when the user
     * links one, but whatever they actually submit is what gets filed: an OA and an EA on
     * the same property can carry different expiry dates, and the register records what
     * was filed, not what the property currently says.
     */
    private function withLink(array $validated, ?DocumentFiling $existing): array
    {
        // Normalise ABSENT to NULL explicitly. An omitted (or blanked) property_id means
        // "unlink" — if we leave the key out, Eloquent simply doesn't touch the column and
        // the row keeps a link the user just removed.
        $newPropertyId = ($validated['property_id'] ?? null) ?: null;
        $oldPropertyId = $existing?->property_id;

        $validated['property_id']       = $newPropertyId;
        $validated['seller_contact_id'] = ($validated['seller_contact_id'] ?? null) ?: null;

        if ($newPropertyId && (int) $newPropertyId !== (int) $oldPropertyId) {
            $validated['link_source']     = 'manual';
            $validated['link_confidence'] = 'exact'; // a human pointed at it
        }

        if (! $newPropertyId) {
            // Unlinked (or never linked) — the row is free text again, and says so.
            $validated['link_source']     = null;
            $validated['link_confidence'] = null;
        }

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

        $results = $linker->candidates($q, (int) $user->agency_id)
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
        abort_unless((int) $property->agency_id === (int) $user->agency_id, 404);

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
