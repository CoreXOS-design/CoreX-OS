<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenancy;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24Listing;
use App\Models\PortalCapture;
use App\Models\PortalListing;
use App\Models\PortalListingObservation;
use App\Models\User;
use App\Services\PortalListingTrackingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lane B step 1: the portal-capture tables and the email-alert table are per agency. Two agencies capture / receive the SAME advert and
 * each keeps its own rows; neither can read the other's.
 */
final class PortalCaptureAgencyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $a;
    private Agency $b;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Model::withoutEvents(fn () => Agency::create(['name' => 'Agency A', 'slug' => 'a-' . uniqid()]));
        $this->b = Model::withoutEvents(fn () => Agency::create(['name' => 'Agency B', 'slug' => 'b-' . uniqid()]));
        $this->userA = $this->agent($this->a, 'A1');
        $this->userB = $this->agent($this->b, 'B1');
    }

    private function agent(Agency $agency, string $code): User
    {
        $branch = Branch::withoutAgencyStamping(fn () => Branch::create(['agency_id' => $agency->id, 'name' => $code . ' Main', 'code' => $code]));

        return User::withoutAgencyStamping(fn () => User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]));
    }

    private function items(): array
    {
        return [['portal_listing_id' => '116765021', 'url' => 'https://www.property24.com/for-sale/uvongo/116765021', 'price' => 1299990, 'beds' => 4]];
    }

    public function test_a_capture_takes_the_agency_of_the_user_who_made_it(): void
    {
        $capture = PortalCapture::factory()->create(['user_id' => $this->userB->id]);
        $this->assertSame($this->b->id, (int) $capture->agency_id);
    }

    public function test_two_agencies_capturing_the_same_advert_each_keep_their_own_listing_and_observations(): void
    {
        $capA = PortalCapture::factory()->create(['user_id' => $this->userA->id]);
        $capB = PortalCapture::factory()->create(['user_id' => $this->userB->id]);
        $svc = new PortalListingTrackingService();

        $this->assertSame(1, $svc->processItems($capA, 'www.property24.com', $this->items())['new']);
        $this->assertSame(1, $svc->processItems($capB, 'www.property24.com', $this->items())['new'], 'agency B\'s first capture of the advert is new for B, not an update of A\'s row');

        $rows = PortalListing::withoutGlobalScopes()->where('portal_listing_id', '116765021')->orderBy('agency_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame([$this->a->id, $this->b->id], $rows->pluck('agency_id')->map(fn ($v) => (int) $v)->sort()->values()->all());
        $this->assertSame($capA->id, (int) $rows->firstWhere('agency_id', $this->a->id)->last_capture_id);
        $this->assertSame($capB->id, (int) $rows->firstWhere('agency_id', $this->b->id)->last_capture_id, 'B\'s capture never becomes the last capture on A\'s listing');

        // a second capture by A updates A's row only
        $capA2 = PortalCapture::factory()->create(['user_id' => $this->userA->id]);
        $again = $svc->processItems($capA2, 'www.property24.com', [['portal_listing_id' => '116765021', 'price' => 1199990] + $this->items()[0]]);
        $this->assertSame(1, $again['updated']);
        $this->assertSame(1, $again['price_changes']);
        $this->assertSame(1299990, (int) PortalListing::withoutGlobalScopes()->where('agency_id', $this->b->id)->first()->current_fields_json['price'], 'B\'s listing price is untouched by A\'s newer capture');

        foreach (PortalListingObservation::withoutGlobalScopes()->get() as $obs) {
            $listing = PortalListing::withoutGlobalScopes()->find($obs->portal_listing_id);
            $this->assertSame((int) $listing->agency_id, (int) $obs->agency_id, 'an observation carries its listing\'s agency');
        }
    }

    public function test_each_agency_reads_only_its_own_captures_listings_and_observations(): void
    {
        $capA = PortalCapture::factory()->create(['user_id' => $this->userA->id]);
        $capB = PortalCapture::factory()->create(['user_id' => $this->userB->id]);
        $svc = new PortalListingTrackingService();
        $svc->processItems($capA, 'www.property24.com', $this->items());
        $svc->processItems($capB, 'www.property24.com', $this->items());

        $this->actingAs($this->userB);
        $this->assertSame([$capB->id], PortalCapture::query()->pluck('id')->all());
        $this->assertSame([$this->b->id], PortalListing::query()->pluck('agency_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([$this->b->id], PortalListingObservation::query()->pluck('agency_id')->map(fn ($v) => (int) $v)->all());
        $this->assertNull(PortalCapture::query()->find($capA->id), 'a direct id lookup of another agency\'s capture finds nothing');
    }

    public function test_an_email_alert_advert_number_is_unique_per_agency_not_across_agencies(): void
    {
        $row = fn (int $agencyId) => [
            'agency_id' => $agencyId, 'p24_listing_number' => '117740465', 'asking_price' => 1500000, 'listing_status' => 'active',
            'first_seen_date' => '2026-10-01', 'last_seen_date' => '2026-10-01', 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('p24_listings')->insert($row($this->a->id));
        DB::table('p24_listings')->insert($row($this->b->id));   // the same number for a second agency is allowed
        $this->assertSame(2, DB::table('p24_listings')->where('p24_listing_number', '117740465')->count());

        $this->expectException(QueryException::class);
        DB::table('p24_listings')->insert($row($this->a->id));   // but not twice for one agency
    }

    public function test_the_email_alert_importer_looks_the_advert_up_inside_its_own_agency(): void
    {
        $src = file_get_contents(base_path('app/Services/P24/P24ImapImportService.php'));
        $this->assertMatchesRegularExpression("/withoutGlobalScope\\(\\\\App\\\\Models\\\\Scopes\\\\AgencyScope::class\\)->where\\('agency_id', \\\$agencyId\\)->where\\('p24_listing_number'/", $src,
            'the importer runs with nobody signed in, so the lookup carries the agency itself');
    }

    public function test_every_row_that_existed_before_the_agency_column_was_given_an_agency(): void
    {
        // the migration made the column NOT NULL after back-filling: a capture or listing without an agency can no longer be stored
        $this->expectException(QueryException::class);
        DB::table('portal_listings')->insert(['source_site' => 'www.property24.com', 'portal_listing_id' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }
}
