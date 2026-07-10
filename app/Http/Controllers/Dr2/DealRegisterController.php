<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\DealMoneyLineRebuilder;
use App\Services\Finance\RollupService;
use App\Services\PermissionService;
use App\Services\SlidingScaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AT-215 / AT-217 (DR2) — the Deal Register (DR2).
 *
 * DR2 is an exact rebuild of DR1 on the SAME `deals` tables (spec
 * .ai/specs/deal-register-v2-rebuild-spec.md), coexisting with DR1 behind its own
 * nav + permission. AT-215 (cc1) built the shell (index/nav/routes/permissions);
 * AT-217 (cc3) builds the capture surface here:
 *   • create()/store()/edit()/update() — the DR1-parity write to
 *     deals / deal_user (same tables, same downstream services), PLUS the §2
 *     capture enhancements: property picker (canonical scopeSearchAddress) linked
 *     on deals.property_id; seller/buyer auto-offered from the linked property;
 *     attorney supplier search + inline-add; selling price + commission prefilled
 *     from the property (overridable); non-colliding External-agency layout.
 *   • searchProperties()/propertyContacts() — the picker's JSON feeds (canonical).
 *
 * DR1 (App\Http\Controllers\Admin\DealController) is UNTOUCHED — DR2 reproduces its
 * persist logic verbatim onto the same tables so both writers stay bit-parity.
 * It NEVER touches the abandoned deals-v2 module (App\Http\Controllers\DealV2,
 * URI `deals-v2/*`) — that sunsets under AT-219. Permissions reuse DR1's: view_deals
 * (register), create_deals (capture/edit) — spec §5.
 */
class DealRegisterController extends Controller
{
    /** DR2 register front page — the same `deals` rows DR1 shows (agency-scoped by BelongsToAgency). */
    public function index(): View
    {
        $deals = Deal::query()
            ->with('agents')
            ->orderByDesc('deal_date')
            ->limit(50)
            ->get();

        $branches = Branch::orderBy('name')->get();

        return view('dr2.index', compact('deals', 'branches'));
    }

    /**
     * DR2 capture screen (create). DR1-parity defaults + the §2 enhancements.
     * Mirrors Admin\DealController::create() — branch default via the acting
     * manager / effectiveBranchId(), current period, today, Pending / Not Paid.
     */
    public function create(): View
    {
        abort_unless(auth()->user()?->hasPermission('deals.create'), 403);

        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'deals');

        // §2.1 BRANCH — auto-select from the acting manager, else the user's home
        // branch (DR1 parity: Admin\DealController::create). Admins keep all-branch.
        $actingBranchId  = $user?->actingBranchManagerId();
        $defaultBranchId = $actingBranchId ?: $user?->effectiveBranchId();

        $agents = User::orderBy('name')->get();

        $branches = Branch::orderBy('name');
        if ($scope === 'branch') {
            $branches->where('id', $defaultBranchId);
        }
        $branches = $branches->get();

        $deal = new Deal();
        $deal->branch_id         = $defaultBranchId;
        $deal->period            = now()->format('Y-m');
        $deal->deal_date         = now()->toDateString();
        $deal->accepted_status   = 'P';
        $deal->commission_status = 'Not Paid';

