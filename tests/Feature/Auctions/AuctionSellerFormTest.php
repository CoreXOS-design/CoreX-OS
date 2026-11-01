<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Events\Auction\AuctionSellerSubmitted;
use App\Mail\Auctions\AuctionSellerSubmissionMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionSellerSubmission;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Auctions\AuctionNavCounts;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/** "Sell at auction" - the seller questionnaire, its endpoint, what it creates, and the Seller leads screen (.ai/specs/auctions.md §22). */
final class AuctionSellerFormTest extends AuctionsV2TestCase
{
    private function home(): string
    {
        return '/auctions/' . $this->agency->slug;
    }

    private function endpoint(): string
    {
        return '/api/v1/auctions/public/sell/' . $this->agency->slug;
    }

    private function asGuest(): void
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
    }

    private function valid(array $over = []): array
    {
        return array_merge([
            'seller_type' => 'individual', 'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'phone' => '082 123 4567', 'preferred_contact' => 'whatsapp',
            'property_type' => 'house', 'address' => '12 Ocean Drive', 'suburb' => 'Ramsgate', 'erf_size' => 800, 'floor_size' => 210, 'bedrooms' => 3, 'bathrooms' => 2,
            'features' => ['pool', 'borehole'], 'occupancy' => 'owner', 'condition' => 'good', 'defects' => 'Damp in the garage.',
            'has_bond' => false, 'levies' => null, 'municipal_valuation' => 1800000, 'price_expectation' => 2450000,
            'auction_format' => 'online', 'reserve' => 'confidential', 'timing' => '1_3_months', 'viewing' => 'appointment', 'sale_reason' => 'Emigrating',
            'documents' => ['title_deed', 'rates_account'], 'referral_source' => 'A friend',
            'consent_terms' => true, 'consent_authorised' => true, 'consent_processing' => true, 'marketing_opt_in' => true,
        ], $over);
    }

    private function send(array $data)
    {
        $this->asGuest();
        \Illuminate\Support\Facades\Cache::flush();   // each call is a different visitor; the limit has its own test

        return $this->postJson($this->endpoint(), $data);
    }

    // ── on the public page ─────────────────────────────────────────────────

    public function test_the_home_page_has_the_section_the_banner_button_and_the_top_bar_link(): void
    {
        $this->asGuest();
        $html = $this->get($this->home())->assertOk()->getContent();

        $this->assertStringContainsString('id="questionnaire"', $html);
        $this->assertStringContainsString('Seller questionnaire', $html);
        $this->assertStringContainsString('Takes about 8 minutes', $html);
        $this->assertStringContainsString('Sell your property at auction', $html);
        $this->assertStringContainsString('data-sell-link', $html);
        $this->assertStringContainsString('Catalogue preview', str_replace('CATALOGUE PREVIEW', 'Catalogue preview', $html));
        foreach (['About you', 'The property', 'Occupancy', 'Bond & finances', 'Auction options', 'Documents', 'Terms & consent'] as $step) {
            $this->assertStringContainsString(str_replace('&', '&amp;', $step), $html, $step);
        }
        $this->assertStringContainsString('name="website"', $html, 'the honeypot');
        $this->assertStringContainsString('Questionnaire received', $html);
        $this->assertDoesNotMatchRegularExpression('/caprivi/i', strip_tags($html), 'another auction house is not on this agency\'s page');
        $this->assertStringContainsString('consent to Cape Auctions processing', $html, "the consent names THIS agency");
        $this->assertStringNotContainsString('data-preview-live', $html, 'on the real page the form really sends');
    }

    public function test_the_agency_can_switch_the_section_off_and_then_the_endpoint_is_gone(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'seller_form_enabled' => false]);
        $this->asGuest();

        $html = $this->get($this->home())->assertOk()->getContent();
        $this->assertStringNotContainsString('id="questionnaire"', $html);
        $this->assertStringNotContainsString('data-sell-link', $html);
        $this->assertStringNotContainsString('Sell your property at auction', $html);
        $this->send($this->valid())->assertNotFound();
        $this->assertSame(0, AuctionSellerSubmission::withoutGlobalScopes()->count());
    }

    public function test_the_thank_you_uses_the_agencys_own_reply_time(): void
    {
        $this->asGuest();
        $this->get($this->home())->assertSee('An auctioneer will contact you within one business day.');

        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'seller_response_text' => 'within 24 hours']);
        $this->get($this->home())->assertSee('An auctioneer will contact you within 24 hours.')->assertDontSee('within one business day');
    }

    public function test_in_the_preview_the_form_can_be_explored_but_not_sent(): void
    {
        $res = $this->get(route('corex.auctions.preview.frame') . '?path=/')->assertOk();
        $this->assertStringContainsString('data-preview-live', $res->getContent());
        $this->assertStringContainsString("form:not([data-preview-live])", $res->getContent(), 'the preview lets this one form work');
        $this->assertStringContainsString('Submitting is switched off in the preview', $res->getContent());
    }

    // ── the endpoint ───────────────────────────────────────────────────────

    public function test_a_valid_questionnaire_is_stored_with_the_key_columns_and_all_the_answers(): void
    {
        Event::fake([AuctionSellerSubmitted::class]);

        $this->send($this->valid())->assertCreated()->assertJsonPath('ok', true);

        $row = AuctionSellerSubmission::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->agency->id, (int) $row->agency_id);
        $this->assertSame('new', $row->status);
        $this->assertSame(['individual', 'Sipho Dlamini', 'sipho@example.com', '082 123 4567', 'whatsapp'], [$row->seller_type, $row->name, $row->email, $row->phone, $row->preferred_contact]);
        $this->assertSame(['house', '12 Ocean Drive', 'Ramsgate', 2450000, 'online'], [$row->property_type, $row->address, $row->suburb, $row->price_expectation, $row->auction_format]);
        $this->assertTrue($row->marketing_opt_in);
        $this->assertNotNull($row->ip);
        $this->assertSame(['pool', 'borehole'], $row->answers['features']);
        $this->assertSame('Damp in the garage.', $row->answers['defects']);
        $this->assertArrayNotHasKey('website', $row->answers, 'the honeypot is never stored');
        Event::assertDispatched(AuctionSellerSubmitted::class);
    }

    public function test_the_person_becomes_a_contact_and_the_property_goes_through_match_or_create(): void
    {
        $this->send($this->valid())->assertCreated();
        $row = AuctionSellerSubmission::withoutGlobalScopes()->firstOrFail();

        $contact = Contact::withoutGlobalScopes()->findOrFail($row->contact_id);
        $this->assertSame('Sipho', $contact->first_name);
        $this->assertSame($this->agency->id, (int) $contact->agency_id);

        $tp = TrackedProperty::withoutGlobalScopes()->findOrFail($row->tracked_property_id);
        $this->assertSame($this->agency->id, (int) $tp->agency_id);
        $this->assertStringContainsStringIgnoringCase('Ocean', (string) $tp->street_name . ' ' . (string) $tp->street_core, 'the street was read from the address');
        $this->assertSame(3, (int) $tp->bedrooms);
        $this->assertSame(2450000, (int) $tp->last_known_asking_price, 'the price they hope for is carried as the asking price');
        $this->assertSame('auction_seller_form', $tp->source_chain[0]['type'], 'the audit chain records where it came from');

        // the same person and property again: matched, not minted twice
        $this->send($this->valid())->assertCreated();
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'sipho@example.com')->count());
        $this->assertSame($tp->id, (int) AuctionSellerSubmission::withoutGlobalScopes()->latest('id')->first()->tracked_property_id);
    }

    public function test_the_team_is_emailed_and_the_administrators_are_alerted(): void
    {
        $this->agency->forceFill(['email' => 'office@example.com'])->save();
        Mail::fake();

        $this->send($this->valid())->assertCreated();

        Mail::assertSent(AuctionSellerSubmissionMail::class, 1);
        Mail::assertSent(AuctionSellerSubmissionMail::class, fn ($m) => $m->hasTo('office@example.com') && str_contains($m->envelope()->subject, 'Sipho Dlamini'));
        $note = $this->admin->notifications()->get()->first(fn ($n) => ($n->data['event_key'] ?? null) === 'auction.seller_submission');
        $this->assertNotNull($note);
        $this->assertStringContainsString('Ramsgate', $note->data['body']);
        $this->assertStringContainsString('/seller-leads/', $note->data['action_url']);
    }

    public function test_switching_the_team_emails_off_stops_the_email_but_not_the_lead(): void
    {
        AgencyAuctionSettings::create(['agency_id' => $this->agency->id, 'notify_emails_enabled' => false]);
        Mail::fake();

        $this->send($this->valid())->assertCreated();

        Mail::assertNothingSent();
        $this->assertSame(1, AuctionSellerSubmission::withoutGlobalScopes()->count());
    }

    public function test_the_required_fields_the_enums_and_the_consents_are_enforced_with_the_forms_own_wording(): void
    {
        $res = $this->send([])->assertStatus(422);
        foreach (['seller_type', 'name', 'email', 'phone', 'property_type', 'address', 'suburb', 'occupancy', 'condition', 'auction_format', 'reserve', 'timing', 'viewing', 'consent_terms', 'consent_authorised', 'consent_processing'] as $f) {
            $res->assertJsonValidationErrors($f);
        }
        $this->assertSame('Please accept the terms to continue.', $res->json('errors.consent_terms.0'));
        $this->assertSame('Please confirm you are authorised to sell the property.', $res->json('errors.consent_authorised.0'));
        $this->assertSame('We need your consent to process this information.', $res->json('errors.consent_processing.0'));

        $this->send($this->valid(['seller_type' => 'nonsense', 'property_type' => 'castle', 'auction_format' => 'x', 'email' => 'not-an-email', 'features' => ['pool', 'jacuzzi']]))
            ->assertStatus(422)->assertJsonValidationErrors(['seller_type', 'property_type', 'auction_format', 'email', 'features.1']);
        $this->send($this->valid(['bedrooms' => 51, 'bathrooms' => -1, 'erf_size' => -5, 'price_expectation' => -1, 'name' => str_repeat('a', 121)]))
            ->assertStatus(422)->assertJsonValidationErrors(['bedrooms', 'bathrooms', 'erf_size', 'price_expectation', 'name']);
        $this->assertSame(0, AuctionSellerSubmission::withoutGlobalScopes()->count());
    }

    public function test_the_required_if_rules_for_spouse_executor_rent_and_bond_bank(): void
    {
        $this->send($this->valid(['seller_type' => 'married_in_community']))->assertStatus(422)->assertJsonValidationErrors('spouse_name');
        $this->send($this->valid(['seller_type' => 'married_in_community', 'spouse_name' => 'Thandi Dlamini']))->assertCreated();
        $this->send($this->valid(['seller_type' => 'deceased_estate']))->assertStatus(422)->assertJsonValidationErrors('executor_name');
        $this->send($this->valid(['seller_type' => 'deceased_estate', 'executor_name' => 'Adv. Naidoo']))->assertCreated();
        $this->send($this->valid(['occupancy' => 'tenant']))->assertStatus(422)->assertJsonValidationErrors('monthly_rent');
        $this->send($this->valid(['occupancy' => 'tenant', 'monthly_rent' => 9500]))->assertCreated();
        $this->send($this->valid(['has_bond' => true]))->assertStatus(422)->assertJsonValidationErrors('bond_bank');
        $this->send($this->valid(['has_bond' => true, 'bond_bank' => 'Standard Bank', 'bond_amount' => 900000, 'bond_arrears' => true]))->assertCreated();
        $this->assertSame(4, AuctionSellerSubmission::withoutGlobalScopes()->count());
    }

    public function test_the_honeypot_rejects_a_bot_and_stores_nothing(): void
    {
        Mail::fake();
        $this->send($this->valid(['website' => 'http://spam.example']))->assertStatus(422);

        $this->assertSame(0, AuctionSellerSubmission::withoutGlobalScopes()->count());
        Mail::assertNothingSent();
    }

    public function test_the_endpoint_is_limited_to_six_a_minute_on_its_own_counter_and_browsing_never_uses_it_up(): void
    {
        $this->asGuest();
        for ($i = 0; $i < 10; $i++) {
            $this->get($this->home())->assertOk();
        }
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($this->endpoint(), $this->valid(['email' => "p{$i}@example.com"]))->assertCreated();
        }
        $this->postJson($this->endpoint(), $this->valid(['email' => 'seventh@example.com']))->assertStatus(429);
    }

    public function test_the_endpoint_is_in_the_api_catalogue_under_v1_and_named(): void
    {
        $route = app('router')->getRoutes()->getByName('api.v1.auctions.public.sell');
        $this->assertNotNull($route);
        $this->assertStringStartsWith('api/v1/', $route->uri());
    }

    // ── the staff screen ───────────────────────────────────────────────────

    private function lead(array $over = []): AuctionSellerSubmission
    {
        return AuctionSellerSubmission::withoutAgencyStamping(fn () => AuctionSellerSubmission::create(array_merge([
            'agency_id' => $this->agency->id, 'status' => 'new', 'seller_type' => 'individual', 'name' => 'Sipho Dlamini', 'email' => 'sipho@example.com', 'phone' => '082 123 4567',
            'preferred_contact' => 'phone', 'property_type' => 'house', 'address' => '12 Ocean Drive', 'suburb' => 'Ramsgate', 'price_expectation' => 2450000,
            'auction_format' => 'online', 'marketing_opt_in' => false, 'answers' => ['sale_reason' => 'Emigrating', 'defects' => 'Damp', 'features' => ['pool'], 'occupancy' => 'owner', 'condition' => 'good', 'has_bond' => false, 'documents' => ['title_deed'], 'reserve' => 'confidential', 'timing' => 'asap', 'viewing' => 'appointment'],
        ], $over)));
    }

    public function test_the_list_searches_sorts_filters_pages_and_has_an_empty_state(): void
    {
        $this->get(route('corex.auctions.sellers.index'))->assertOk()->assertSee('No one has filled in');

        $a = $this->lead();
        $b = $this->lead(['name' => 'Lerato Mokoena', 'email' => 'lerato@example.com', 'address' => '4 Palm Road', 'suburb' => 'Margate', 'property_type' => 'apartment', 'auction_format' => 'live', 'price_expectation' => 900000, 'status' => 'contacted']);

        $this->get(route('corex.auctions.sellers.index', ['search' => 'Margate']))->assertSee('Lerato Mokoena')->assertDontSee('Sipho Dlamini');
        $this->get(route('corex.auctions.sellers.index', ['search' => 'Emigrating']))->assertSee('Sipho Dlamini')->assertSee('Lerato Mokoena', false);
        $this->get(route('corex.auctions.sellers.index', ['search' => 'lerato@']))->assertSee('Lerato Mokoena')->assertDontSee('Sipho Dlamini');
        $this->get(route('corex.auctions.sellers.index', ['status' => 'contacted']))->assertSee('Lerato Mokoena')->assertDontSee('Sipho Dlamini');
        $this->get(route('corex.auctions.sellers.index', ['property_type' => 'house']))->assertSee('Sipho Dlamini')->assertDontSee('Lerato Mokoena');
        $this->get(route('corex.auctions.sellers.index', ['auction_format' => 'live']))->assertSee('Lerato Mokoena')->assertDontSee('Sipho Dlamini');
        $this->get(route('corex.auctions.sellers.index', ['assigned' => 'none']))->assertSee('Sipho Dlamini')->assertSee('Lerato Mokoena');
        $this->get(route('corex.auctions.sellers.index', ['search' => 'zzzz']))->assertSee('No seller leads match these filters');

        $html = $this->get(route('corex.auctions.sellers.index', ['sort' => 'price_expectation', 'dir' => 'asc']))->getContent();
        $this->assertLessThan(strpos($html, 'Sipho Dlamini'), strpos($html, 'Lerato Mokoena'), 'cheapest first');
        $this->get(route('corex.auctions.sellers.index', ['sort' => 'DROP TABLE']))->assertOk();
    }

    public function test_a_lead_can_be_opened_assigned_marked_contacted_archived_and_restored(): void
    {
        $lead = $this->lead();

        $this->get(route('corex.auctions.sellers.show', $lead->id))->assertOk()->assertSee('12 Ocean Drive')->assertSee('1. About the seller')->assertSee('7. Consent')->assertSee('Emigrating');

        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->put(route('corex.auctions.sellers.update', $lead->id), ['status' => 'contacted', 'assigned_user_id' => $agent->id])->assertSessionHasNoErrors();
        $fresh = $lead->fresh();
        $this->assertSame('contacted', $fresh->status);
        $this->assertSame($agent->id, (int) $fresh->assigned_user_id);
        $this->assertNotNull($fresh->contacted_at);

        $this->delete(route('corex.auctions.sellers.archive', $lead->id))->assertRedirect();
        $this->assertSoftDeleted('auction_seller_submissions', ['id' => $lead->id]);
        $this->get(route('corex.auctions.sellers.index'))->assertDontSee('Sipho Dlamini');
        $this->get(route('corex.auctions.sellers.index', ['archived' => 1]))->assertSee('Sipho Dlamini');
        $this->post(route('corex.auctions.sellers.restore', $lead->id))->assertRedirect();
        $this->assertNull($lead->fresh()->deleted_at);
    }

    public function test_a_lead_cannot_be_assigned_to_someone_outside_the_agency(): void
    {
        $lead = $this->lead();
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-' . uniqid()]);
        $stranger = User::withoutAgencyStamping(fn () => User::factory()->create(['agency_id' => $rival->id, 'role' => 'agent']));

        $this->put(route('corex.auctions.sellers.update', $lead->id), ['assigned_user_id' => $stranger->id])->assertSessionHasErrors('assigned_user_id');
        $this->assertNull($lead->fresh()->assigned_user_id);
    }

    public function test_own_branch_and_agency_visibility_and_other_agencies_are_unreachable(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $mine = $this->lead(['name' => 'Assigned To Agent', 'assigned_user_id' => $agent->id]);
        $unassigned = $this->lead(['name' => 'Nobody Yet']);

        $visible = AuctionSellerSubmission::visibleTo($agent)->pluck('id')->all();
        $this->assertContains($mine->id, $visible);
        $this->assertNotContains($unassigned->id, $visible, 'unassigned leads belong to the agency tier only');
        $this->assertCount(2, AuctionSellerSubmission::visibleTo($this->admin)->get());

        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv2-' . uniqid()]);
        $foreign = AuctionSellerSubmission::withoutAgencyStamping(fn () => AuctionSellerSubmission::create(['agency_id' => $rival->id, 'status' => 'new', 'seller_type' => 'individual', 'name' => 'Foreign Seller', 'email' => 'f@x.com', 'phone' => '1', 'property_type' => 'house', 'address' => 'x', 'suburb' => 'y', 'answers' => []]));
        $this->actingAs($this->admin);
        $this->get(route('corex.auctions.sellers.show', $foreign->id))->assertNotFound();
        $this->put(route('corex.auctions.sellers.update', $foreign->id), ['status' => 'closed'])->assertNotFound();
        $this->get(route('corex.auctions.sellers.index'))->assertDontSee('Foreign Seller');
    }

    public function test_the_screen_needs_the_permission(): void
    {
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->actingAs($agent);

        $this->assertFalse($agent->hasPermission('auctions.sellers.view'));
        $this->get(route('corex.auctions.sellers.index'))->assertForbidden();
    }

    // ── sidebar and settings ───────────────────────────────────────────────

    public function test_the_seller_leads_number_counts_new_leads_and_shows_on_the_sidebar(): void
    {
        $this->assertSame(0, (new AuctionNavCounts)->sellerLeads($this->admin));
        $this->get(route('corex.auctions.index'))->assertOk()->assertDontSee('data-auction-count="auctionsellers"', false);

        $this->lead();
        $this->lead(['name' => 'Two', 'email' => 'two@example.com']);
        $this->lead(['name' => 'Done', 'email' => 'done@example.com', 'status' => 'closed']);

        $this->assertSame(2, (new AuctionNavCounts)->sellerLeads($this->admin));
        $this->get(route('corex.auctions.index'))->assertOk()->assertSee('data-auction-count="auctionsellers"', false)->assertSee('Seller leads');
    }

    public function test_the_two_settings_round_trip_through_the_settings_screen_and_the_wizard(): void
    {
        $this->get(route('corex.settings.auctions.show'))->assertOk()->assertSee('data-seller-form-settings', false)->assertSee('Show the "Sell at auction" form', false);
        $this->post(route('corex.settings.auctions.update'), ['seller_form_enabled' => '0', 'seller_response_text' => 'within 2 days'])->assertSessionHasNoErrors();
        $this->assertFalse(AgencyAuctionSettings::sellerFormEnabledFor($this->agency->id));
        $this->assertSame('within 2 days', AgencyAuctionSettings::sellerResponseTextFor($this->agency->id));

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['soft_close_minutes' => 4]);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);
        $this->assertFalse(AgencyAuctionSettings::sellerFormEnabledFor($this->agency->id), 'a wizard step that never showed the switch must not change it');
        $this->assertSame('within 2 days', AgencyAuctionSettings::sellerResponseTextFor($this->agency->id));

        AgencyAuctionSettings::query()->where('agency_id', $this->agency->id)->update(['seller_response_text' => null]);
        $this->assertSame('within one business day', AgencyAuctionSettings::sellerResponseTextFor($this->agency->id), 'blank falls back to the neutral default');
    }
}
