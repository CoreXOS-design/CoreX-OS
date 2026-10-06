<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalJobCard;
use App\Services\Rentals\RentalPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.4.4 — the card-level Pricing panel: the three optional percentage boxes
 * (All lines % / Parts % / Labour %) with Apply. The route holds `rental_job_cards.price`; the own / branch / agency record
 * guard runs here too. One call writes the card's markups, reprices every line that is not typed by hand or marked up on its
 * own, and logs one history row per box that changed ("Parts markup set to 20 %").
 */
class RentalJobCardPricingController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function markup(Request $request, RentalPricingService $pricing, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $data = $request->validate([
            'markup_all_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'markup_parts_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'markup_labour_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ], [
            '*.numeric' => 'A markup must be a number, like 20 or 12.5.',
            '*.min' => 'A markup cannot be negative.',
            '*.max' => 'A markup of more than 1000 % is not allowed.',
        ]);

        $scopes = ['all' => 'markup_all_percent', 'parts' => 'markup_parts_percent', 'labour' => 'markup_labour_percent'];
        try {
            foreach ($scopes as $scope => $field) {
                if (! $request->has($field)) {
                    continue;
                }
                $raw = $data[$field] ?? null;
                $new = ($raw === null || $raw === '') ? null : round((float) $raw, 2);
                $old = $rentalJobCard->{$field} !== null ? round((float) $rentalJobCard->{$field}, 2) : null;
                if ($new !== $old) {
                    $pricing->applyJobMarkup($rentalJobCard, $scope, $new, $request->user());
                    $rentalJobCard->refresh();
                }
            }
            // BUILD 2 (§17.6.3) — a card-level markup can raise the owner-facing total of an APPROVED job; the gate decides whether that is a
            // variation (within the owner's terms it is auto-approved, beyond them the owner is asked). Inert while nothing is approved yet.
            app(\App\Services\Rentals\RentalApprovalGateService::class)->assessAfterLineChange($rentalJobCard->refresh(), $request->user());
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Markup applied.');
    }
}
