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
            return view('rentals.secure-link.unavailable');
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
            'agencyName' => $workOrder->agency?->trading_name ?: ($workOrder->agency?->name ?: 'The agency'),
            'accessNotes' => trim((string) ($workOrder->property?->access_notes ?? '')) ?: null,
            // section 22.7 - the owner stopped it: "On hold - do not attend" and nothing to answer, quote, photograph or mark done (the owner's reason is never shown here)
            'onHold' => app(\App\Services\Rentals\RentalOwnerHoldService::class)->activeFor($workOrder) !== null,
        ]);
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

        $data = $request->validate([
            'photo' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:51200',
        ]);

        $service->storePhoto($record->workOrder, $request->file('photo'), RentalWorkOrder::PHOTO_COMPLETED, null, null);

        $record->forceFill(['last_used_at' => now()])->save();

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

        return redirect()->route('rentals.secure-link.show', $token)->with('success', 'Marked as done. The agency has been notified.');
    }
}
