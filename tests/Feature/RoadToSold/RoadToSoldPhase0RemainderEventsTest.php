<?php

declare(strict_types=1);

namespace Tests\Feature\RoadToSold;

use App\Events\Contact\ContactMatchCreated;
use App\Events\Deal\DealCommissionFinalised;
use App\Events\Docuperfect\OtpSigned;
use App\Events\Docuperfect\SignatureEnvelopeFinalized;
use App\Events\Document\DocumentFiledToProperty;
use App\Events\Mandate\MandateSigned;
use App\Events\Marketing\SellerLinkCreated;
use App\Events\Marketing\SellerLinkOpened;
use App\Events\Marketing\ViewingPackCreated;
use App\Events\Presentation\PresentationOutcomeRecorded;
use App\Events\Viewing\ViewingHeld;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Deal;
use App\Models\Docuperfect\Document as EsignDocument;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Models\ViewingPack;
use App\Services\Docuperfect\SignatureService;
use App\Services\RoadToSold\Scope;
use App\Services\RoadToSold\VisibleAgents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * road-to-sold.md section 6.2 / phase 0 remainder (P8): the Sell Stock moments that had no domain event, the dead MandateSigned
 * wired to its real raise site, the OTP document type, and the three phase 0 defects.
 *
 *   seller link made / opened   SellerLinkCreated, SellerLinkOpened
 *   viewing pack made           ViewingPackCreated
 *   Core Match (wishlist) made  ContactMatchCreated
 *   viewing held                ViewingHeld
 *   OTP e-signed / on paper     OtpSigned, DocumentFiledToProperty (type otp)
 *   mandate e-signed            MandateSigned
 *   commission paid             deal.commission_finalised credited to the agents on the deal
 *   lost presentation           presentation.lost credited
 *   VisibleAgents               a role with no stored breadth sees its own row (same as Scope::level)
 *
 * Per event: it fires, the lookalikes that must NOT fire it, a repeat, another agency's record, a listener that blows up (the
 * user's action still stands), and the domain_event_log row.
 */
