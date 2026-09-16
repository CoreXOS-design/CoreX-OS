<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Jobs\DownloadPortalPropertyImages;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Gallery invariant: every URL in gallery_images_json appears exactly once in
 * gallery_categories_json — in one category's `images` or in `unsorted`.
 *
 * Regression guard. The mobile app builds its room-by-room gallery from
 * gallery_categories_json alone. The web create form wrote photos into
 * gallery_images_json and never filed them; the web edit form only filed them
 * when an image_category was sent. A listing with a full web gallery therefore
 * read "0 photos" in the app. Every web writer now files what it stores
 * (Property::syncGalleryCategories), the mobile read side back-fills unfiled
 * photos into `unsorted` for rows written before the fix, and
 * properties:sync-gallery-categories repairs those rows for good.
 */
final class GalleryCategorySyncTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        PermissionService::clearCache();
        config(['app.url' => 'https://corex.test']);

        $this->agency = Agency::create([
            'name' => 'Gallery Sync Agency',
            'slug' => 'gallery-sync-' . uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    // ── Web create ──────────────────────────────────────────────────────

    public function test_web_store_files_every_gallery_photo_into_unsorted(): void
    {
        $payload = $this->storePayload();
        $payload['gallery_images'] = [
            UploadedFile::fake()->image('a.jpg', 800, 600),
            UploadedFile::fake()->image('b.jpg', 800, 600),
            UploadedFile::fake()->image('c.jpg', 800, 600),
        ];

        $this->actingAs($this->user)
            ->post(route('corex.properties.store'), $payload)
            ->assertSessionHasNoErrors();

        $property = Property::withoutGlobalScopes()->where('title', $payload['title'])->firstOrFail();

        $this->assertCount(3, $property->gallery_images_json);
        $this->assertSame([], $property->gallery_categories_json['categories']);
        $this->assertSame(
            $property->gallery_images_json,
            $property->gallery_categories_json['unsorted'],
            'every photo stored by the create form must be filed under unsorted'
        );
    }

    // ── Web edit ────────────────────────────────────────────────────────

    public function test_web_update_without_category_files_new_photos_into_unsorted(): void
    {
        $property = $this->makeProperty([
            'status'             => 'draft',
            'gallery_images_json' => ['/storage/properties/1/old.jpg'],
        ]);

        $this->actingAs($this->user)
            ->put(route('corex.properties.update', $property), [
                'title'          => $property->title,
                'gallery_images' => [
                    UploadedFile::fake()->image('n1.jpg', 800, 600),
                    UploadedFile::fake()->image('n2.jpg', 800, 600),
                ],
            ])
            ->assertSessionHasNoErrors();

        $property->refresh();

        $this->assertCount(3, $property->gallery_images_json);
        $this->assertSame([], $property->gallery_categories_json['categories']);
        $this->assertEqualsCanonicalizing(
            $property->gallery_images_json,
            $property->gallery_categories_json['unsorted'],
            'the pre-existing unfiled photo AND both new uploads land in unsorted'
        );
    }

    public function test_web_update_with_category_files_new_photos_into_that_room_only(): void
    {
        $property = $this->makeProperty(['status' => 'draft']);

        $this->actingAs($this->user)
            ->put(route('corex.properties.update', $property), [
                'title'          => $property->title,
                'image_category' => 'Kitchen',
                'gallery_images' => [
                    UploadedFile::fake()->image('k1.jpg', 800, 600),
                    UploadedFile::fake()->image('k2.jpg', 800, 600),
                ],
            ])
            ->assertSessionHasNoErrors();

        $property->refresh();
        $cats = $property->gallery_categories_json;

        $this->assertCount(2, $property->gallery_images_json);
        $this->assertSame('Kitchen', $cats['categories'][0]['name']);
        $this->assertSame($property->gallery_images_json, $cats['categories'][0]['images']);
        $this->assertSame([], $cats['unsorted'], 'a photo filed under a room is never also in unsorted');
    }

    public function test_upload_images_endpoint_files_new_photos_into_unsorted(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.upload-images', $property), [
                'gallery_images' => [UploadedFile::fake()->image('u1.jpg', 800, 600)],
            ])
            ->assertOk()
            ->assertJsonPath('added', 1);

        $property->refresh();

        $this->assertCount(1, $property->gallery_images_json);
        $this->assertSame($property->gallery_images_json, $property->gallery_categories_json['unsorted']);
    }

    // ── Mobile read side ────────────────────────────────────────────────

    public function test_mobile_show_lists_unfiled_photos_under_unsorted(): void
    {
        $urls = [
            '/storage/properties/9/a.jpg',
            '/storage/properties/9/b.jpg',
            '/storage/properties/9/c.jpg',
        ];
        $property = $this->makeProperty([
            'gallery_images_json'     => $urls,
            'gallery_categories_json' => null,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}")
            ->assertOk();

        $this->assertSame(
            array_map(fn ($u) => 'https://corex.test' . $u, $urls),
            $res->json('property.gallery_categories.unsorted'),
            'a row written before the fix must still show every photo in the app'
        );

        // A GET never writes.
        $this->assertNull($property->fresh()->gallery_categories_json);
    }

    public function test_a_photo_filed_in_a_room_is_not_duplicated_into_unsorted(): void
    {
        // The room holds the ABSOLUTE url the app was handed; the master list
        // holds the host-relative path. Same photo — must match on path.
        $property = $this->makeProperty([
            'gallery_images_json'     => ['/storage/properties/9/a.jpg', '/storage/properties/9/b.jpg'],
            'gallery_categories_json' => [
                'categories' => [['name' => 'Kitchen', 'images' => ['https://corex.test/storage/properties/9/a.jpg']]],
                'unsorted'   => [],
            ],
        ]);

        // Read side.
        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}")
            ->assertOk();

        $this->assertSame(
            ['https://corex.test/storage/properties/9/a.jpg'],
            $res->json('property.gallery_categories.categories.Kitchen')
        );
        $this->assertSame(
            ['https://corex.test/storage/properties/9/b.jpg'],
            $res->json('property.gallery_categories.unsorted'),
            'the photo already in Kitchen must not be repeated in unsorted'
        );

        // Write side.
        $this->assertTrue($property->syncGalleryCategories());
        $this->assertSame(
            [['name' => 'Kitchen', 'images' => ['https://corex.test/storage/properties/9/a.jpg']]],
            $property->gallery_categories_json['categories']
        );
        $this->assertSame(['/storage/properties/9/b.jpg'], $property->gallery_categories_json['unsorted']);

        // And it is a fixed point.
        $this->assertFalse($property->syncGalleryCategories());
    }

    public function test_mobile_show_drops_a_stale_filed_url_that_left_the_master_list(): void
    {
        // 'gone.jpg' is filed under Kitchen but no longer in the master list —
        // buildGalleryCategories() must reconcile like every other reader
        // instead of echoing the stale stored row back to the app.
        $property = $this->makeProperty([
            'gallery_images_json'     => ['/storage/properties/9/a.jpg'],
            'gallery_categories_json' => [
                'categories' => [['name' => 'Kitchen', 'images' => ['/storage/properties/9/gone.jpg']]],
                'unsorted'   => [],
            ],
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}")
            ->assertOk();

        $this->assertSame([], $res->json('property.gallery_categories.categories.Kitchen'));
        $this->assertSame(
            ['https://corex.test/storage/properties/9/a.jpg'],
            $res->json('property.gallery_categories.unsorted')
        );

        // A GET never writes — the stale row is still stale in the database.
        $this->assertSame(
            ['/storage/properties/9/gone.jpg'],
            $property->fresh()->gallery_categories_json['categories'][0]['images']
        );
    }

    public function test_sync_drops_a_filed_url_that_left_the_master_list(): void
    {
        $property = $this->makeProperty([
            'gallery_images_json'     => ['/storage/properties/9/a.jpg'],
            'gallery_categories_json' => [
                'categories' => [['name' => 'Kitchen', 'images' => ['/storage/properties/9/gone.jpg']]],
                'unsorted'   => ['/storage/properties/9/a.jpg', '/storage/properties/9/a.jpg'],
            ],
        ]);

        $this->assertTrue($property->syncGalleryCategoriesLocked());

        $cats = $property->fresh()->gallery_categories_json;
        $this->assertSame([['name' => 'Kitchen', 'images' => []]], $cats['categories'], 'the room stays (its name is a tag); the dead url goes');
        $this->assertSame(['/storage/properties/9/a.jpg'], $cats['unsorted'], 'de-duplicated');
    }

    // ── Backfill command ────────────────────────────────────────────────

    public function test_backfill_command_is_idempotent(): void
    {
        $needsSync = $this->makeProperty([
            'title'                   => 'Needs sync',
            'gallery_images_json'     => ['/storage/properties/9/a.jpg', '/storage/properties/9/b.jpg'],
            'gallery_categories_json' => null,
        ]);
        $consistent = $this->makeProperty([
            'title'                   => 'Already fine',
            'gallery_images_json'     => ['/storage/properties/10/a.jpg'],
            'gallery_categories_json' => ['categories' => [], 'unsorted' => ['/storage/properties/10/a.jpg']],
        ]);

        $this->artisan('properties:sync-gallery-categories', ['--dry-run' => true])
            ->expectsOutputToContain('Would change 1 rows')
            ->assertSuccessful();
        $this->assertNull($needsSync->fresh()->gallery_categories_json, 'dry run writes nothing');

        $this->artisan('properties:sync-gallery-categories')
            ->expectsOutputToContain('Changed 1 rows')
            ->assertSuccessful();

        // assertEquals: MySQL re-orders JSON object keys on storage; the shape,
        // not the key order, is the contract.
        $this->assertEquals(
            ['categories' => [], 'unsorted' => ['/storage/properties/9/a.jpg', '/storage/properties/9/b.jpg']],
            $needsSync->fresh()->gallery_categories_json
        );
        $this->assertEquals(
            ['categories' => [], 'unsorted' => ['/storage/properties/10/a.jpg']],
            $consistent->fresh()->gallery_categories_json
        );

        $this->artisan('properties:sync-gallery-categories')
            ->expectsOutputToContain('Changed 0 rows')
            ->assertSuccessful();
    }

    public function test_portal_pull_job_files_downloaded_photos_into_categories(): void
    {
        Http::fake(fn () => Http::response(Str::random(2500), 200, ['Content-Type' => 'image/jpeg']));

        $property = $this->makeProperty(['gallery_images_json' => null, 'gallery_categories_json' => null]);

        (new DownloadPortalPropertyImages($property->id, 90000, 2))->handle();

        $property->refresh();
        $this->assertCount(2, $property->gallery_images_json);

        // Every writer of gallery_images_json must also file into
        // gallery_categories_json, or the mobile app's room-by-room gallery
        // (built from categories alone) shows 0 photos for a portal pull.
        $this->assertCount(2, $property->gallery_categories_json['unsorted'] ?? []);
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title'        => 'Gallery Sync Property ' . Str::random(4),
            'agency_id'    => $this->agency->id,
            'agent_id'     => $this->user->id,
            'branch_id'    => $this->branch->id,
            'listing_type' => 'sale',
            'status'       => 'active',
        ], $attrs));
    }

    /**
     * The create form's minimum: store() enforces a P24-recognised suburb and
     * a linked contact (see PropertyUploadContactTest).
     */
    private function storePayload(): array
    {
        $countryId = (int) DB::table('p24_countries')->insertGetId([
            'p24_id' => 90000, 'name' => 'South Africa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId([
            'p24_id' => 90001, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cityId = (int) DB::table('p24_cities')->insertGetId([
            'p24_id' => 90002, 'p24_province_id' => $provinceId, 'name' => 'Hibiscus Coast',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId([
            'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(4),
            'p24_id' => 90003, 'p24_city_id' => $cityId, 'confirmed' => 1,
            'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'title'         => 'Gallery Store Listing ' . Str::random(4),
            'price'         => 1_500_000,
            'beds'          => 3,
            'baths'         => 2,
            'garages'       => 1,
            'suburb'        => 'Uvongo',
            'listing_type'  => 'sale',
            'agent_id'      => $this->user->id,
            'p24_suburb_id' => $suburbId,
            'pending_new_contacts' => [
                ['first_name' => 'Owner', 'last_name' => 'Gallery', 'phone' => '0825550001'],
            ],
        ];
    }
}
