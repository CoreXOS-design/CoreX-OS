<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepComment;
use App\Models\DealV2\DealStepInstance;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-216 (DR2 · WS-PIPELINE) — the pipeline experience on the DR2 register.
 *
 * PURE TRACKING OVERLAY (Johan-locked, option (a)): viewing/attaching a pipeline and
 * completing its steps NEVER changes the DR1 deal's state — only the pipeline's own step
 * rows and the deal's pipeline pointer. DR1 keeps modelling its lifecycle through
 * settlements. Kept separate from Dr2\DealRegisterController so it never collides with
 * AT-217's capture edits.
 */
class PipelineController extends Controller
{
    public function __construct(
        private readonly Dr1PipelineService $pipelines,
        private readonly \App\Services\Deal\DealPipelineLockService $lock,
    ) {
    }

    /**
     * Pipeline Dashboard Phase 2 — after an action, return to whichever pipeline view the agent acted
     * from. Board is the default; `?from=timeline` (posted by the timeline's tile actions) returns to
     * the timeline so an action taken there doesn't bounce the agent to the board.
     */
    private function pipelineRedirect(Deal $deal): RedirectResponse
    {
        $route = match (request('from')) {
            'timeline' => 'deals-dr2.pipeline.timeline',
            'list'     => 'deals-dr2.pipeline.list',
            default    => 'deals-dr2.pipeline',
        };
        return redirect()->route($route, $deal);
    }

    /**
     * Pipeline Dashboard Phase 4 — land the agent on their remembered view (board | timeline | list).
     * A neutral entry the deal's "Pipeline" link points at; each view also remembers itself on visit.
     */
    public function viewDefault(Deal $deal): RedirectResponse
    {
        // The pipeline surface is Timeline + List only (the board view is retired, Johan 2026-07-27).
        $view  = auth()->id() ? \App\Models\DealV2\PipelineUserPreference::viewForUser(auth()->id()) : 'timeline';
        $route = $view === 'list' ? 'deals-dr2.pipeline.list' : 'deals-dr2.pipeline.timeline';
        return redirect()->route($route, $deal);
    }

    /**
     * The classic board is RETIRED — the pipeline surface is Timeline + List only. Every old entry to
     * `deals-dr2.pipeline` (register link, step-action redirects, other blades) now lands on the agent's
     * pipeline view instead of the old board screen. Kept as a redirect so nothing 404s.
     */
    public function show(Deal $deal): RedirectResponse
    {
        return $this->viewDefault($deal);
    }

