<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Mail\Rentals\RentalJobCardCrewLinkMail;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardPriceRequest;
use App\Models\RentalPortalSetting;
use App\Services\Rentals\RentalCrewPricingService;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.5.4 / §17.5.5 — "Ask crew to price this job" and "Close request". The route holds
 * `rental_job_cards.share`; every action also runs the own / branch / agency record guard (a direct POST by id from outside
 * the user's scope is a 403, another agency's card a 404) and the service refuses a closed card, a card with no crew and a
 * second open request in plain language.
 */
class RentalJobCardPriceRequestController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function store(Request $request, RentalCrewPricingService $service, RentalSecureAccessTokenService $tokens, RentalMailDispatcher $dispatcher, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            'generate_link' => ['nullable', 'boolean'],
        ]);

        try {
            $service->requestPricing($rentalJobCard, $data['note'] ?? null, $request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['pricing' => $e->getMessage()]);
        }

        $redirect = redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'The crew has been asked to price this job.');

        // "Offers to generate/email the link in the same step": only when asked, only when crew links are on, and never when a
        // live link already exists (re-issuing would kill the one the crew may already have open).
        if (! empty($data['generate_link']) && RentalPortalSetting::crewLinksEnabledFor($rentalJobCard->agency_id)) {
            $live = \App\Models\RentalSecureAccessToken::withoutGlobalScopes()
                ->where('rental_job_card_id', $rentalJobCard->id)->where('purpose', \App\Models\RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD)
                ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
            if (! $live) {
                $issued = $tokens->issueForJobCard($rentalJobCard, $request->user());
                $url = route('rentals.crew-job.show', $issued['raw_token']);
                $email = $rentalJobCard->crew?->email;
                $emailed = false;
                if ($email) {
                    try {
                        $dispatcher->send($email, new RentalJobCardCrewLinkMail($rentalJobCard, $url, $issued['token']->expires_at?->format('j M Y'), $request->user()));
                        $rentalJobCard->logUpdate('link_emailed', $request->user(), "Crew link emailed to {$email}");
                        $emailed = true;
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
                $redirect->with('crew_link_url', $url)->with('crew_link_token', $issued['raw_token'])
                    ->with('success', $emailed
                        ? "The crew has been asked to price this job, and their link was emailed to {$email}."
                        : 'The crew has been asked to price this job. Their link is shown below — copy it now, it cannot be shown again.');
            }
        }

        return $redirect;
    }

    public function close(Request $request, RentalCrewPricingService $service, RentalJobCard $rentalJobCard, int $priceRequest): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $model = RentalJobCardPriceRequest::where('rental_job_card_id', $rentalJobCard->id)->findOrFail($priceRequest);

        try {
            $service->closeRequest($rentalJobCard, $model, $request->user());
        } catch (\LogicException $e) {
            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->withErrors(['pricing' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Price request closed.');
    }
}
