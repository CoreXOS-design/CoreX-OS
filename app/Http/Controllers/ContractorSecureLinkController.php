<?php

namespace App\Http\Controllers;

use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. The contractor's
 * per-job secure link — no login, no CoreX session. Deliberately a
 * TOP-LEVEL controller (App\Http\Controllers, not ...\CoreX), same
 * reasoning as RentalInspectionPublicController: reached by someone with
 * no CoreX session at all. Every lookup goes through
 * RentalSecureAccessToken::findLiveByRawToken() + ->isLive() — never a
 * route-model-bound {token}, never a bare work-order id. Rate-limited at
 * the route level (throttle:30,1 — same middleware every other public
 * token-gated route in this app already uses, e.g. buyer-portal).
 *
 * Expired/revoked/used-up (work order already completed/cancelled) all
 * render the SAME "unavailable" view — never distinguishing which, same
 * principle RentalInspectionPublicController already applies.
 */
class ContractorSecureLinkController extends Controller
{
    private function resolveToken(string $token): ?RentalSecureAccessToken
    {
        // Purpose-checked (§14.27.6): a crew link's token must never open the
        // contractor page, nor the reverse.
        $record = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)
            ->resolveLive($token, RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER);

        // AT-452 round 2 - a link only ever opens the job for the contractor it was made for: once the work order is given to someone
        // else (or the contractor is taken off it), the old link goes dead even before it expires.
        if ($record && (int) $record->agency_service_provider_id !== (int) $record->workOrder?->agency_service_provider_id) {
            return null;
        }

