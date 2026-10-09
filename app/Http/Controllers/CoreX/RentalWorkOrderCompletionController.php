<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalCompletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.9.6 / §17.10 — the office's three completion-check actions, all behind the
 * Role Manager key `rental_work_orders.manage_completion` (route middleware AND the scope guard below):
 *
 *   - "Contractor reports done" — an outside contractor has finished; the agent captures it (date, how, note, photos)
 *     and a completion round opens;
 *   - "Record tenant's answer" — the tenant answered by phone / WhatsApp; the office captures it on their behalf
 *     (`responded_via = office_on_behalf`, optional photos);
 *   - "Send back" — a disputed job goes back to the crew (fresh link + the tenant's note and photos) or the contractor.
 *
 * Every action resolves the work order through route-model binding (AgencyScope: another agency's id is a 404) and
 * then guardRentalRecordScope() (own / branch / agency: an id outside the user's scope is a 403) — a direct URL by id
 * is blocked, not just unlinked. All work is in RentalCompletionService. A refusal comes back as a plain sentence.
 */
class RentalWorkOrderCompletionController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalCompletionService $completion)
    {
    }

    public function contractorDone(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $validated = $request->validate([
            'date_done' => ['nullable', 'date', 'before_or_equal:today'],
            'reported_via' => ['required', 'in:phone,whatsapp,email,in_person,other'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:' . RentalCompletionService::MAX_DISPUTE_PHOTOS],
            'photos.*' => ['file', 'image', 'max:15360'],
        ], [
            'reported_via.required' => 'Say how the contractor told you the work was done.',
            'date_done.before_or_equal' => 'The date the work was done cannot be in the future.',
            'photos.max' => 'You can attach up to ' . RentalCompletionService::MAX_DISPUTE_PHOTOS . ' photos.',
            'photos.*.image' => 'Photos must be image files.',
            'photos.*.max' => 'Each photo can be up to 15 MB.',
        ]);

        try {
            $round = $this->completion->recordContractorDone($rentalWorkOrder, $validated, $request->user(), $request->file('photos', []));
        } catch (\LogicException $e) {
            return back()->withErrors(['completion' => $e->getMessage()]);
        }

        return $this->backTo($rentalWorkOrder, $request)->with('success', $round->outcome === \App\Models\RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT
            ? 'Contractor’s completion recorded — the tenant has been asked to check the work.'
            : 'Contractor’s completion recorded.');
    }

    public function recordTenantAnswer(Request $request, RentalWorkOrder $rentalWorkOrder, int $round): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        // Scoped to THIS work order by the relation itself — another work order's round id is a 404.
        $roundModel = $rentalWorkOrder->completionRounds()->findOrFail($round);

        $validated = $request->validate([
            'answer' => ['required', 'in:fixed,not_fixed'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:' . RentalCompletionService::MAX_DISPUTE_PHOTOS],
            'photos.*' => ['file', 'image', 'max:15360'],
        ], [
            'answer.required' => 'Choose what the tenant said — the work is done, or it is not complete.',
            'photos.max' => 'You can attach up to ' . RentalCompletionService::MAX_DISPUTE_PHOTOS . ' photos.',
            'photos.*.image' => 'Photos must be image files.',
            'photos.*.max' => 'Each photo can be up to 15 MB.',
        ]);

        try {
            $this->completion->respond(
                $roundModel,
                $validated['answer'] === 'fixed',
                $validated['note'] ?? null,
                $request->file('photos', []),
                ['user' => $request->user(), 'via' => \App\Models\RentalWorkCompletionRound::RESPONDED_OFFICE_ON_BEHALF],
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            return back()->withErrors(['completion' => $e->getMessage()])->withInput();
        }

        return $this->backTo($rentalWorkOrder, $request)->with('success', $validated['answer'] === 'fixed'
            ? 'The tenant’s answer is recorded: the work is done.'
            : 'The tenant’s answer is recorded: the work is NOT complete — the job is now Disputed.');
    }

    public function sendBack(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        try {
            $result = $this->completion->sendBackWithResult($rentalWorkOrder, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['completion' => $e->getMessage()]);
        }

        $redirect = $this->backTo($rentalWorkOrder, $request)->with($result['emailed'] ? 'success' : 'completion_warning', $result['message']);
        if ($result['link_url']) {
            // Shown ONCE on the next page, like the crew-link panel — the raw token is never stored.
            $redirect->with('crew_link_url', $result['link_url']);
        }

        return $redirect;
    }

    /** T1 (9 Oct 2026): the agent has read the tenant's "not fixed" and needs no further action - the needs-action row goes; nothing is reopened. */
    public function disputeSeen(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        try {
            $this->completion->acknowledgeDispute($rentalWorkOrder, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['completion' => $e->getMessage()]);
        }

        return $this->backTo($rentalWorkOrder, $request)->with('success', 'Marked as seen - nothing was reopened.');
    }

    /** Back to the screen the action was pressed on: the job card when it came from there, else the work order. */
    private function backTo(RentalWorkOrder $workOrder, Request $request): RedirectResponse
    {
        if ($request->input('return_to') === 'job_card') {
            $card = $workOrder->jobCard;
            if ($card) {
                return redirect()->route('corex.rental-job-cards.show', $card);
            }
        }

        return redirect()->route('corex.rental-work-orders.show', $workOrder);
    }
}
