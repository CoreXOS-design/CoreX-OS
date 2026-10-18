<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Mail\Rentals\RentalCrewStandingLinkMail;
use App\Models\RentalCrew;
use App\Models\RentalCrewLinkEvent;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §14.29 — the office side of a crew's STANDING
 * link: generate / regenerate, email, revoke, and the link's event log.
 * Lives on the Rental Crews edit page ("Crew link" panel). Permission:
 * `rental_job_cards.share` (route middleware) on top of the crews block's
 * `rental_catalogue.view`. Crews are agency-wide records — scoping is the
 * agency (RentalCrew's AgencyScope), so another agency's crew id is a 404 and
 * an archived crew's panel is not reachable (the edit page itself 404s).
 *
 * Only the SHA-256 of a link is stored, so the raw URL exists exactly once —
 * in the response to the request that generated it. Emailing therefore takes
 * the just-generated URL back from the page and re-verifies it server-side
 * (it must hash to THIS crew's current live link), so the email can never carry
 * a link the system did not issue, and nothing raw is ever kept in the database.
 */
class RentalCrewLinkController extends Controller
{
    public function __construct(
        private readonly RentalSecureAccessTokenService $tokens,
        private readonly RentalMailDispatcher $mail,
    ) {}

    /** Generate (first time) or regenerate (replaces the old link on its very next request), optionally emailing it straight away. */
    public function issue(Request $request, RentalCrew $rentalCrew): RedirectResponse
    {
        $back = redirect()->route('corex.rental-crews.edit', $rentalCrew);

        if (! $rentalCrew->is_active) {
            return $back->withErrors(['crew_link' => 'This crew is switched off — make it active first, then create its link.']);
        }
        if (! RentalPortalSetting::crewLinksEnabledFor($rentalCrew->agency_id)) {
            return $back->withErrors(['crew_link' => 'Crew links are switched off for your agency (Settings → Rental Portal).']);
        }

        $data = $request->validate(['email_to' => ['nullable', 'string', 'email:rfc', 'max:191']]);

        $issued = $this->tokens->issueForCrew($rentalCrew, $request->user());
        $url = route('rentals.crew-page.show', $issued['raw_token']);
        $back->with('crew_link_url', $url);

        $to = trim((string) ($data['email_to'] ?? ''));
        if ($to !== '') {
            return $this->send($back, $rentalCrew, $issued['token'], $url, $to, $request);
        }

        return $back->with('success', 'Link created — copy it now, it will not be shown again.');
    }

    /** Email the link that was JUST generated (the page posts it back; it is re-verified against the live token). */
    public function email(Request $request, RentalCrew $rentalCrew): RedirectResponse
    {
        $back = redirect()->route('corex.rental-crews.edit', $rentalCrew);

        $data = $request->validate([
            'to' => ['required', 'string', 'email:rfc', 'max:191'],
            'link_url' => ['required', 'string', 'max:500'],
        ], [
            'to.required' => 'Type the email address to send the link to.',
            'to.email' => 'That email address does not look right.',
        ]);

        $raw = $this->rawTokenFromUrl($data['link_url']);
        $token = $raw ? $this->tokens->resolveLive($raw, RentalSecureAccessToken::PURPOSE_CREW_STANDING) : null;
        if (! $token || (int) $token->rental_crew_id !== (int) $rentalCrew->id || (int) $token->agency_id !== (int) $rentalCrew->agency_id) {
            return $back->withErrors(['crew_link' => 'That link is no longer the current one. Create a new link, then email it.']);
        }

        // Keep the URL on screen so the agent can still copy it after emailing.
        return $this->send($back->with('crew_link_url', $data['link_url']), $rentalCrew, $token, $data['link_url'], $data['to'], $request);
    }

    /** Revoke: the link is dead on its very next request. Safe to repeat. */
    public function revoke(Request $request, RentalCrew $rentalCrew): RedirectResponse
    {
        $live = RentalSecureAccessToken::withoutGlobalScopes()
            ->where('rental_crew_id', $rentalCrew->id)->where('purpose', RentalSecureAccessToken::PURPOSE_CREW_STANDING)
            ->whereNull('revoked_at')->get();

        $this->tokens->revokeAllFor($rentalCrew);

        foreach ($live as $token) {
            RentalCrewLinkEvent::record(RentalCrewLinkEvent::EVENT_REVOKED, (int) $rentalCrew->agency_id, (int) $rentalCrew->id, (int) $token->id, null, 'Crew link revoked', $request->user());
        }

        return redirect()->route('corex.rental-crews.edit', $rentalCrew)
            ->with('success', $live->isEmpty() ? 'There was no live link to revoke.' : 'Link revoked — it no longer works.');
    }

    /** The full, paginated, newest-first log of this crew's link. */
    public function events(Request $request, RentalCrew $rentalCrew): View
    {
        $events = RentalCrewLinkEvent::query()
            ->where('rental_crew_id', $rentalCrew->id)
            ->with(['actor', 'jobCard'])
            ->when($request->get('event'), fn ($q, $e) => $q->where('event', $e))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('corex.rental-crews.link-events', ['crew' => $rentalCrew, 'events' => $events, 'eventFilter' => $request->get('event')]);
    }

    private function send(RedirectResponse $back, RentalCrew $crew, RentalSecureAccessToken $token, string $url, string $to, Request $request): RedirectResponse
    {
        $expiryText = $token->expires_at ? 'This link works until ' . $token->expires_at->format('j F Y') . '.' : null;

        try {
            $this->mail->send($to, new RentalCrewStandingLinkMail($crew, $url, $expiryText, $request->user()));
        } catch (\Throwable $e) {
            report($e);

            return $back->withErrors(['crew_link' => 'The link was created, but the email could not be sent. Copy the link and send it another way.']);
        }

        RentalCrewLinkEvent::record(RentalCrewLinkEvent::EVENT_EMAILED, (int) $crew->agency_id, (int) $crew->id, (int) $token->id, null, 'Link emailed to ' . $to, $request->user());

        return $back->with('success', "Link emailed to {$to}.");
    }

    private function rawTokenFromUrl(string $url): ?string
    {
        $path = parse_url(trim($url), PHP_URL_PATH) ?: '';
        if (! preg_match('#/secure/crews/([A-Za-z0-9]{64})/?$#', $path, $m)) {
            return null;
        }

        return $m[1];
    }
}
