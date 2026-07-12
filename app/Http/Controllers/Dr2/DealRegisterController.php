<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealLog;
use App\Models\DealSettlement;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\Property;
use App\Models\User;
use App\Services\ContactDuplicateService;
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
    /**
     * DR2 register list — a FAITHFUL copy of DR1's Admin\DealController::index()
     * (search, status/commission/branch/agent filters, sort, paid-not-settled
     * exception, agent scope via visibleTo). Renders the DR1-identical dr2.index.
     * Read access (deals.view) — admin + BM + agent (agent scoped to own deals).
     */
    public function index(Request $request): View
    {
        abort_unless(auth()->user()?->hasPermission('deals.view'), 403);

        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'deals');
        $query = Deal::query()->visibleTo($user)->with('agents');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('property_address', 'like', "%{$search}%")
                  ->orWhere('seller_name', 'like', "%{$search}%")
                  ->orWhere('buyer_name', 'like', "%{$search}%")
                  ->orWhere('deal_no', 'like', "%{$search}%")
                  ->orWhere('file_no', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $map = [
                'Pending'    => ['Pending', 'P'],
                'Granted'    => ['Granted', 'G'],
                'Registered' => ['Registered', 'R'],
                'Declined'   => ['Declined', 'D'],
            ];
            if (isset($map[$status])) {
                $query->whereIn('accepted_status', $map[$status]);
            } else {
                $query->where('accepted_status', $status);
            }
        }

        if ($commStatus = $request->input('commission')) {
            $query->where('commission_status', $commStatus);
        }

        if ($scope === 'all' && ($branchFilter = $request->input('branch'))) {
            $query->where('branch_id', $branchFilter);
        }

        if ($agentFilter = $request->input('agent')) {
            $query->whereHas('agents', fn ($q) => $q->where('users.id', $agentFilter));
        }

        $sortField = $request->input('sort', 'deal_no');
        $sortDir = $request->input('direction', 'desc');
        $allowed = ['deal_no', 'deal_date', 'property_value', 'accepted_status', 'commission_status', 'property_address'];
        if (! in_array($sortField, $allowed)) {
            $sortField = 'deal_no';
        }
        $query->orderBy($sortField, $sortDir === 'asc' ? 'asc' : 'desc');

        $deals = $query->paginate(20)->withQueryString();

        // PAID_NOT_SETTLED exception report (admin all-scope only) — DR1 parity.
        $paidNotSettledDeals = collect();
        if ($scope === 'all') {
            $allPaidDeals = Deal::query()->visibleTo($user)->where('commission_status', 'Paid')->get();
            $paidDealIds = $allPaidDeals->pluck('id')->map(fn ($v) => (int) $v)->all();

            $settledPaidDealIds = [];
            if (count($paidDealIds) > 0) {
                $settledPaidDealIds = DealSettlement::query()
                    ->whereIn('deal_id', $paidDealIds)
                    ->whereNotNull('paid_at')
                    ->distinct()
                    ->pluck('deal_id')
                    ->map(fn ($v) => (int) $v)
                    ->all();
            }

            $settledPaidSet = array_flip($settledPaidDealIds);
            $paidNotSettledDeals = $allPaidDeals->filter(fn ($d) => ! isset($settledPaidSet[(int) $d->id]))->values();
        }

        $agents = User::orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();

        $branchIdContext = (int) $request->input('branch_id');
        if ($branchIdContext <= 0 && $scope === 'branch') {
            $branchIdContext = (int) ($user->effectiveBranchId() ?? ($user->branch_id ?? 0));
        }

        return view('dr2.index', compact('deals', 'agents', 'branches', 'paidNotSettledDeals', 'branchIdContext'));
    }

    /**
     * DR2 deal log — DR1 parity (read the audit trail). deals.view (all three roles;
     * agents see their own deals' feedback).
     */
    public function log(Deal $deal): View
    {
        abort_unless(auth()->user()?->hasPermission('deals.view'), 403);

        $logs = DealLog::query()
            ->where('deal_id', $deal->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $actors = User::whereIn('id', $logs->pluck('actor_user_id')->filter()->unique()->values())->get()->keyBy('id');

        return view('dr2.log', compact('deal', 'logs', 'actors'));
    }

    /**
     * DR2 add remark — FEEDBACK. Per Johan's DR2 permission doctrine, AGENTS may give
     * feedback (log/remarks), so this gates on deals.view (not deals.create like DR1).
     * Read-plus-feedback, not deal setup.
     */
    public function addRemark(Request $request, Deal $deal): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.view'), 403);

        $data = $request->validate([
            'remark' => ['required', 'string', 'max:2000'],
        ]);

        $remark = trim((string) $data['remark']);
        if ($remark === '') {
            return redirect()->route('deals-dr2.log', $deal)->withErrors('Remark cannot be blank.');
        }

        // Backwards compatibility: keep the latest remark on the deal row (DR1 parity).
        $deal->remarks = $remark;
        $deal->save();

        $this->logDealEvent($deal, 'remark_added', null, null, $remark);

        return redirect()->route('deals-dr2.log', $deal)->with('status', 'Remark added.');
    }

    /**
     * DR2 quick status update — DR1 parity. Deal STATUS is setup, not feedback, so it
     * stays deals.edit (admin + BM). Agents' allowed writes are feedback + pipeline
     * steps, not accepted/commission status.
     */
    public function quickUpdate(Request $request, Deal $deal): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.edit'), 403);

        $oldAccepted = (string) ($deal->accepted_status ?? '');
        $oldCommission = (string) ($deal->commission_status ?? '');

        $data = $request->validate([
            'accepted_status'   => ['nullable', 'string', 'max:1'],
            'commission_status' => ['nullable', 'string', 'max:50'],
        ]);

        $newAccepted = array_key_exists('accepted_status', $data) ? (string) ($data['accepted_status'] ?? '') : $oldAccepted;
        $newCommission = array_key_exists('commission_status', $data) ? (string) ($data['commission_status'] ?? '') : $oldCommission;

        // Wave 2 granted-uniqueness — block a second grant on the same property.
        if ($newAccepted === 'G' && $oldAccepted !== 'G') {
            if ($conflict = app(\App\Services\Deal\DealPropertyStatusService::class)->committedDealOnProperty($deal->property_id, (int) $deal->id)) {
                return back()->with('grant_conflict', $this->grantConflictPayload($conflict));
            }
        }

        $deal->fill([
            'accepted_status'   => $newAccepted,
            'commission_status' => $newCommission,
        ])->save();

        if ($oldAccepted !== $newAccepted) {
            $this->logDealEvent($deal, 'status_changed', $oldAccepted, $newAccepted);
        }
        if ($oldCommission !== $newCommission) {
            $this->logDealEvent($deal, 'commission_status_changed', $oldCommission, $newCommission);
        }

        // The deal row + its money lines are updated SYNCHRONOUSLY above (the register's
        // status column + money lines are correct the instant this returns).
        DealMoneyLineRebuilder::rebuildDealId((int) $deal->id);

        // (Johan DR2-walk fix 3) The slow part of a quick status save is the PERIOD-WIDE
        // finance rollup — RollupService::refreshPeriod recomputes finance_computed_values
        // across every deal/agent/branch in the period (O(period), not O(this deal)). It
        // feeds reports/dashboards, NOT the register, so DEFER it until after the response:
        // the save returns snappy, the rollup still runs the same request cycle (no queue
        // worker needed). Nothing is failing/retrying — it was just heavy work run inline.
        $dealPeriod = (string) ($deal->period ?? '');
        if ($dealPeriod && preg_match('/^\d{4}-\d{2}$/', $dealPeriod)) {
            dispatch(function () use ($dealPeriod) {
                (new RollupService())->refreshPeriod($dealPeriod);
            })->afterResponse();
        }

        return redirect()->route('deals-dr2.index')->with('status', 'Deal updated.');
    }

    /** DR1-parity audit-trail writer. Never blocks the deal operation on a logging failure. */
    private function logDealEvent(Deal $deal, string $eventType, ?string $from = null, ?string $to = null, ?string $message = null): void
    {
        try {
            DealLog::create([
                'deal_id'       => $deal->id,
                'actor_user_id' => auth()->id(),
                'event_type'    => $eventType,
                'from_value'    => $from,
                'to_value'      => $to,
                'message'       => $message,
            ]);
        } catch (\Throwable $e) {
            // Never block deal operations because logging failed.
        }
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

        // AT-216 V1.1 — pipeline auto-attach at capture: offer the agency's active templates,
        // defaulted per deal_type (agency-configurable is_default), changeable, attached on save.
        $templates     = \App\Models\DealV2\DealPipelineTemplate::where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->get();
        $defaultByType = [];
        foreach (['bond', 'cash', 'sale_of_2nd'] as $t) {
            $tpl = $templates->first(fn ($x) => $x->deal_type === $t && $x->is_default)
                ?? $templates->first(fn ($x) => $x->deal_type === $t)
                ?? $templates->first(fn ($x) => (bool) $x->is_default)
                ?? $templates->first();
            $defaultByType[$t] = optional($tpl)->id;
        }

        return view('dr2.create', [
            'mode'               => 'create',
            'deal'               => $deal,
            'agents'             => $agents,
            'branches'           => $branches,
            'availableTemplates' => $templates,
            'defaultByType'      => $defaultByType,
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

                $resp = $this->persistDeal($deal, $request, true);
                if ($deal->exists) {
                    $this->logDealEvent($deal, 'created', null, null, 'Deal created');

                    // AT-216 V1.1 — auto-attach the selected/defaulted pipeline on save. A bad
                    // or foreign template must never fail the deal save (nested savepoint).
                    $templateId = (int) $request->input('pipeline_template_id');
                    if ($templateId > 0) {
                        try {
                            app(\App\Services\Deal\Dr1PipelineService::class)->createPipeline($deal, $templateId);
                        } catch (\Throwable $e) {
                            \Log::warning('DR2 pipeline auto-attach skipped', [
                                'deal_id' => $deal->id, 'template_id' => $templateId, 'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }

                return $resp;
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
            // (Enhancement 6) deal type is COMPULSORY — explicit choice, no silent
            // default. Additive column on `deals`; DR1 ignores it (legacy rows NULL).
            'deal_type'        => ['required', 'in:bond,cash,sale_of_2nd'],
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
            // (DR2 reverse link) CSV of chosen contact ids for each party. Used
            // to create the property↔contact link with the right role at save
            // (one action, both records). Display names stay in *_name.
            'seller_contact_ids' => ['nullable', 'string', 'max:500'],
            'buyer_contact_ids'  => ['nullable', 'string', 'max:500'],
            'attorney_name'    => ['nullable', 'string', 'max:255'],
            // (fix 2) attorney = firm + contact person; the deal links both.
            'attorney_provider_id' => ['nullable', 'integer', 'exists:agency_service_providers,id'],
            'attorney_contact_id'  => ['nullable', 'integer', 'exists:agency_service_provider_contacts,id'],
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

        // Wave 2 granted-uniqueness — a property may carry multiple concurrent
        // deals, but AT MOST ONE granted. Block a NEW grant here (before any
        // write) when another deal already holds the granted/registered lane.
        // back()->withInput() preserves every field the user entered so the
        // block modal can send them to resolve the other deal, then return.
        $intendedAccepted = (string) ($data['accepted_status'] ?? '');
        if ($intendedAccepted === 'G' && $oldAcceptedStatus !== 'G') {
            if ($conflict = app(\App\Services\Deal\DealPropertyStatusService::class)->committedDealOnProperty($propertyId, (int) ($deal->id ?? 0))) {
                return back()->withInput()->with('grant_conflict', $this->grantConflictPayload($conflict));
            }
        }

        $deal->fill([
            'period'           => $data['period'],
            'deal_date'        => $data['deal_date'],
            'deal_type'        => $data['deal_type'],
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
            'attorney_provider_id' => ! empty($data['attorney_provider_id']) ? (int) $data['attorney_provider_id'] : null,
            'attorney_contact_id'  => ! empty($data['attorney_contact_id']) ? (int) $data['attorney_contact_id'] : null,
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

        // (DR2 reverse link — property-spine doctrine) Deal capture is often the
        // moment a buyer/seller enters the story. Linking a party on the deal
        // MUST also link them to the PROPERTY with the correct role, so the
        // property knows its buyer/seller too. One action, both records —
        // idempotent, audit-logged, never re-roles an existing link.
        if ($propertyId) {
            $linkProperty = Property::find($propertyId);
            if ($linkProperty) {
                $this->syncPartyLinks($linkProperty, $this->parseIdCsv($data['seller_contact_ids'] ?? null), 'seller');
                $this->syncPartyLinks($linkProperty, $this->parseIdCsv($data['buyer_contact_ids'] ?? null), 'buyer');
            }
        }

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

        // Wave 2 resale/duplicate-address guard — a buyer who buys, renovates and
        // relists leaves TWO property records at one address (old Sold/archived,
        // new Active). By DEFAULT steer agents to the ON-MARKET record; a
        // ?all=1 toggle reveals the off-market ones for genuine edge cases. Old
        // (off-market) records never receive status updates from new deals (the
        // Wave 2 listeners already skip OFF_MARKET_STATUSES), so linking one is
        // almost always a mistake — the UI warns before it is selected.
        $showAll = $request->boolean('all');

        // (Enhancement 1) Rich results matching the PDF splitter's property search
        // exactly: each row carries EXTRA identifying info — reference + seller +
        // listing agent — plus (Wave 2) a status badge + key dates so an agent can
        // tell the live listing from the sold twin at the same address.
        $properties = Property::query()
            ->visibleTo($request->user())
            ->searchAddress($search)
            ->when(! $showAll, fn ($q) => $q->onMarket())
            ->with('agent')
            ->latest()
            ->limit(15)
            ->get();

        // Sold date for off-market rows = the registration_date of the property's
        // registered ('R') deal, if any. One query for the whole result set.
        $offMarketIds = $properties->filter(fn (Property $p) => ! $p->isOnMarket())->pluck('id');
        $soldDates = $offMarketIds->isEmpty() ? collect() : \App\Models\Deal::withoutGlobalScopes()
            ->whereIn('property_id', $offMarketIds)
            ->where('accepted_status', 'R')
            ->whereNull('deleted_at')
            ->selectRaw('property_id, MAX(registration_date) as sold_date')
            ->groupBy('property_id')
            ->pluck('sold_date', 'property_id');

        $results = $properties->map(function (Property $p) use ($soldDates) {
            $seller  = $p->sellerOwnerContact();
            $onMarket = $p->isOnMarket();

            return $p->toSearchResult([
                'address'            => $p->buildDisplayAddress(),
                'ref'                => $p->property_number,
                'seller'             => $seller ? trim(($seller->first_name ?? '') . ' ' . ($seller->last_name ?? '')) : null,
                'price'              => $p->listing_price ?? $p->price ?? null,
                'commission_percent' => $p->commission_percent,
                'listing_agent_id'   => $p->agent_id,
                'listing_agent_name' => $p->agent?->name,
                // Wave 2 resale guard payload:
                'status'             => (string) $p->status,
                'on_market'          => $onMarket,
                'listed_date'        => optional($p->listed_date ?? $p->first_marketed_at)->toDateString(),
                'sold_date'          => $onMarket ? null : ($soldDates[$p->id] ?? null),
            ]);
        });

        return response()->json($results);
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

    /**
     * (DR2 party picker) Contact autocomplete for the buyer/seller fields — the
     * UNIVERSAL path when the property has no linked party yet (the property
     * tick-list is only the fast path). Reuses the canonical Contact::search +
     * toSearchResult primitives (same engine the property-page picker uses).
     */
    public function contactSearch(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        // Search the whole AGENCY (bypass the 'own'/'branch' ContactScope) so a
        // capturer can link ANY existing agency contact instead of being shown
        // nothing and creating a duplicate (Non-Negotiable #10). AgencyScope +
        // soft-deletes still apply. Mirrors the property-page link picker.
        $rows = Contact::withoutGlobalScope(\App\Models\Scopes\ContactScope::class)
            ->with(['phones', 'emails', 'type', 'agent'])
            ->search($q)
            ->limit(15)
            ->get()
            ->map(fn (Contact $c) => $c->toSearchResult($q, [
                'name'  => $c->full_name,
                'email' => $c->email,
                'phone' => $c->phone,
            ]));

        return response()->json($rows);
    }

    /**
     * (DR2 party picker) Add-new contact inline — Match-or-Create (Non-Neg #10):
     * an existing contact matching phone/email is REUSED, never duplicated. Does
     * NOT link here — the deal save creates the property↔contact link with the
     * correct role. Returns {id, name} for the picker to token, or a 409
     * duplicate payload when the agency's dupe policy needs a human decision.
     */
    public function contactInline(Request $request): JsonResponse
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('deals.create') || $user?->hasPermission('deals.edit'), 403);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name'  => ['nullable', 'string', 'max:100'],
            'phone'      => ['nullable', 'string', 'max:30'],
            'email'      => ['nullable', 'email', 'max:150'],
            'bypass_duplicate_check' => ['nullable', 'boolean'],
        ]);

        $agencyId = (int) ($user->effectiveAgencyId() ?? 0);
        $bypass   = ! empty($data['bypass_duplicate_check']);
        unset($data['bypass_duplicate_check']);

        $service = app(ContactDuplicateService::class);
        if (! $bypass) {
            $dupes = $service->findDuplicates($data, $agencyId);
            if ($dupes->isNotEmpty()) {
                $mode = $service->resolveMode($agencyId);
                if ($mode === 'auto_link') {
                    $existing = $dupes->first();
                    return response()->json(['id' => $existing->id, 'name' => $existing->full_name, 'matched' => true]);
                }
                return response()->json([
                    'duplicate_detected' => [
                        'duplicates' => $dupes->map(fn ($c) => [
                            'id'   => $c->id,
                            'name' => $c->full_name,
                            'phone' => $mode === 'hard_block_request' ? null : $c->phone,
                        ])->values()->all(),
                        'mode'         => $mode,
                        'can_override' => $mode === 'hard_block_override' && in_array($user->effectiveRole(), ['admin', 'super_admin', 'owner'], true),
                    ],
                ], 409);
            }
        }

        $data['created_by_user_id'] = $user->id;
        $contact = Contact::create($data);

        return response()->json(['id' => $contact->id, 'name' => $contact->full_name], 201);
    }

    /**
     * Build the block-modal payload for a granted-uniqueness conflict: the
     * blocking deal's number, a link that opens THAT deal in a new tab (so the
     * user can resolve it — e.g. decline the fallen-through deal), and its status.
     *
     * @return array{deal_no:string,deal_id:int,url:string,status:string}
     */
    private function grantConflictPayload(Deal $conflict): array
    {
        return [
            'deal_no' => (string) ($conflict->deal_no ?? $conflict->id),
            'deal_id' => (int) $conflict->id,
            'url'     => route('deals-dr2.edit', $conflict->id),
            'status'  => $conflict->accepted_status === 'R' ? 'Registered' : 'Granted',
        ];
    }

    /**
     * Parse a CSV of contact ids (from the party picker's hidden field) into a
     * clean, deduped list of positive ints.
     *
     * @return int[]
     */
    private function parseIdCsv(?string $csv): array
    {
        if (! $csv) {
            return [];
        }
        return array_values(array_unique(array_filter(
            array_map('intval', explode(',', $csv)),
            fn ($n) => $n > 0
        )));
    }

    /**
     * (DR2 reverse link) Link each chosen contact to the property with $role —
     * idempotent, and it NEVER re-roles a contact already linked in another
     * role (a seller picked as a buyer on a later deal keeps its seller link).
     * Ensures the seller-side PropertySellerLink, and fires the canonical
     * ContactLinkedToProperty audit event only for genuinely NEW links.
     *
     * @param  int[]  $contactIds
     */
    private function syncPartyLinks(Property $property, array $contactIds, string $role): void
    {
        foreach ($contactIds as $cid) {
            // Respect an existing link of ANY role — no silent re-roling.
            if ($property->contacts()->where('contacts.id', $cid)->exists()) {
                continue;
            }
            $contact = Contact::find($cid);
            if (! $contact) {
                continue;
            }
            $property->contacts()->attach($cid, ['role' => $role]);
            if ($role === 'seller') {
                \App\Models\PropertySellerLink::ensureExists((int) $property->id, $cid);
            }
            // Domain event — new contact↔property link (Non-Neg #9 / audit).
            event(new \App\Events\Contact\ContactLinkedToProperty(
                contact: $contact,
                property: $property,
                role: $role,
                actorUserId: auth()->id(),
            ));
        }
    }

    /**
     * (Johan DR2-walk fix 2) Attorney = a FIRM with MULTIPLE contact persons.
     * Search attorney firms (agency-scoped, active) and flatten each firm × its
     * contacts into pick options, so the capture can attach FIRM + the specific
     * contact person (BBB Inc → attorney X via his assistant, attorney Y via his
     * paralegal). A firm with no contacts yet is still offerable (firm-only).
     */
    public function attorneySearch(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $firms = AgencyServiceProvider::query()
            ->where('is_active', true)
            ->where('specialty', 'transfer_attorney')
            ->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                  ->orWhereHas('serviceContacts', fn ($c) => $c->where('attorney_name', 'like', "%{$q}%")->orWhere('contact_person', 'like', "%{$q}%"));
            })
            ->with(['serviceContacts' => fn ($c) => $c->where('is_active', true)])
            ->limit(10)
            ->get();

        $results = [];
        foreach ($firms as $firm) {
            if ($firm->serviceContacts->isEmpty()) {
                $results[] = [
                    'firm' => $firm->name, 'provider_id' => $firm->id, 'contact_id' => null,
                    'attorney' => null, 'contact' => null, 'email' => $firm->email,
                    'label' => $firm->name,
                ];
                continue;
            }
            foreach ($firm->serviceContacts as $c) {
                $results[] = [
                    'firm' => $firm->name, 'provider_id' => $firm->id, 'contact_id' => $c->id,
                    'attorney' => $c->attorney_name, 'contact' => $c->contact_person, 'email' => $c->email,
                    'label' => $this->attorneyLabel($firm->name, $c->attorney_name, $c->contact_person),
                ];
            }
        }

        return response()->json(['results' => $results]);
    }

    /**
     * (Johan DR2-walk fix 2) Add-new attorney inline. Modal field order per Johan:
     * Firm, Attorney, Contact, Email, Address. Find-or-create the FIRM (agency-scoped,
     * by name) then create a CONTACT person under it. Returns the firm + contact ids
     * the deal links, plus the display label. Soft-delete rules + agency scope apply.
     */
    public function attorneyInline(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $data = $request->validate([
            'firm'     => ['required', 'string', 'max:191'],
            'attorney' => ['nullable', 'string', 'max:191'],
            'contact'  => ['nullable', 'string', 'max:191'],
            'email'    => ['nullable', 'email', 'max:191'],
            'address'  => ['nullable', 'string', 'max:500'],
        ]);

        $agencyId = (int) ($request->user()->effectiveAgencyId() ?? 0);
        $userId = $request->user()->id;

        $firm = AgencyServiceProvider::query()
            ->where('name', $data['firm'])
            ->where('specialty', 'transfer_attorney')
            ->first();

        if (! $firm) {
            $firm = AgencyServiceProvider::create([
                'agency_id'     => $agencyId,
                'name'          => $data['firm'],
                'specialty'     => 'transfer_attorney',
                'address'       => $data['address'] ?? null,
                'is_active'     => true,
                'created_by_id' => $userId,
            ]);
        } elseif (! empty($data['address']) && empty($firm->address)) {
            $firm->update(['address' => $data['address']]);
        }

        $contact = AgencyServiceProviderContact::create([
            'agency_id'           => $agencyId,
            'service_provider_id' => $firm->id,
            'attorney_name'       => $data['attorney'] ?? null,
            'contact_person'      => $data['contact'] ?? null,
            'email'               => $data['email'] ?? null,
            'is_active'           => true,
            'created_by_id'       => $userId,
        ]);

        return response()->json([
            'provider_id' => $firm->id,
            'contact_id'  => $contact->id,
            'label'       => $this->attorneyLabel($firm->name, $contact->attorney_name, $contact->contact_person),
        ], 201);
    }

    private function attorneyLabel(?string $firm, ?string $attorney, ?string $contact): string
    {
        return trim(($firm ?? '')
            . ($attorney ? ' — ' . $attorney : '')
            . ($contact ? ' (via ' . $contact . ')' : ''));
    }
}
