<?php

namespace App\Http\Controllers\Public;

use App\Events\Auction\BidderRegistered;
use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBidder;
use App\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §10.1/§14.4. The public,
 * unauthenticated "Register to Bid" entry point AuctionBidderController's
 * own Phase 2 docblock flagged as not-yet-built ("the public tokenised
 * link is a separate, not-yet-built path").
 *
 * Deliberately narrower than §10.1's full flow: this captures the
 * registrant's details and creates a real, trackable AuctionBidder
 * (registration_source='online') — it does NOT self-serve FICA document
 * upload or Rules-of-Auction e-signing. Phase 2 never built FICA document
 * STORAGE either (fica_status is staff-attested, not backed by an upload),
 * so keeping FICA/Rules-of-Auction as a staff-completed follow-up (in
 * person, by phone, or a staff-initiated e-sign send — all channels that
 * already exist) is consistent with how every bidder is verified today,
 * not a narrowing invented for this page. It replaces "call this number"
 * with a real submission the agent picks up from the Bidder Register —
 * the bar §14.4 actually names.
 *
 * No secret token: unlike RentalApplication's per-recipient prefilled
 * link, this is a fresh, anonymous public form anyone reaches from the
 * auction's public listing — there is nothing recipient-specific to
 * protect by obscurity. Abuse is bounded by rate-limiting the submit
 * action (auction-registration-submit, AppServiceProvider) instead.
 */
class AuctionRegistrationController extends Controller
{
    private function findPublicAuction(int $auctionId): Auction
    {
        $auction = Auction::queryWithoutAgencyScope()
            ->where('id', $auctionId)
            ->with(['lots' => fn ($q) => $q->whereNotIn('status', \App\Models\AuctionLot::CONCLUDED_STATUSES)->with('property:id,title,address,suburb')])
            ->first();

        abort_if($auction === null || ! $auction->isCataloguePublished(), 404);

        return $auction;
    }

    public function show(int $auction): View
    {
        $auction = $this->findPublicAuction($auction);

        return view('public.auctions.register', [
            'auction' => $auction,
            'registrationOpen' => $auction->isRegistrationOpen(),
            'entityBiddersAllowed' => AgencyAuctionSettings::entityBiddersAllowedFor((int) $auction->agency_id),
            'ficaChecklist' => AgencyAuctionSettings::ficaDocumentChecklistFor((int) $auction->agency_id),
        ]);
    }

    public function store(Request $request, int $auction): RedirectResponse
    {
        $auction = $this->findPublicAuction($auction);

        if (! $auction->isRegistrationOpen()) {
            return back()->withErrors(['registration' => 'Registration for this auction is not currently open.'])->withInput();
        }

        $data = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'bidding_for' => 'required|in:self,entity',
            'entity_name' => 'required_if:bidding_for,entity|nullable|string|max:255',
            'popia_consent' => 'accepted',
        ], [
            'popia_consent.accepted' => 'You must accept the privacy notice to register.',
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            return back()->withErrors(['email' => 'Enter an email address or a phone number so we can reach you.'])->withInput();
        }

        $contact = $this->matchOrCreateContact($auction, $data);

        $entityContact = null;
        if ($data['bidding_for'] === 'entity') {
            // Matches the existing convention for an entity Contact: first_name
            // (NOT NULL, and what full_name() reads) duplicates entity_name.
            $entityContact = Contact::create([
                'agency_id' => $auction->agency_id,
                'contact_kind' => Contact::TYPE_ENTITY,
                'first_name' => $data['entity_name'],
                'entity_name' => $data['entity_name'],
            ]);
        }

        $bidder = AuctionBidder::create([
            'agency_id' => $auction->agency_id,
            'auction_id' => $auction->id,
            'contact_id' => $contact->id,
            'bidding_for' => $data['bidding_for'],
            'entity_contact_id' => $entityContact?->id,
            'status' => AuctionBidder::STATUS_SUBMITTED,
            'registration_source' => 'online',
            'deposit_required' => AgencyAuctionSettings::registrationDepositRequiredFor((int) $auction->agency_id),
            'deposit_amount' => AgencyAuctionSettings::registrationDepositAmountFor((int) $auction->agency_id),
        ]);

        event(new BidderRegistered($bidder));

        return redirect()->route('public.auctions.register.thanks', $auction->id);
    }

    public function thanks(int $auction): View
    {
        return view('public.auctions.register-thanks', [
            'auction' => $this->findPublicAuction($auction),
        ]);
    }

    /**
     * §10.1 — "match-or-create... Existing contact-matching rules apply; no
     * second matcher is written." There is no dedicated Contact matcher
     * service anywhere in the codebase to reuse (only
     * TrackedPropertyMatchOrCreateService, which is property-specific) — so
     * this is the plain, straightforward lookup the spec asks for: an exact
     * email or phone match within the SAME agency, else a new Contact.
     */
    private function matchOrCreateContact(Auction $auction, array $data): Contact
    {
        $query = Contact::withoutGlobalScopes()->where('agency_id', $auction->agency_id);
        $existing = $query->where(function ($q) use ($data) {
            if (! empty($data['email'])) {
                $q->orWhere('email', $data['email']);
            }
            if (! empty($data['phone'])) {
                $q->orWhere('phone', $data['phone']);
            }
        })->first();

        if ($existing) {
            return $existing;
        }

        return Contact::create([
            'agency_id' => $auction->agency_id,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ]);
    }
}