    /** @deprecated retired board render — kept only so historical references don't fatal. */
    private function legacyBoard(Deal $deal): View
    {
        $deal->load(['pipelineSteps.comments.user']);

        $steps = $deal->pipelineSteps->map(function (DealStepInstance $s) {
            $terminal = in_array($s->status, ['completed', 'skipped'], true);
            $rag = $terminal ? 'grey' : $this->pipelines->calculateRag($s); // live, not the stored snapshot
            return [
                'model'   => $s,
                'rag'     => $rag,
                'colour'  => Dr1PipelineService::ragColour($rag),
                'blocked' => $s->blockedByLabel(),
                'na'      => $s->status === 'skipped' && ! empty($s->na_reason),
            ];
        });

        // Templates are only offered when nothing is attached yet (single pipeline per deal).
        // The default pre-selection follows the deal's deal_type (m3's capture writes it):
        // deal_type → the agency's is_default template of that type — agency-configurable,
        // and the user can still change it in the attach form.
        $templates        = $deal->deal_pipeline_template_id ? collect() : $this->activeTemplates($deal);
        $defaultTemplateId = $deal->deal_pipeline_template_id ? null : optional($this->defaultTemplateFor($deal, $templates))->id;

        // R2 — soft-deleted steps, so they can be restored (nobody strands a pipeline).
        $removedSteps = DealStepInstance::onlyTrashed()
            ->where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')
            ->get();

        // AT-244 — a not-proceeding (Declined) deal renders its pipeline READ-ONLY: it stays
        // visible as history, but every action is withdrawn. The lock is stated, never silent,
        // and it carries its own way out (STANDARDS — "No Silent Locks").
        $locked      = $this->lock->isLocked($deal);
        $lockReason  = $locked ? $this->lock->reason($deal) : null;
        $unlockHint  = $locked ? $this->lock->unlockHint() : null;

        // AT-334 — Deal Structure tab: the composable-condition picker.
        $conditionCatalog = app(\App\Services\DealV2\Dr2ConditionCatalog::class)->conditions();
        $dealConditions   = \App\Models\DealV2\DealCondition::where('deal_id', $deal->id)->get()->keyBy('key');
        $hasPipeline      = $steps->isNotEmpty();
        // AT-334 Phase 5 — the per-step "Follows + offset" control only applies to
        // composable (new-model) deals; old-model/template deals never show it.
        $isNewModel       = app(\App\Services\DealV2\DealDateCascade::class)->isNewModel($deal);

        // AT-334 (concurrent-lanes rework) — new-model deals render as a CLEAN CONCURRENT-LANE
        // board: Anchor → Stage 1 (condition lanes) → Granted gate → Stage 2 (sequence points +
        // concurrent bands). Convergence is field-driven off the predecessor SET (primary
        // follows ∪ AND-gate fan-in). Old-model deals keep the flat render.
        $board = ($isNewModel && $steps->isNotEmpty())
            ? app(\App\Services\DealV2\DealLaneComposer::class)->board($deal->pipelineSteps)
            : null;

        // AT-334 P3 — work orders held at the trigger for want of a supplier. Drives the RED
        // warnings: the Supplier Work Orders tab turns red, and each held WO's own step row
        // turns red with "no supplier has been set".
        $awaitingWos      = \App\Models\DealV2\DealStepWorkOrder::where('dr1_deal_id', $deal->id)
            ->where('status', 'awaiting_supplier')->get();
        $woNeedsAttention = $awaitingWos->isNotEmpty();
        $awaitingStepIds  = $awaitingWos->pluck('deal_step_instance_id')
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return view('dr2.pipeline', compact(
            'deal', 'steps', 'templates', 'defaultTemplateId', 'removedSteps',
            'locked', 'lockReason', 'unlockHint',
            'conditionCatalog', 'dealConditions', 'hasPipeline', 'isNewModel', 'board',
            'woNeedsAttention', 'awaitingStepIds',
        ));
    }

