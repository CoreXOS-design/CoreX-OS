<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Mail\Rentals\RentalJobCardCrewLinkMail;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §14.28 — the office side of the crew's
 * per-job link: mint / re-issue, email, revoke. The route middleware holds
 * `rental_job_cards.share`; every action also runs guardRentalRecordScope()
 * (own / branch / agency at the record level — direct-URL access by id is
 * blocked, not just unlinked) and refuses a closed card.
 *
 * The raw link exists only in the response to the issuing request (flashed
 * once to the next page) — it is never stored and can never be shown again.
 * That is why "Email to crew" either posts back the link just generated, or
 * issues a fresh one (replacing the old) and emails that.
 */
class RentalJobCardCrewLinkController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function issue(Request $request, RentalSecureAccessTokenService $tokens, RentalJobCard $rentalJobCard): RedirectResponse
    {
        if ($blocked = $this->refuse($rentalJobCard)) {
            return $blocked;
        }

        $issued = $tokens->issueForJobCard($rentalJobCard, $request->user());

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
            ->with('success', 'Crew link created. Copy it now — it cannot be shown again.')
            ->with('crew_link_url', route('rentals.crew-job.show', $issued['raw_token']))
            ->with('crew_link_token', $issued['raw_token']);
    }

    public function revoke(Request $request, RentalSecureAccessTokenService $tokens, RentalJobCard $rentalJobCard): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalJobCard, 'rental_job_cards', $rentalJobCard->property?->branch_id);

        $tokens->revokeAllFor($rentalJobCard);
        $rentalJobCard->logUpdate('link_revoked', $request->user(), 'Crew link revoked');

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)->with('success', 'Crew link revoked — it no longer opens.');
    }

    public function email(Request $request, RentalSecureAccessTokenService $tokens, RentalMailDispatcher $dispatcher, RentalJobCard $rentalJobCard): RedirectResponse
    {
        if ($blocked = $this->refuse($rentalJobCard)) {
            return $blocked;
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:191'],
            'link_token' => ['nullable', 'string', 'size:64'],
        ]);

        // Re-use the link just generated (its raw token is posted back by the
        // panel) — but only if it really is a LIVE link for THIS card. Anything
        // else issues a fresh link, replacing whatever was live.
        $raw = null;
        if (! empty($data['link_token'])) {
            $live = $tokens->resolveLive($data['link_token'], RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD);
            if ($live && (int) $live->rental_job_card_id === (int) $rentalJobCard->id) {
                $raw = $data['link_token'];
                $expires = $live->expires_at;
            }
        }
        if ($raw === null) {
            $issued = $tokens->issueForJobCard($rentalJobCard, $request->user());
            $raw = $issued['raw_token'];
            $expires = $issued['token']->expires_at;
        }
        $url = route('rentals.crew-job.show', $raw);

        try {
            $dispatcher->send($data['email'], new RentalJobCardCrewLinkMail(
                $rentalJobCard, $url, $expires?->format('j M Y'), $request->user(),
            ));
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
                ->withErrors(['crew_link' => 'The email could not be sent. The link is still live — copy it from below and send it another way.'])
                ->with('crew_link_url', $url)->with('crew_link_token', $raw);
        }

        $rentalJobCard->logUpdate('link_emailed', $request->user(), "Crew link emailed to {$data['email']}");

        return redirect()->route('corex.rental-job-cards.show', $rentalJobCard)
            ->with('success', "Crew link emailed to {$data['email']}.")
            ->with('crew_link_url', $url)->with('crew_link_token', $raw);
    }

    /** Scope guard + the two reasons a link cannot be made right now. Returns a redirect to refuse, or null to proceed. */
    private function refuse(RentalJobCard $card): ?RedirectResponse
    {
        $this->guardRentalRecordScope($card, 'rental_job_cards', $card->property?->branch_id);

        if ($card->isClosed()) {
            return redirect()->route('corex.rental-job-cards.show', $card)
                ->withErrors(['crew_link' => 'This job card is closed — a crew link can no longer be created.']);
        }
        if (! RentalPortalSetting::crewLinksEnabledFor($card->agency_id)) {
            return redirect()->route('corex.rental-job-cards.show', $card)
                ->withErrors(['crew_link' => 'Crew links are switched off for this agency (Settings → Rental Portal).']);
        }

        return null;
    }
}
