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
            // a link that stopped working because the job is FINISHED says so - it is not "expired or replaced"
            $closed = \App\Models\RentalSecureAccessToken::withoutGlobalScopes()->where('token_hash', \App\Models\RentalSecureAccessToken::hashToken($token))
                ->where('purpose', \App\Models\RentalSecureAccessToken::PURPOSE_CONTRACTOR_WORK_ORDER)->first()?->workOrder;

            return view('rentals.secure-link.unavailable', ['finished' => $closed && $closed->status === \App\Models\RentalWorkOrder::STATUS_COMPLETED]);
        }

        $workOrder = $record->workOrder()->with(['property', 'quotes', 'photos', 'supplier'])->first();
        $jobs = app(\App\Services\Rentals\RentalContractorJobService::class);
        // the reported photos, with the office's marks and notes (they help the repair) - the same payload the crew and the staff screens use
        $photos = app(\App\Services\Rentals\RentalFaultPhotoAnnotationService::class)->workOrderPayload(
            $workOrder, $workOrder->photos->where('photo_type', RentalWorkOrder::PHOTO_REPORTED)->values(), \App\Services\Rentals\RentalFaultPhotoAnnotationService::AUDIENCE_CONTRACTOR
        );
        $record->forceFill(['last_used_at' => now()])->save();

        return view('rentals.secure-link.show', [
            'token' => $token,
            'workOrder' => $workOrder,
            'photos' => $photos,
            'response' => $workOrder->contractor_response,
            'responseLabel' => $jobs::responseLabel($workOrder->contractor_response),
            'agencyName' => \App\Models\Agency::displayNameFor($workOrder->agency, 'The agency'),
            'accessNotes' => trim((string) ($workOrder->property?->access_notes ?? '')) ?: null,
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
            'authorisedAmount' => $ceiling,
            'appointmentText' => $workOrder->appointment_at ? $workOrder->appointment_at->copy()->setTimezone($tz)->format('l j F Y, H:i') : null,
            'appointmentNote' => trim((string) $workOrder->appointment_note) ?: null,
            'invoices' => $unlocked ? $workOrder->invoices()->orderBy('id')->get() : collect(),
            'invoiceLimits' => $invoiceService->limitsText($workOrder->agency_id),
            'extraOpen' => $variations->first(fn ($v) => $v->isAwaitingOwner()),
            'extraLast' => $variations->first(fn ($v) => ! $v->isAwaitingOwner() && $v->status !== \App\Models\RentalWorkOrderVariation::STATUS_WITHDRAWN),
        ];
    }

    /** The agents hear about everything the contractor does on their link (one event, one rule for who they are). */
    private function tellAgents(RentalWorkOrder $workOrder, string $title, string $body): void
    {
        try {
            $address = $workOrder->property?->buildDisplayAddress() ?: ('Property #' . $workOrder->property_id);
            app(\App\Services\Rentals\RentalWorkOrderService::class)->notifyAgentOfOwnerAction(
                $workOrder, \App\Services\Rentals\RentalContractorJobService::EV_RESPONDED, $title . ' - ' . $address, $body, now()
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
            return view('rentals.secure-link.unavailable');
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
        $this->tellAgents($workOrder, 'Contractor sent an invoice', $this->contractorName($workOrder) . ': ' . $invoice->describe());

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Thank you - your invoice was sent to the agency.');
    }

    /** "Ask for extra money": amount + reason, BEFORE the extra work. It goes to the owner under the hard lock; the contractor waits for the answer. */
    public function requestExtra(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (! $record) {
            return view('rentals.secure-link.unavailable');
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
        $note = $this->contractorName($workOrder) . ' asks for R' . number_format($extra, 2, '.', ' ') . ' extra: ' . trim($data['reason']);

        try {
            app(\App\Services\Rentals\RentalApprovalGateService::class)->raiseContractorExtra($workOrder, $ownerFacing, $note);
        } catch (\LogicException $e) {
            return back()->withErrors(['extra' => $e->getMessage()])->withInput();
        }
        $record->forceFill(['last_used_at' => now()])->save();
        $this->tellAgents($workOrder, 'Contractor asks for extra money', $note);

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Your request was sent to the owner. Please wait for the answer before doing any extra work - we will tell you.');
    }

    /** The contractor's answer: accept, or decline with a reason. Last answer wins while the work order is open. */
    public function respond(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
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
            return view('rentals.secure-link.unavailable');
        }
        if ($held = $this->onHold($record)) {
            return back()->withErrors(['work_order' => $held]);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'quote_date' => ['required', 'date'],
            'detail_text' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ]);

        if (empty($data['detail_text']) && !$request->hasFile('document')) {
            return back()->withErrors(['quote' => 'Attach the quote document, or enter its details — at least one is required.'])->withInput();
        }

        $workOrder = $record->workOrder;
        if ($request->hasFile('document')) {
            $data['document_storage_path'] = $request->file('document')
                ->store("rental-work-order-quotes/{$workOrder->id}", 'local');
        }
        unset($data['document']);
        $data['agency_service_provider_id'] = $record->agency_service_provider_id;

        $workOrder->recordQuote($data, null, 'via contractor link');
        app(\App\Services\Rentals\RentalContractorJobService::class)->recordResponse($workOrder, \App\Services\Rentals\RentalContractorJobService::RESPONSE_QUOTE);

        $record->forceFill(['last_used_at' => now()])->save();

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Quote submitted.');
    }

    public function storePhoto(Request $request, RentalWorkOrderService $service, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
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

        $this->tellAgents($record->workOrder, 'Contractor uploaded a ' . ($phase === RentalWorkOrder::PHOTO_IN_PROGRESS ? 'before / during' : 'after') . ' photo', $this->contractorName($record->workOrder) . ': ' . $record->workOrder->title);

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Photo uploaded.');
    }

    public function markDone(Request $request, string $token): RedirectResponse|View
    {
        $record = $this->resolveToken($token);
        if (!$record) {
            return view('rentals.secure-link.unavailable');
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

        try {
            $record->workOrder->complete(null, [
                'paid_by' => RentalWorkOrder::PAID_BY_NOT_YET_PAID,
            ], 'via contractor link');
        } catch (\Throwable $e) {
            return back()->withErrors(['work_order' => $e->getMessage()]);
        }

        $record->forceFill(['last_used_at' => now()])->save();

        $this->tellAgents($record->workOrder, 'Contractor says the job is done', $this->contractorName($record->workOrder) . ': ' . $record->workOrder->title);

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Marked as done. The agency has been notified.');
    }
}
