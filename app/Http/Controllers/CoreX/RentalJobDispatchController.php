<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalJobCard;
use App\Models\RentalJobDispatch;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalContractorJobService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rentals-faults-work-orders.md §19 (AT-452 round 2) - the office side of "the contractor / crew is told the moment they are
 * appointed": choose or change the contractor without a typed quote, send the job again, and the two honest WhatsApp presses (open, then
 * mark as sent). The route middleware holds the permission; every action also runs guardRentalRecordScope() (own / branch / agency at the
 * record level) and checks the dispatch really belongs to the record in the URL.
 */
class RentalJobDispatchController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalContractorJobService $jobs)
    {
    }

    /** Choose (or change) the contractor from the work order screen - the job goes to them to quote. */
    public function appoint(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardWorkOrder($rentalWorkOrder);
        $data = $request->validate([
            'agency_service_provider_id' => ['required', 'integer'],
            'trade_type' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $result = $this->jobs->appoint($rentalWorkOrder, (int) $data['agency_service_provider_id'], $data['trade_type'] ?? null, $request->user());
        } catch (\LogicException $e) {
            return $this->back($rentalWorkOrder)->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return $this->back($rentalWorkOrder)->with($this->flash($result, 'Contractor chosen.'));
    }

    /** "Send the job again" - one press, one send (the form carries a one-time key). */
    public function resend(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardWorkOrder($rentalWorkOrder);
        $data = $request->validate(['press_key' => ['required', 'string', 'max:80']]);
        if (! $rentalWorkOrder->agency_service_provider_id) {
            return $this->back($rentalWorkOrder)->withErrors(['rental_work_order' => 'Choose a contractor first.']);
        }

        $result = $this->jobs->resend($rentalWorkOrder, $request->user(), $data['press_key']);

        return $this->back($rentalWorkOrder)->with($this->flash($result, 'The job was sent again.'));
    }

    public function openWhatsApp(Request $request, RentalWorkOrder $rentalWorkOrder, int $dispatch): RedirectResponse
    {
        $this->guardWorkOrder($rentalWorkOrder);
        $d = $this->dispatchOf($rentalWorkOrder->id, $dispatch, RentalJobDispatch::KIND_CONTRACTOR);
        try {
            $url = $this->jobs->openWhatsApp($d, $request->user());
        } catch (\LogicException $e) {
            return $this->back($rentalWorkOrder)->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function markWhatsAppSent(Request $request, RentalWorkOrder $rentalWorkOrder, int $dispatch): RedirectResponse
    {
        $this->guardWorkOrder($rentalWorkOrder);
        $d = $this->dispatchOf($rentalWorkOrder->id, $dispatch, RentalJobDispatch::KIND_CONTRACTOR);
        try {
            $this->jobs->markWhatsAppSent($d, $request->user());
        } catch (\LogicException $e) {
            return $this->back($rentalWorkOrder)->withErrors(['rental_work_order' => $e->getMessage()]);
        }

        return $this->back($rentalWorkOrder)->with('success', 'Marked as sent by WhatsApp.');
    }

    // ── job card (the in-house crew) ────────────────────────────────────────────────────────────────────────────────────────

    /** Send the crew their link now (for a job card whose automatic send could not go, or when details were added later). */
    public function crewSend(Request $request, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardCard($rentalJobCard);
        $data = $request->validate(['press_key' => ['required', 'string', 'max:80']]);
        $result = $this->jobs->sendCrewLink($rentalJobCard, $request->user(), RentalJobDispatch::REASON_RESEND, $data['press_key']);

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with($this->flash($result, 'Crew link sent.', 'The crew link could not be sent - see the line on the job card.'));
    }

    public function crewOpenWhatsApp(Request $request, RentalJobCard $rentalJobCard, int $dispatch): RedirectResponse
    {
        $this->guardCard($rentalJobCard);
        $d = RentalJobDispatch::where('rental_job_card_id', $rentalJobCard->id)->where('recipient_kind', RentalJobDispatch::KIND_CREW)->findOrFail($dispatch);
        try {
            $url = $this->jobs->openWhatsApp($d, $request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['crew_link' => $e->getMessage()]);
        }

        return redirect()->away($url);
    }

    public function crewMarkWhatsAppSent(Request $request, RentalJobCard $rentalJobCard, int $dispatch): RedirectResponse
    {
        $this->guardCard($rentalJobCard);
        $d = RentalJobDispatch::where('rental_job_card_id', $rentalJobCard->id)->where('recipient_kind', RentalJobDispatch::KIND_CREW)->findOrFail($dispatch);
        try {
            $this->jobs->markWhatsAppSent($d, $request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['crew_link' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Marked as sent by WhatsApp.');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function guardWorkOrder(RentalWorkOrder $wo): void
    {
        $this->guardRentalRecordScope($wo, 'rental_work_orders', $wo->property?->branch_id);
    }

    private function guardCard(RentalJobCard $card): void
    {
        $this->guardRentalRecordScope($card, 'rental_job_cards', $card->property?->branch_id);
    }

    private function dispatchOf(int $workOrderId, int $dispatchId, string $kind): RentalJobDispatch
    {
        return RentalJobDispatch::where('rental_work_order_id', $workOrderId)->where('recipient_kind', $kind)->findOrFail($dispatchId);
    }

    private function back(RentalWorkOrder $wo): RedirectResponse
    {
        return redirect()->route('corex.rental-work-orders.show', $wo);
    }

    /** @param array{rows:array, sent:bool, not_sent:bool} $result */
    private function flash(array $result, string $okText, ?string $badText = null): array
    {
        if (! empty($result['held'])) {
            return ['warning' => $result['held']];
        }
        if ($result['sent']) {
            return ['success' => $okText . ' The contractor has been emailed.'];
        }
        if ($result['not_sent']) {
            return ['warning' => $badText ?? 'NOT sent - this contractor has no email or mobile number on file. Add their details and press Send.'];
        }
        $wa = collect($result['rows'])->first(fn ($r) => $r->channel === RentalJobDispatch::CHANNEL_WHATSAPP);

        return $wa
            ? ['success' => $okText . ' Nothing has gone yet - press "Open WhatsApp and send", then "Mark as sent".']
            : ['warning' => $badText ?? 'The job could not be sent - see the supplier card.'];
    }
}
