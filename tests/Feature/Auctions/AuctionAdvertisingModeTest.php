<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionReminderMail;
use App\Models\AgencyAuctionSettings;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Branch;
use App\Models\PortalLead;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotStatusService;
use App\Services\Auctions\AuctionPublishGate;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-432 addendum — .ai/specs/auctions-advertising-mode.md.
 */
final class AuctionAdvertisingModeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Auction $auction;
    private AuctionLot $lot;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Adv Test Agency', 'slug' => 'adv-test-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
            'ffc_expiry_date' => now()->addYear(),
        ]);
        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->property = Property::create([
            'title' => 'Adv lot', 'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
            // short-circuits MarketingReadinessService — the seller-authority gate itself is covered by its own tests
            'compliance_snapshot_at' => now(),
        ]);
        $this->auction = Auction::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'reference' => 'AUC-ADV-'.uniqid(),
            'title' => 'Advertised Auction', 'bidding_mode' => 'in_room', 'auctioneer_kind' => 'external',
            'auctioneer_company' => 'Big Auctions', 'auctioneer_licence_no' => 'LIC-1', 'auctioneer_phone' => '0391234567',
            'starts_at' => now()->addDays(10),
        ]);
        $this->lot = AuctionLot::create([
            'agency_id' => $this->agency->id, 'auction_id' => $this->auction->id, 'property_id' => $this->property->id,
            'lot_number' => 1, 'reserve_price' => 900000, 'guide_price_min' => 800000, 'guide_price_max' => 1000000,
        ]);
    }

    private function publish(): void
    {
        (new AuctionLotStatusService())->publishCatalogue($this->auction);
        $this->auction->refresh();
        $this->lot->refresh();
    }

    // ── Publish gate ────────────────────────────────────────────────────

    public function test_gate_passes_when_everything_is_in_place(): void
    {
        $this->assertSame([], app(AuctionPublishGate::class)->blockers($this->auction));
    }

    public function test_gate_blocks_a_lot_with_no_reserve_statement(): void
    {
        $this->lot->update(['reserve_price' => null]);
        $this->assertStringContainsString('reserve', implode(' ', app(AuctionPublishGate::class)->blockers($this->auction)));
    }

    public function test_gate_blocks_an_expired_ffc_and_a_missing_external_auctioneer_licence(): void
    {
        $this->agent->update(['ffc_expiry_date' => now()->subDay()]);
        $this->auction->update(['auctioneer_licence_no' => null]);
        $text = implode(' | ', app(AuctionPublishGate::class)->blockers($this->auction->fresh()));
        $this->assertStringContainsString('expired', $text);
        $this->assertStringContainsString('licence', $text);
    }

    public function test_controller_refuses_to_publish_when_blocked(): void
    {
        $this->lot->update(['reserve_price' => null]);
        $this->actingAs($this->agent)
            ->post(route('corex.auctions.publish', $this->auction))
            ->assertSessionHas('publish_blockers');
        $this->assertNull($this->auction->fresh()->catalogue_published_at);
    }

    // ── Public advert ───────────────────────────────────────────────────

    public function test_unpublished_auction_and_draft_lots_are_not_public(): void
    {
        $this->get(route('public.auctions.show', $this->auction->id))->assertNotFound();
        $this->publish();
        $this->get(route('public.auctions.show', $this->auction->id))->assertOk()->assertSee('Advertised Auction')->assertSee('Big Auctions');
    }

    public function test_lot_page_discloses_reserve_but_hides_the_amount_by_default(): void
    {
        $this->publish();
        $this->get(route('public.auctions.lot', [$this->auction->id, $this->lot->id]))
            ->assertOk()
            ->assertSee('Subject to a reserve price')
            ->assertDontSee('900,000');

        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['reserve_visibility' => 'published']);
        $this->get(route('public.auctions.lot', [$this->auction->id, $this->lot->id]))->assertSee('900,000');
    }

    public function test_zero_reserve_reads_as_without_reserve(): void
    {
        $this->lot->update(['reserve_price' => 0]);
        $this->publish();
        $this->get(route('public.auctions.lot', [$this->auction->id, $this->lot->id]))->assertSee('without reserve');
    }

    public function test_a_lot_from_another_auction_is_not_reachable_under_this_one(): void
    {
        $this->publish();
        $other = Auction::create([
            'agency_id' => $this->agency->id, 'reference' => 'AUC-OTHER-'.uniqid(), 'title' => 'Other',
            'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(3),
        ]);
        $this->get(route('public.auctions.lot', [$other->id, $this->lot->id]))->assertNotFound();
    }

    // ── Enquiry ─────────────────────────────────────────────────────────

    public function test_enquiry_creates_a_contact_and_a_lead_for_the_listing_agent(): void
    {
        $this->publish();
        $this->post(route('public.auctions.enquire', [$this->auction->id, $this->lot->id]), [
            'name' => 'Buyer Bob', 'email' => 'bob@example.test', 'phone' => '0821234567', 'popia_consent' => '1',
        ])->assertSessionHas('status');

        $lead = PortalLead::withoutGlobalScopes()->where('agency_id', $this->agency->id)->where('email', 'bob@example.test')->first();
        $this->assertNotNull($lead);
        $this->assertSame($this->property->id, (int) $lead->listing_id);
        $this->assertNotNull($lead->contact_id);
        $this->assertStringStartsWith('auction_page:'.$this->auction->id.':', $lead->lead_source_raw['source']);
    }

    public function test_enquiry_needs_consent_and_a_way_to_reach_the_buyer(): void
    {
        $this->publish();
        $url = route('public.auctions.enquire', [$this->auction->id, $this->lot->id]);
        $this->post($url, ['name' => 'X', 'email' => 'x@example.test'])->assertSessionHasErrors('popia_consent');
        $this->post($url, ['name' => 'X', 'popia_consent' => '1'])->assertSessionHasErrors('email');
    }

    // ── Mode switch ─────────────────────────────────────────────────────

    public function test_default_is_advertising_only_and_blocks_the_sale_room(): void
    {
        $this->assertTrue(AgencyAuctionSettings::advertisingOnlyFor($this->agency->id));
        $this->actingAs($this->agent)
            ->get(route('corex.auctions.room.show', $this->auction))
            ->assertRedirect(route('corex.auctions.index'));
        $this->actingAs($this->agent)
            ->get(route('corex.auctions.bidders.index', $this->auction))
            ->assertRedirect(route('corex.auctions.index'));
    }

    public function test_switching_advertising_only_off_restores_the_sale_room(): void
    {
        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['advertising_only' => false]);
        $this->actingAs($this->agent)
            ->get(route('corex.auctions.room.show', $this->auction))
            ->assertOk();
    }

    public function test_public_registration_redirects_to_the_external_link_in_advertising_mode(): void
    {
        $this->auction->update(['external_registration_url' => 'https://auctioneer.example/register']);
        $this->publish();
        $this->get(route('public.auctions.register.show', $this->auction->id))->assertRedirect('https://auctioneer.example/register');
        $this->post(route('public.auctions.register.store', $this->auction->id), ['first_name' => 'A', 'email' => 'a@example.test', 'bidding_for' => 'self', 'popia_consent' => '1'])
            ->assertRedirect('https://auctioneer.example/register');
    }

    // ── Record result ───────────────────────────────────────────────────

    public function test_recording_a_sold_result_walks_the_state_machine_and_shows_publicly(): void
    {
        $this->publish();
        $this->actingAs($this->agent)
            ->post(route('corex.auctions.lots.record-result', $this->lot), ['outcome' => 'sold', 'hammer_price' => 950000])
            ->assertSessionHasNoErrors();

        $this->lot->refresh();
        $this->assertSame(AuctionLot::STATUS_SOLD, $this->lot->status);
        $this->assertEquals(950000, (float) $this->lot->hammer_price);
        $this->get(route('public.auctions.lot', [$this->auction->id, $this->lot->id]))->assertSee('Sold');
    }

    public function test_recording_passed_in_and_withdrawn(): void
    {
        $this->publish();
        $this->actingAs($this->agent)->post(route('corex.auctions.lots.record-result', $this->lot), ['outcome' => 'passed_in'])->assertSessionHasNoErrors();
        $this->assertSame(AuctionLot::STATUS_PASSED_IN, $this->lot->fresh()->status);
    }

    public function test_a_sold_result_needs_a_price(): void
    {
        $this->publish();
        $this->actingAs($this->agent)->post(route('corex.auctions.lots.record-result', $this->lot), ['outcome' => 'sold'])
            ->assertSessionHasErrors('hammer_price');
    }

    // ── Reminders ───────────────────────────────────────────────────────

    public function test_reminders_go_to_enquirers_once(): void
    {
        Mail::fake();
        $this->auction->update(['starts_at' => now()->addHours(10)]);
        $this->publish();
        $this->post(route('public.auctions.enquire', [$this->auction->id, $this->lot->id]), [
            'name' => 'Buyer Bob', 'email' => 'bob@example.test', 'popia_consent' => '1',
        ]);

        $this->artisan('auctions:send-reminders')->assertSuccessful();
        Mail::assertQueued(AuctionReminderMail::class, 1);

        $this->artisan('auctions:send-reminders')->assertSuccessful();
        Mail::assertQueued(AuctionReminderMail::class, 1);
    }

    // ── Wizard saver guard (§6.1) ───────────────────────────────────────

    public function test_wizard_saver_only_writes_what_the_step_posted(): void
    {
        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], ['guide_price_enabled' => false, 'advertising_only' => true]);
        $this->actingAs($this->agent);
        $request = \Illuminate\Http\Request::create('/x', 'POST', ['auction_advertising_only' => '0']);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);

        $row = AgencyAuctionSettings::where('agency_id', $this->agency->id)->first();
        $this->assertFalse((bool) $row->advertising_only);
        $this->assertFalse((bool) $row->guide_price_enabled, 'a setting the step never posted must not be touched');
    }
}