        return $record;
    }

    /** One dead-link page. A link that stopped because the job was CANCELLED or FINISHED says so (audit fix C-A4); every other reason stays the one generic page. */
    private function unavailable(string $token): View
    {
        $state = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->closedStateFor($token);

        return view('rentals.secure-link.unavailable', ['finished' => $state === 'finished', 'cancelled' => $state === 'cancelled']);
    }

    /** section 22.7 - the plain line a contractor reads while the owner has the job on hold; never the owner's reason. */
    private function onHold(RentalSecureAccessToken $record): ?string
    {
        return app(\App\Services\Rentals\RentalOwnerHoldService::class)->activeFor($record->workOrder) !== null
            ? 'This job is on hold - please do not attend until you hear from us.'
            : null;
    }

    public function show(Request $request, string $token): View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return $this->unavailable($token);
        }

        $workOrder = $record->workOrder()->with(['property', 'quotes', 'photos', 'supplier'])->first();
        $jobs = app(\App\Services\Rentals\RentalContractorJobService::class);
        // the photos of the problem - every photo of the fault, read-only, with the office's marks and notes (they help the repair)
        $photos = app(\App\Services\Rentals\RentalFaultPhotoAnnotationService::class)->contractorProblemPhotos($workOrder);
        $record->forceFill(['last_used_at' => now()])->save();

        return view('rentals.secure-link.show', [
            'token' => $token,
            'workOrder' => $workOrder,
            'photos' => $photos,
            'response' => $workOrder->contractor_response,
            'responseLabel' => $jobs::responseLabel($workOrder->contractor_response),
            'agencyName' => \App\Models\Agency::displayNameFor($workOrder->agency, 'The agency'),
            // AT-459 - the same facts the job email carries: who lets the contractor in (name, phone, note), the tenant (only when the agency
            // allows crews/contractors to see them) and an Open in Maps link. Nothing here lets the contractor capture parts, labour or hours.
            'access' => \App\Services\Rentals\JobSiteInfo::accessContact($workOrder),
            'tenants' => \App\Models\RentalPortalSetting::crewLinkShowTenantContactFor($workOrder->agency_id) ? \App\Services\Rentals\JobSiteInfo::tenants($workOrder->lease_id) : [],
            'mapUrl' => \App\Services\Rentals\JobSiteInfo::mapUrl($workOrder->property, (string) $workOrder->property?->buildDisplayAddress()),
            // section 22.7 - the owner stopped it: "On hold - do not attend" and nothing to answer, quote, photograph or mark done (the owner's reason is never shown here)
            'onHold' => app(\App\Services\Rentals\RentalOwnerHoldService::class)->activeFor($workOrder) !== null,
            'lookAndQuote' => app(\App\Services\Rentals\RentalWhoPaysService::class)->isFaultJob($workOrder),
        ] + $this->finishingData($workOrder));
    }

    /**
     * Spec 22.17 - what the page offers once the cost is authorised: the appointment, before/after photos, the invoice, "Ask for extra money" (and where the last
     * request stands), and "Mark job done". `unlocked` is false until the owner has authorised the cost (old, grandfathered work counts as authorised).
     *
     * @return array<string,mixed>
     */
    private function finishingData(RentalWorkOrder $workOrder): array
    {
        $goAhead = app(\App\Services\Rentals\RentalGoAheadService::class);
        $unlocked = $goAhead->jobOpenForWork($workOrder);
        $tz = $workOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $invoiceService = app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class);
        $variations = $workOrder->variations()->where('origin', \App\Models\RentalWorkOrderVariation::ORIGIN_CONTRACTOR)->orderByDesc('id')->get();
        $ceiling = $unlocked ? $goAhead->contractorCeiling($workOrder) : null;

        return [
            'unlocked' => $unlocked,
            // Johan's 9 Oct rule: once reported finished the job waits for the agent - the page says so instead of offering the button again
            'finishedAwaitingAgent' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($workOrder),
            'authorisedAmount' => $ceiling,
            'appointmentText' => $workOrder->appointment_at ? $workOrder->appointment_at->copy()->setTimezone($tz)->format('l j F Y, H:i') : null,
            'appointmentNote' => trim((string) $workOrder->appointment_note) ?: null,
            'appointmentContractorNote' => trim((string) $workOrder->appointment_contractor_note) ?: null,   // the agent's private word to the contractor
            'invoices' => $unlocked ? $workOrder->invoices()->orderBy('id')->get() : collect(),
            // audit fix C-E2: the invoice starts at what was authorised for the contractor less what they already invoiced - nothing they must retype
            'invoiceSuggested' => $unlocked ? $invoiceService->suggestedInvoiceAmount($workOrder) : null,
            // audit fix C-A6: "I have started" is offered once, while the job has not started and is not waiting for the agent's check
            'canStart' => $unlocked && ! in_array($workOrder->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED, RentalWorkOrder::STATUS_DISPUTED], true)
                && $workOrder->contractor_response !== \App\Services\Rentals\RentalContractorJobService::RESPONSE_DECLINED
                && ! app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($workOrder),
            'hasStarted' => $workOrder->status === RentalWorkOrder::STATUS_IN_PROGRESS,
            'invoiceLimits' => $invoiceService->limitsText($workOrder->agency_id),
            'extraOpen' => $variations->first(fn ($v) => $v->isAwaitingOwner()),
            'extraLast' => $variations->first(fn ($v) => ! $v->isAwaitingOwner() && $v->status !== \App\Models\RentalWorkOrderVariation::STATUS_WITHDRAWN),
        ];
    }

    /** The agents hear about everything the contractor does on their link (one event, one rule for who they are). */
    private function tellAgents(RentalWorkOrder $workOrder, string $title, string $body, string $event): void
    {
        try {
            $address = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
            app(\App\Services\Rentals\RentalWorkOrderService::class)->notifyAgentOfOwnerAction(
                $workOrder, $event, $title . ' - ' . $address, $body, now()
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Contractor link: agents not told', ['work_order' => $workOrder->id, 'error' => $e->getMessage()]);
        }
    }

    private function contractorName(RentalWorkOrder $workOrder): string
    {
        return (string) ($workOrder->supplier?->name ?: $workOrder->contractor_name ?: 'The contractor');
    }

    /** The contractor files their invoice (number, date, amount, the document). Refused with the hard-lock words when it would go above what was authorised. */
    public function storeInvoice(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (! $record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }
        $workOrder = $record->workOrder;
        $invoices = app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class);
        if (! app(\App\Services\Rentals\RentalGoAheadService::class)->jobOpenForWork($workOrder)) {
            return back()->withErrors(['invoice' => 'You can send your invoice once the owner has authorised the cost.']);
        }

        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date', 'after:2000-01-01', 'before_or_equal:' . now()->addDay()->toDateString()],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'document' => $invoices->fileRules($workOrder->agency_id, true),
        ], [
            'document.required' => 'Attach your invoice (a file).',
            'amount.gt' => 'The invoice amount must be more than R0.',
        ]);

        if ($locked = app(\App\Services\Rentals\RentalGoAheadService::class)->invoiceLockMessage($workOrder, (float) $data['amount'])) {
            return back()->withErrors(['invoice' => $locked])->withInput();
        }

        try {
            $invoice = $invoices->upload($workOrder, [
                'invoice_number' => $data['invoice_number'], 'invoice_date' => $data['invoice_date'], 'amount' => $data['amount'],
                'agency_service_provider_id' => $record->agency_service_provider_id, 'share_with_owner' => false,
            ], $request->file('document'), null, $this->contractorName($workOrder));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()])->withInput();
        }
        $record->forceFill(['last_used_at' => now()])->save();
        $this->tellAgents($workOrder, 'Contractor sent an invoice', $this->contractorName($workOrder) . ': ' . $invoice->describe(), \App\Services\Rentals\RentalContractorJobService::EV_INVOICE);

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Thank you - your invoice was sent to the agency.');
    }

    /** "Ask for extra money": amount + reason, BEFORE the extra work. It goes to the owner under the hard lock; the contractor waits for the answer. */
    public function requestExtra(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (! $record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }
        $workOrder = $record->workOrder;
        if (! app(\App\Services\Rentals\RentalGoAheadService::class)->jobOpenForWork($workOrder)) {
            return back()->withErrors(['extra' => 'You can ask for extra money once the owner has authorised the cost.']);
        }

        $data = $request->validate([
            'extra_amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], ['reason.required' => 'Say why the extra money is needed.', 'reason.min' => 'Say why the extra money is needed.', 'extra_amount.gt' => 'The extra amount must be more than R0.']);

        // what the owner would pay on top: the contractor's extra plus the agency's own fee on it, if the agency adds one
        $extra = round((float) $data['extra_amount'], 2);
        $feeAttrs = \App\Models\RentalWorkOrderQuote::feeAttributes($workOrder, $extra, false);
        $ownerFacing = isset($feeAttrs['selling_amount']) && $feeAttrs['selling_amount'] !== null ? (float) $feeAttrs['selling_amount'] : $extra;
        $note = $this->contractorName($workOrder) . ' asks for R' . number_format($extra, 2, '.', ',') . ' extra: ' . trim($data['reason']);

        try {
            app(\App\Services\Rentals\RentalApprovalGateService::class)->raiseContractorExtra($workOrder, $ownerFacing, $note);
        } catch (\LogicException $e) {
            return back()->withErrors(['extra' => $e->getMessage()])->withInput();
        }
        $record->forceFill(['last_used_at' => now()])->save();
        $this->tellAgents($workOrder, 'Contractor asks for extra money', $note, \App\Services\Rentals\RentalContractorJobService::EV_EXTRA_MONEY);

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Your request was sent to the owner. Please wait for the answer before doing any extra work - we will tell you.');
    }

    /** The contractor's answer: accept, or decline with a reason. Last answer wins while the work order is open. */
    public function respond(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }
        $data = $request->validate([
            'answer' => ['required', 'in:accept,decline'],
            'reason' => ['nullable', 'string', 'max:1000', 'required_if:answer,decline'],
        ], ['reason.required_if' => 'Please tell us briefly why you cannot take the job.']);

        $workOrder = $record->workOrder;
        if (in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return back()->withErrors(['answer' => 'This job is already closed.']);
        }
        // audit fix C-C6: once the work has started (or the cost is authorised and the job is under way) it can no longer be declined - the agency has to be called
        if ($data['answer'] === 'decline' && app(\App\Services\Rentals\RentalOwnerHoldService::class)->workStarted($workOrder)) {
            return back()->withErrors(['answer' => 'The work has already started, so the job cannot be declined here. Please phone the agency.'])->withInput();
        }
        app(\App\Services\Rentals\RentalContractorJobService::class)->recordResponse(
            $workOrder, $data['answer'] === 'accept' ? \App\Services\Rentals\RentalContractorJobService::RESPONSE_ACCEPTED : \App\Services\Rentals\RentalContractorJobService::RESPONSE_DECLINED,
            $data['reason'] ?? null,
        );
        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', $data['answer'] === 'accept' ? 'Thank you - the agency has been told you accepted the job.' : 'Thank you - the agency has been told you cannot take the job.');
    }

    public function storeQuote(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }

        // audit fix C-C6: the order is enforced on the server (the page only hides the form). A contractor who declined cannot quote - they have to accept the job first -
        // and nothing is quoted on a job that has started or that the owner has already authorised.
        $workOrder = $record->workOrder;
        if ($workOrder->contractor_response === \App\Services\Rentals\RentalContractorJobService::RESPONSE_DECLINED) {
            return back()->withErrors(['quote' => 'You declined this job, so you cannot send a quote. If you can take it after all, accept the job first.'])->withInput();
        }
        if (app(\App\Services\Rentals\RentalOwnerHoldService::class)->workStarted($workOrder) || app(\App\Services\Rentals\RentalGoAheadService::class)->jobOpenForWork($workOrder)) {
            return back()->withErrors(['quote' => 'This job has already been authorised, so a new quote is not needed. If the price has changed, use "Ask for extra money".'])->withInput();
        }

        $max = \App\Models\RentalPortalSetting::quoteMaxAmountFor((int) $record->agency_id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:' . $max],
            'quote_date' => ['required', 'date'],
            'detail_text' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ], [
            'amount.gt' => 'The quote must be more than R0.',
            'amount.max' => 'A quote can be at most R' . number_format($max, 0, '.', ',') . ' here. If that is right, please phone the agency.',
        ]);

        if (empty($data['detail_text']) && !$request->hasFile('document')) {
            return back()->withErrors(['quote' => 'Attach the quote document, or enter its details — at least one is required.'])->withInput();
        }

        if ($request->hasFile('document')) {
            $data['document_storage_path'] = $request->file('document')
                ->store("rental-work-order-quotes/{$workOrder->id}", 'local');
        }
        unset($data['document']);
        $data['agency_service_provider_id'] = $record->agency_service_provider_id;

        $quote = $workOrder->recordQuote($data, null, 'via contractor link');
        app(\App\Services\Rentals\RentalContractorJobService::class)->recordResponse($workOrder, \App\Services\Rentals\RentalContractorJobService::RESPONSE_QUOTE, null, $quote);

        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Quote submitted.');
    }

    public function storePhoto(Request $request, RentalWorkOrderService $service, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }
        if (! app(\App\Services\Rentals\RentalGoAheadService::class)->jobOpenForWork($record->workOrder) && ! in_array($record->workOrder->contractor_response, [\App\Services\Rentals\RentalContractorJobService::RESPONSE_ACCEPTED, \App\Services\Rentals\RentalContractorJobService::RESPONSE_QUOTE], true)) {
            return back()->withErrors(['work_order' => 'Please accept the job first.']);
        }

        $data = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
            'phase' => 'nullable|in:before,after',
        ]);

        // spec 22.17: "before / during the work" is the in-progress phase, "after the work" the completed phase; no choice keeps the old meaning (after)
        $phase = ($data['phase'] ?? 'after') === 'before' ? RentalWorkOrder::PHOTO_IN_PROGRESS : RentalWorkOrder::PHOTO_COMPLETED;
        $service->storePhoto($record->workOrder, $request->file('photo'), $phase, null, null);

        $record->forceFill(['last_used_at' => now()])->save();

        $this->tellAgents($record->workOrder, 'Contractor uploaded a ' . ($phase === RentalWorkOrder::PHOTO_IN_PROGRESS ? 'before / during' : 'after') . ' photo', $this->contractorName($record->workOrder) . ': ' . $record->workOrder->title, \App\Services\Rentals\RentalContractorJobService::EV_PHOTO);

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Photo uploaded.');
    }

    /** Audit fix C-A6: the contractor's own, optional "I have started" - the same state change as the tenant's / owner's report, refused in plain words when it does not apply. */
    public function started(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (! $record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }
        $workOrder = $record->workOrder;
        try {
            app(RentalWorkOrderService::class)->recordContractorStarted($workOrder, $this->contractorName($workOrder));
        } catch (\LogicException $e) {
            return back()->withErrors(['work_order' => $e->getMessage()]);
        }
        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Thank you - the agency has been told you have started.');
    }

    public function markDone(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return $this->unavailable($token);
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }
        if (! app(\App\Services\Rentals\RentalGoAheadService::class)->jobOpenForWork($record->workOrder) && ! in_array($record->workOrder->contractor_response, [\App\Services\Rentals\RentalContractorJobService::RESPONSE_ACCEPTED, \App\Services\Rentals\RentalContractorJobService::RESPONSE_QUOTE], true)) {
            return back()->withErrors(['work_order' => 'Please accept the job first.']);
        }
        // spec 22.17: a request for extra money is still with the owner - say THAT (the job is authorised; it is the extra that waits)
        if ($record->workOrder->openVariation()) {
            return back()->withErrors(['work_order' => 'Your request for extra money is still waiting for the owner. You can mark the job done once they have answered - we will tell you.']);
        }
        // section 22.6: nothing is finished before its cost is authorised - in the contractor's words, never the office's (cc4's walk of QA1, item 1)
        if (! app(\App\Services\Rentals\RentalApprovalGateService::class)->authoriseToProceed($record->workOrder, false)->authorised) {
            return back()->withErrors(['work_order' => 'This job has not been authorised yet - the agency will tell you when you can go ahead. Send your quote and wait for their answer.']);
        }

        // already reported finished and the agent has not closed it: a second press (a double tap) changes nothing and says nothing more
        if (app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedFinished($record->workOrder)) {
            return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Thank you - the agency has been told the work is finished. They will check it and close the job.');
        }

        // spec section 22.13.2 - a job that came from a fault is not done until the contractor says what was found and what caused it
        $who = app(\App\Services\Rentals\RentalWhoPaysService::class);
        if ($who->isFaultJob($record->workOrder)) {
            $data = $request->validate(['found_text' => ['nullable', 'string', 'max:2000'], 'cause_id' => ['nullable', 'integer'], 'cause_note' => ['nullable', 'string', 'max:500']]);
            try {
                $who->recordFindings($record->workOrder, $data['found_text'] ?? null, ($data['cause_id'] ?? null) ? (int) $data['cause_id'] : null, $data['cause_note'] ?? null,
                    ($record->workOrder->supplier?->name ?: $record->workOrder->contractor_name ?: 'The contractor') . ' (contractor link)');
            } catch (\InvalidArgumentException $e) {
                return back()->withErrors(['work_order' => $e->getMessage()])->withInput();
            }
        }

        // Johan's 9 Oct rule (ruling 14 Oct 2026, spec 22.17 item 6): finished work WAITS FOR THE AGENT. The link reports it finished; it does not close the job,
        // send the owner's final statement or tell the tenant "Work completed" - the agent's own close does (confirm the work, rule who pays).
        try {
            $round = app(\App\Services\Rentals\RentalCompletionService::class)->recordContractorLinkDone($record->workOrder, $this->contractorName($record->workOrder));
        } catch (\Throwable $e) {
            return back()->withErrors(['work_order' => $e->getMessage()]);
        }

        $record->forceFill(['last_used_at' => now()])->save();

        // one message to the agent per report - a second press while the first still waits opens no new round and says nothing more
        if ($round->wasRecentlyCreated) {
            app(\App\Services\Rentals\OwnerFinishNotice::class)->contractorReportedFinished($record->workOrder, $this->contractorName($record->workOrder));
            $this->tellAgents($record->workOrder, 'Finished - awaiting your check', $this->contractorName($record->workOrder) . ' says the work is finished: ' . $record->workOrder->title . '. Check it and close the job.', \App\Services\Rentals\RentalContractorJobService::EV_FINISHED);
        }

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Thank you - the agency has been told the work is finished. They will check it and close the job.');
    }
}
