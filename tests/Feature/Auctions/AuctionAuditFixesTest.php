<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Auction;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Auctions\AuctionLotAttacher;
use App\Services\Auctions\PropertyAuctionInfo;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-432 follow-ups: one lot per property per auction (server-enforced), the
 * attach typeahead, staff PDF view/download, Price On Application, and the
 * property-page auction block.
 */
final class AuctionAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Audit Agency', 'slug' => 'audit-'.uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);

        AgencyFeature::updateOrCreate(['agency_id' => $this->agency->id, 'feature_key' => 'auctions'], ['enabled' => true]);
        config(['features.auctions' => true]);
        app(\App\Services\Features\AgencyFeatureService::class)->forget($this->agency->id);

        $this->actingAs($this->user);

        $this->auction = Auction::create([
            'agency_id' => $this->agency->id, 'created_by_id' => $this->user->id, 'reference' => 'AUD-1', 'title' => 'Audit Auction',
            'bidding_mode' => 'in_room', 'auctioneer_kind' => 'internal', 'starts_at' => now()->addDays(10),
        ]);
    }

    private function makeProperty(string $title = 'Audit property', array $extra = []): Property
    {
        return Property::create(array_merge([
            'title' => $title, 'agency_id' => $this->agency->id, 'agent_id' => $this->user->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale', 'price' => 1250000,
        ], $extra));
    }

    public function test_the_same_property_cannot_become_a_second_lot_in_one_auction(): void
    {
        $property = $this->makeProperty();

        $this->post(route('corex.auctions.lots.add', $this->auction), ['property_id' => $property->id])->assertSessionHasNoErrors();
        $this->post(route('corex.auctions.lots.add', $this->auction), ['property_id' => $property->id])->assertSessionHasErrors('property_id');

        $this->assertSame(1, $this->auction->lots()->count());
    }

    public function test_the_attacher_is_idempotent(): void
    {
        $property = $this->makeProperty();
        $attacher = app(AuctionLotAttacher::class);

        $first = $attacher->attach($this->auction, $property, []);
        $second = $attacher->attach($this->auction, $property, []);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->auction->lots()->count());
    }

    public function test_a_property_from_another_agency_cannot_be_attached(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-'.uniqid()]);
        // BelongsToAgency stamps new rows with the ACTING user's agency (tenant-spoof protection), so a row
        // that genuinely belongs to another agency has to be created through the trusted-provisioning helper.
        $foreign = Property::withoutAgencyStamping(fn () => Property::create(['title' => 'Foreign', 'agency_id' => $other->id, 'agent_id' => $this->user->id, 'status' => 'active', 'listing_type' => 'sale']));
        $this->assertSame($other->id, (int) $foreign->agency_id);

        // The app's handler may render the not-found as a redirect back; what matters is that nothing was attached.
        $response = $this->post(route('corex.auctions.lots.add', $this->auction), ['property_id' => $foreign->id]);
        $this->assertContains($response->getStatusCode(), [302, 404]);
        $this->assertSame(0, $this->auction->lots()->count());
        $this->assertFalse($foreign->fresh()->isAuction());
    }

    public function test_property_search_finds_by_title_and_leaves_out_attached_properties(): void
    {
        $attached = $this->makeProperty('Zebra Lodge');
        $free = $this->makeProperty('Zebra Cottage');
        app(AuctionLotAttacher::class)->attach($this->auction, $attached, []);

        $json = $this->getJson(route('api.v1.auctions.property-search', $this->auction).'?q=Zebra')->assertOk()->json();

        $ids = array_column($json, 'id');
        $this->assertContains($free->id, $ids);
        $this->assertNotContains($attached->id, $ids);
    }

    public function test_property_search_needs_two_characters(): void
    {
        $this->getJson(route('api.v1.auctions.property-search', $this->auction).'?q=a')->assertOk()->assertExactJson([]);
    }

    public function test_staff_can_view_and_download_an_uploaded_pdf_before_publishing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('auctions/x/rules.pdf', '%PDF-1.4 test');
        $this->auction->update(['rules_file_path' => 'auctions/x/rules.pdf', 'rules_file_name' => 'Rules.pdf']);

        $this->get(route('corex.auctions.document', [$this->auction, 'rules']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('corex.auctions.document', [$this->auction, 'rules', 'download' => 1]))
            ->assertOk()->assertHeader('content-disposition', 'attachment; filename=Rules.pdf');
    }

    public function test_a_missing_or_unknown_document_is_a_404(): void
    {
        Storage::fake('local');
        $this->auction->update(['rules_file_path' => '0', 'rules_file_name' => 'Rules.pdf']);

        $this->get(route('corex.auctions.document', [$this->auction, 'rules']))->assertNotFound();
        $this->get('/corex/auctions/'.$this->auction->id.'/documents/passwords')->assertNotFound();
    }

    public function test_another_agency_cannot_open_this_auctions_documents(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('auctions/x/rules.pdf', '%PDF-1.4 test');
        $this->auction->update(['rules_file_path' => 'auctions/x/rules.pdf', 'rules_file_name' => 'Rules.pdf']);

        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-'.uniqid()]);
        $otherBranch = Branch::withoutAgencyStamping(fn () => Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']));
        $outsider = User::withoutAgencyStamping(fn () => User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']));
        $this->assertSame($otherAgency->id, (int) $outsider->agency_id);
        \Illuminate\Support\Facades\DB::table('agency_features')->updateOrInsert(
            ['agency_id' => $otherAgency->id, 'feature_key' => 'auctions'],
            ['enabled' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        app(\App\Services\Features\AgencyFeatureService::class)->forget($otherAgency->id);

        $this->actingAs($outsider)->get(route('corex.auctions.document', [$this->auction, 'rules']))->assertNotFound();
    }

    public function test_price_on_application_hides_the_amount_everywhere_the_price_is_formatted(): void
    {
        $property = $this->makeProperty('POA house');
        $this->assertSame('R 1 250 000', $property->formattedPrice());

        $property->price_on_application = true;
        $this->assertSame('Price on Application', $property->formattedPrice());
    }

    public function test_the_auction_block_only_exists_for_a_property_on_auction_and_hides_the_reserve_by_default(): void
    {
        $plain = $this->makeProperty('Plain');
        $this->assertNull(PropertyAuctionInfo::for($plain));

        $property = $this->makeProperty('On auction');
        app(AuctionLotAttacher::class)->attach($this->auction, $property, ['reserve_price' => 900000]);
        $property->refresh();

        $info = PropertyAuctionInfo::for($property, false);
        $this->assertNotNull($info);
        $this->assertSame($this->auction->id, $info['auction']->id);
        $this->assertFalse($info['showReserve']);
    }
}
