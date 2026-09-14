<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealLog;
use App\Models\DealProperty;
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
use Illuminate\Validation\Rule;
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
        // withCount('pipelineSteps') powers the register's pipeline label: a deal has a "Pipeline"
        // once it has step instances — whether attached from a template OR composed from the Deal
        // Structure tab (composable deals carry no deal_pipeline_template_id, so template alone is
        // not a reliable signal). pipelineSteps is anchored via dr1_deal_id and excludes trashed.
        $query = Deal::query()->visibleTo($user)->with('agents')->withCount('pipelineSteps');

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

        $agents = User::where('is_assistant', false) // AT-267 / AUDIT 2026-07-26 (F4): an assistant is never a deal-side agent
            ->orderBy('name')->get();
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
        $dealAgencyId = (int) $deal->agency_id;
        if ($dealPeriod && preg_match('/^\d{4}-\d{2}$/', $dealPeriod)) {
            dispatch(function () use ($dealPeriod, $dealAgencyId) {
                (new RollupService())->refreshPeriod($dealPeriod, $dealAgencyId);
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

        $agents = User::where('is_assistant', false) // AT-267 / AUDIT 2026-07-26 (F4): an assistant is never a deal-side agent
            ->orderBy('name')->get();

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

        // AT-216 V1.1 — pipeline auto-attach at capture: offer the agency's active templates.
        // (Deal Type radio removed — no per-type default pre-selection; the Deal Structure tab
        // now drives composition, so the Pipeline select simply defaults to "None".)
        $templates     = \App\Models\DealV2\DealPipelineTemplate::where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')->get();

        return view('dr2.create', [
            'mode'               => 'create',
            'deal'               => $deal,
            'agents'             => $agents,
            'branches'           => $branches,
            'availableTemplates' => $templates,
            // AT-334 — no saved parties on a new deal; the picker seeds empty (create-path
            // auto-tokenizes the property's seller client-side once a property is picked).
            'sellerParties'      => [],
            'buyerParties'       => [],
        ]);
    }

    /** DR2 edit — same capture surface, hydrated from an existing `deals` row. */
    public function edit(Deal $deal): View
    {
        abort_unless(auth()->user()?->hasPermission('deals.edit'), 403);

        $agents   = User::where('is_assistant', false) // AT-267 / AUDIT 2026-07-26 (F4): an assistant is never a deal-side agent
            ->orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();

        return view('dr2.create', [
            'mode'          => 'edit',
            'deal'          => $deal,
            'agents'        => $agents,
            'branches'      => $branches,
            // AT-334 — seed the picker from the deal's CURRENT parties so an untouched edit
            // save re-posts the existing ids (syncDealParties no-op = parties preserved).
            // Without this the hidden ids start empty and the save DELETES deal_contacts rows.
            'sellerParties' => $this->dealPartyList($deal, 'seller'),
            'buyerParties'  => $this->dealPartyList($deal, 'buyer'),
        ]);
    }

    /**
     * AT-334 — the deal's currently-linked parties for a role, as [{id,name}], so the edit
     * form seeds its picker tokens + hidden `*_contact_ids` from deal_contacts (not just
     * old()). Prevents the silent party-wipe on an untouched edit save.
     *
     * @return array<int,array{id:int,name:string}>
     */
    private function dealPartyList(Deal $deal, string $role): array
    {
        return $deal->contacts()->wherePivot('role', $role)->get()
            ->map(fn ($c) => [
                'id'   => (int) $c->id,
                'name' => trim((string) ($c->full_name ?? ($c->first_name . ' ' . $c->last_name))) ?: ('Contact #' . $c->id),
            ])->values()->all();
    }

    /**
     * DR2 capture persist (create). DR1-parity numeric deal number + persistDeal,
     * writing the SAME `deals` / `deal_user` tables and firing the SAME downstream
     * services. Reproduces Admin\DealController::store() so the two writers agree.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create'), 403);

        // Create-time multi-property, Johan 2026-09-14/16 — his own finding:
        // the "Add another property" control only existed on the EDIT
        // screen, so a user building a two-property deal was told to save
        // first, then add — forcing a deal register to carry either
        // knowingly-wrong figures or a deliberately unbalanced intermediate
        // state, however briefly. His words: "2 properties sold together
        // makes up 1 selling price... that was never the spec." Validated
        // for shape and BALANCE here, before anything is persisted — pure
        // arithmetic needs no DB write to check, so an obviously-wrong
        // payload writes nothing at all, not even the deal itself. The
        // same-owner gate can only run once the primary property is
        // actually linked (assertCanAddToDeal() reads $deal->properties()),
        // so that check happens after persistDeal() below, still inside the
        // SAME transaction — any failure there rolls back everything
        // already written this request, deal number allocation included.
        // The deal is never created half-right.
        $additionalProperties = $this->validateAdditionalPropertiesPayload($request);

        try {
            return DB::transaction(function () use ($request, $additionalProperties) {
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

                    if ($additionalProperties !== null) {
                        $this->applyCreateTimeMultiProperty($deal, $additionalProperties);
                    }
                }

                return $resp;
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\App\Exceptions\Deal\PropertyOwnerMismatchException $e) {
            // Same exact shape as addProperty()'s own catch — the plain-
            // English refusal message travels unwrapped, never behind the
            // generic "Failed to save deal:" prefix below.
            return back()->withErrors(['property_id' => $e->getMessage()])->withInput();
        } catch (\Throwable $e) {
            \Log::error('DR2 store() failed', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'input' => $request->except(['_token']),
            ]);
            return back()->withErrors('Failed to save deal: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Create-time multi-property, Johan 2026-09-14/16. Validates the shape of
     * the client's staged `properties[]` array (see dr2/create.blade.php's
     * own create-mode JS) and enforces the balance rule server-side — never
     * trusting the browser's own live check, same posture as every other
     * gate on this feature. Returns null when this wasn't a genuine
     * multi-property submission at all (fewer than 2 entries — the client
     * only ever sends this array once a second property is staged), in
     * which case store() proceeds exactly as it always has.
     *
     * @return array<int, array{property_id:int, allocated_price:float, allocated_commission:float}>|null
     */
    private function validateAdditionalPropertiesPayload(Request $request): ?array
    {
        $raw = $request->input('properties');
        if (! is_array($raw) || count($raw) < 2) {
            return null;
        }

        $validated = $request->validate([
            'properties' => ['required', 'array', 'min:2'],
            'properties.*.property_id' => ['required', 'integer', 'exists:properties,id'],
            'properties.*.allocated_price' => ['required', 'numeric', 'min:0'],
            'properties.*.allocated_commission' => ['required', 'numeric', 'min:0'],
        ])['properties'];

        $ids = array_column($validated, 'property_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'properties' => 'The same property was submitted twice.',
            ]);
        }

        // Johan's own principle, this same week, on this same feature: "a
        // deal register must never carry figures that do not balance." The
        // TOTAL (property_value/total_commission — the BM's own entered
        // figure once 2+ properties exist, per his ruling: "bm or admin can
        // capture total and on properties... has to verify that the price
        // balances") must equal the sum of every property's own allocation,
        // primary included. Blocking, not warning — this is enforcement of
        // his own stated rule, not a new one.
        //
        // Price and commission are two INDEPENDENT captured figures, per
        // Johan's later ruling, verbatim: "we don't work with the R240000 at
        // all, we work with the R24000, that's the agency money" — neither
        // is derived from the other, and one balancing does not imply the
        // other does. Checked and reported as two SEPARATE errors, under
        // separate keys, never combined into one sentence — a deal that's
        // out on price but correct on commission (or vice versa) must read
        // as exactly that, both here and in the matching client-side check.
        $sumPrice = array_sum(array_column($validated, 'allocated_price'));
        $sumCommission = array_sum(array_column($validated, 'allocated_commission'));
        $totalPrice = (float) $request->input('property_value');
        $totalCommission = (float) $request->input('total_commission');

        $errors = [];
        if (abs($totalPrice - $sumPrice) >= 0.01) {
            $errors['property_value'] = sprintf(
                "The selling price (R %s) doesn't match the sum of the %d properties' own prices (R %s). Fix the figures before saving — a deal register must never carry numbers that don't balance.",
                number_format($totalPrice, 2), count($validated), number_format($sumPrice, 2)
            );
        }
        if (abs($totalCommission - $sumCommission) >= 0.01) {
            $errors['total_commission'] = sprintf(
                "The commission (R %s) doesn't match the sum of the %d properties' own commissions (R %s). Fix the figures before saving — a deal register must never carry numbers that don't balance.",
                number_format($totalCommission, 2), count($validated), number_format($sumCommission, 2)
            );
        }
        if ($errors) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        return $validated;
    }

    /**
     * Create-time multi-property, Johan 2026-09-14/16 — links every
     * additional property, corrects the primary's own allocation (the
     * automatic single-property mirror in Deal::booted() already ran by the
     * time this executes, guessing the primary's price from property_value/
     * total_commission — the TOTAL, for a multi-property submission, which
     * is wrong for the primary's own individual allocation), then
     * recalculates the deal's totals as the true sum. Runs inside the SAME
     * transaction store() already wraps everything in — a thrown
     * PropertyOwnerMismatchException here rolls back the whole request,
     * deal creation included, via that transaction's own exception
     * propagation. Never a second, looser gate for the create-time path —
     * the exact same DealPropertyOwnerGate/DealPropertyStatusService checks
     * addProperty() already runs for the edit-mode case.
     */
    private function applyCreateTimeMultiProperty(Deal $deal, array $properties): void
    {
        $gate = app(\App\Services\Deal\DealPropertyOwnerGate::class);

        foreach ($properties as $row) {
            $property = Property::findOrFail($row['property_id']);

            if ((int) $row['property_id'] === (int) $deal->property_id) {
                DealProperty::where('deal_id', $deal->id)->where('property_id', $property->id)->update([
                    'allocated_price' => $row['allocated_price'],
                    'allocated_commission' => $row['allocated_commission'],
                ]);
                continue;
            }

            $gate->assertCanAddToDeal($deal, $property);

            if (in_array($deal->accepted_status, ['G', 'R'], true)) {
                $conflict = app(\App\Services\Deal\DealPropertyStatusService::class)
                    ->committedDealOnProperty($property->id, $deal->id);
                if ($conflict !== null) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'property_id' => "Can't add {$property->address} — deal #{$conflict->deal_no} already carries a Granted or Registered status on it.",
                    ]);
                }
            }

            DealProperty::create([
                'deal_id' => $deal->id,
                'property_id' => $property->id,
                'is_primary' => false,
                'allocated_price' => $row['allocated_price'],
                'allocated_commission' => $row['allocated_commission'],
            ]);

            // Branch sharing (spec §6) — same co-share rule addProperty() already applies.
            if ($property->branch_id && $property->branch_id !== $deal->branch_id) {
                $deal->attachCoBranch($property->branch_id);
            }
        }

        app(\App\Services\Deal\DealPropertyPricingService::class)->recalculateTotals($deal->fresh());
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

        // SECURITY (Bug 3) — plain exists:agency_service_providers,id /
        // exists:agency_service_provider_contacts,id let a cross-agency provider
        // id be persisted here; Dr2DistributionComposer later resolves it with
        // withoutGlobalScopes() (on the assumption it was already agency-checked)
        // and uses its name/email/phone as an "attorney"/"bond originator"
        // recipient on the AT-228 party-send flow. Scope both to the acting
        // user's own agency.
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);

        $data = $request->validate([
            'period'           => ['required'],
            'deal_date'        => ['required', 'date'],
            // AT-334 P2 — deal_type is now OPTIONAL. The composable Deal Structure tab drives
            // the pipeline from suspensive conditions, so a capture no longer needs a type/
            // pipeline pick. Column is already nullable (most deals carry NULL); no migration.
            'deal_type'        => ['nullable', 'in:bond,cash,sale_of_2nd'],
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
            'attorney_provider_id' => ['nullable', 'integer', Rule::exists('agency_service_providers', 'id')->where('agency_id', $agencyId)],
            'attorney_contact_id'  => ['nullable', 'integer', Rule::exists('agency_service_provider_contacts', 'id')->where('agency_id', $agencyId)],
            'bond_originator_provider_id' => ['nullable', 'integer', Rule::exists('agency_service_providers', 'id')->where('agency_id', $agencyId)],
            'bond_originator_contact_id'  => ['nullable', 'integer', Rule::exists('agency_service_provider_contacts', 'id')->where('agency_id', $agencyId)],
            'accepted_status'  => ['nullable', 'string', 'max:1'],
            'commission_status' => ['nullable', 'string', 'max:50'],
            'registration_date' => ['nullable', 'date'],
            'remarks'          => ['nullable', 'string'],

            'listing_external'        => ['nullable'],
            'listing_our_share_percent' => ['nullable', 'numeric'],
            'listing_external_agency' => ['nullable', 'string', 'max:255'],
            // Per-side external agency = firm + contact (same searchable-supplier picker
            // as attorney / bond-originator). The name column above is the display label.
            'listing_external_agency_provider_id' => ['nullable', 'integer', 'exists:agency_service_providers,id'],
            'listing_external_agency_contact_id'  => ['nullable', 'integer', 'exists:agency_service_provider_contacts,id'],

            'selling_external'        => ['nullable'],
            'selling_our_share_percent' => ['nullable', 'numeric'],
            'selling_external_agency' => ['nullable', 'string', 'max:255'],
            'selling_external_agency_provider_id' => ['nullable', 'integer', 'exists:agency_service_providers,id'],
            'selling_external_agency_contact_id'  => ['nullable', 'integer', 'exists:agency_service_provider_contacts,id'],

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

        // STANDARDS Rule 17 — a NEW deal must belong to a real agency. An unscoped
        // owner/super_admin (no branch, no active agency switcher) resolves
        // effectiveAgencyId() to NULL; BelongsToAgency's single-agency fallback is a
        // no-op on any multi-agency install, so the deal would silently save with
        // agency_id=NULL, and pipeline-building code downstream that casts it to
        // (int) then manufactures the invalid sentinel 0 and 1452s the
        // deal_step_instances FK (QA1 deal 218). Block here with a clear message —
        // never invent, hardcode, or silently omit the agency.
        $effectiveAgencyId = $user?->effectiveAgencyId();
        if ($isNew && ! $effectiveAgencyId) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'agency_id' => 'Select an agency before creating a deal — switch into an agency, then try again.',
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

        // A filled external-agency NAME is the authoritative signal that this side
        // was handled externally — treat the side as external even if the checkbox
        // was not submitted (e.g. JS disabled, or the box was left unticked). This
        // keeps the stored checkbox consistent with the name and stops the
        // "requires at least one agent" guard below from demanding internal-agent
        // fields for a side that is plainly external.
        foreach (['listing', 'selling'] as $side) {
            if (trim((string) ($data[$side . '_external_agency'] ?? '')) !== '') {
                $data[$side . '_external'] = true;
            }
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

        // AT-398 — Johan: "there cannot be a deal without an owner." Whenever a
        // property IS being linked (this field is nullable — a name-only deal
        // with no property at all is unaffected, that's a different, pre-existing
        // DR1-parity capability), that property must have a resolvable seller-
        // side contact. A refusal here, not a silent empty owner list to design
        // around later.
        if ($propertyId) {
            $linkCandidate = Property::find($propertyId);
            if ($linkCandidate) {
                try {
                    app(\App\Services\Deal\DealPropertyOwnerGate::class)->assertHasKnownOwner($linkCandidate);
                } catch (\App\Exceptions\Deal\PropertyOwnerMismatchException $e) {
                    return back()->withErrors(['property_id' => $e->getMessage()])->withInput();
                }
            }
        }

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
            'deal_type'        => $data['deal_type'] ?? null,
            'property_value'   => $data['property_value'],
            'total_commission' => $data['total_commission'],

            'listing_split_percent' => $listingSplit,
            'selling_split_percent' => $sellingSplit,
            'file_no'          => $data['file_no'] ?? null,
            'branch_id'        => $data['branch_id'] ?? null,
            // Explicitly stamped (not left to BelongsToAgency's auto-stamp) so a NEW
            // deal never depends on the owner-bypass fallback gap — see the guard above.
            'agency_id'        => $isNew ? $effectiveAgencyId : $deal->agency_id,

            'property_id'      => $propertyId,
            'property_address' => $data['property_address'] ?? null,

            'seller_name'      => $data['seller_name'] ?? null,
            'buyer_name'       => $data['buyer_name'] ?? null,
            'attorney_name'    => $data['attorney_name'] ?? null,
            'attorney_provider_id' => ! empty($data['attorney_provider_id']) ? (int) $data['attorney_provider_id'] : null,
            'attorney_contact_id'  => ! empty($data['attorney_contact_id']) ? (int) $data['attorney_contact_id'] : null,
            'bond_originator_provider_id' => ! empty($data['bond_originator_provider_id']) ? (int) $data['bond_originator_provider_id'] : null,
            'bond_originator_contact_id'  => ! empty($data['bond_originator_contact_id']) ? (int) $data['bond_originator_contact_id'] : null,
            'accepted_status'  => $data['accepted_status'] ?? null,
            'commission_status' => $data['commission_status'] ?? null,
            'registration_date' => $data['registration_date'] ?? null,
            'remarks'          => $data['remarks'] ?? null,

            'listing_external' => !empty($data['listing_external']),
            'listing_our_share_percent' => $data['listing_our_share_percent'] ?? 100,
            'listing_external_agency' => $data['listing_external_agency'] ?? null,
            'listing_external_agency_provider_id' => ! empty($data['listing_external_agency_provider_id']) ? (int) $data['listing_external_agency_provider_id'] : null,
            'listing_external_agency_contact_id'  => ! empty($data['listing_external_agency_contact_id']) ? (int) $data['listing_external_agency_contact_id'] : null,

            'selling_external' => !empty($data['selling_external']),
            'selling_our_share_percent' => $data['selling_our_share_percent'] ?? 100,
            'selling_external_agency' => $data['selling_external_agency'] ?? null,
            'selling_external_agency_provider_id' => ! empty($data['selling_external_agency_provider_id']) ? (int) $data['selling_external_agency_provider_id'] : null,
            'selling_external_agency_contact_id'  => ! empty($data['selling_external_agency_contact_id']) ? (int) $data['selling_external_agency_contact_id'] : null,
        ]);

        // Stamp link provenance only when a property is picked; never clobber a
        // pre-existing auto-match link with NULLs when the user leaves it blank.
        if ($propertyId) {
            $deal->link_source     = 'manual';
            $deal->link_confidence = 'exact';
        }

        $deal->save();

        $sellerIds = $this->parseIdCsv($data['seller_contact_ids'] ?? null);
        $buyerIds  = $this->parseIdCsv($data['buyer_contact_ids'] ?? null);

        // (AT-243) Record the parties ON THE DEAL. This is the half that was missing: the
        // ids below were previously used only to link people to the PROPERTY and were then
        // discarded, so a property with several offers could not say which buyer belonged
        // to which deal — and therefore could not say who actually bought when one was
        // granted. The deal register owns the transaction, so it owns its parties.
        $this->syncDealParties($deal, $sellerIds, 'seller');
        $this->syncDealParties($deal, $buyerIds, 'buyer');

        // (DR2 reverse link — property-spine doctrine) Deal capture is often the
        // moment a buyer/seller enters the story. Linking a party on the deal
        // MUST also link them to the PROPERTY with the correct role, so the
        // property knows its buyer/seller too. One action, both records —
        // idempotent, audit-logged, never re-roles an existing link.
        if ($propertyId) {
            $linkProperty = Property::find($propertyId);
            if ($linkProperty) {
                $this->syncPartyLinks($linkProperty, $sellerIds, 'seller', (int) $deal->id);
                $this->syncPartyLinks($linkProperty, $buyerIds, 'buyer', (int) $deal->id);
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

        // AT-245 — mint the DR2 twin now that the listing agent is on deal_user.
        // Without this, a newly-captured deal has no twin and is invisible to
        // distribution ("no DR2 record") — the overnight backfill was one-time.
        try {
            app(\App\Services\DealV2\DealSyncService::class)->ensureTwin($deal);
        } catch (\Throwable $e) {
            \Log::error('DR2 ensureTwin on capture failed', ['deal_id' => $deal->id, 'error' => $e->getMessage()]);
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
                (new RollupService())->refreshPeriod($dealPeriod, (int) $deal->agency_id);
            } catch (\Throwable $e) {
                \Log::error('DR2 RollupService failed', ['deal_id' => $deal->id, 'period' => $dealPeriod, 'error' => $e->getMessage()]);
            }
        }

        // Wave 2 auto-decline-on-capture notice — the DealCreated listener
        // (AutoDeclineNewDealOnCommittedProperty) declines a NEW pending offer
        // captured against a property that already carries a granted/registered
        // deal. Surface Johan's clickable notice: the capture SUCCEEDED, but it
        // landed Declined, with the blocking deal #XXXX linked (new tab).
        if ($isNew && $intendedAccepted === 'P') {
            if ($committed = app(\App\Services\Deal\DealPropertyStatusService::class)->committedDealOnProperty($propertyId, (int) $deal->id)) {
                return redirect()->route('deals-dr2.index')
                    ->with('status', "Deal {$deal->deal_no} captured — auto-declined (property already committed).")
                    ->with('capture_declined', $this->grantConflictPayload($committed));
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
     * "Add another property" eligibility, Johan 2026-09-16 — his own
     * finding, live: "add another property should only display the other
     * properties on this seller. why offer a search, it can be a plain
     * dropdown." Refined by him one step further before any code was
     * written: "properties on this seller" and "properties this deal will
     * accept" are not the same set — DealPropertyOwnerGate compares exact
     * OWNER SETS, not a single seller, so a seller who owns one property
     * solely and another jointly has two DIFFERENT owner sets, and a
     * dropdown scoped to "linked to this seller" would still offer
     * something the gate then refuses — the exact defect in a new shape.
     * Reuses DealPropertyOwnerGate's own sellerSideContactIds()/
     * ownerSetsMatch() verbatim, never a second, looser comparison
     * invented for this endpoint.
     *
     * Johan's follow-up ruling, 2026-09-16, after being told how many real
     * QA1 properties fall in exactly that gap (31 — sized on real data
     * BEFORE this was built, per his own instruction not to decide it
     * silently): "those properties must NOT be silently absent. An agent
     * who knows their seller owns three houses, opens the dropdown and
     * sees two, will conclude the system lost one." So a candidate sharing
     * a seller but failing the owner-set match is still RETURNED, marked
     * `eligible: false` with a plain-language `reason` — never the gate's
     * own vocabulary — rather than dropped. G/R exclusivity remains a hard
     * exclusion (see below) — a genuinely separate, still-open question,
     * not decided the same way here.
     *
     * ONE shared endpoint for create AND edit (Johan: "same behaviour on
     * create and on edit. One implementation, not two.") — the reference
     * property is passed explicitly rather than resolved from a Deal,
     * because create mode has no Deal yet; edit mode passes its own
     * primary property_id the same way.
     */
    public function eligibleProperties(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $reference = Property::find((int) $request->input('reference_property_id'));
        if (! $reference) {
            return response()->json(['properties' => []]);
        }

        $gate = app(\App\Services\Deal\DealPropertyOwnerGate::class);
        $referenceOwnerIds = $gate->sellerSideContactIds($reference);
        if (empty($referenceOwnerIds)) {
            // No resolvable owner on the reference at all — nothing can
            // ever match (assertHasKnownOwner()'s own precondition would
            // refuse any candidate regardless of set comparison).
            return response()->json(['properties' => []]);
        }

        $excludeIds = array_values(array_filter(array_map('intval', (array) $request->input('exclude', []))));
        $excludeIds[] = $reference->id;

        $showAll = $request->boolean('all');

        // Cheap, necessary pre-filter: any candidate whose owner set
        // exactly matches the reference's must share at least one contact
        // with it. Narrows a whole-agency scan down to a small pool before
        // the real (exact-set) comparison runs.
        $candidateIds = DB::table('contact_property')
            ->whereIn('contact_id', $referenceOwnerIds)
            ->whereIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->whereNull('deleted_at')
            ->whereNotIn('property_id', $excludeIds)
            ->distinct()
            ->pluck('property_id');

        if ($candidateIds->isEmpty()) {
            return response()->json(['properties' => []]);
        }

        $candidates = Property::query()
            ->visibleTo($request->user())
            ->whereIn('id', $candidateIds)
            ->when(! $showAll, fn ($q) => $q->onMarket())
            ->with('agent')
            ->get();

        // Same G/R exclusivity check addProperty() already runs (only ever
        // relevant when the deal itself is already Granted/Registered) —
        // reused verbatim, never a second set of status rules for this
        // dropdown. $dealId is null on create (no Deal exists yet, so
        // nothing to exclude the candidate FROM). Kept as a hard exclusion
        // (never shown, not even disabled) — Johan's ruling on the
        // owner-set gap below does not extend here automatically; whether
        // this deserves the same disabled-with-reason treatment is a
        // separate, explicitly open question (see the conductor's own
        // brief and this endpoint's class-level docblock).
        $acceptedStatus = (string) $request->input('accepted_status', 'P');
        $dealId = $request->filled('deal_id') ? (int) $request->input('deal_id') : null;
        $statusService = in_array($acceptedStatus, ['G', 'R'], true)
            ? app(\App\Services\Deal\DealPropertyStatusService::class)
            : null;
        $candidates = $candidates
            ->filter(fn (Property $p) => $statusService === null || $statusService->committedDealOnProperty($p->id, $dealId) === null)
            ->values();

        // Johan's ruling, 2026-09-16: "31 IS MEANINGFUL... those properties
        // must NOT be silently absent. An agent who knows their seller
        // owns three houses, opens the dropdown and sees two, will
        // conclude the system lost one." So an owner-set MISMATCH is no
        // longer filtered out — it's returned, marked ineligible, with a
        // plain-language reason (never the gate's own vocabulary: "owner
        // set" means nothing to a working agent). Sorted eligible-first so
        // the real choices are never buried under the ones that can't be
        // picked (his own explicit instruction).
        $eligible = [];
        $ineligible = [];
        foreach ($candidates as $p) {
            if ($gate->ownerSetsMatch($reference, $p)) {
                $eligible[] = $p;
            } else {
                $ineligible[] = $p;
            }
        }

        $toRow = fn (Property $p, bool $isEligible) => $p->toSearchResult([
            // Enough to tell two of the same seller's properties apart
            // without a search (Johan's own requirement) — same fields
            // searchProperties() already surfaces for this reason.
            'ref' => $p->property_number,
            'price' => $p->listing_price ?? $p->price ?? null,
            'eligible' => $isEligible,
            'reason' => $isEligible ? null : "Can't be added — the owners on this property aren't the same as the owners on this deal.",
        ]);

        return response()->json([
            'properties' => collect($eligible)->map(fn (Property $p) => $toRow($p, true))
                ->concat(collect($ineligible)->map(fn (Property $p) => $toRow($p, false)))
                ->values(),
        ]);
    }

    /**
     * §2.3 — seller / buyer offered from the linked property. Returns the property's
     * contacts split by role so the capture screen can auto-fill the seller and
     * present a tick-list of linked buyers. Agency scope is structural (global scope).
     *
     * The role split is LISTING-TYPE-AWARE (AT-262/DR2): a rental property offers its
     * landlord/lessor as the seller-side party and its tenant/lessee as the buyer-side,
     * a sale offers seller/owner and buyer — so a genuine rental deal's landlord pulls
     * through instead of silently vanishing. Sets come from the canonical maps on
     * Property, not a literal here.
     */
    public function propertyContacts(Property $property): JsonResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        // Listing-type-aware (AT-262/DR2): a RENTAL property's seller-side party is the
        // landlord/lessor, its buyer-side the tenant/lessee; a SALE keeps seller/owner and
        // buyer. The fixed ['seller','owner'] set was blind to a rental's landlord, so a
        // genuine rental deal could never pull its seller through. Canon lives on Property.
        $sellerRoles = Property::sellerSidePivotRolesForListingType($property->listing_type);
        $buyerRoles  = Property::buyerSidePivotRolesForListingType($property->listing_type);

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
    /**
     * AT-243 — persist the deal's party list for one role (buyer | seller).
     *
     * Authoritative for THIS role on THIS deal: parties removed on an edit are detached, so
     * correcting a mis-captured buyer actually corrects it (a purchaser badge derived from a
     * stale party would be worse than none). Other roles on the deal are left alone, so
     * syncing buyers never disturbs sellers.
     *
     * Empty input is a legitimate path (a deal captured without naming contacts — the lazy-
     * but-valid shortcut) and simply clears that role. It is never an error.
     */
    private function syncDealParties(Deal $deal, array $contactIds, string $role): void
    {
        $ids = array_values(array_unique(array_map('intval', $contactIds)));

        // Only contacts that actually exist — a stale id from a stale form must not
        // explode the capture, and must not create a dangling party row.
        $valid = $ids ? Contact::whereIn('id', $ids)->pluck('id')->all() : [];

        $existing = DB::table('deal_contacts')
            ->where('deal_id', $deal->id)->where('role', $role)
            ->pluck('contact_id')->map(fn ($id) => (int) $id)->all();

        $toAdd    = array_diff($valid, $existing);
        $toRemove = array_diff($existing, $valid);

        if ($toRemove) {
            DB::table('deal_contacts')
                ->where('deal_id', $deal->id)->where('role', $role)
                ->whereIn('contact_id', $toRemove)
                ->delete();
        }

        foreach ($toAdd as $cid) {
            // Idempotent: the unique (deal, contact, role) index makes a double-submit a no-op.
            DB::table('deal_contacts')->insertOrIgnore([
                'deal_id'    => $deal->id,
                'contact_id' => $cid,
                'role'       => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function syncPartyLinks(Property $property, array $contactIds, string $role, ?int $excludingDealId = null): void
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
            // AT-398 — the owner set behind an open deal cannot move
            // underneath it. Excludes THIS deal's own lock: syncing this
            // deal's own seller onto its own property is the lock's purpose,
            // not a violation of it — a genuinely different seller arriving
            // here while another deal is open is exactly what must be caught.
            app(\App\Services\Property\PropertyOwnershipGuard::class)
                ->assertCanLink($property, $role, $excludingDealId);
            // ContactPropertyLinker, not a bare attach() — the exists()
            // check above already preserves this method's own "no silent
            // re-roling" rule for an ACTIVE link (we never reach this line
            // for one); what a plain attach() would still get wrong is a
            // TRASHED row for this exact pair, which a blind insert would
            // collide with. The linker restores it instead. See .ai/specs/
            // rental-applications.md, "The contact_property hard-delete fix".
            \App\Services\Property\ContactPropertyLinker::link($cid, $property->id, $role);
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

        // AT-228 — the same picker serves the transferring attorney and the bond originator.
        $specialty = in_array($request->input('specialty'), ['transfer_attorney', 'bond_originator', 'external_agency', 'bond_attorney'], true)
            ? $request->input('specialty') : 'transfer_attorney';

        $firms = AgencyServiceProvider::query()
            ->where('is_active', true)
            ->capableOf($specialty) // AT-364 — bond/transfer surface capability-flagged firms too (BBB does both)
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

        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        $userId = $request->user()->id;

        // AT-228 — same inline-create serves attorney + bond originator (specialty from the picker).
        $specialty = in_array($request->input('specialty'), ['transfer_attorney', 'bond_originator', 'external_agency', 'bond_attorney'], true)
            ? $request->input('specialty') : 'transfer_attorney';

        // AT-364 — for an attorney specialty, reuse ANY same-named attorney firm (so adding BBB as a
        // bond attorney reuses the existing BBB transfer firm instead of duplicating it) and set the
        // requested capability. Non-attorney specialties (bond originator / external agency) keep the
        // exact (name, specialty) find they always used.
        $capCol = AgencyServiceProvider::ATTORNEY_CAPABILITY_COLUMNS[$specialty] ?? null;

        $firm = $capCol
            ? AgencyServiceProvider::query()->where('name', $data['firm'])->anyAttorney()->first()
            : AgencyServiceProvider::query()->where('name', $data['firm'])->where('specialty', $specialty)->first();

        if (! $firm) {
            $firm = AgencyServiceProvider::create([
                'agency_id'            => $agencyId,
                'name'                 => $data['firm'],
                'specialty'            => $specialty,
                'is_transfer_attorney' => $specialty === 'transfer_attorney',
                'is_bond_attorney'     => $specialty === 'bond_attorney',
                'address'              => $data['address'] ?? null,
                'is_active'            => true,
                'created_by_id'        => $userId,
            ]);
        } else {
            $updates = [];
            if (! empty($data['address']) && empty($firm->address)) {
                $updates['address'] = $data['address'];
            }
            if ($capCol && ! $firm->{$capCol}) {
                $updates[$capCol] = true; // ensure the reused firm gains the requested attorney capability
            }
            if ($updates) {
                $firm->update($updates);
            }
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

    // ── AT-398 — multi-property ──────────────────────────────────────────────

    /**
     * Add a property to a deal. Johan's strict rule: the property's owners
     * must be EXACTLY the same set as the deal's existing properties — every
     * seller on the deal must be able to sign for every property on it.
     * Refused (never silently dropped) with a plain-English reason when the
     * owners don't match, the owners aren't known yet, or the property is
     * already committed elsewhere and this deal is already Granted/Registered.
     */
    public function addProperty(Request $request, Deal $deal): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        // AT-398 split-pricing: the FIRST property on a deal inherits its price
        // from the deal's own (already-required) property_value/total_commission
        // fields — see Deal::syncPrimaryPropertyPivot(). Every property after
        // that needs its OWN price entered here; Johan's ruling (via
        // AskUserQuestion): the existing price is never redistributed, and the
        // deal total is always the sum of every property's own price — never a
        // separately-typed total that could disagree with the parts.
        $isFirst = $deal->properties()->count() === 0;
        $data = $request->validate([
            'property_id' => ['required', 'integer', 'exists:properties,id'],
            'allocated_price' => [$isFirst ? 'nullable' : 'required', 'numeric', 'min:0'],
            'allocated_commission' => [$isFirst ? 'nullable' : 'required', 'numeric', 'min:0'],
        ]);
        $property = Property::findOrFail($data['property_id']);

        try {
            app(\App\Services\Deal\DealPropertyOwnerGate::class)->assertCanAddToDeal($deal, $property);
        } catch (\App\Exceptions\Deal\PropertyOwnerMismatchException $e) {
            return back()->withErrors(['property_id' => $e->getMessage()]);
        }

        // A deal already Granted/Registered may only gain a property that is
        // not itself already committed elsewhere — the same exclusivity rule
        // a fresh grant would be checked against, applied at add-time because
        // this deal will not pass through a fresh grant again.
        if (in_array($deal->accepted_status, ['G', 'R'], true)) {
            $conflict = app(\App\Services\Deal\DealPropertyStatusService::class)
                ->committedDealOnProperty($property->id, $deal->id);
            if ($conflict !== null) {
                return back()->withErrors([
                    'property_id' => "Can't add {$property->address} — deal #{$conflict->deal_no} already carries a Granted or Registered status on it.",
                ]);
            }
        }

        DB::transaction(function () use ($deal, $property, $isFirst, $data) {
            $row = DealProperty::withTrashed()->where('deal_id', $deal->id)->where('property_id', $property->id)->first();
            if ($row) {
                if ($row->trashed()) {
                    $row->restore();
                }
                if (! $isFirst) {
                    $row->update(['allocated_price' => $data['allocated_price'], 'allocated_commission' => $data['allocated_commission']]);
                }
            } else {
                DealProperty::create(['deal_id' => $deal->id, 'property_id' => $property->id, 'is_primary' => $isFirst]);
                if ($isFirst) {
                    // saveQuietly() bypasses Deal::booted()'s updated hook (by
                    // design — see that hook's own docblock), so the price
                    // mirror it would normally do never fires here. Mirror it
                    // explicitly onto the row just created instead.
                    $deal->forceFill(['property_id' => $property->id])->saveQuietly();
                    DealProperty::where('deal_id', $deal->id)->where('property_id', $property->id)->update([
                        'allocated_price' => $deal->property_value,
                        'allocated_commission' => $deal->total_commission,
                    ]);
                } else {
                    DealProperty::where('deal_id', $deal->id)->where('property_id', $property->id)->update([
                        'allocated_price' => $data['allocated_price'],
                        'allocated_commission' => $data['allocated_commission'],
                    ]);
                }
            }

            // Johan (Q3, answered): share the deal across both branches when a
            // linked property belongs to a different one — reuses the existing
            // co-branch pivot (Deal::attachCoBranch()), never invents a new one.
            if ($property->branch_id && (int) $property->branch_id !== (int) $deal->branch_id) {
                $deal->attachCoBranch((int) $property->branch_id);
            }

            $this->logDealEvent($deal, 'property_added', null, null, "Property added: {$property->address}");
        });

        // Split-pricing: deal totals are always the SUM of every linked
        // property's own allocation — never a separately-entered figure that
        // could disagree with the parts (Johan's ruling). No-op while the
        // deal has 0-1 properties (nothing to sum beyond what's already there).
        app(\App\Services\Deal\DealPropertyPricingService::class)->recalculateTotals($deal);

        // Bring the newly added property into sync with the deal's CURRENT
        // status — re-fires the same, already-tested Wave 2 listeners rather
        // than duplicating their logic. Idempotent: a property already in the
        // right state is left alone; the deal's OTHER properties are untouched
        // (each listener acts on its own linked properties independently).
        $fresh = $deal->fresh();
        if (in_array($fresh->accepted_status, ['P', 'G'], true)) {
            event(new \App\Events\Deal\DealCreated($fresh, auth()->id()));
        }
        if (in_array($fresh->accepted_status, ['G', 'R'], true)) {
            event(new \App\Events\Deal\DealStageAdvanced($fresh, $fresh->accepted_status, $fresh->accepted_status, auth()->id()));
        }

        return back()->with('success', "{$property->address} added to this deal.");
    }

    /**
     * Remove a property from a deal. SOFT removal only (deal_properties.deleted_at)
     * — Johan: keep a note it was once there, never let it just disappear. The
     * primary property may not be removed directly here — reassign a different
     * property as primary first (editing property_id already does this via
     * Deal's own syncPrimaryPropertyPivot()).
     */
    public function removeProperty(Request $request, Deal $deal, Property $property): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $row = $deal->properties()->where('properties.id', $property->id)->first();
        if (! $row) {
            return back()->withErrors(['property_id' => 'That property is not on this deal.']);
        }
        if ((bool) $row->pivot->is_primary) {
            return back()->withErrors(['property_id' => 'This is the primary property on the deal — pick a different property as primary before removing this one.']);
        }

        DealProperty::where('id', $row->pivot->id)->delete(); // soft
        $this->logDealEvent($deal, 'property_removed', null, null, "Property removed: {$property->address}");

        // Split-pricing: dropping a property's allocation out of the sum.
        app(\App\Services\Deal\DealPropertyPricingService::class)->recalculateTotals($deal);

        return back()->with('success', "{$property->address} removed from this deal.");
    }

    /**
     * AT-398 — restore a soft-removed property link. A distinct action from
     * addProperty() (rather than "search and re-add") so the Archived/Restore
     * UI (BUILD_STANDARD full-CRUD floor) has a direct, one-click affordance —
     * mirrors dr2/_removed-steps.blade.php's restore pattern for pipeline steps.
     */
    public function restoreProperty(Request $request, Deal $deal, Property $property): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        $row = DealProperty::onlyTrashed()->where('deal_id', $deal->id)->where('property_id', $property->id)->first();
        if (! $row) {
            return back()->withErrors(['property_id' => 'That property is not in this deal\'s removed list.']);
        }

        try {
            app(\App\Services\Deal\DealPropertyOwnerGate::class)->assertCanAddToDeal($deal, $property);
        } catch (\App\Exceptions\Deal\PropertyOwnerMismatchException $e) {
            return back()->withErrors(['property_id' => $e->getMessage()]);
        }

        $row->restore();
        $this->logDealEvent($deal, 'property_restored', null, null, "Property restored: {$property->address}");
        app(\App\Services\Deal\DealPropertyPricingService::class)->recalculateTotals($deal);

        return back()->with('success', "{$property->address} restored to this deal.");
    }

    /**
     * AT-398 split-pricing — edit an already-linked property's own price.
     * Only meaningful once a deal has 2+ properties (below that, the deal's
     * own property_value/total_commission fields ARE the property's price —
     * see Deal::syncPrimaryPropertyPivot()); this endpoint refuses otherwise
     * so there is never a second place editing the same single figure.
     */
    public function updatePropertyPrice(Request $request, Deal $deal, Property $property): RedirectResponse
    {
        abort_unless(auth()->user()?->hasPermission('deals.create') || auth()->user()?->hasPermission('deals.edit'), 403);

        if ($deal->properties()->count() < 2) {
            return back()->withErrors(['allocated_price' => 'This deal has only one property — edit its price on the main deal form above.']);
        }

        $row = DealProperty::where('deal_id', $deal->id)->where('property_id', $property->id)->whereNull('deleted_at')->first();
        if (! $row) {
            return back()->withErrors(['allocated_price' => 'That property is not on this deal.']);
        }

        $data = $request->validate([
            'allocated_price' => ['required', 'numeric', 'min:0'],
            'allocated_commission' => ['required', 'numeric', 'min:0'],
        ]);

        $row->update($data);
        $this->logDealEvent($deal, 'property_price_updated', null, null, "Price updated for {$property->address}");
        app(\App\Services\Deal\DealPropertyPricingService::class)->recalculateTotals($deal);

        return back()->with('success', "Price updated for {$property->address}.");
    }
}
