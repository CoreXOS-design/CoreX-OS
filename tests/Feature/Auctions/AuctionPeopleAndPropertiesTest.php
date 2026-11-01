<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AgencyFeature;
use App\Models\AuctionSellerSubmission;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionContactResolver;
use App\Services\Auctions\AuctionSellerSubmissionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/** New people go to an admin and are never duplicated; the seller questionnaire makes a Property under "Auction enquiries" (.ai/specs/auctions.md §23). */
final class AuctionPeopleAndPropertiesTest extends AuctionsV2TestCase
{
    private function base(): string
    {
        return '/auctions/' . $this->agency->slug;
    }

    private function asGuest(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
        Cache::flush();
    }

    private function answers(array $over = []): array
    {
        return array_merge([
            'seller_type' => 'individual', 'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'phone' => '0821234567', 'preferred_contact' => 'phone',
            'property_type' => 'house', 'address' => '12 Ocean Drive', 'suburb' => 'Ramsgate', 'erf_size' => 800, 'floor_size' => 210, 'bedrooms' => 3, 'bathrooms' => 2,
            'features' => ['double_garage'], 'occupancy' => 'owner', 'condition' => 'good', 'has_bond' => false, 'price_expectation' => 2450000,
            'auction_format' => 'online', 'reserve' => 'confidential', 'timing' => '1_3_months', 'viewing' => 'appointment', 'documents' => [],
            'consent_terms' => true, 'consent_authorised' => true, 'consent_processing' => true, 'marketing_opt_in' => false,
        ], $over);
    }

    private function signUp(string $email, string $name = 'Thandi Nkosi'): void
    {
        Mail::fake();
        $this->asGuest();
        $this->post("{$this->base()}/signup", ['email' => $email])->assertRedirect();
        $code = Mail::sent(\App\Mail\ClientAuthOtpMail::class)->last()->code;
        $this->post("{$this->base()}/signup/verify", ['code' => $code, 'name' => $name, 'phone' => '0829998888', 'password' => 'a-strong-pass-1', 'password_confirmation' => 'a-strong-pass-1', 'popia_consent' => '1'])->assertSessionHasNoErrors();
    }

    // ── contacts ───────────────────────────────────────────────────────────