        return view('dr2.create', [
            'mode'     => 'create',
            'deal'     => $deal,
            'agents'   => $agents,
            'branches' => $branches,
        ]);
    }

    /** DR2 edit — same capture surface, hydrated from an existing `deals` row. */
    public function edit(Deal $deal): View
    {
        abort_unless(auth()->user()?->hasPermission('deals.edit'), 403);

        $agents   = User::orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();

        return view('dr2.create', [
            'mode'     => 'edit',
            'deal'     => $deal,
            'agents'   => $agents,
            'branches' => $branches,
        ]);
    }

    /**
     * DR2 capture persist (create). DR1-parity numeric deal number + persistDeal,
     * writing the SAME `deals` / `deal_user` tables and firing the SAME downstream
     * services. Reproduces Admin\DealController::store() so the two writers agree.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create'), 403);

        try {
            return DB::transaction(function () use ($request) {
                $deal = new Deal();

                // NUMERIC DEAL NUMBERING — supports legacy D-#### and numeric formats (DR1 parity).
                $maxNumericOnly = (int) Deal::query()
                    ->whereRaw("deal_no NOT LIKE 'D-%'")
                    ->whereRaw("deal_no REGEXP '^[0-9]+$'")
                    ->max('deal_no');

                $maxFromPrefixed = (int) Deal::query()
                    ->selectRaw("MAX(CAST(SUBSTR(deal_no, 3) AS UNSIGNED)) as m")
                    ->where('deal_no', 'like', 'D-%')
                    ->value('m');

                $maxNumeric = max($maxNumericOnly, $maxFromPrefixed, 0);
                if ($maxNumeric <= 0) {
                    $maxNumeric = 1000; // fresh/wiped DB starts at 1001 to match real-world file numbering
                }

                $deal->deal_no = (string) ($maxNumeric + 1);

                return $this->persistDeal($deal, $request, true);
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('DR2 store() failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'input' => $request->except(['_token']),
            ]);
            return back()->withErrors('Failed to save deal: ' . $e->getMessage())->withInput();
        }
    }

    /** DR2 capture persist (update) — DR1 parity (Admin\DealController::update). */
    public function update(Request $request, Deal $deal): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.edit'), 403);

        try {
            return DB::transaction(fn () => $this->persistDeal($deal, $request, false));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('DR2 update() failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'deal_id' => $deal->id,
            ]);
            return back()->withErrors('Failed to save deal: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * The DR1-parity writer. Reproduces Admin\DealController::persistDeal() onto the
     * SAME `deals` / `deal_user` tables and downstream services, extended with the §2
     * property link (deals.property_id + link_source/link_confidence). Kept faithful
     * so DR1 and DR2 write byte-identical rows.
     */
    protected function persistDeal(Deal $deal, Request $request, bool $isNew): RedirectResponse
    {
        $oldAcceptedStatus = (string) ($deal->accepted_status ?? '');

        $data = $request->validate([
            'period'           => ['required'],
            'deal_date'        => ['required', 'date'],
            'property_value'   => ['required', 'numeric'],
            'total_commission' => ['required', 'numeric'],

            'listing_split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'selling_split_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'file_no'          => ['nullable', 'string', 'max:255'],
            'branch_id'        => ['nullable', 'integer'],

            // §2.2 property link (canonical picker). Free-text address kept for parity/display.
            'property_id'      => ['nullable', 'integer', 'exists:properties,id'],
            'property_address' => ['nullable', 'string', 'max:255'],

            'seller_name'      => ['nullable', 'string', 'max:255'],
            'buyer_name'       => ['nullable', 'string', 'max:255'],
            'attorney_name'    => ['nullable', 'string', 'max:255'],
            'accepted_status'  => ['nullable', 'string', 'max:1'],
            'commission_status' => ['nullable', 'string', 'max:50'],
            'registration_date' => ['nullable', 'date'],
            'remarks'          => ['nullable', 'string'],

            'listing_external'        => ['nullable'],
            'listing_our_share_percent' => ['nullable', 'numeric'],
            'listing_external_agency' => ['nullable', 'string', 'max:255'],

            'selling_external'        => ['nullable'],
            'selling_our_share_percent' => ['nullable', 'numeric'],
            'selling_external_agency' => ['nullable', 'string', 'max:255'],

            'listing_agents'  => ['array'],
            'selling_agents'  => ['array'],
            'listing_override' => ['array'],
            'selling_override' => ['array'],
        ]);

        $user  = auth()->user();
        $scope = PermissionService::getDataScope($user, 'deals');

        // Branch-scope users are forced to their branch (DR1 parity).
        if ($user && $scope === 'branch') {
            $data['branch_id'] = $user->effectiveBranchId();
        }

        // (AT-192 b) No deal may be stored without a branch — server-side gate.
        if ($scope !== 'branch' && empty($data['branch_id'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'branch_id' => 'Please choose the branch this deal belongs to. Your account has no home branch, so the branch cannot be filled in automatically.',
            ]);
        }

        if ($isNew) {
            if (empty($data['accepted_status']))   { $data['accepted_status'] = 'P'; }
            if (empty($data['commission_status'])) { $data['commission_status'] = 'Not Paid'; }

            // Admin Multi-Branch Manager capture at registration (DR1 parity).
            if ($user) {
                $acting       = $user->actingBranchManagerId();
                $chosenBranch = (int) ($data['branch_id'] ?? 0);
                if ($acting && $chosenBranch === $acting && $user->isManagerOfBranch($chosenBranch)) {
                    $deal->managed_by_user_id = $user->id;
                }
            }
        }

        // Deal-level side split must total 100 (tolerance 0.01) — DR1 parity.
        $listingSplit = isset($data['listing_split_percent']) && $data['listing_split_percent'] !== '' ? (float) $data['listing_split_percent'] : 50.0;
        $sellingSplit = isset($data['selling_split_percent']) && $data['selling_split_percent'] !== '' ? (float) $data['selling_split_percent'] : 50.0;

        if (abs(($listingSplit + $sellingSplit) - 100) > 0.01) {
            return back()->withErrors('Listing split % + Selling split % must equal 100. Currently: ' . ($listingSplit + $sellingSplit))->withInput();
        }

        foreach (['listing', 'selling'] as $side) {
            $external  = !empty($data[$side . '_external']);
            $agents    = $data[$side . '_agents'] ?? [];
            $overrides = $data[$side . '_override'] ?? [];

            if ($external) {
                $data[$side . '_our_share_percent'] = 0;
                continue;
            }

            if (count($agents) === 0) {
                return back()->withErrors("{$side} side requires at least one agent.")->withInput();
            }

            $anyOverride = false;
            foreach ($agents as $id) {
                $v = $overrides[$id] ?? null;
                if ($v !== null && $v !== '') { $anyOverride = true; break; }
            }

            if ($anyOverride) {
                $sum = 0;
                foreach ($agents as $id) {
                    $v = $overrides[$id] ?? null;
                    if ($v === null || $v === '') {
                        return back()->withErrors("{$side} side: if you use % overrides, every selected agent needs a % (and total must be 100).")->withInput();
                    }
                    $sum += (float) $v;
                }
                if (abs($sum - 100) > 0.01) {
                    return back()->withErrors("{$side} side percentages must total 100. Currently: {$sum}")->withInput();
                }
            }
        }

        // §2.2 — resolve the picked property link (manual pick = exact confidence).
        $propertyId = !empty($data['property_id']) ? (int) $data['property_id'] : null;

        $deal->fill([
            'period'           => $data['period'],
            'deal_date'        => $data['deal_date'],
            'property_value'   => $data['property_value'],
            'total_commission' => $data['total_commission'],

            'listing_split_percent' => $listingSplit,
            'selling_split_percent' => $sellingSplit,
            'file_no'          => $data['file_no'] ?? null,
            'branch_id'        => $data['branch_id'] ?? null,

            'property_id'      => $propertyId,
            'property_address' => $data['property_address'] ?? null,

            'seller_name'      => $data['seller_name'] ?? null,
            'buyer_name'       => $data['buyer_name'] ?? null,
            'attorney_name'    => $data['attorney_name'] ?? null,
            'accepted_status'  => $data['accepted_status'] ?? null,
            'commission_status' => $data['commission_status'] ?? null,
            'registration_date' => $data['registration_date'] ?? null,
            'remarks'          => $data['remarks'] ?? null,

            'listing_external' => !empty($data['listing_external']),
            'listing_our_share_percent' => $data['listing_our_share_percent'] ?? 100,
            'listing_external_agency' => $data['listing_external_agency'] ?? null,

            'selling_external' => !empty($data['selling_external']),
            'selling_our_share_percent' => $data['selling_our_share_percent'] ?? 100,
            'selling_external_agency' => $data['selling_external_agency'] ?? null,
        ]);

        // Stamp link provenance only when a property is picked; never clobber a
        // pre-existing auto-match link with NULLs when the user leaves it blank.
        if ($propertyId) {
            $deal->link_source     = 'manual';
            $deal->link_confidence = 'exact';
        }

        $deal->save();

        // Rebuild agent pivots (DR1 parity: detach then re-attach per side with snapshots).
        $deal->agents()->detach();

        foreach (['listing', 'selling'] as $side) {
            if (!empty($data[$side . '_external'])) { continue; }

            $agents    = $data[$side . '_agents'] ?? [];
            $overrides = $data[$side . '_override'] ?? [];

            $anyOverride = false;
            foreach ($agents as $id) {
                $v = $overrides[$id] ?? null;
                if ($v !== null && $v !== '') { $anyOverride = true; break; }
            }

            $count = max(count($agents), 1);
            $auto  = 100 / $count;

            if ($anyOverride) {
                $sum = 0.0;
                foreach ($agents as $id) {
                    $sum += (float) ($overrides[$id] ?? 0);
                }
                if (abs($sum - 100.0) > 0.01) {
                    return back()->withErrors(strtoupper($side) . ' split overrides must total 100%. Currently: ' . $sum)->withInput();
                }
            }

            foreach ($agents as $userId) {
                $agentUser = User::find($userId);

                $defaultCut        = ($agentUser && $agentUser->agent_cut_percent !== null && $agentUser->agent_cut_percent !== '') ? (float) $agentUser->agent_cut_percent : 50;
                $defaultPayeMethod = ($agentUser && $agentUser->paye_method) ? $agentUser->paye_method : 'percentage';
                $defaultPayeValue  = ($agentUser && $agentUser->paye_value !== null && $agentUser->paye_value !== '') ? (float) $agentUser->paye_value : 0;

                $split = $anyOverride ? (float) ($overrides[$userId] ?? 0) : $auto;

                $deal->agents()->attach($userId, [
                    'side'                => $side,
                    'agent_split_percent' => $split,
                    'agent_cut_percent'   => $defaultCut,
                    'paye_method'         => $defaultPayeMethod,
                    'paye_value'          => $defaultPayeValue,
                ]);
            }
        }

        // Sliding scale recalculation: only when accepted_status crosses Granted (DR1 parity).
        $newAcceptedStatus = (string) ($deal->accepted_status ?? '');
        if ($oldAcceptedStatus !== $newAcceptedStatus && ($oldAcceptedStatus === 'G' || $newAcceptedStatus === 'G')) {
            try {
                (new SlidingScaleService())->applyForDeal($deal->fresh());
            } catch (\Throwable $e) {
                \Log::error('DR2 SlidingScaleService failed', ['deal_id' => $deal->id, 'error' => $e->getMessage()]);
            }
        }

        // Rebuild deal_money_lines + refresh the period rollup (DR1 parity).
        try {
            DealMoneyLineRebuilder::rebuildDealId((int) $deal->id);
        } catch (\Throwable $e) {
            \Log::error('DR2 DealMoneyLineRebuilder failed', ['deal_id' => $deal->id, 'error' => $e->getMessage()]);
        }

        $dealPeriod = (string) ($deal->period ?? '');
        if ($dealPeriod && preg_match('/^\d{4}-\d{2}$/', $dealPeriod)) {
            try {
                (new RollupService())->refreshPeriod($dealPeriod);
            } catch (\Throwable $e) {
                \Log::error('DR2 RollupService failed', ['deal_id' => $deal->id, 'period' => $dealPeriod, 'error' => $e->getMessage()]);
            }
        }

        return redirect()->route('deals-dr2.index')
            ->with('status', $isNew ? "Deal {$deal->deal_no} captured." : "Deal {$deal->deal_no} updated.");
    }

    /**
     * §2.2 — canonical property picker feed. Reuses Property::scopeSearchAddress
     * (token-AND, unit/complex clarity — the AT-128 standard) and rides the
     * enhancement payload (price / commission %) along so §2.5 + §2.6 can prefill.
     */
    public function searchProperties(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $search = (string) $request->input('q', '');
        if (strlen(trim($search)) < 2) {
            return response()->json([]);
        }

        $properties = Property::query()
            ->searchAddress($search)
            ->with('agent')
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (Property $p) => $p->toSearchResult([
                'address'            => $p->buildDisplayAddress(),
                'price'              => $p->listing_price ?? $p->price ?? null,
                'commission_percent' => $p->commission_percent,
                'listing_agent_id'   => $p->agent_id,
                'listing_agent_name' => $p->agent?->name,
            ]));

        return response()->json($properties);
    }

    /**
     * §2.3 — seller / buyer offered from the linked property. Returns the property's
     * contacts split by role so the capture screen can auto-fill the seller and
     * present a tick-list of linked buyers. Agency scope is structural (global scope).
     */
    public function propertyContacts(Property $property): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $sellerRoles = Property::pivotRolesForContactRole('seller_owner'); // ['seller','owner']
        $buyerRoles  = Property::pivotRolesForContactRole('buyer');        // ['buyer']

        $sellers = [];
        $buyers  = [];

        foreach ($property->contacts()->get() as $c) {
            $role = strtolower((string) ($c->pivot->role ?? ''));
            $row  = [
                'id'    => $c->id,
                'name'  => $c->full_name,
                'email' => $c->email,
                'phone' => $c->phone,
                'role'  => $role,
            ];
            if (in_array($role, $sellerRoles, true)) {
                $sellers[] = $row;
            } elseif (in_array($role, $buyerRoles, true)) {
                $buyers[] = $row;
            }
        }

        return response()->json([
            'sellers' => $sellers,
            'buyers'  => $buyers,
        ]);
    }
}
