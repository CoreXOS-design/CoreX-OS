<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\Auction;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §10.1/§14.4. The public,
 * unauthenticated "Register to Bid" entry point AuctionBidderController's
 * Phase 2 docblock flagged as not-yet-built. No auth, no session, no
 * secret token (see the controller's own docblock for why a token isn't
 * needed here) — verified via a real HTTP round-trip against a running
 * `php artisan serve` (login-free) before writing this, matching the
 * discipline used elsewhere in this session for anything touching real
 * request auth/CSRF.
 */
final class PublicAuctionRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Public Reg Test Agency', 'slug' => 'public-reg-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id]);
        $property = Property::create([
            'title' => 'Public reg test lot', 'agency_id' => $this->agency->id, 'agent_id' => $agent->id,
            'branch_id' => $branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
        $this->auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'reference' => 'AUC-PUBREG-'.uniqid(),
            'title' => 'Public Reg Test Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);
        AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'property_id' => $property->id, 'lot_number' => 1,
        ]);
        (new AuctionLotStatusService())->publishCatalogue($this->auction);
    }

    public function test_the_registration_page_is_reachable_with_no_auth(): void
    {
        $this->get(route('public.auctions.register.show', $this->auction->id))
            ->assertOk()
            ->assertSee($this->auction->title);
    }

    public function test_an_un_catalogued_auction_404s(): void
    {
        $draft = Auction::create([
            'agency_id' => $this->agency->id, 'reference' => 'AUC-DRAFT-'.uniqid(),
            'title' => 'Draft Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal',
            'starts_at' => now()->addDays(10),
        ]);

        $this->get(route('public.auctions.register.show', $draft->id))->assertNotFound();
    }

    public function test_registering_with_only_an_email_creates_a_bidder(): void
    {
        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'Public', 'last_name' => 'Registrant', 'email' => 'public.registrant@example.test',
            'bidding_for' => 'self', 'popia_consent' => '1',
        ])->assertRedirect(route('public.auctions.register.thanks', $this->auction->id));

        $bidder = AuctionBidder::where('auction_id', $this->auction->id)->first();
        $this->assertNotNull($bidder);
        $this->assertSame(AuctionBidder::STATUS_SUBMITTED, $bidder->status);
        $this->assertSame('online', $bidder->registration_source);
    }

    public function test_without_email_or_phone_the_submission_is_rejected(): void
    {
        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'Public', 'bidding_for' => 'self', 'popia_consent' => '1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, AuctionBidder::where('auction_id', $this->auction->id)->count());
    }

    public function test_without_popia_consent_the_submission_is_rejected(): void
    {
        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'Public', 'email' => 'no-consent@example.test', 'bidding_for' => 'self',
        ])->assertSessionHasErrors('popia_consent');
    }

    public function test_a_second_registration_with_the_same_email_matches_the_existing_contact(): void
    {
        $email = 'matched.contact@example.test';
        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'First', 'email' => $email, 'bidding_for' => 'self', 'popia_consent' => '1',
        ]);
        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'Second', 'email' => $email, 'bidding_for' => 'self', 'popia_consent' => '1',
        ]);

        $this->assertSame(1, Contact::where('agency_id', $this->agency->id)->where('email', $email)->count());
        $this->assertSame(2, AuctionBidder::where('auction_id', $this->auction->id)->count());
    }

    public function test_registering_as_an_entity_creates_an_entity_contact(): void
    {
        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'Trustee', 'email' => 'trustee@example.test', 'bidding_for' => 'entity',
            'entity_name' => 'Example Family Trust', 'popia_consent' => '1',
        ]);

        $bidder = AuctionBidder::where('auction_id', $this->auction->id)->first();
        $this->assertSame('entity', $bidder->bidding_for);
        $entity = Contact::find($bidder->entity_contact_id);
        $this->assertSame(Contact::TYPE_ENTITY, $entity->contact_kind);
        $this->assertSame('Example Family Trust', $entity->entity_name);
    }

    public function test_registration_is_rejected_once_the_window_has_closed(): void
    {
        $this->auction->update(['registration_closes_at' => now()->subDay()]);

        $this->post(route('public.auctions.register.store', $this->auction->id), [
            'first_name' => 'TooLate', 'email' => 'toolate@example.test', 'bidding_for' => 'self', 'popia_consent' => '1',
        ])->assertSessionHasErrors('registration');

        $this->assertSame(0, AuctionBidder::where('auction_id', $this->auction->id)->count());
    }
}