    /**
     * AT-334 — build (or, later, restructure) a deal's pipeline from the chosen
     * suspensive conditions. New-model path; the assembler refuses if a pipeline
     * already exists (Restructure is a later phase).
     */
    public function saveStructure(Deal $deal, Request $request, \App\Services\DealV2\DealStructureAssembler $assembler): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('create_deals'), 403);
        if ($this->lock->isLocked($deal)) {
            return back()->with('error', 'This pipeline is locked and cannot be structured.');
        }

        $catalog    = app(\App\Services\DealV2\Dr2ConditionCatalog::class);
        $in         = (array) $request->input('conditions', []);

        // A captured date → 'Y-m-d' or null (blank/invalid falls back to the offset chain downstream).
        $cleanDate = function ($v): ?string {
            if (! is_string($v) || trim($v) === '') {
                return null;
            }
            try {
                return \Carbon\Carbon::parse($v)->toDateString();
            } catch (\Throwable $e) {
                return null;
            }
        };

        // Editable deal-signed (anchor) date — e.g. signed Friday but captured Tuesday. It drives
        // the 30-day bond default AND the whole cascade, so persist it to the deal first (the
        // anchor is deals.deal_date). Blank/invalid leaves the existing date untouched.
        if ($signed = $cleanDate($request->input('deal_signed_date'))) {
            $deal->deal_date = $signed;
            $deal->save();
        }
        $anchor = $deal->deal_date ? \Carbon\Carbon::parse($deal->deal_date) : \Carbon\Carbon::now();

        $selections = [];
        foreach (array_keys($catalog->conditions()) as $key) {
            if (empty($in[$key]['on'])) {
                continue;
            }
            $opts = [];
            if ($key === 'bond') {
                $opts['deposit'] = ! empty($in[$key]['deposit']);
                // Bond due defaults to signed + 30 days (Johan), editable (a seller may allow only 14).
                $opts['bond_due'] = $cleanDate($in[$key]['bond_due'] ?? null) ?? $anchor->copy()->addDays(30)->toDateString();
                if ($opts['deposit']) {
                    // Deposit anchor (either/or) — Deal Signed + N (default) | fixed date | Bond Approved + N.
                    // The deposit STAYS suspensive in every case; the anchor only rewires its trigger/offset.
                    $depositDue = $cleanDate($in[$key]['deposit_due'] ?? null);
                    $anchorIn   = $in[$key]['deposit_anchor'] ?? null;
                    // Back-compat: an OLD form (no anchor field) with a date = fixed; without = Deal Signed + default.
                    $depAnchor = $anchorIn !== null
                        ? (in_array($anchorIn, ['signed', 'fixed', 'bond_approved'], true) ? $anchorIn : 'signed')
                        : ($depositDue ? 'fixed' : 'signed');
                    $opts['deposit_anchor'] = $depAnchor;
                    $opts['deposit_offset'] = (($in[$key]['deposit_offset'] ?? '') !== '')
                        ? max(0, (int) $in[$key]['deposit_offset']) : null;
                    $opts['deposit_due']    = $depAnchor === 'fixed' ? $depositDue : null;
                }
            }
            if ($key === 'cash') {
                $opts['funds_mode'] = (($in[$key]['funds_mode'] ?? 'available') === 'proof_later') ? 'proof_later' : 'available';
                $opts['payments']   = max(1, min(6, (int) ($in[$key]['payments'] ?? 1)));
                if ($opts['funds_mode'] === 'proof_later') {
                    $opts['proof_due'] = $cleanDate($in[$key]['proof_due'] ?? null);
                }
                // One by-when date per payment (1-based), matching the payment_i steps.
                $rawDues = (array) ($in[$key]['payment_dues'] ?? []);
                $opts['payment_dues'] = [];
                for ($i = 1; $i <= $opts['payments']; $i++) {
                    $opts['payment_dues'][$i] = $cleanDate($rawDues[$i] ?? null);
                }
            }
            if ($key === 'sale_of_another') {
                $opts['property_sold_due'] = $cleanDate($in[$key]['property_sold_due'] ?? null);
            }
            $selections[$key] = $opts;
        }

        if (empty($selections)) {
            return back()->with('error', 'Pick at least one suspensive condition to build the pipeline.');
        }

        try {
            $assembler->assemble($deal, $selections);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->pipelineRedirect($deal)
            ->with('info', 'Deal structure saved — pipeline assembled.');
    }

    /** Attach a template's pipeline to the deal (the service guards against double-attach). */
    public function attach(Deal $deal, Request $request): RedirectResponse
    {
        // A declined deal does not get a NEW pipeline started on it. (The capture-time
        // auto-attach in DealRegisterController is deliberately NOT gated — a deal that is
        // auto-declined at birth by the Wave 2 capture-after-grant rule still materialises
        // its steps in that same request, and they simply render locked.)
        $this->lock->assertUnlocked($deal, 'Attach a pipeline');

        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
        ]);

        // Honour the user's pick (changeable at attach); if none, fall back to the
        // deal_type → agency-default template.
        $template = ! empty($data['template_id'])
            ? $this->activeTemplates($deal)->firstWhere('id', (int) $data['template_id'])
            : $this->defaultTemplateFor($deal);

        if (! $template) {
            return back()->with('error', 'That pipeline template is not available for this deal.');
        }

        try {
            $this->pipelines->createPipeline($deal, $template->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->pipelineRedirect($deal)
            ->with('info', "Pipeline \"{$template->name}\" attached.");
    }

    /**
     * Mark a step complete — cascades to its ready successors (step-level only; the DR1
     * deal is never touched). Guards that the step belongs to THIS deal and is active.
     */
    public function completeStep(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $isNewModel = app(\App\Services\DealV2\DealDateCascade::class)->isNewModel($deal);
        // Old-model steps complete in strict order (active only). New-model (composable) deals
        // let the agent mark ANY not-started/active step done — real deals rarely complete in
        // order, and the Due cascade re-baselines downstream off the actual date. The deal-level
        // lock (declined deals) still applies, enforced by the service's assertStepUnlocked.
        if ($step->status !== 'active' && ! ($isNewModel && $step->status === 'not_started')) {
            return back()->with('error', 'Only an active step can be completed.');
        }

        $notes = trim((string) $request->input('notes', ''));
        // AT-229 6b — a decision step may complete with a NEGATIVE outcome (e.g. "Bond Declined");
        // only honour it when the step actually has a negative branch configured.
        $outcome = $request->input('outcome') === 'negative' && $step->negative_status_trigger ? 'negative' : 'positive';

        $completion = [];
        if ($notes !== '') { $completion['notes'] = $notes; }
        if ($outcome === 'negative') { $completion['outcome'] = 'negative'; }

        // Wave 2 granted-uniqueness — a step whose trigger would GRANT this deal
        // is blocked when the property already carries a granted deal. The
        // service throws inside the transaction (step completion rolls back);
        // surface it to the user instead of silently swallowing it.
        try {
            $this->pipelines->completeStep($step, $request->user()?->id, $completion);
        } catch (\App\Exceptions\Deal\BondAttorneyRequiredException $e) {
            return back()->with('error',
                'Capture the bond attorney (Email Parties → Bond Attorney) before this deal can be registered.');
        } catch (\App\Exceptions\Deal\DuplicateGrantException $e) {
            $other = $e->existingGrantedDeal;
            return back()->with('error', sprintf(
                'Step not completed — it would grant this deal, but deal #%s already carries a %s status on this property. Resolve that deal first.',
                (string) ($other->deal_no ?? $other->id),
                $e->existingStatusLabel(),
            ));
        }

        // AT-334 P1 — new-model: honour an editable actual_date (defaults to today; a user can
        // back-date "bond was actually approved on 1 Aug") and re-cascade downstream Dues off it
        // (Due = predecessor Actual-if-set else Due + offset). RAG recomputes live on render.
        if ($isNewModel) {
            $actual = $request->input('actual_date');
            $actualDate = $actual
                ? \Illuminate\Support\Carbon::parse($actual)->toDateString()
                : now()->toDateString();
            $step->forceFill(['actual_date' => $actualDate])->saveQuietly();
            app(\App\Services\DealV2\DealDateCascade::class)->recompute($deal);
        }

        return $this->pipelineRedirect($deal)
            ->with('info', "Step \"{$step->name}\" completed.");
    }

    /**
     * Manual "Decline deal" — surfaces the CANONICAL decline transition on the DR2 pipeline
     * (there was no manual control here; declining was register-only). Reuses the exact
     * accepted_status → 'D' transition the auto-decline writes: DealPipelineLockService treats
     * 'D' as the whole lock set (the pipeline goes read-only), and the save fires the existing
     * property-revert reactivity. Forward-safe — a Registered deal is never declined here; an
     * already-declined deal is a no-op. A declined deal stays re-grantable from the register.
     */
    public function declineDeal(Deal $deal, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('view_deals'), 403);

        $current = (string) ($deal->accepted_status ?? '');
        if ($current === 'D') {
            return $this->pipelineRedirect($deal)->with('info', 'This deal is already declined.');
        }
        if ($current === 'R') {
            return $this->pipelineRedirect($deal)->with('error', 'A registered deal cannot be declined from the pipeline.');
        }

        $deal->accepted_status = 'D';   // canonical transition — same field/value the auto-decline writes
        $deal->save();                  // fires DealObserver → DealStatusChanged → property-revert etc.

        \App\Models\DealLog::create([
            'deal_id'       => $deal->id,
            'agency_id'     => $deal->agency_id,   // derive from the parent — robust for owner/non-agency context
            'actor_user_id' => $request->user()?->id,
            'event_type'    => 'declined',
            'from_value'    => $current,
            'to_value'      => 'D',
            'message'       => 'Deal declined manually from the pipeline.',
        ]);

        return $this->pipelineRedirect($deal)
            ->with('info', 'Deal declined — the pipeline is now locked (read-only). It stays re-grantable from the register.');
    }

    /**
     * Feature 1 — capture the BOND ATTORNEY on the deal (firm + working contact), from Email
     * Parties. The bank appoints the bond attorney only AFTER the bond is granted, so it is never
     * a deal-setup field; the "Capture Bond Attorney" step activates at grant and prompts here.
     * Storage mirrors the transferring-attorney / bond-originator / external-agency scalar pairs.
     * Once captured, the bond attorney becomes an emailable Email-Parties recipient + doc-copy party,
     * and the deal's registration gate (see Dr1PipelineService) is satisfied.
     */
    public function captureBondAttorney(Deal $deal, Request $request): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('view_deals'), 403);
        if ($this->lock->isLocked($deal)) {
            return $this->pipelineRedirect($deal)->with('error', 'This pipeline is locked.');
        }

        $data = $request->validate([
            'bond_attorney_provider_id' => ['required', 'integer', 'exists:agency_service_providers,id'],
            'bond_attorney_contact_id'  => ['nullable', 'integer', 'exists:agency_service_provider_contacts,id'],
        ]);

        $deal->bond_attorney_provider_id = (int) $data['bond_attorney_provider_id'];
        $deal->bond_attorney_contact_id  = ! empty($data['bond_attorney_contact_id']) ? (int) $data['bond_attorney_contact_id'] : null;
        $deal->save();

        return $this->pipelineRedirect($deal)
            ->with('info', 'Bond attorney captured — they can now be emailed from Email Parties and receive document copies.');
    }

    /**
     * AT-334 P1 — reopen a completed step (composable deals only): clear actual_date /
     * completed_at, return it to not_started, and re-cascade downstream Dues. Reversible;
     * the deal-level lock still applies. Direct successors that this completion activated are
     * left as-is (reopen them individually if needed).
     */
    public function reopenStep(
        Deal $deal,
        DealStepInstance $step,
        Request $request,
        \App\Services\DealV2\DealDateCascade $cascade
    ): RedirectResponse {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        if ($this->lock->isLocked($deal)) {
            return back()->with('error', 'This deal is not proceeding — its pipeline is locked.');
        }
        if (! $cascade->isNewModel($deal)) {
            return back()->with('error', 'Reopen applies to composable deals only.');
        }
        if (! in_array($step->status, ['completed', 'skipped'], true)) {
            return back()->with('error', 'Only a completed step can be reopened.');
        }

        $step->forceFill([
            'status'          => 'not_started',
            'actual_date'     => null,
            'completed_at'    => null,
            'completed_by_id' => null,
            'completion_data' => null,
            'current_rag'     => 'grey',
        ])->save();

        $cascade->recompute($deal);

        return $this->pipelineRedirect($deal)
            ->with('info', "Step \"{$step->name}\" reopened.");
    }

    /** V1.1 — mark a step Not Applicable (kept, visibly excused; reason recorded + audited). */
    public function markNa(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $reason = trim((string) $request->input('reason', ''));
        $this->pipelines->markNotApplicable($step, $request->user()?->id, $reason !== '' ? $reason : null);

        return $this->pipelineRedirect($deal)->with('info', "Step \"{$step->name}\" marked N/A.");
    }

    /** V1.1 — remove a step (soft-delete; audited). */
    public function removeStep(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $this->pipelines->removeStep($step, $request->user()?->id);

        return $this->pipelineRedirect($deal)->with('info', "Step \"{$step->name}\" removed.");
    }

    /** V1.1 — add a custom step: name + due date + position (relative to an existing step). */
    public function addStep(
        Deal $deal,
        Request $request,
        \App\Services\DealV2\DealDateCascade $cascade,
        \App\Services\DealV2\DealStepReorderService $reorder
    ): RedirectResponse {
        // A custom (ad-hoc) step. Johan's either/or (mirrors the deposit anchor): when the step is LINKED
        // to another step, its DUE is EITHER "+N days after that step completes" (relative) OR a fixed date.
        // We reuse the EXISTING step fields + machinery — identical to editFollows(): set
        // trigger_step_instance_id + days_offset (+ trigger_type=after_step), then let the SAME cascade
        // compute a relative Due (a fixed Due is due_date_manual → the cascade never clobbers it) and the
        // SAME reorder place it after its link. No new date logic; no change to any shared date/model file.
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'link_step_id' => ['nullable', 'integer'],           // the step this one follows (trigger/dependency)
            'due_mode'     => ['nullable', 'in:relative,fixed'],  // relative => +offset days; fixed => due_date
            'offset'       => ['nullable', 'integer', 'min:0', 'max:3650'],
            'due_date'     => ['nullable', 'date'],
        ]);

        // The link must be a LIVE step of THIS deal (never self can't apply — the step doesn't exist yet).
        $link = ! empty($data['link_step_id'])
            ? DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->find($data['link_step_id'])
            : null;

        $isNew    = $cascade->isNewModel($deal);
        // "relative" only means anything when linked AND on a composable deal (which owns the cascade).
        $relative = $link && $isNew && (($data['due_mode'] ?? 'fixed') === 'relative');
        // Fixed Due goes in at creation as a manual Due; relative gets NO manual Due so the cascade fills it.
        $fixedDue = $relative ? null : ($data['due_date'] ?? null);

        // Create via the existing service ($link also seeds the display position near it).
        $step = $this->pipelines->addCustomStep($deal, trim($data['name']), $fixedDue, $link, $request->user()?->id);

        // Wire the dependency LINK + anchor exactly as editFollows() does (composable deals only).
        if ($link && $isNew) {
            $step->forceFill([
                'trigger_step_instance_id' => $link->id,
                'days_offset'              => $relative ? (int) ($data['offset'] ?? 0) : 0,
                'trigger_type'             => 'after_step',
            ])->save();
            $cascade->recompute($deal);        // fills a relative Due; never touches the fixed (manual) Due
            $reorder->reorderByFollows($deal); // place it right after the step it follows
        }

        return $this->pipelineRedirect($deal)->with('info', 'Step added.');
    }

    /** R2 — edit a step's due date inline (audited; RAG recalcs off the edited date). */
    public function editDue(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $data = $request->validate(['due_date' => ['nullable', 'date']]);
        $this->pipelines->updateStepDueDate($step, $data['due_date'] ?? null, $request->user()?->id);

        return $this->pipelineRedirect($deal)->with('info', "Due date updated for \"{$step->name}\".");
    }

    /**
     * AT-334 Phase 5 — set a step's "follows" (predecessor) + offset (days). Re-cascades
     * the Due dates off the new predecessor, then reorders the pipeline so the step sits
     * right after the step it follows (dependency chains stay contiguous). New-model deals
     * only; existing deals are never touched.
     */
    public function editFollows(
        Deal $deal,
        DealStepInstance $step,
        Request $request,
        \App\Services\DealV2\DealDateCascade $cascade,
        \App\Services\DealV2\DealStepReorderService $reorder
    ): RedirectResponse {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        if (! $cascade->isNewModel($deal)) {
            return $this->pipelineRedirect($deal)
                ->with('error', 'Sequence editing applies to composable deals only.');
        }

        $data = $request->validate([
            'follows'       => ['nullable', 'integer'],
            'offset'        => ['nullable', 'integer', 'min:0', 'max:3650'],
            'depends_on'    => ['sometimes', 'array'],
            'depends_on.*'  => ['integer'],
        ]);

        // Drag-to-relink posts a dependency SET (depends_on[]) — a full predecessor set,
        // written to the AND-gate table. The Sequence MODAL posts only `follows` (single
        // primary) and never carries depends_on, so its path below is unchanged and a
        // convergence step's existing fan-in deps are preserved.
        if ($request->has('depends_on')) {
            return $this->relinkBySet($deal, $step, $data, $cascade, $reorder);
        }

        // The predecessor must be another LIVE step of THIS deal (never self).
        $follows = $data['follows'] ?? null;
        if ($follows) {
            $ok = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')
                ->where('id', $follows)->where('id', '!=', $step->id)->exists();
            if (! $ok) {
                $follows = null;
            }
        }

        // Cycle guard — walking the follows-chain up from the chosen predecessor must
        // never reach this step (that would create a loop).
        if ($follows) {
            $cursor = $follows;
            $seen   = [];
            while ($cursor && ! isset($seen[$cursor])) {
                if ((int) $cursor === (int) $step->id) {
                    return $this->pipelineRedirect($deal)
                        ->with('error', 'That would make the step follow itself (a loop).');
                }
                $seen[$cursor] = true;
                $cursor = DealStepInstance::where('id', $cursor)->value('trigger_step_instance_id');
            }
        }

        $step->forceFill([
            'trigger_step_instance_id' => $follows,
            'days_offset'              => (int) ($data['offset'] ?? 0),
            'trigger_type'             => $follows ? 'after_step' : 'on_creation',
        ])->save();

        $cascade->recompute($deal);          // dates
        $reorder->reorderByFollows($deal);   // visual order

        return $this->pipelineRedirect($deal)
            ->with('info', "Sequence updated for \"{$step->name}\".");
    }

    /**
     * AT-334 (concurrent-lanes rework) — relink a step to a full predecessor SET (drag-to-
     * relink). Writes the AND-gate rows in the EXISTING deal_step_instance_dependencies table
     * and sets the single primary "follows" pointer, then re-cascades + reorders. Only live
     * steps of THIS deal are honoured; self and loops are rejected.
     */
    private function relinkBySet(
        Deal $deal,
        DealStepInstance $step,
        array $data,
        \App\Services\DealV2\DealDateCascade $cascade,
        \App\Services\DealV2\DealStepReorderService $reorder
    ): RedirectResponse {
        $liveSet = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')
            ->where('id', '!=', $step->id)->pluck('id')->map(fn ($i) => (int) $i)->flip()->all();

        // The validated predecessor set (in-deal, live, never self).
        $set = collect($data['depends_on'] ?? [])->map(fn ($i) => (int) $i)
            ->filter(fn ($i) => isset($liveSet[$i]))->unique()->values()->all();

        // Primary = the given follows if valid, else the first of the set (may be null → root).
        $primary = (! empty($data['follows']) && isset($liveSet[(int) $data['follows']]))
            ? (int) $data['follows']
            : ($set[0] ?? null);
        if ($primary !== null && ! in_array($primary, $set, true)) {
            $set[] = $primary;
        }

        if ($this->wouldCycle($deal, (int) $step->id, $set)) {
            return $this->pipelineRedirect($deal)
                ->with('error', 'That relink would create a loop.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($step, $deal, $set, $primary, $data) {
            $step->forceFill([
                'trigger_step_instance_id' => $primary,
                'days_offset'              => array_key_exists('offset', $data) && $data['offset'] !== null
                    ? (int) $data['offset'] : (int) $step->days_offset,
                'trigger_type'             => $primary ? 'after_step' : 'on_creation',
            ])->save();

            // Replace this step's fan-in set (pivot rows carry no user-recoverable record).
            \Illuminate\Support\Facades\DB::table('deal_step_instance_dependencies')
                ->where('deal_step_instance_id', $step->id)->delete();
            $extra = array_values(array_filter($set, fn ($i) => $i !== $primary));
            if (! empty($extra)) {
                \Illuminate\Support\Facades\DB::table('deal_step_instance_dependencies')->insert(
                    array_map(fn ($d) => [
                        'agency_id'                   => $deal->agency_id,
                        'deal_step_instance_id'       => $step->id,
                        'depends_on_step_instance_id' => $d,
                        'created_at'                  => now(),
                        'updated_at'                  => now(),
                    ], $extra),
                );
            }
        });

        $cascade->recompute($deal);
        $reorder->reorderByFollows($deal);

        return $this->pipelineRedirect($deal)
            ->with('info', "Re-linked \"{$step->name}\".");
    }

    /** True if making $stepId depend on every id in $preds would close a loop. */
    private function wouldCycle(Deal $deal, int $stepId, array $preds): bool
    {
        $steps = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')->get();
        $map   = app(\App\Services\DealV2\DealDependencyResolver::class)->predecessorMap($steps);

        // A cycle forms iff $stepId is already an ancestor of any proposed predecessor —
        // i.e. $stepId is reachable by walking predecessors up from that predecessor.
        $seen  = [];
        $stack = array_values($preds);
        while ($stack) {
            $n = array_pop($stack);
            if ($n === $stepId) {
                return true;
            }
            if (isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            foreach ($map[$n] ?? [] as $p) {
                $stack[] = $p;
            }
        }

        return false;
    }

    /** R2 — restore a removed (soft-deleted) step to its original position. */
    public function restoreStep(Deal $deal, Request $request): RedirectResponse
    {
        $data = $request->validate(['step_id' => ['required', 'integer']]);
        $step = $this->pipelines->restoreRemovedStep($deal, (int) $data['step_id'], $request->user()?->id);

        return $this->pipelineRedirect($deal)
            ->with($step ? 'info' : 'error', $step ? "Step \"{$step->name}\" restored." : 'That step could not be restored.');
    }

    /** R2 — reinstate an N/A'd step back to a live step. */
    public function reinstateStep(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $this->pipelines->reinstateStep($step, $request->user()?->id);

        return $this->pipelineRedirect($deal)->with('info', "Step \"{$step->name}\" reinstated.");
    }

    /** V1.1 — add a comment to a step's thread. */
    public function addComment(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        DealStepComment::create([
            'agency_id'             => $deal->agency_id,
            'deal_step_instance_id' => $step->id,
            'user_id'               => $request->user()?->id,
            'body'                  => trim($data['body']),
        ]);

        return $this->pipelineRedirect($deal)->with('info', 'Comment added.');
    }

    /** This agency's active pipeline templates (is_default first). */
    private function activeTemplates(Deal $deal)
    {
        return DealPipelineTemplate::where('agency_id', $deal->agency_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')
            ->get();
    }

    /**
     * The default template for a deal by its deal_type (m3's capture writes deal.deal_type):
     * the agency's is_default template OF THAT TYPE (agency-configurable). Falls back to any
     * template of that type, then any is_default, then the first — so attach never dead-ends.
     */
    private function defaultTemplateFor(Deal $deal, $templates = null): ?DealPipelineTemplate
    {
        $templates = $templates ?? $this->activeTemplates($deal);

        return $templates->first(fn ($t) => $t->deal_type === $deal->deal_type && $t->is_default)
            ?? $templates->first(fn ($t) => $t->deal_type === $deal->deal_type)
            ?? $templates->first(fn ($t) => (bool) $t->is_default)
            ?? $templates->first();
    }
}
