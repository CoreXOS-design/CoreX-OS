<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Services\Rentals\RentalCrewPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.5.3 step 3 — Accept / Reject the parts and labour the crew sent, and "Accept all".
 * The routes hold `rental_job_cards.price`; every action also runs the own / branch / agency record guard. A crew line only
 * becomes part of the job (and of any total the owner sees) through RentalCrewPricingService::accept().
 */
class RentalJobCardCrewLineController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function accept(Request $request, RentalCrewPricingService $service, RentalJobCard $rentalJobCard, int $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);
        $model = RentalJobCardLine::where('rental_job_card_id', $rentalJobCard->id)->findOrFail($line);

        $data = $request->validate([
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'unit_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'markup_type' => ['nullable', 'in:percent,amount'],
            'markup_value' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'required_with:markup_type'],
        ]);
        // Correcting the crew's cost needs to SEE costs; the route already needs `price` for the selling side.
        if (! $request->user()->hasPermission('rental_job_cards.view_costs')) {
            unset($data['unit_cost']);
        }

        try {
            $service->accept($rentalJobCard, $model, $request->user(), $data);
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line accepted — it now counts on the job card.')->with('jc_focus_line', $model->id);
    }

    public function reject(Request $request, RentalCrewPricingService $service, RentalJobCard $rentalJobCard, int $line): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);
        $model = RentalJobCardLine::where('rental_job_card_id', $rentalJobCard->id)->findOrFail($line);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Give a short reason — the crew will see it.']);

        try {
            $service->reject($rentalJobCard, $model, $data['reason'], $request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Line not accepted — the crew will see your reason.');
    }

    public function acceptAll(Request $request, RentalCrewPricingService $service, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        try {
            $count = $service->acceptAll($rentalJobCard, $request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
            ->with('success', $count === 0 ? 'Nothing is waiting for you.' : ($count === 1 ? '1 line accepted and priced.' : "{$count} lines accepted and priced."));
    }
}
