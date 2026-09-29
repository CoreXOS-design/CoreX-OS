<?php

namespace Tests\Feature\Properties;

use App\Jobs\DownloadOtherAgencyStockGalleryJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md §5 — 2026-09-29 gallery-filter fix.
 * Server-side belt-and-braces: OtherAgencyStockImportService must strip any
 * photo URL matching the agent's own image or the agency's logo out of the
 * gallery it queues for download, independent of the extension's own
 * client-side exclusion. Uses the REAL 32 image URLs captured live from
 * property24.com/.../117522773 (2026-09-29 QA1 proof) — 30 genuine gallery
 * photos plus the agent's profile photo (id 287944112) and the agency's
 * logo (id 310646249), both confirmed present on that real page and both
 * served from images.prop24.com at a DIFFERENT size suffix than any
 * gallery photo — proving the id-based match (not just exact-URL) is what
 * makes this robust.
 */
class OtherAgencyStockGalleryFilterTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
    }

    /** The real 30 gallery ids, as captured live. */
    private const REAL_GALLERY_IDS = [
        384696867, 384696872, 384696874, 384696876, 384696879, 384696882, 384696884, 384696886,
        384696888, 384696889, 384696890, 384696891, 384696892, 384696893, 384696894, 384696895,
        384696896, 384696897, 384696898, 384696899, 384696900, 384696901, 384696902, 384696903,
        384696904, 384696905, 384696906, 384696907, 384696908, 384696909,
    ];

    private const AGENT_IMAGE_ID = 287944112;
    private const AGENCY_LOGO_ID = 310646249;

    public function test_p24_real_fixture_agent_photo_and_agency_logo_are_stripped_leaving_exactly_30(): void
    {
        Queue::fake();

        // The raw payload an (unpatched) extension — or a regressed future
        // one — could send: the 30 real gallery photos PLUS the agent photo
        // and agency logo mixed in, exactly as found live.
        $photos = array_map(fn ($id) => "https://images.prop24.com/{$id}/Ensure960x540", self::REAL_GALLERY_IDS);
        $photos[] = 'https://images.prop24.com/' . self::AGENT_IMAGE_ID . '/UpperCrop200x200'; // different size suffix, same id
        $photos[] = 'https://images.prop24.com/' . self::AGENCY_LOGO_ID . '/Fit450x225';
        shuffle($photos);

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117522773',
            'listing_url' => 'https://www.property24.com/for-sale/mandela-view/bloemfontein/free-state/11564/117522773',
            'consent' => true, 'price' => 1650000, 'property_type' => 'House', 'suburb' => 'Mandela View',
            'photos' => $photos,
            'source_agent_image_url' => 'https://images.prop24.com/' . self::AGENT_IMAGE_ID . '/UpperCrop200x200',
            'source_agency_logo_url' => 'https://images.prop24.com/' . self::AGENCY_LOGO_ID . '/Fit450x225',
        ];

        app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        Queue::assertPushed(DownloadOtherAgencyStockGalleryJob::class, function (DownloadOtherAgencyStockGalleryJob $job) {
            $this->assertCount(30, $job->urls, 'expected exactly 30 gallery photos after filtering');

            $ids = array_map(fn ($u) => (int) preg_replace('#.*images\.prop24\.com/(\d+)/.*#', '$1', $u), $job->urls);
            $this->assertEqualsCanonicalizing(self::REAL_GALLERY_IDS, $ids);
            $this->assertNotContains(self::AGENT_IMAGE_ID, $ids);
            $this->assertNotContains(self::AGENCY_LOGO_ID, $ids);

            return true;
        });
    }

    public function test_pp_real_fixtures_are_untouched_by_the_filter(): void
    {
        Queue::fake();

        // Real PP data: galleryPhotos confirmed clean (different CDN host
        // entirely from agent/agency images), so filtering must be a no-op.
        $galleryUrls = array_map(fn ($i) => "https://images.pp.co.za/listing/8249248/photo{$i}/1024/682/contain/jpegorpng", range(1, 19));

        $payload = [
            'portal' => 'pp', 'listing_ref' => 'T3497323',
            'listing_url' => 'https://www.privateproperty.co.za/for-sale/kwazulu-natal/kzn-south-coast/margate/uvongo/36-ss-topanga/2587-colin/T3497323',
            'consent' => true, 'price' => 1400000, 'property_type' => 'Apartment', 'suburb' => 'Uvongo',
            'photos' => $galleryUrls,
            'source_agent_image_url' => 'https://helium.privateproperty.co.za/live-za-images/accountholders/2303478/image/x.jpg',
            'source_agency_logo_url' => 'https://helium.privateproperty.co.za/live-za-images/offices/11230/image/y.jpg',
        ];

        app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        Queue::assertPushed(DownloadOtherAgencyStockGalleryJob::class, function (DownloadOtherAgencyStockGalleryJob $job) use ($galleryUrls) {
            $this->assertCount(19, $job->urls);
            $this->assertEqualsCanonicalizing($galleryUrls, $job->urls);

            return true;
        });
    }

    public function test_no_exclusion_fields_leaves_photos_untouched(): void
    {
        Queue::fake();

        $photos = ['https://images.prop24.com/111/Ensure960x540', 'https://images.prop24.com/222/Ensure960x540'];

        $payload = [
            'portal' => 'p24', 'listing_ref' => '999999',
            'listing_url' => 'https://www.property24.com/x/999999',
            'consent' => true, 'price' => 1000000, 'property_type' => 'House', 'suburb' => 'Testville',
            'photos' => $photos,
        ];

        app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        Queue::assertPushed(DownloadOtherAgencyStockGalleryJob::class, function (DownloadOtherAgencyStockGalleryJob $job) use ($photos) {
            $this->assertSame($photos, $job->urls);

            return true;
        });
    }
}