    public function test_a_new_buyer_sign_up_becomes_a_contact_assigned_to_the_administrator(): void
    {
        $second = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin', 'branch_id' => $this->branch->id]);
        $this->signUp('thandi@example.com');

        $c = Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'thandi@example.com')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $c->agent_id, 'the first active administrator owns them');
        $this->assertSame($this->branch->id, (int) $c->branch_id);
        $this->assertNotNull($c->client_user_id);
    }

    public function test_someone_corex_already_knows_is_not_duplicated_when_they_sign_up_for_auctions(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent', 'branch_id' => $this->branch->id]);
        $known = Contact::withoutAgencyStamping(fn () => Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi', 'email' => 'thandi@example.com', 'agent_id' => $agent->id, 'branch_id' => $this->branch->id]));

        $this->signUp('thandi@example.com');

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'thandi@example.com')->count(), 'still one Thandi');
        $known->refresh();
        $this->assertSame($agent->id, (int) $known->agent_id, "an agent's own contact stays with that agent");
        $this->assertNotNull($known->client_user_id, 'their auction account is linked to the contact they already were');
    }

    public function test_a_known_contact_with_no_owner_is_given_to_the_administrator_not_duplicated(): void
    {
        $known = Contact::withoutAgencyStamping(fn () => Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Lerato', 'last_name' => 'Mokoena', 'email' => 'lerato@example.com', 'branch_id' => $this->branch->id]));

        [$contact, $existed] = app(AuctionContactResolver::class)->resolve($this->agency->id, 'Lerato Mokoena', 'LERATO@example.com', null);

        $this->assertTrue($existed);
        $this->assertSame($known->id, $contact->id);
        $this->assertSame($this->admin->id, (int) $known->fresh()->agent_id);
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'lerato@example.com')->count());
    }

    public function test_a_person_matched_by_phone_is_not_duplicated_either(): void
    {
        Contact::withoutAgencyStamping(fn () => Contact::create(['agency_id' => $this->agency->id, 'first_name' => 'Sipho', 'last_name' => 'D', 'phone' => '082 123 4567', 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id]));
        $sub = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(['email' => 'different@example.com', 'phone' => '0821234567']), '127.0.0.1');

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
        $this->assertNotNull($sub->contact_id);
    }

    public function test_the_same_person_signing_up_then_selling_is_one_contact(): void
    {
        $this->signUp('sipho@example.com', 'Sipho Dlamini');
        $this->asGuest();
        $this->postJson('/api/v1/auctions/public/sell/' . $this->agency->slug, $this->answers())->assertCreated();

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'sipho@example.com')->count());
    }

    public function test_a_seller_who_is_new_becomes_a_contact_assigned_to_the_administrator(): void
    {
        $sub = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(), '127.0.0.1');

        $contact = Contact::withoutGlobalScopes()->findOrFail($sub->contact_id);
        $this->assertSame($this->admin->id, (int) $contact->agent_id);
        $this->assertSame('Sipho', $contact->first_name);
    }

    // ── the property ───────────────────────────────────────────────────────

    public function test_the_questionnaire_creates_a_property_with_the_auction_enquiry_status_owned_by_the_seller(): void
    {
        $sub = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(), '127.0.0.1');

        $p = Property::withoutGlobalScopes()->findOrFail($sub->property_id);
        $this->assertSame('auction_enquiry', $p->status);
        $this->assertSame($this->agency->id, (int) $p->agency_id);
        $this->assertSame($this->admin->id, (int) $p->agent_id);
        $this->assertSame('3-bedroom house in Ramsgate', $p->title);
        $this->assertSame(['Ramsgate', 3, 2, 2, 'House'], [$p->suburb, (int) $p->beds, (int) $p->baths, (int) $p->garages, $p->property_type]);
        $this->assertSame([210, 800, 2450000.0, 'auction'], [(int) $p->size_m2, (int) $p->erf_size_m2, (float) $p->price, $p->sale_method]);
        $this->assertSame('12', (string) $p->street_number);
        $this->assertStringContainsString('Ocean Drive', (string) $p->street_name);

        $this->assertTrue(ContactProperty::query()->where('contact_id', $sub->contact_id)->where('property_id', $p->id)->where('role', 'owner')->exists(), 'the seller is the owner');
        $this->assertFalse($p->isOnMarket(), 'never on market, so never syndicated or counted as live stock');
        $this->assertSame('Auction enquiry', $p->statusBadge());
    }

    public function test_the_same_address_sent_twice_makes_one_property(): void
    {
        $a = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(), '127.0.0.1');
        $b = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(['name' => 'Sipho D', 'phone' => '0821234567']), '127.0.0.1');

        $this->assertSame($a->property_id, $b->property_id);
        $this->assertSame(1, Property::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('status', 'auction_enquiry')->count());
    }

    public function test_an_agency_with_no_administrator_still_keeps_the_submission(): void
    {
        User::query()->where('agency_id', $this->agency->id)->update(['role' => 'agent']);
        $sub = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(), '127.0.0.1');

        $this->assertNotNull($sub->id);
        $this->assertNull($sub->property_id, 'nobody to own it, so no property record');
        $this->assertNotNull($sub->tracked_property_id, 'the property is still tracked');
    }

    // ── the Properties page ────────────────────────────────────────────────

    public function test_the_auction_enquiries_tile_filter_and_rows_show_only_when_auctions_is_on(): void
    {
        app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(), '127.0.0.1');
        $normal = $this->property('Normal Listing');
        $this->actingAs($this->admin);

        // on: the tile counts it, the default list does not mix it in, the filter shows it
        $page = $this->get(route('corex.properties.index'))->assertOk();
        $this->assertStringContainsString('Auction enquiries', $page->getContent());
        $page->assertSee('Normal Listing')->assertDontSee('3-bedroom house in Ramsgate');
        $this->get(route('corex.properties.index', ['status' => 'auction_enquiry']))->assertOk()->assertSee('3-bedroom house in Ramsgate')->assertDontSee('Normal Listing');
        $this->get(route('corex.properties.index', ['status' => 'on_market']))->assertDontSee('3-bedroom house in Ramsgate');

        // off: no tile, no option, and the rows cannot be reached from the Properties page at all
        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
        $this->flushSession();   // the Properties page remembers the last filter and would redirect to it
        $this->actingAs($this->admin);
        $off = $this->get(route('corex.properties.index'))->assertOk();
        $this->assertStringNotContainsString('Auction enquiries', $off->getContent());
        $this->get(route('corex.properties.index', ['status' => 'auction_enquiry']))->assertOk()->assertDontSee('3-bedroom house in Ramsgate');
    }

    public function test_the_sidebar_has_an_auction_enquiries_link_under_properties_only_while_auctions_is_on(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('corex.auctions.index'))->assertOk()->assertSee('data-auction-enquiries-link', false)->assertSee('status=auction_enquiry', false);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);
        $this->flushSession();
        $this->actingAs($this->admin);
        $this->get(route('corex.properties.index'))->assertOk()->assertDontSee('data-auction-enquiries-link', false);
    }

    public function test_the_seller_lead_links_to_the_property_and_the_contact(): void
    {
        $sub = app(AuctionSellerSubmissionService::class)->capture($this->agency->id, $this->answers(), '127.0.0.1');
        $this->actingAs($this->admin);

        $this->get(route('corex.auctions.sellers.show', $sub->id))->assertOk()
            ->assertSee(route('corex.properties.show', $sub->property_id), false)
            ->assertSee(route('corex.contacts.show', $sub->contact_id), false);
    }
}
