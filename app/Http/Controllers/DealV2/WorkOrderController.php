<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealV2;
use App\Services\DealV2\AgencyServiceProviderService;
use App\Services\DealV2\DealDistributionService;
use App\Services\DealV2\Dr2DistributionSendService;
use App\Services\DealV2\WorkAuthorisationGenerator;
use Illuminate\Http\Request;

/**
 * AT-229 DR2 W3 — the OPTIONAL "send work order" flow off a pipeline step.
 *
 * form()  → the auto-filled authorisation fields + the supplier picker payload.
 * send()  → build the work-authorisation PDF from the (edited) fields, resolve or
 *           ad-hoc-create the supplier (never preselected — Q2), address its primary
 *           contact (Q5), send via the built distribution chain (+ audit), and link
 *           the supplier to the deal. Skippable — never blocks the step.
 */
class WorkOrderController extends Controller
{
    /** The form fields the agent may edit before send (all auto-filled by default). */
    private const FIELD_KEYS = [
        'date', 'service_label', 'property_address',
        'seller_name', 'seller_email', 'seller_tel',
        'purchaser_name', 'purchaser_tel', 'attorneys',
        'rep_name', 'rep_email', 'rep_tel',
        'keys_name', 'keys_tel', 'payer', 'notes',
    ];

    public function form(DealV2 $dealV2, DealStepInstance $dealStepInstance, WorkAuthorisationGenerator $generator)
    {
        abort_unless((int) $dealStepInstance->deal_id === (int) $dealV2->id, 404);

        $serviceType = $dealStepInstance->pipelineStep?->work_order_service_type;
        $defaults    = $generator->defaultFields($dealV2, $serviceType);

        $suppliers = AgencyServiceProvider::query()
            ->where('is_active', true)
            ->with(['serviceContacts' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_preferred')->orderBy('name')
            ->get(['id', 'name', 'company', 'email', 'specialty', 'is_preferred']);

        return response()->json([
            'service_type' => $serviceType,
            'fields'       => $defaults,
            'suppliers'    => $suppliers,
        ]);
    }

    public function send(Request $request, DealV2 $dealV2, DealStepInstance $dealStepInstance, WorkAuthorisationGenerator $generator, AgencyServiceProviderService $providers, DealDistributionService $distribution)
    {
        abort_unless((int) $dealStepInstance->deal_id === (int) $dealV2->id, 404);

        $data = $request->validate([
            // Supplier: an existing directory id OR an ad-hoc capture (never preselected).
            'service_provider_id'         => ['nullable', 'integer'],
            'service_provider_contact_id' => ['nullable', 'integer'],
            'supplier_name'               => ['nullable', 'string', 'max:255'],
            'supplier_company'            => ['nullable', 'string', 'max:255'],
            'supplier_email'              => ['nullable', 'email', 'max:255'],
            'supplier_phone'              => ['nullable', 'string', 'max:60'],
            'service_type'                => ['nullable', 'string', 'max:40'],
            'notes'                       => ['nullable', 'string'],
            'payer'                       => ['nullable', 'string'],
        ]);

        $actor       = $request->user();
        $agencyId    = (int) ($dealV2->agency_id ?: 0);
        $serviceType = $dealStepInstance->pipelineStep?->work_order_service_type ?? ($data['service_type'] ?? null);

        [$provider, $toEmail, $toName] = $this->resolveProviderAndRecipient($data, $agencyId, $serviceType, $actor, $providers);

        // Link the supplier to the deal (idempotent) + build the auth PDF from the
        // auto-filled fields overlaid with the agent's edits.
        $providers->attachToDeal($dealV2, $provider, 'service_provider');
        $fields = array_merge(
            $generator->defaultFields($dealV2, $serviceType),
            array_filter($request->only(self::FIELD_KEYS), fn ($v) => $v !== null)
        );

        $document = $generator->generate($dealV2, $fields, $provider, $actor);

        $distribution->send(
            $dealV2,
            $document,
            'service_provider',
            DealDocumentDistribution::MODE_DIRECT_ATTACHMENT,
            ['type' => 'provider', 'model' => $provider, 'email' => $toEmail, 'name' => $toName],
            $actor,
            false
        );

        $msg = "Work order sent to {$toName} ({$toEmail}). The returned certificate/invoice will auto-file when you upload it.";

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg, 'document_id' => $document->id]);
        }