final class RoadToSoldPhase0RemainderEventsTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Agency $other;
    private Branch $otherBranch;
    private User $otherAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-23 09:00:00');

        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . Str::random(6)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Sea Point']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'super_admin', 'is_active' => true,
        ]);

        $this->other       = Agency::create(['name' => 'Durban Homes', 'slug' => 'dbn-' . Str::random(6)]);
        $this->otherBranch = Branch::create(['agency_id' => $this->other->id, 'name' => 'Umhlanga']);
        $this->otherAgent  = User::factory()->create([
            'agency_id' => $this->other->id, 'branch_id' => $this->otherBranch->id, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->actingAs($this->agent);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────

    /** BelongsToAgency stamps the ACTING user's agency on create, so another agency's records are made while one of its users acts. */
    private function asAgency(Agency $agency, callable $make): mixed
    {
        $this->actingAs($agency->is($this->other) ? $this->otherAgent : $this->agent);
        try {
            return $make();
        } finally {
            $this->actingAs($this->agent);
        }
    }

    /** Records the events of one class WITHOUT faking the dispatcher, so the audit log and the log-based "already raised" checks still run. */
    private function record(string $eventClass): \ArrayObject
    {
        $seen = new \ArrayObject();
        Event::listen($eventClass, function ($e) use ($seen) {
            $seen[] = $e;
        });

        return $seen;
    }

    private function property(?Agency $agency = null, ?Branch $branch = null, ?User $agent = null): Property
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;
        $agent  ??= $this->agent;

        return $this->asAgency($agency, fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing ' . Str::random(4), 'address' => '14 Marine Drive',
            'suburb' => 'Uvongo', 'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 1500000, 'beds' => 3, 'baths' => 2, 'garages' => 1, 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]));
    }

    private function contact(?Agency $agency = null, ?Branch $branch = null, ?User $agent = null, string $first = 'Pieter'): Contact
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;
        $agent  ??= $this->agent;

        return $this->asAgency($agency, fn () => Contact::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
            'first_name' => $first, 'last_name' => 'van der Merwe', 'phone' => '0825512345',
        ]));
    }

    private function logRows(string $eventClass): int
    {
        return DB::table('domain_event_log')->where('event_name', $eventClass)->count();
    }

    private function sellerLink(?User $madeBy = null, ?Property $property = null): PropertySellerLink
    {
        $property ??= $this->property();

        return PropertySellerLink::withoutGlobalScopes()->create([
            'agency_id' => $property->agency_id, 'property_id' => $property->id, 'contact_id' => $this->contact()->id,
            'token' => PropertySellerLink::generateToken(), 'generated_by_user_id' => $madeBy?->id, 'generated_at' => now(),
        ]);
    }

    // ── 1. seller link made ──────────────────────────────────────────────────────────────

    public function test_pressing_generate_raises_the_seller_link_event_once_with_the_facts_and_logs_it(): void
    {
        $property = $this->property();
        $contact = $this->contact();

        $this->postJson('/corex/properties/seller-links/generate', ['property_id' => $property->id, 'contact_id' => $contact->id])
            ->assertOk();

        $link = PropertySellerLink::withoutGlobalScopes()->where('property_id', $property->id)->firstOrFail();
        $this->assertSame(1, $this->logRows(SellerLinkCreated::class));
        $row = DB::table('domain_event_log')->where('event_name', SellerLinkCreated::class)->first();
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertSame($this->agent->id, (int) $row->actor_user_id);
        $this->assertSame($link->id, (int) $row->subject_id);
        $payload = json_decode($row->payload_snapshot, true);
        $this->assertSame($property->id, $payload['propertyId']);
        $this->assertSame($contact->id, $payload['contactId']);
        $this->assertSame($this->branch->id, $payload['branchId']);
        $this->assertSame($this->agent->id, $payload['userId']);
    }

    public function test_each_press_of_generate_is_its_own_seller_link_and_the_links_made_by_the_system_are_not(): void
    {
        $property = $this->property();
        $contact = $this->contact();
        Event::fake([SellerLinkCreated::class]);

        $this->postJson('/corex/properties/seller-links/generate', ['property_id' => $property->id, 'contact_id' => $contact->id])->assertOk();
        $this->postJson('/corex/properties/seller-links/generate', ['property_id' => $property->id, 'contact_id' => $contact->id])->assertOk();
        Event::assertDispatchedTimes(SellerLinkCreated::class, 2);   // the second press replaces the first link: a second real action

        // CoreX's own link for a linked seller (some pages make it just by being opened) is not the agent's work
        PropertySellerLink::ensureExists($this->property()->id, $this->contact()->id);
        Event::assertDispatchedTimes(SellerLinkCreated::class, 2);
    }

    public function test_a_link_for_another_agencys_property_raises_nothing(): void
    {
        $theirProperty = $this->property($this->other, $this->otherBranch, $this->otherAgent);
        Event::fake([SellerLinkCreated::class]);

        // the form's `exists` rule does not look at the agency, so the link itself still gets made - the event must not follow it
        $this->postJson('/corex/properties/seller-links/generate', ['property_id' => $theirProperty->id, 'contact_id' => $this->contact()->id]);

        Event::assertNotDispatched(SellerLinkCreated::class);
    }

    public function test_a_failing_listener_never_loses_the_seller_link(): void
    {
        Event::listen(SellerLinkCreated::class, function () {
            throw new \RuntimeException('listener exploded');
        });
        $property = $this->property();

        $this->postJson('/corex/properties/seller-links/generate', ['property_id' => $property->id, 'contact_id' => $this->contact()->id])
            ->assertOk()->assertJsonStructure(['url', 'link_id']);
    }

    // ── 2. seller link opened ────────────────────────────────────────────────────────────

    private function open(PropertySellerLink $link, string $userAgent = self::BROWSER)
    {
        return $this->withHeader('User-Agent', $userAgent)->get('/property/live/' . $link->token);
    }

    public function test_the_seller_opening_the_link_raises_the_event_once_and_a_second_visit_does_not(): void
    {
        $link = $this->sellerLink($this->agent);
        $seen = $this->record(SellerLinkOpened::class);

        $this->open($link)->assertOk();
        $this->open($link)->assertOk();
        $this->open($link)->assertOk();

        $this->assertCount(1, $seen);
        $e = $seen[0];
        $this->assertSame($link->id, $e->linkId);
        $this->assertSame($link->property_id, $e->propertyId);
        $this->assertSame($this->agency->id, $e->agencyId);
        $this->assertSame($this->branch->id, $e->branchId);
        $this->assertSame($this->agent->id, $e->userId);
        $this->assertSame(1, $this->logRows(SellerLinkOpened::class), 'one domain_event_log row');
    }

    public function test_a_link_preview_robot_is_not_the_seller_and_the_real_open_after_it_still_counts(): void
    {
        $link = $this->sellerLink($this->agent);
        $seen = $this->record(SellerLinkOpened::class);

        $this->open($link, 'WhatsApp/2.23.20.0 A')->assertOk();
        $this->open($link, 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)')->assertOk();
        $this->open($link, '')->assertOk();
        $this->assertCount(0, $seen);

        $this->open($link)->assertOk();
        $this->assertCount(1, $seen);
    }

    public function test_a_link_made_by_the_system_credits_the_properties_agent_and_a_revoked_link_raises_nothing(): void
    {
        $property = $this->property();
        $made = $this->sellerLink(null, $property);   // no generated_by (made by CoreX when the seller was linked)
        $revoked = $this->sellerLink($this->agent);
        $revoked->update(['revoked_at' => now()]);
        $seen = $this->record(SellerLinkOpened::class);

        $this->open($revoked);
        $this->assertCount(0, $seen);

        $this->open($made)->assertOk();
        $this->assertCount(1, $seen);
        $this->assertSame($made->id, $seen[0]->linkId);
        $this->assertSame($this->agent->id, $seen[0]->userId);
    }

    public function test_a_link_whose_property_belongs_to_another_agency_raises_nothing(): void
    {
        $theirs = $this->property($this->other, $this->otherBranch, $this->otherAgent);
        $link = $this->sellerLink($this->agent, $theirs);   // PropertySellerLink is stamped Cape by the acting Cape user
        $seen = $this->record(SellerLinkOpened::class);

        $this->open($link);

        $this->assertCount(0, $seen);
    }

    public function test_a_failing_listener_never_stops_the_seller_seeing_their_page(): void
    {
        Event::listen(SellerLinkOpened::class, function () {
            throw new \RuntimeException('listener exploded');
        });

        $this->open($this->sellerLink($this->agent))->assertOk();
    }

    // ── 3. viewing pack made ─────────────────────────────────────────────────────────────

    private function pack(?User $agent = null, ?Agency $agency = null, ?Branch $branch = null): ViewingPack
    {
        $agency ??= $this->agency;
        $branch ??= $this->branch;
        $agent  ??= $this->agent;

        $contact = $this->contact($agency, $branch, $agent);

        return $this->asAgency($agency, fn () => ViewingPack::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'agent_id' => $agent->id, 'status' => ViewingPack::STATUS_DRAFT, 'title' => 'Tour: Uvongo',
        ]));
    }

    public function test_making_a_viewing_pack_raises_one_event_with_the_facts_and_logs_it(): void
    {
        $pack = $this->pack();

        $this->assertSame(1, $this->logRows(ViewingPackCreated::class));
        $row = DB::table('domain_event_log')->where('event_name', ViewingPackCreated::class)->first();
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertSame($pack->id, (int) $row->subject_id);
        $payload = json_decode($row->payload_snapshot, true);
        $this->assertSame($this->agent->id, $payload['userId']);
        $this->assertSame($this->branch->id, $payload['branchId']);
        $this->assertSame($pack->contact_id, $payload['contactId']);
    }

    public function test_changing_archiving_or_restoring_a_pack_is_not_a_new_pack(): void
    {
        $pack = $this->pack();
        Event::fake([ViewingPackCreated::class]);

        $pack->update(['status' => ViewingPack::STATUS_READY, 'title' => 'Tour: Uvongo (final)']);
        $pack->delete();
        $pack->restore();

        Event::assertNotDispatched(ViewingPackCreated::class);
    }

    public function test_a_pack_carries_its_own_agency_and_a_failing_listener_never_loses_the_pack(): void
    {
        Event::listen(ViewingPackCreated::class, function () {
            throw new \RuntimeException('listener exploded');
        });

        $mine = $this->pack();
        $theirs = $this->pack($this->otherAgent, $this->other, $this->otherBranch);

        $this->assertDatabaseHas('viewing_packs', ['id' => $mine->id]);
        $this->assertDatabaseHas('viewing_packs', ['id' => $theirs->id]);
        $this->assertSame($this->other->id, (int) $theirs->agency_id);
    }

    public function test_the_pack_event_names_the_packs_own_agency_never_the_acting_users(): void
    {
        Event::fake([ViewingPackCreated::class]);

        $theirs = $this->pack($this->otherAgent, $this->other, $this->otherBranch);   // made while the Cape agent is logged in

        Event::assertDispatched(ViewingPackCreated::class, fn ($e) => $e->packId === $theirs->id
            && $e->agencyId === $this->other->id && $e->userId === $this->otherAgent->id);
    }

    // ── 4. Core Match made ───────────────────────────────────────────────────────────────

    private function match(Contact $contact, array $criteria = ['price_min' => 1_000_000, 'price_max' => 2_000_000, 'beds_min' => 2]): ContactMatch
    {
        return ContactMatch::create(array_merge([
            'agency_id' => $contact->agency_id, 'contact_id' => $contact->id, 'created_by_user_id' => $this->agent->id,
            'agent_id' => $contact->agent_id, 'name' => 'Wishlist', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
        ], $criteria));   // made by the acting (Cape) user: its agency is stamped, whoever the buyer belongs to
    }

    public function test_a_buyers_new_wishlist_raises_the_core_match_event_once_and_logs_it(): void
    {
        $buyer = $this->contact();
        Event::fake([ContactMatchCreated::class]);

        $match = $this->match($buyer);
        $match->update(['price_max' => 2_500_000, 'notes' => 'Wants a sea view']);   // an edit is not a new match

        Event::assertDispatchedTimes(ContactMatchCreated::class, 1);
        Event::assertDispatched(ContactMatchCreated::class, fn ($e) => $e->matchId === $match->id && $e->contactId === $buyer->id
            && $e->agencyId === $this->agency->id && $e->userId === $this->agent->id && $e->actorUserId === $this->agent->id);
    }

    public function test_the_core_match_event_is_in_the_event_log(): void
    {
        $this->match($this->contact());

        $this->assertSame(1, $this->logRows(ContactMatchCreated::class));
    }

    public function test_an_empty_wishlist_is_not_a_captured_buyer_and_raises_nothing(): void
    {
        Event::fake([ContactMatchCreated::class]);

        $this->match($this->contact(), [
            'price_min' => null, 'price_max' => null, 'beds_min' => null, 'baths_min' => null, 'garages_min' => null,
            'property_types' => null, 'notes' => 'Phoned, no details yet',
        ]);

        Event::assertNotDispatched(ContactMatchCreated::class);
    }

    public function test_a_wishlist_seeded_from_a_portal_lead_has_no_actor_and_names_the_buyers_agent(): void
    {
        $buyer = $this->contact();
        Event::fake([ContactMatchCreated::class]);

        $this->app['auth']->forgetGuards();   // the P24 poller runs with nobody logged in
        $match = $this->match($buyer);

        Event::assertDispatched(ContactMatchCreated::class, fn ($e) => $e->matchId === $match->id && $e->actorUserId === null && $e->userId === $this->agent->id);
    }

    public function test_a_match_whose_buyer_belongs_to_another_agency_raises_nothing(): void
    {
        $theirBuyer = $this->contact($this->other, $this->otherBranch, $this->otherAgent);
        Event::fake([ContactMatchCreated::class]);

        $this->match($theirBuyer);   // stamped with the Cape agency by the acting Cape user: a mismatched pair

        Event::assertNotDispatched(ContactMatchCreated::class);
    }

    public function test_a_failing_listener_never_loses_the_wishlist(): void
    {
        Event::listen(ContactMatchCreated::class, function () {
            throw new \RuntimeException('listener exploded');
        });

        $match = $this->match($this->contact());

        $this->assertDatabaseHas('contact_matches', ['id' => $match->id]);
    }

    // ── 5. viewing held ──────────────────────────────────────────────────────────────────

    private function appointment(string $category = 'viewing'): CalendarEvent
    {
        return CalendarEvent::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'user_id' => $this->agent->id,
            'category' => $category, 'event_type' => $category, 'title' => 'Viewing: 14 Marine Drive',
            'event_date' => '2026-10-22 10:00:00', 'start_at' => '2026-10-22 10:00:00', 'end_at' => '2026-10-22 11:00:00',
        ]);
    }

    private function viewingFeedback(CalendarEvent $event, ?Property $property = null, array $over = []): CalendarEventFeedback
    {
        return CalendarEventFeedback::create(array_merge([
            'calendar_event_id' => $event->id, 'contact_id' => $this->contact(first: 'Buyer')->id,
            'property_id' => ($property ?? $this->property())->id, 'feedback_kind' => 'viewing', 'visibility' => 'public_to_seller',
            'viewing_status' => 'viewed', 'seller_visible_notes' => 'Loved the kitchen', 'agency_id' => $this->agency->id,
            'captured_by_user_id' => $this->agent->id, 'captured_at' => now(),
        ], $over));
    }

    public function test_feedback_that_says_the_buyer_viewed_the_property_raises_viewing_held_once(): void
    {
        $event = $this->appointment();
        $property = $this->property();
        Event::fake([ViewingHeld::class]);

        $fb = $this->viewingFeedback($event, $property);
        $fb->update(['internal_notes' => 'Wants a second look']);   // an edit of a row that already counts
        $fb->update(['seller_visible_notes' => 'Loved the kitchen and the garden']);

        Event::assertDispatchedTimes(ViewingHeld::class, 1);
        Event::assertDispatched(ViewingHeld::class, fn ($e) => $e->feedbackId === $fb->id && $e->calendarEventId === $event->id
            && $e->propertyId === $property->id && $e->contactId === $fb->contact_id && $e->agencyId === $this->agency->id
            && $e->branchId === $this->branch->id && $e->userId === $this->agent->id && $e->actorUserId === $this->agent->id);
    }

    public function test_a_tour_of_two_houses_is_two_viewings_held_and_the_event_is_logged(): void
    {
        $event = $this->appointment();
        $buyer = $this->contact(first: 'Annelie');

        $this->viewingFeedback($event, null, ['contact_id' => $buyer->id]);
        $this->viewingFeedback($event, null, ['contact_id' => $buyer->id]);

        $this->assertSame(2, $this->logRows(ViewingHeld::class));
    }

    public function test_a_viewing_that_did_not_happen_a_blank_row_or_an_uncaptured_one_is_not_held_and_counts_when_it_becomes_so(): void
    {
        $event = $this->appointment();
        Event::fake([ViewingHeld::class]);

        $noShow   = $this->viewingFeedback($event, null, ['viewing_status' => 'did_not_happen']);
        $declined = $this->viewingFeedback($event, null, ['viewing_status' => 'declined_on_arrival']);
        $blank    = $this->viewingFeedback($event, null, ['seller_visible_notes' => null]);
        $draft    = $this->viewingFeedback($event, null, ['captured_at' => null]);
        Event::assertNotDispatched(ViewingHeld::class);

        $draft->update(['captured_at' => now()]);                        // captured now
        Event::assertDispatchedTimes(ViewingHeld::class, 1);
        $noShow->update(['viewing_status' => 'viewed']);                 // the agent corrects it: it did happen
        Event::assertDispatchedTimes(ViewingHeld::class, 2);
        $blank->update(['seller_visible_notes' => 'Buyer liked the position']);
        Event::assertDispatchedTimes(ViewingHeld::class, 3);
        $declined->update(['internal_notes' => 'Still declined']);       // still not a viewing
        Event::assertDispatchedTimes(ViewingHeld::class, 3);
    }

    public function test_feedback_on_an_evaluation_or_an_archived_row_is_not_a_viewing_held(): void
    {
        Event::fake([ViewingHeld::class]);

        $this->viewingFeedback($this->appointment('property_evaluation'));
        $archived = $this->viewingFeedback($this->appointment(), null, ['viewing_status' => 'did_not_happen']);
        $archived->delete();                                  // archived (soft-deleted)
        $archived->update(['viewing_status' => 'viewed']);   // even if it is then set to viewed while archived

        Event::assertNotDispatched(ViewingHeld::class);
    }

    public function test_an_archived_row_that_is_restored_as_viewed_counts_once(): void
    {
        $fb = $this->viewingFeedback($this->appointment(), null, ['viewing_status' => 'did_not_happen']);
        $fb->delete();
        $fb->update(['viewing_status' => 'viewed']);
        Event::fake([ViewingHeld::class]);

        $fb->restore();

        Event::assertDispatchedTimes(ViewingHeld::class, 1);
    }

    public function test_viewing_held_never_crosses_agencies_and_a_failing_listener_never_loses_the_feedback(): void
    {
        $event = $this->appointment();
        $seen = $this->record(ViewingHeld::class);
        $property = $this->property();
        $buyer = $this->contact();
        // another agency's feedback on this appointment (made while that agency's user acts, so it is stamped with their agency)
        $this->asAgency($this->other, fn () => CalendarEventFeedback::create([
            'calendar_event_id' => $event->id, 'contact_id' => $buyer->id, 'property_id' => $property->id, 'feedback_kind' => 'viewing',
            'viewing_status' => 'viewed', 'seller_visible_notes' => 'Loved it', 'captured_by_user_id' => $this->otherAgent->id, 'captured_at' => now(),
        ]));
        $this->assertCount(0, $seen);

        Event::listen(ViewingHeld::class, function () {
            throw new \RuntimeException('listener exploded');
        });
        $fb = $this->viewingFeedback($event);
        $this->assertDatabaseHas('calendar_event_feedback', ['id' => $fb->id]);
    }

    // ── 6. OTP signed (e-sign) ───────────────────────────────────────────────────────────

    /** @return array{0:SignatureTemplate,1:EsignDocument} */
    private function envelope(string $documentType, ?Agency $agency = null, ?User $sender = null, ?Property $property = null): array
    {
        $agency ??= $this->agency;
        $sender ??= $this->agent;

        return $this->asAgency($agency, function () use ($documentType, $agency, $sender, $property) {
            $document = EsignDocument::create([
                'name' => 'Offer to purchase', 'document_type' => $documentType, 'owner_id' => $sender->id, 'agency_id' => $agency->id,
                'branch_id' => $sender->branch_id, 'property_id' => $property?->id, 'web_template_data' => ['merged_html' => '<p>x</p>'],
            ]);
            $envelope = SignatureTemplate::create([
                'agency_id' => $agency->id, 'document_id' => $document->id, 'document_hash' => Str::random(64),
                'status' => SignatureTemplate::STATUS_COMPLETED, 'created_by' => $sender->id,
            ]);
            SignatureRequest::create([
                'signature_template_id' => $envelope->id, 'party_role' => 'agent', 'role_index' => 1, 'signing_order' => 1,
                'signer_name' => 'Agent', 'signer_email' => 'agent@example.co.za', 'token' => Str::random(48),
                'token_expires_at' => now()->addDays(14), 'status' => SignatureRequest::STATUS_PENDING, 'sent_by' => $sender->id,
            ]);

            return [$envelope, $document];
        });
    }

    public function test_a_finalised_otp_envelope_raises_otp_signed_once_even_if_finalisation_is_recorded_again(): void
    {
        $property = $this->property();
        [$envelope, $document] = $this->envelope('otp', property: $property);

        app(SignatureService::class)->recordFinalizationSucceeded($envelope);
        app(SignatureService::class)->recordFinalizationSucceeded($envelope->fresh());   // a retried finalise job

        $this->assertSame(1, $this->logRows(OtpSigned::class));
        $row = DB::table('domain_event_log')->where('event_name', OtpSigned::class)->first();
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertSame($envelope->id, (int) $row->subject_id);
        $payload = json_decode($row->payload_snapshot, true);
        $this->assertSame($document->id, $payload['documentId']);
        $this->assertSame($property->id, $payload['propertyId']);
        $this->assertSame($this->agent->id, $payload['userId']);
        $this->assertSame($this->branch->id, $payload['branchId']);
    }

    public function test_an_otp_is_recognised_by_its_template_document_type_too(): void
    {
        $type = DocumentType::firstOrCreate(['slug' => 'otp'], ['label' => 'OTP (Offer to Purchase)', 'is_active' => true]);
        [$envelope, $document] = $this->envelope('agreement');   // the plain type word says nothing; the template's type does
        $template = \App\Models\Docuperfect\Template::withoutGlobalScopes()->create([
            'name' => 'Standard OTP', 'document_type_id' => $type->id, 'agency_id' => $this->agency->id,
        ]);
        $document->forceFill(['template_id' => $template->id])->save();
        Event::fake([OtpSigned::class]);

        app(SignatureService::class)->recordFinalizationSucceeded($envelope);

        Event::assertDispatchedTimes(OtpSigned::class, 1);
    }

    public function test_a_mandate_a_lease_or_a_failed_finalisation_is_not_an_otp_signed(): void
    {
        Event::fake([OtpSigned::class]);

        [$mandate] = $this->envelope('mandate');
        [$lease] = $this->envelope('agreement');
        [$otp] = $this->envelope('otp');
        app(SignatureService::class)->recordFinalizationSucceeded($mandate);
        app(SignatureService::class)->recordFinalizationSucceeded($lease);
        app(SignatureService::class)->recordFinalizationFailed($otp, 'disk full');

        Event::assertNotDispatched(OtpSigned::class);
    }

    public function test_an_otp_event_names_the_envelopes_own_agency_and_a_failing_listener_never_disturbs_the_signing(): void
    {
        Event::listen(OtpSigned::class, function () {
            throw new \RuntimeException('listener exploded');
        });
        [$theirs] = $this->envelope('otp', $this->other, $this->otherAgent);

        app(SignatureService::class)->recordFinalizationSucceeded($theirs);

        $this->assertSame(SignatureTemplate::FINALIZATION_SUCCEEDED, $theirs->fresh()->finalization_status);
    }

    public function test_the_otp_event_carries_the_agency_of_the_envelope_not_of_the_user_who_happened_to_be_logged_in(): void
    {
        [$theirs] = $this->envelope('otp', $this->other, $this->otherAgent);
        Event::fake([OtpSigned::class]);

        $this->app->make(\App\Listeners\RoadToSold\RaiseOtpSignedFromEnvelope::class)
            ->handle(new SignatureEnvelopeFinalized($theirs->id, $theirs->document_id, $this->other->id));

        Event::assertDispatched(OtpSigned::class, fn ($e) => $e->agencyId === $this->other->id && $e->userId === $this->otherAgent->id);
    }

    // ── 7. OTP on paper ──────────────────────────────────────────────────────────────────

    private function typedDocument(string $slug, ?Agency $agency = null, array $over = []): Document
    {
        $agency ??= $this->agency;
        $type = DocumentType::firstOrCreate(['slug' => $slug], ['label' => ucfirst($slug), 'is_active' => true]);

        return $this->asAgency($agency, fn () => Document::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $this->branch->id, 'original_name' => 'OTP - Merwe.pdf', 'storage_path' => 'x/' . Str::random(6) . '.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1234, 'document_type_id' => $type->id,
            'source_type' => 'upload', 'uploaded_by' => $this->agent->id,
        ], $over)));
    }

    public function test_a_paper_otp_uploaded_onto_a_property_raises_the_filed_event_with_its_branch_and_deal(): void
    {
        $property = $this->property();
        Event::fake([DocumentFiledToProperty::class]);

        $doc = $this->typedDocument('otp');
        $doc->properties()->attach($property->id);

        Event::assertDispatchedTimes(DocumentFiledToProperty::class, 1);
        Event::assertDispatched(DocumentFiledToProperty::class, fn ($e) => $e->documentId === $doc->id && $e->documentTypeSlug === 'otp'
            && $e->propertyId === $property->id && $e->agencyId === $this->agency->id && $e->branchId === $this->branch->id && $e->dealId === null);
    }

    public function test_the_mandate_still_counts_and_a_file_of_another_agency_never_ticks_this_agencys_property(): void
    {
        $property = $this->property();
        $theirs = $this->property($this->other, $this->otherBranch, $this->otherAgent);
        Event::fake([DocumentFiledToProperty::class]);

        $this->typedDocument('mandate')->properties()->attach($property->id);
        $this->typedDocument('otp', $this->other)->properties()->attach($property->id);   // another agency's file on this agency's property
        $this->typedDocument('otp')->properties()->attach($theirs->id);                    // this agency's file on another agency's property

        Event::assertDispatchedTimes(DocumentFiledToProperty::class, 1);
        Event::assertDispatched(DocumentFiledToProperty::class, fn ($e) => $e->documentTypeSlug === 'mandate');
    }

    // ── 8. MandateSigned (the dead event, wired) ─────────────────────────────────────────

    public function test_completing_a_mandate_signing_raises_mandate_signed_once_with_ids_only_in_the_log(): void
    {
        $property = $this->property();
        [$envelope, $document] = $this->envelope('mandate', property: $property);
        $envelope->forceFill(['status' => SignatureTemplate::STATUS_SIGNING])->save();
        Event::fake([MandateSigned::class]);

        app(SignatureService::class)->completeDocument($envelope->fresh());

        Event::assertDispatchedTimes(MandateSigned::class, 1);
        Event::assertDispatched(MandateSigned::class, fn ($e) => $e->mandate->getKey() === $document->id && $e->property?->getKey() === $property->id
            && $e->agencyId() === $this->agency->id && $e->userId === $this->agent->id && $e->branchId === $this->branch->id);
    }

    public function test_a_second_call_for_an_already_completed_mandate_raises_nothing_and_the_log_row_is_small(): void
    {
        [$envelope] = $this->envelope('mandate', property: $this->property());
        $envelope->forceFill(['status' => 'signing'])->save();

        app(SignatureService::class)->completeDocument($envelope->fresh());
        app(SignatureService::class)->completeDocument($envelope->fresh());   // already completed: a repeat

        $this->assertSame(1, $this->logRows(MandateSigned::class));
        $row = DB::table('domain_event_log')->where('event_name', MandateSigned::class)->first();
        $this->assertLessThan(1000, strlen((string) $row->payload_snapshot), 'ids only - never the whole signed document');
    }

    public function test_completing_an_otp_or_a_lease_is_not_a_mandate_signed(): void
    {
        Event::fake([MandateSigned::class]);
        [$otp] = $this->envelope('otp');
        $otp->forceFill(['status' => 'signing'])->save();

        app(SignatureService::class)->completeDocument($otp->fresh());

        Event::assertNotDispatched(MandateSigned::class);
    }

    // ── 9. deal.commission_finalised points ──────────────────────────────────────────────

    private function deal(): Deal
    {
        static $seq = 9000;
        $deal = Deal::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'deal_no' => $seq++, 'period' => '2026-10',
            'deal_date' => '2026-10-10', 'accepted_status' => 'R', 'commission_status' => 'Not Paid',
            'property_address' => '1 Marine Dr', 'seller_name' => 'Sam Seller', 'buyer_name' => 'Bea Buyer',
            'property_value' => 1_000_000, 'total_commission' => 57_500, 'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);

        return $deal;
    }

    /**
     * The REAL points engine, switched on: InstantPointService skips everything when the app environment is "testing", so the
     * environment is flipped for the test and the standard instant-action catalogue is seeded (as a deploy does).
     */
    private function pointsOn(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        (new \Database\Seeders\ActivityInstantActionsSeeder())->run();
    }

    /** @return list<int> the users with a points row for this slug on this subject, for this agency */
    private function creditedUsers(string $slug, string $subjectClass, int $subjectId): array
    {
        $defId = DB::table('activity_definition_calendar_classes')->where('agency_id', $this->agency->id)
            ->where('trigger_kind', 'instant')->where('slug', $slug)->value('activity_definition_id');

        return DB::table('daily_activity_entries')->where('activity_definition_id', $defId)
            ->where('subject_type', $subjectClass)->where('subject_id', $subjectId)->where('point_state', '!=', 'revoked')->orderBy('user_id')->pluck('user_id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_marking_commission_paid_credits_each_agent_on_the_deal_once_and_not_the_person_who_pressed(): void
    {
        $this->pointsOn();
        $listing = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $selling = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $deal = $this->deal();
        $deal->agents()->attach($listing->id, ['side' => 'listing']);
        $deal->agents()->attach($selling->id, ['side' => 'selling']);
        $deal->agents()->attach($listing->id, ['side' => 'selling']);   // the same agent on both sides: still one credit

        $deal->update(['commission_status' => 'Paid']);
        $deal->update(['total_commission' => 60_000]);   // an unrelated later save
        $deal->update(['commission_status' => 'Paid']);   // saved as paid again: no change, no second credit

        $this->assertSame([$listing->id, $selling->id], $this->creditedUsers('deal.commission_finalised', Deal::class, $deal->id));
        $this->assertNotContains($this->agent->id, $this->creditedUsers('deal.commission_finalised', Deal::class, $deal->id), 'the office user who marked it paid earns nothing');
    }

    public function test_a_deal_with_no_agents_credits_nobody_and_marking_paid_still_works(): void
    {
        $this->pointsOn();
        $deal = $this->deal();

        $deal->update(['commission_status' => 'Paid']);

        $this->assertSame('Paid', $deal->fresh()->commission_status);
        $this->assertSame([], $this->creditedUsers('deal.commission_finalised', Deal::class, $deal->id));
    }

    public function test_archiving_a_deal_takes_its_commission_points_back_with_the_other_deal_points(): void
    {
        $this->pointsOn();
        $listing = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $deal = $this->deal();
        $deal->agents()->attach($listing->id, ['side' => 'listing']);
        $deal->update(['commission_status' => 'Paid']);
        $this->assertSame([$listing->id], $this->creditedUsers('deal.commission_finalised', Deal::class, $deal->id));

        $deal->delete();

        $this->assertSame([], $this->creditedUsers('deal.commission_finalised', Deal::class, $deal->id));
    }

    public function test_the_commission_event_fires_once_when_paid_and_not_again_on_a_later_save(): void
    {
        $deal = $this->deal();
        Event::fake([DealCommissionFinalised::class]);

        $deal->update(['commission_status' => 'Paid']);
        $deal->update(['commission_status' => 'Paid', 'total_commission' => 61_000]);

        Event::assertDispatchedTimes(DealCommissionFinalised::class, 1);
    }

    // ── 10. presentation.lost ────────────────────────────────────────────────────────────

    public function test_a_lost_presentation_is_credited_like_a_won_one_and_pending_or_other_is_not(): void
    {
        $this->pointsOn();
        $listener = app(\App\Listeners\Activity\CreditInstantActionListener::class);
        $presentations = [];

        foreach (['won_mandate', 'won_sale', 'lost_to_competitor', 'lost_to_no_decision', 'lost_to_price_dispute', 'lost_to_no_response', 'still_pending', 'other'] as $outcome) {
            $p = $this->asAgency($this->agency, fn () => \App\Models\Presentation::withoutEvents(fn () => \App\Models\Presentation::create([
                'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'created_by_user_id' => $this->agent->id,
                'title' => $outcome, 'property_address' => '14 Marine Drive', 'status' => 'draft',
            ])));
            $presentations[$outcome] = $p->id;
            $listener->handlePresentationOutcomeRecorded(new PresentationOutcomeRecorded(1, $p->id, $outcome, null, null, null, $this->agency->id, $this->agent->id));
        }

        $credited = fn (string $slug, string $outcome) => $this->creditedUsers($slug, \App\Models\Presentation::class, $presentations[$outcome]);
        $this->assertSame([$this->agent->id], $credited('presentation.won', 'won_mandate'));
        $this->assertSame([$this->agent->id], $credited('presentation.won', 'won_sale'));
        foreach (['lost_to_competitor', 'lost_to_no_decision', 'lost_to_price_dispute', 'lost_to_no_response'] as $lost) {
            $this->assertSame([$this->agent->id], $credited('presentation.lost', $lost), $lost);
            $this->assertSame([], $credited('presentation.won', $lost), "$lost is not a win");
        }
        foreach (['still_pending', 'other'] as $open) {
            $this->assertSame([], $credited('presentation.won', $open));
            $this->assertSame([], $credited('presentation.lost', $open));
        }

        // recording the same lost outcome again the same day does not double the points
        $listener->handlePresentationOutcomeRecorded(new PresentationOutcomeRecorded(1, $presentations['lost_to_competitor'], 'lost_to_competitor', null, null, null, $this->agency->id, $this->agent->id));
        $this->assertSame([$this->agent->id], $credited('presentation.lost', 'lost_to_competitor'));
    }

    // ── 11. VisibleAgents / Scope::level ─────────────────────────────────────────────────

    public function test_a_role_with_no_stored_breadth_sees_its_own_row_in_the_agent_lists_like_the_page_does(): void
    {
        $mate = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $me   = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        // a grants table that exists, with no `road_to_sold.view` breadth row for the agent role
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'access_contacts', 'agency_id' => $this->agency->id]);
        \App\Services\PermissionService::clearCache();

        $this->assertSame('own', Scope::level($me), 'the page falls back to own');
        $visible = VisibleAgents::for($me);

        $this->assertSame([$me->id], $visible->keys()->all(), 'the Areas / Targets / Budgets cohort agrees: own, not empty and not everyone');
        $this->assertFalse(VisibleAgents::canSee($me, $mate->id));
        $this->assertTrue(VisibleAgents::canSee($me, $me->id));
    }

    public function test_a_stored_branch_or_all_breadth_still_widens_the_cohort_and_never_crosses_the_agency(): void
    {
        $mate = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'branch_manager', 'is_active' => true]);
        $peer = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        \App\Models\RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        \App\Models\RolePermission::create(['role' => 'agent', 'permission_key' => 'access_road_to_sold', 'agency_id' => $this->agency->id]);
        \App\Models\RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'road_to_sold.view', 'agency_id' => $this->agency->id, 'scope' => 'branch']);
        \App\Services\PermissionService::clearCache();

        $visible = VisibleAgents::for($mate);

        $this->assertEqualsCanonicalizing([$mate->id, $peer->id], $visible->keys()->all());
        $this->assertFalse($visible->has($this->otherAgent->id), 'another agency is never visible');
        $this->assertFalse(VisibleAgents::canSee($mate, $this->otherAgent->id));
    }
}
