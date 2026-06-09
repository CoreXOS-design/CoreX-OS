<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks the contract the mobile app reads for:
 *   1. Portal links — company website, Property24, Private Property, and any
 *      future portal — as a portal-agnostic array with live/not_published.
 *   2. Image URLs — absolute (load on a real device) and the cover/first image
 *      matching the web listing card (Property::allImages()[0]).
 *
 * Backend for: .ai/specs/property-portal-links-and-images-MOBILE-PROMPT.md
 */
class MobilePropertyPortalAndImagesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://corex.test']);

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
    }

    /** A real p24_suburbs row so the FK on properties.p24_suburb_id is satisfiable. */
    private function makeP24Suburb(): int
    {
        return \Illuminate\Support\Facades\DB::table('p24_suburbs')->insertGetId([
            'name'        => 'Uvongo',
            'slug'        => 'uvongo',
            'p24_id'      => 9123,
            'p24_city_id' => null,
        ]);
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $this->user->branch_id,
            'title'         => 'Sea-view 3 bed',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ], $overrides));
    }

    /** Every property exposes all three core portals, agnostically shaped. */
    public function test_portal_links_endpoint_lists_website_p24_and_pp(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}/portal-links");

        $res->assertOk()
            ->assertJsonStructure([
                'property_id',
                'portal_links' => [['portal', 'label', 'status', 'url', 'ref']],
            ]);

        $keys = collect($res->json('portal_links'))->pluck('portal')->all();
        $this->assertEqualsCanonicalizing(
            ['website', 'property24', 'private_property'],
            $keys
        );
    }

    /** A live P24 listing yields status=live + a non-null openable url. */
    public function test_active_property24_listing_is_live_with_url(): void
    {
        $suburbId = $this->makeP24Suburb();
        $property = $this->makeProperty([
            'p24_ref'                => '115847291',
            'p24_syndication_status' => 'active',
            'p24_suburb_id'          => $suburbId,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}/portal-links");

        $p24 = collect($res->json('portal_links'))->firstWhere('portal', 'property24');
        $this->assertSame('live', $p24['status']);
        $this->assertNotNull($p24['url']);
        $this->assertStringContainsString('property24.com', $p24['url']);
        // Uses the P24 suburb id, not PP's, in the slug path.
        $this->assertStringContainsString("/{$suburbId}/", $p24['url']);
    }

    /** Not-yet-syndicated portals are present but url-less, never crashing the list. */
    public function test_unsyndicated_portal_is_not_published_with_null_url(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}/portal-links");

        $pp = collect($res->json('portal_links'))->firstWhere('portal', 'private_property');
        $this->assertSame('not_published', $pp['status']);
        $this->assertNull($pp['url']);
    }

    /** Overview carries the full canonical list plus the live-only legacy key. */
    public function test_overview_includes_portal_links_and_legacy_placements(): void
    {
        $suburbId = $this->makeP24Suburb();
        $property = $this->makeProperty([
            'p24_ref'                => '115847291',
            'p24_syndication_status' => 'active',
            'p24_suburb_id'          => $suburbId,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}/overview");

        $res->assertOk();
        // Full list has all three; placements has only the one live portal.
        $this->assertCount(3, $res->json('portal_links'));
        $this->assertCount(1, $res->json('placements'));
        $this->assertSame('property24', $res->json('placements.0.portal'));
    }

    /** Stored relative image paths come back absolute so the device can load them. */
    public function test_image_urls_are_absolute(): void
    {
        $property = $this->makeProperty([
            'gallery_images_json' => ['/storage/properties/9/a.jpg', '/storage/properties/9/b.jpg'],
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}");

        $res->assertOk();
        // show() wraps its payload under `property`.
        foreach ($res->json('property.gallery_images') as $url) {
            $this->assertStringStartsWith('https://corex.test/', $url);
        }
        $this->assertStringStartsWith('https://corex.test/', $res->json('property.thumbnail'));
    }

    /** Cover/thumbnail = first of allImages() (web order), not gallery-only. */
    public function test_cover_image_matches_web_first_image_order(): void
    {
        // Web's allImages() order is dawn → noon → dusk → gallery → images, so a
        // dawn image wins over a gallery image as the cover.
        $property = $this->makeProperty([
            'dawn_images_json'    => ['/storage/properties/9/dawn.jpg'],
            'gallery_images_json' => ['/storage/properties/9/gallery.jpg'],
        ]);

        $expected = 'https://corex.test/storage/properties/9/dawn.jpg';

        $overview = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}/overview");
        $this->assertSame($expected, $overview->json('cover_image'));

        $list = $this->actingAs($this->user)->getJson('/api/v1/mobile/properties');
        $row  = collect($list->json('properties'))->firstWhere('id', $property->id);
        $this->assertSame($expected, $row['thumbnail']);
    }
}