        return back()->with('success', $msg);
    }

    // =========================================================================
    // DR1-native surface (the LIVE pipeline — dr2/pipeline.blade.php). The live
    // deal register runs on the DR1 `Deal`; its pipeline steps carry dr1_deal_id,
    // not deal_id (DealV2 is soft-retired, AT-219). Same optional work-order flow,
    // generalised to the DR1 deal + the AT-228 DR1 distribution path.
    // =========================================================================

    public function dr1Form(Request $request, Deal $deal, DealStepInstance $step, WorkAuthorisationGenerator $generator)
    {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id, 404);

        // AT-229 multi — the service type comes from the chosen work-order ENTRY (a step may
        // configure several); fall back to the legacy single field.
        $serviceType = $request->query('service_type') ?: $step->pipelineStep?->work_order_service_type;

        return response()->json([
            'service_type' => $serviceType,
            'fields'       => $generator->defaultFields($deal, $serviceType),
            'suppliers'    => $this->supplierPayload(),
        ]);
    }

    public function dr1Send(
        Request $request,
        Deal $deal,
        DealStepInstance $step,
        WorkAuthorisationGenerator $generator,
        AgencyServiceProviderService $providers,
        Dr2DistributionSendService $sender
    ) {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id, 404);

        $data = $this->validateSupplier($request);
        $actor       = $request->user();
        $agencyId    = (int) ($deal->agency_id ?: 0);
        // AT-229 multi — prefer the entry's service type posted with the send; fall back to legacy.
        $serviceType = ($data['service_type'] ?? null) ?: $step->pipelineStep?->work_order_service_type;

        [$provider, $toEmail, $toName] = $this->resolveProviderAndRecipient($data, $agencyId, $serviceType, $actor, $providers);

        // Build the auth PDF from the auto-filled fields overlaid with the agent's edits,
        // and file it to the DR1 deal + its pipeline step (so it appears in the corpus the
        // AT-228 send draws from, and lands on the step).
        $fields = array_merge(
            $generator->defaultFields($deal, $serviceType),
            array_filter($request->only(self::FIELD_KEYS), fn ($v) => $v !== null)
        );
        $document = $generator->generate($deal, $fields, $provider, $actor, $step->id);

        // Send via the shipped DR1 distribution path (AT-228): mints the twin on demand,
        // attaches the PDF, audits deal_document_distributions (recipient_provider_id).
        try {
            $sender->sendToParty(
                $deal,
                'service_provider',
                ['type' => 'provider', 'id' => $provider->id, 'email' => $toEmail, 'name' => $toName],
                [$document->id],
                DealDocumentDistribution::MODE_DIRECT_ATTACHMENT,
                DealDocumentDistribution::CHANNEL_EMAIL,
                $data['notes'] ?? null,
                $actor
            );
        } catch (\DomainException $e) {
            abort(422, $e->getMessage());
        }

        $msg = "Work order sent to {$toName} ({$toEmail}). The returned certificate/invoice will auto-file when you upload it against this step.";

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg, 'document_id' => $document->id]);
        }

        return back()->with('success', $msg);
    }

    // =========================================================================
    // AT-229 COC sub-process — the per-deal work-order panel on the live pipeline.
    // The step-template config lists the COC types a step OFFERS; here the agent
    // selects which this deal needs, sets each COC's RESPONSIBLE party + recipient,
    // and sends (CC agents, de-duped) — one work order + audit each.
    // =========================================================================

    public function cocPanel(Deal $deal, DealStepInstance $step)
    {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id, 404);

        // AT-229 — the offered service types are the step's configured CODES,
        // mapped to the agency's friendly labels (withTrashed so an archived-but-
        // still-configured type keeps a name). Returned as {code,label} pairs.
        $svcLabels = \App\Models\DealV2\AgencyServiceType::withTrashed()->pluck('label', 'code');
        $offered = ($step->pipelineStep?->workOrders ?? collect())
            ->pluck('service_type')->filter()->unique()->values()
            ->map(fn ($c) => ['code' => $c, 'label' => $svcLabels[$c] ?? $c])->all();

        $rows = \App\Models\DealV2\DealStepWorkOrder::where('deal_step_instance_id', $step->id)
            ->orderBy('id')->get()
            ->map(fn ($w) => [
                'id' => $w->id, 'service_type' => $w->service_type, 'responsible_party' => $w->responsible_party,
                'service_provider_id' => $w->service_provider_id, 'status' => $w->status,
                'recipient_email' => $w->recipient_email, 'cc_emails' => $w->cc_emails,
            ])->all();

        return response()->json([
            'offered_types'      => $offered,
            'responsible_labels' => \App\Services\DealV2\CocWorkOrderService::responsibleLabels(),
            'work_orders'        => $rows,
            'suppliers'          => $this->supplierPayload(),
        ]);
    }

    public function cocSync(Request $request, Deal $deal, DealStepInstance $step)
    {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id, 404);

        $data = $request->validate([
            'work_orders' => ['nullable', 'array'],
            'work_orders.*.id' => ['nullable', 'integer'],
            'work_orders.*.service_type' => ['required', 'string', 'max:40'],
            'work_orders.*.responsible_party' => ['required', 'in:' . implode(',', \App\Models\DealV2\DealStepWorkOrder::RESPONSIBLE)],
            'work_orders.*.service_provider_id' => ['nullable', 'integer'],
        ]);

        $keepIds = [];
        foreach ($data['work_orders'] ?? [] as $row) {
            $attrs = [
                'dr1_deal_id'         => $deal->id,
                'agency_id'           => $deal->agency_id,
                'service_type'        => $row['service_type'],
                'responsible_party'   => $row['responsible_party'],
                'service_provider_id' => $row['responsible_party'] === 'supplier' || $row['responsible_party'] === 'transfer_attorney' ? ($row['service_provider_id'] ?? null) : null,
            ];
            // Never re-write a row that already sent (audit integrity).
            $existing = ! empty($row['id']) ? \App\Models\DealV2\DealStepWorkOrder::where('deal_step_instance_id', $step->id)->find($row['id']) : null;
            if ($existing && $existing->isSent()) { $keepIds[] = $existing->id; continue; }
            if ($existing) { $existing->update($attrs); $keepIds[] = $existing->id; }
            else { $created = \App\Models\DealV2\DealStepWorkOrder::create($attrs + ['deal_step_instance_id' => $step->id]); $keepIds[] = $created->id; }
        }
        // Soft-delete rows the agent removed (never a sent one).
        \App\Models\DealV2\DealStepWorkOrder::where('deal_step_instance_id', $step->id)
            ->whereNotIn('id', $keepIds ?: [0])->where('status', '!=', 'sent')->get()->each->delete();

        return response()->json(['ok' => true]);
    }

    public function cocSend(Request $request, Deal $deal, DealStepInstance $step, \App\Models\DealV2\DealStepWorkOrder $workOrder, \App\Services\DealV2\CocWorkOrderService $coc)
    {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id && (int) $workOrder->deal_step_instance_id === (int) $step->id, 404);
        if ($workOrder->isSent()) {
            return response()->json(['ok' => false, 'message' => 'This work order was already sent.'], 422);
        }
        try {
            $coc->send($workOrder, $request->user(), $request->only(self::FIELD_KEYS));
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
        $workOrder->refresh();
        $cc = $workOrder->cc_emails ? " (cc {$workOrder->cc_emails})" : '';
        return response()->json(['ok' => true, 'message' => "Work order sent to {$workOrder->recipient_email}{$cc}."]);
    }

    /**
     * §17 — the DEAL-level right-panel: the agency's COC/service list, each mapped to
     * its pipeline step, with the current tick/responsible/recipient config + the
     * trigger step (default Bond Granted) that fires the sends.
     */
    public function cocConfigPanel(Deal $deal)
    {
        $types = \App\Models\DealV2\AgencyServiceType::active()->orderBy('sort_order')->orderBy('id')->get();
        $steps = \App\Models\DealV2\DealStepInstance::where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')->get();

        $existing = \App\Models\DealV2\DealStepWorkOrder::where('dr1_deal_id', $deal->id)
            ->get()->keyBy('service_type');

        $items = $types->map(function ($t) use ($steps, $existing) {
            $step = $t->matchStep($steps);
            $wo   = $existing->get($t->code);
            return [
                'code'              => $t->code,
                'label'             => $t->label,
                'step_id'           => $step?->id,
                'step_name'         => $step?->name,
                'applies'           => (bool) $wo,
                'responsible_party' => $wo->responsible_party ?? 'supplier',
                'service_provider_id' => $wo->service_provider_id ?? '',
                'status'            => $wo->status ?? null,
                'send_error'        => $wo->send_error ?? null, // AT-329 — reason a send failed
                'recipient_email'   => $wo->recipient_email ?? null,
                'cc_emails'         => $wo->cc_emails ?? null,
            ];
        })->values()->all();

        // The WHEN/trigger step is defined in PIPELINE SETUP (the granting step), not
        // selected on this panel — so no trigger options are surfaced to the UI (Johan 2026-07-20).
        return response()->json([
            'items'              => $items,
            'responsible_labels' => \App\Services\DealV2\CocWorkOrderService::responsibleLabels(),
            'suppliers'          => $this->supplierPayload(),
            // Supplier-type list for the inline "＋ Add supplier" — the SAME set the Suppliers
            // directory add-form offers (incl. transfer_attorney), so any supplier type can be
            // added inline from the pipeline.
            'specialties'        => \App\Http\Controllers\DealV2\SupplierDirectoryController::SPECIALTIES,
        ]);
    }

    /**
     * §17 — save the right-panel config: ticked COCs become pending work orders (fired
     * when the trigger step completes); un-ticked COCs auto-cascade their pipeline step
     * to N/A. Never rewrites a sent row.
     */
    public function cocConfigSave(Request $request, Deal $deal, \App\Services\Deal\Dr1PipelineService $pipelines)
    {
        $data = $request->validate([
            'items'                      => ['required', 'array'],
            'items.*.code'               => ['required', 'string', 'max:40'],
            'items.*.applies'            => ['required', 'boolean'],
            'items.*.responsible_party'  => ['required', 'in:' . implode(',', \App\Models\DealV2\DealStepWorkOrder::RESPONSIBLE)],
            'items.*.service_provider_id' => ['nullable', 'integer'],
        ]);

        $steps   = \App\Models\DealV2\DealStepInstance::where('dr1_deal_id', $deal->id)->get();
        $types   = \App\Models\DealV2\AgencyServiceType::active()->get()->keyBy('code');
        // The trigger step is defined in PIPELINE SETUP (the granting step), never re-selected on
        // the deal panel — derive it here, ignore any client-posted trigger (Johan 2026-07-20).
        $triggerId = optional($steps->firstWhere('status_trigger', 'granted'))->id
                     ?? optional($steps->firstWhere('status_trigger', 'accepted'))->id;
        $naReason  = 'Not required — supplier work orders';
        $userId    = $request->user()?->id;

        foreach ($data['items'] as $item) {
            $type = $types->get($item['code']);
            if (! $type) { continue; }
            $step = $type->matchStep($steps);                     // the COC's own step (may be null)
            $wo   = \App\Models\DealV2\DealStepWorkOrder::where('dr1_deal_id', $deal->id)
                        ->where('service_type', $item['code'])->first();

            if ($item['applies']) {
                $attrs = [
                    'deal_step_instance_id'    => $step?->id ?? $triggerId,
                    'trigger_step_instance_id' => $triggerId,
                    'agency_id'                => $deal->agency_id,
                    'responsible_party'        => $item['responsible_party'],
                    'service_provider_id'      => in_array($item['responsible_party'], ['supplier', 'transfer_attorney'], true) ? ($item['service_provider_id'] ?? null) : null,
                ];
                if ($wo && $wo->isSent()) {
                    // keep — audit integrity; do not rewrite a sent row
                } elseif ($wo) {
                    $wo->update($attrs);
                } else {
                    \App\Models\DealV2\DealStepWorkOrder::create($attrs + [
                        'dr1_deal_id'  => $deal->id,
                        'service_type' => $item['code'],
                        'status'       => 'pending',
                    ]);
                }
                // Ticked → the COC's step is live; undo an auto-N/A we set earlier.
                if ($step && $step->status === 'skipped' && $step->na_reason === $naReason) {
                    $pipelines->reinstateStep($step, $userId);
                }
            } else {
                // Un-ticked → drop the pending work order and N/A its pipeline step.
                if ($wo && ! $wo->isSent()) { $wo->delete(); }
                if ($step && ! in_array($step->status, ['completed', 'skipped'], true)) {
                    $pipelines->markNotApplicable($step, $userId, $naReason);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    // ── shared helpers ───────────────────────────────────────────────────────

    private function validateSupplier(Request $request): array
    {
        return $request->validate([
            'service_provider_id'         => ['nullable', 'integer'],
            'service_provider_contact_id' => ['nullable', 'integer'],
            'supplier_name'               => ['nullable', 'string', 'max:255'],
            'supplier_company'            => ['nullable', 'string', 'max:255'],
            'supplier_email'              => ['nullable', 'email', 'max:255'],
            'supplier_phone'              => ['nullable', 'string', 'max:60'],
            'service_type'                => ['nullable', 'string', 'max:40'],
            'notes'                       => ['nullable', 'string'],
            'payer'                       => ['nullable', 'string'],
        ]);
    }

    /** The active-supplier picker payload (with each firm's active contacts). */
    private function supplierPayload()
    {
        return AgencyServiceProvider::query()
            ->where('is_active', true)
            ->with([
                'serviceContacts' => fn ($q) => $q->where('is_active', true),
                'serviceTypes', // AT-319 — the type codes this supplier handles (drives the picker filter)
            ])
            ->orderByDesc('is_preferred')->orderBy('name')
            ->get(['id', 'name', 'company', 'email', 'specialty', 'is_preferred'])
            ->map(function ($p) {
                // AT-319 — expose `types` (AgencyServiceType codes) so the work-order panel can filter
                // the supplier dropdown by the row's required type. `specialty` is kept for attorney rows.
                $p->setAttribute('types', $p->serviceTypes->pluck('service_type')->unique()->values()->all());
                $p->unsetRelation('serviceTypes');

                return $p;
            });
    }

    /**
     * Resolve the supplier (existing directory row or ad-hoc create — never preselected,
     * Q2) and its addressee: the selectable primary contact (Q5), else the firm email.
     *
     * @return array{0:AgencyServiceProvider,1:string,2:string} [provider, toEmail, toName]
     */
    private function resolveProviderAndRecipient(array $data, int $agencyId, ?string $serviceType, $actor, AgencyServiceProviderService $providers): array
    {
        if (! empty($data['service_provider_id'])) {
            $provider = AgencyServiceProvider::findOrFail($data['service_provider_id']);
        } else {
            abort_if(empty($data['supplier_name']), 422, 'Pick a supplier or capture a new one to send the work order.');
            $provider = $providers->findOrCreate($agencyId, [
                'name'      => $data['supplier_name'],
                'company'   => $data['supplier_company'] ?? null,
                'email'     => $data['supplier_email'] ?? null,
                'phone'     => $data['supplier_phone'] ?? null,
                'specialty' => $serviceType ?: 'other',
            ], $actor->id);
        }

        $toEmail = null;
        $toName  = $provider->name;
        if (! empty($data['service_provider_contact_id'])) {
            $contact = AgencyServiceProviderContact::where('service_provider_id', $provider->id)
                ->find($data['service_provider_contact_id']);
            if ($contact) {
                $toEmail = $contact->email;
                $toName  = $contact->contact_person ?: $contact->attorney_name ?: $provider->name;
            }
        }
        $toEmail = $toEmail ?: $provider->email;
        abort_if(! $toEmail, 422, 'The chosen supplier has no email address to send the work order to.');

        return [$provider, $toEmail, $toName];
    }
}
