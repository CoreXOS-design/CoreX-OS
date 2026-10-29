<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\PropertyFile;
use App\Models\User;
use App\Services\Auctions\AuctionLotService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Screens S1 Auctions, S2 Lots, S8 Settings and the property Auction tab / Drive pack (.ai/specs/auctions.md §5-7). */
final class AuctionScreensTest extends AuctionsV2TestCase
{
    private function foreignAuction(): Auction
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-'.uniqid()]);

        return Auction::withoutAgencyStamping(fn () => Auction::create([
            'agency_id' => $other->id, 'reference' => 'AUC-OTHER', 'title' => 'Secret Auction', 'format' => 'timed', 'status' => 'draft',
        ]));
    }

    public function test_the_auction_list_searches_filters_and_shows_an_empty_state(): void
    {
        $this->get(route('corex.auctions.index'))->assertOk()->assertSee('No auctions yet');

        $this->auction(['title' => 'Coastal Villas Auction', 'reference' => 'AUC-T-A']);
        $this->auction(['title' => 'Farm Portfolio', 'reference' => 'AUC-T-B', 'status' => 'published']);

        $this->get(route('corex.auctions.index', ['search' => 'Coastal']))->assertOk()->assertSee('Coastal Villas Auction')->assertDontSee('Farm Portfolio');
        $this->get(route('corex.auctions.index', ['status' => 'published']))->assertOk()->assertSee('Farm Portfolio')->assertDontSee('Coastal Villas Auction');
        $this->get(route('corex.auctions.index', ['search' => 'nothing-matches']))->assertOk()->assertSee('No auctions match these filters');
    }

    public function test_another_agencys_auction_is_invisible_and_unreachable_by_id(): void
    {
        $foreign = $this->foreignAuction();

        $this->get(route('corex.auctions.index'))->assertOk()->assertDontSee('Secret Auction');
        $this->get(route('corex.auctions.edit', $foreign->id))->assertNotFound();
        $this->put(route('corex.auctions.update', $foreign->id), ['title' => 'Hacked'])->assertNotFound();
        $this->post(route('corex.auctions.publish', $foreign->id))->assertNotFound();
    }

    public function test_an_agent_only_sees_their_own_auctions_and_lots(): void
    {
        $mine = $this->auction(['reference' => 'AUC-T-MINE', 'created_by' => $this->admin->id]);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $theirs = $this->auction(['reference' => 'AUC-T-THEIRS', 'created_by' => $agent->id]);

        $this->assertSame('own', \App\Services\PermissionService::getDataScope($agent, 'auctions'));
        $visible = Auction::visibleTo($agent)->pluck('id')->all();

        $this->assertContains($theirs->id, $visible);
        $this->assertNotContains($mine->id, $visible);
        $this->assertCount(2, Auction::visibleTo($this->admin)->get(), 'an admin sees the whole agency');
    }

    public function test_an_auction_is_created_edited_archived_and_restored(): void
    {
        $this->post(route('corex.auctions.store'), [
            'title' => 'Spring Auction', 'format' => 'timed', 'type' => 'real_estate', 'currency' => 'zar',
            'starts_at' => now()->addDays(7)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(9)->format('Y-m-d\TH:i'),
            'inc_from' => [0, 1000000], 'inc_amount' => [10000, 50000],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $a = Auction::firstOrFail();
        $this->assertSame('ZAR', $a->currency);
        $this->assertSame('draft', $a->status);
        $this->assertMatchesRegularExpression('/^AUC-\d{4}-0001$/', $a->reference);
        $this->assertSame([10000.0, 50000.0], $a->increments->map(fn ($i) => (float) $i->increment)->all());

        $this->put(route('corex.auctions.update', $a->id), [
            'title' => 'Spring Auction (renamed)', 'format' => 'live', 'type' => 'real_estate', 'currency' => 'ZAR',
            'venue_name' => 'Town Hall', 'inc_from' => [0], 'inc_amount' => [20000],
        ])->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame('Spring Auction (renamed)', $a->title);
        $this->assertSame('live', $a->format);
        $this->assertCount(1, $a->increments()->get());

        $this->delete(route('corex.auctions.archive', $a->id))->assertRedirect();
        $this->assertSoftDeleted('auctions', ['id' => $a->id]);
        $this->get(route('corex.auctions.index', ['archived' => 1]))->assertOk()->assertSee('Spring Auction (renamed)');

        $this->post(route('corex.auctions.restore', $a->id))->assertRedirect();
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_an_auction_with_live_lots_cannot_be_archived(): void
    {
        $a = $this->auction();
        app(AuctionLotService::class)->saveForProperty($this->property(), ['auction_id' => $a->id], $this->admin);

        $this->delete(route('corex.auctions.archive', $a->id))->assertSessionHasErrors('archive');
        $this->assertNull($a->fresh()->deleted_at);
    }

    public function test_publish_cancel_and_clone_work_from_the_screen(): void
    {
        $a = $this->auction();
        app(AuctionLotService::class)->saveForProperty($this->property(), ['auction_id' => $a->id], $this->admin);

        $this->post(route('corex.auctions.publish', $a->id))->assertSessionHasNoErrors();
        $this->assertSame('published', $a->fresh()->status);

        $this->post(route('corex.auctions.clone', $a->id))->assertRedirect();
        $this->assertSame(2, Auction::count());

        $this->post(route('corex.auctions.cancel', $a->id), ['cancel_reason' => 'Test'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $a->fresh()->status);
    }

    public function test_documents_are_uploaded_downloaded_and_removed(): void
    {
        Storage::fake('local');
        $a = $this->auction();

        $this->post(route('corex.auctions.documents.store', $a->id), [
            'kind' => 'conditions_of_sale', 'file' => UploadedFile::fake()->create('conditions.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $doc = $a->documents()->firstOrFail();
        $this->assertSame('public', $doc->visibility, 'Conditions of Sale are public by default');
        $this->assertSame('Conditions of Sale', $doc->name);
        $this->get(route('corex.auctions.documents.download', [$a->id, $doc->id]))->assertOk();

        $this->delete(route('corex.auctions.documents.destroy', [$a->id, $doc->id]))->assertSessionHasNoErrors();
        $this->assertSoftDeleted('auction_documents', ['id' => $doc->id]);
    }

    public function test_the_lots_screen_lists_searches_and_creates_through_the_service(): void
    {
        $this->get(route('corex.auctions.lots.index'))->assertOk()->assertSee('No lots yet');

        $a = $this->auction();
        $p = $this->property('Hilltop Cottage');
        $this->post(route('corex.auctions.lots.store'), [
            'property_id' => $p->id, 'auction_id' => $a->id, 'opening_bid' => 500000, 'reserve_price' => 650000,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lot = AuctionLot::firstOrFail();
        $this->assertSame(1, $lot->lot_number);
        $this->get(route('corex.auctions.lots.index', ['search' => 'Hilltop']))->assertOk()->assertSee('Hilltop Cottage');
        $this->get(route('corex.auctions.lots.index', ['search' => 'zzz']))->assertOk()->assertSee('No lots match these filters');

        // the same property cannot become a second live lot
        $this->post(route('corex.auctions.lots.store'), ['property_id' => $p->id, 'auction_id' => $a->id])->assertSessionHasErrors('property_id');
        $this->assertSame(1, AuctionLot::count());
    }

    public function test_a_lot_is_edited_withdrawn_archived_and_restored(): void
    {
        $a = $this->auction();
        $lot = app(AuctionLotService::class)->saveForProperty($this->property(), ['auction_id' => $a->id], $this->admin);

        $this->put(route('corex.auctions.lots.update', $lot->id), ['auction_id' => $a->id, 'opening_bid' => 750000])->assertSessionHasNoErrors();
        $this->assertSame('750000.00', $lot->fresh()->opening_bid);

        $this->delete(route('corex.auctions.lots.archive', $lot->id))->assertRedirect();
        $this->assertSoftDeleted('auction_lots', ['id' => $lot->id]);
        $this->assertFalse($lot->property->fresh()->isAuction(), 'archiving frees the property');

        $this->post(route('corex.auctions.lots.restore', $lot->id))->assertRedirect();
        $this->assertSame('draft', $lot->fresh()->status);
        $this->assertTrue($lot->property->fresh()->isAuction());
    }

    public function test_the_property_picker_only_offers_free_sale_properties_of_this_agency(): void
    {
        $free = $this->property('Zebra Cottage');
        $taken = $this->property('Zebra Lodge');
        $rental = $this->property('Zebra Flat', ['listing_type' => 'rental']);
        app(AuctionLotService::class)->saveForProperty($taken, ['auction_id' => $this->auction()->id], $this->admin);

        $ids = collect($this->getJson(route('api.v1.auctions.property-search', ['q' => 'Zebra']))->assertOk()->json('data'))->pluck('id')->all();

        $this->assertContains($free->id, $ids);
        $this->assertNotContains($taken->id, $ids);
        $this->assertNotContains($rental->id, $ids);
    }

    public function test_a_property_is_marked_as_auction_and_its_tab_creates_the_lot(): void
    {
        $p = $this->property();

        $this->post(route('corex.properties.auction.mark', $p))->assertRedirect();
        $this->assertTrue($p->fresh()->isAuction());

        $this->put(route('corex.properties.auction.update', $p), [
            'opens_at' => now()->addDays(2)->format('Y-m-d\TH:i'), 'closes_at' => now()->addDays(4)->format('Y-m-d\TH:i'),
            'opening_bid' => 800000, 'reserve_price' => 950000,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $lot = $p->fresh()->currentAuctionLot();
        $this->assertNotNull($lot);
        $this->assertTrue($lot->auction->is_single_property);

        $this->get(route('corex.properties.show', $p).'?tab=auction')->assertOk()->assertSee('data-auction-tab', false)->assertSee('Lot 1');

        $this->delete(route('corex.properties.auction.unmark', $p))->assertSessionHasErrors('sale_method');   // a live lot blocks it
        $this->delete(route('corex.properties.auction.withdraw', $p))->assertRedirect();
        $this->assertNull($p->fresh()->currentAuctionLot());
    }

    public function test_a_rental_cannot_be_marked_as_auction(): void
    {
        $rental = $this->property('Flat', ['listing_type' => 'rental']);

        $this->post(route('corex.properties.auction.mark', $rental))->assertSessionHasErrors('sale_method');
        $this->assertFalse($rental->fresh()->isAuction());
    }

    public function test_the_drive_auction_pack_visibility_is_saved_per_file(): void
    {
        $p = $this->property(extra: ['sale_method' => 'auction']);
        $file = PropertyFile::create(['agency_id' => $this->agency->id, 'property_id' => $p->id, 'user_id' => $this->admin->id, 'name' => 'Title deed.pdf', 'path' => 'p/title.pdf', 'size' => 10, 'mime_type' => 'application/pdf']);
        $this->assertNull($file->auction_visibility);

        $url = route('corex.properties.files.auction-visibility', [$p, $file->id]);
        $this->putJson($url, ['auction_visibility' => 'registered'])->assertOk();
        $this->assertSame('registered', $file->fresh()->auction_visibility);

        $this->putJson($url, ['auction_visibility' => 'nonsense'])->assertStatus(422);
        $this->putJson($url, ['auction_visibility' => null])->assertOk();
        $this->assertNull($file->fresh()->auction_visibility);

        $this->get(route('corex.properties.show', $p).'?tab=drive')->assertOk()->assertSee('data-auction-pack', false);
    }

    public function test_settings_save_and_default_to_neutral_values(): void
    {
        $id = $this->agency->id;
        $this->assertSame(5, AgencyAuctionSettings::softCloseMinutesFor($id));
        $this->assertSame('met_not_met', AgencyAuctionSettings::reserveVisibilityFor($id));
        $this->assertSame('Cape Auctions', AgencyAuctionSettings::publicTitleFor($id), 'falls back to the agency name, never HFC wording');

        $this->get(route('corex.settings.auctions.show'))->assertOk();
        $this->post(route('corex.settings.auctions.update'), [
            'site_title' => 'Cape Property Auctions', 'soft_close_minutes' => 10, 'reserve_visibility' => 'hidden',
            'reference_prefix' => 'cpa', 'show_bid_count' => '1',
            'docs_required_individual' => ['id_or_passport'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Cape Property Auctions', AgencyAuctionSettings::publicTitleFor($id));
        $this->assertSame(10, AgencyAuctionSettings::softCloseMinutesFor($id));
        $this->assertSame('hidden', AgencyAuctionSettings::reserveVisibilityFor($id));
        $this->assertSame('CPA', AgencyAuctionSettings::referencePrefixFor($id));
        $this->assertFalse(AgencyAuctionSettings::outbidEmailsFor($id), 'an unticked box on the full form is stored as off');
        $this->assertSame(['id_or_passport'], AgencyAuctionSettings::docsRequiredIndividualFor($id));
        $this->assertStringStartsWith('CPA-', Auction::nextReference($id));
    }

    public function test_the_wizard_saver_never_wipes_a_setting_the_step_did_not_post(): void
    {
        $id = $this->agency->id;
        AgencyAuctionSettings::create(['agency_id' => $id, 'show_bid_count' => false, 'outbid_emails' => false, 'show_bidder_numbers' => true, 'soft_close_minutes' => 12]);

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['reserve_visibility' => 'hidden']);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);

        $this->assertSame('hidden', AgencyAuctionSettings::reserveVisibilityFor($id));
        $this->assertFalse(AgencyAuctionSettings::showBidCountFor($id));
        $this->assertFalse(AgencyAuctionSettings::outbidEmailsFor($id));
        $this->assertTrue(AgencyAuctionSettings::showBidderNumbersFor($id));
        $this->assertSame(12, AgencyAuctionSettings::softCloseMinutesFor($id));
    }

    public function test_the_feature_switch_hides_everything_when_off(): void
    {
        \App\Models\AgencyFeature::where('agency_id', $this->agency->id)->where('feature_key', 'auctions')->update(['enabled' => false]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->assertContains($this->get(route('corex.auctions.index'))->getStatusCode(), [403, 404, 302]);
        $p = $this->property();
        $this->assertContains($this->post(route('corex.properties.auction.mark', $p))->getStatusCode(), [403, 404, 302]);
        $this->assertFalse($p->fresh()->isAuction());
    }
}
