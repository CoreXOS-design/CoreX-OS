<?php

namespace Tests\Feature\Properties;

use App\Jobs\DownloadOtherAgencyStockGalleryJob;
use App\Jobs\DownloadPortalPropertyImages;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OtherAgencyStockImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    /**
     * 2026-09-30 URGENT FIX #3 (Norkem Park, property #21098): OAS import
     * had NO photos in practice — the extension's client-collected photos[]
     * URL array reliably ended up empty/wrong. The Pull flow's own image
     * mechanism (P24's sequential image-id pattern, proven live) is reused
     * exactly, not reimplemented: when the payload carries
     * first_image_id/image_count (the SAME signal
     * PropertyPullController::pullFromPortal() already accepts),
     * OtherAgencyStockImportService must dispatch the SAME
     * DownloadPortalPropertyImages job Pull uses — never the URL-array job.
     */
    public function test_p24_first_image_id_and_image_count_dispatch_the_same_job_pull_uses(): void
    {
        Queue::fake();

        $payload = [
            'portal' => 'p24', 'listing_ref' => '117424272',
            'listing_url' => 'https://www.property24.com/for-sale/norkem-park/kempton-park/gauteng/1344/117424272',
            'consent' => true, 'price' => 795000, 'property_type' => 'House', 'suburb' => 'Norkem Park',
            'first_image_id' => 400000001,
            'image_count' => 14,
            // Even though these are sent (harmless, still useful for
            // display/audit), they must NOT be used for gallery download —
            // first_image_id/image_count wins outright for P24.
            'photos' => ['https://images.prop24.com/999999999/Ensure960x540'],
        ];

        app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        Queue::assertPushed(DownloadPortalPropertyImages::class, function (DownloadPortalPropertyImages $job) {
            $this->assertSame(400000001, $job->firstImageId);
            $this->assertSame(14, $job->imageCount);

            return true;
        });
        // 2026-09-30 demo-reliability fix: this job used to have NO queue
        // assignment (silently landed on `default`), so a live import's
        // photos could queue behind whatever unrelated long-running job got
        // there first — confirmed live, a 6-7 minute RegenerateBuyerMatchesJob
        // blocked a real property's images for minutes. Must be on the SAME
        // dedicated, isolated queue DownloadOtherAgencyStockGalleryJob uses.
        Queue::assertPushedOn('p24images', DownloadPortalPropertyImages::class);
        Queue::assertNotPushed(DownloadOtherAgencyStockGalleryJob::class);
    }

    public function test_pp_import_still_uses_the_url_array_job_no_p24_sequential_id(): void
    {
        Queue::fake();

        $galleryUrls = ['https://images.pp.co.za/listing/1/photo1/1024/682/contain/jpegorpng'];

        $payload = [
            'portal' => 'pp', 'listing_ref' => 'T1',
            'listing_url' => 'https://www.privateproperty.co.za/for-sale/x/T1',
            'consent' => true, 'price' => 1000000, 'property_type' => 'House', 'suburb' => 'Testville',
            'photos' => $galleryUrls,
        ];

        app(OtherAgencyStockImportService::class)->import($payload, $this->agent);

        Queue::assertNotPushed(DownloadPortalPropertyImages::class);
        Queue::assertPushed(DownloadOtherAgencyStockGalleryJob::class);
    }

    /**
     * 2026-09-30 URGENT REGRESSION (property #21098): DownloadPortalPropertyImages
     * writes every file under a fresh Str::random(8) suffix, so re-running it
     * for the SAME property never overwrites the previous run's files —
     * merging against gallery_images_json's CURRENT (stale) DB value just
     * kept appending. Confirmed live: 3 accumulated rounds, 69 entries for a
     * genuinely 23-photo property, and the property card's "N photos" badge
     * (already fixed once tonight to read the curated gallery instead of
     * allImages()) inherited the inflated count because the underlying DATA
     * was wrong, not the display logic. A second, independent dispatch for
     * the same property (a reimport) must REPLACE the gallery, never append
     * to whatever a previous run already left there.
     */
    public function test_second_dispatch_for_the_same_property_replaces_the_gallery_not_appends(): void
    {
        Storage::fake('public');
        Http::fake(fn () => Http::response(Str::random(2500), 200, ['Content-Type' => 'image/jpeg']));

        $property = Property::create([
            'title'        => 'Gallery Replace Regression ' . Str::random(4),
            'agency_id'    => $this->agency->id,
            'agent_id'     => $this->agent->id,
            'branch_id'    => Branch::where('agency_id', $this->agency->id)->first()->id,
            'listing_type' => 'sale',
            'status'       => 'active',
            // Simulates the stale data left by an EARLIER run of this same
            // job (or, live, two different jobs writing different filename
            // patterns) — exactly what property #21098 had before repair.
            'gallery_images_json' => [
                'https://example.test/properties/999/stale-1.jpg',
                'https://example.test/properties/999/stale-2.jpg',
                'https://example.test/properties/999/stale-3.jpg',
            ],
        ]);

        (new DownloadPortalPropertyImages($property->id, 90000, 4))->handle();

        $property->refresh();
        $this->assertCount(4, $property->gallery_images_json, 'expected the fresh run to REPLACE the gallery, not append 3 stale + 4 new = 7');
        foreach ($property->gallery_images_json as $url) {
            $this->assertStringNotContainsString('stale-', $url);
        }
    }
}
