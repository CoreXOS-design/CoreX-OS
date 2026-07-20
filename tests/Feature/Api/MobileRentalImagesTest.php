<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Locks the mobile rental-inspection-gallery contract:
 *   - the feature is RENTAL-only AND LIVE-only (gated on both the property
 *     payload flag and every endpoint);
 *   - uploads append to the right section and persist with absolute URLs;
 *   - dates / custom sections save; images delete; scope is enforced.
 *
 * Backend for: .ai/specs/rental-images.md (Mobile API section).
 */
class MobileRentalImagesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://corex.test']);
        Storage::fake('public');

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
    }

    private function makeProperty(array $overrides = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $this->user->branch_id,
            'title'         => 'Sea-view 3 bed to let',
            'suburb'        => 'Uvongo',
            'city'          => 'Margate',
            'province'      => 'KwaZulu-Natal',
            'property_type' => 'house',
            'listing_type'  => 'rental',
            'status'        => 'active',
            'rental_amount' => 12500,
        ], $overrides));
    }

    /** A live rental advertises the feature on its property payload. */
    public function test_flag_true_on_live_rental(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}");

        $res->assertOk();
        $this->assertTrue($res->json('property.rental_inspections_available'));
    }

    /**
     * The OVERVIEW endpoint — the one the property-detail screen actually reads —
     * carries the flag at top level (overview has no `property` wrapper).
     */
    public function test_overview_includes_flag(): void
    {
        $liveRental = $this->makeProperty();
        $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$liveRental->id}/overview")
            ->assertOk()
            ->assertJsonPath('rental_inspections_available', true);

        $sale = $this->makeProperty(['listing_type' => 'sale', 'price' => 100000]);
        $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$sale->id}/overview")
            ->assertOk()
            ->assertJsonPath('rental_inspections_available', false);

        $draft = $this->makeProperty(['status' => 'draft']);
        $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$draft->id}/overview")
            ->assertOk()
            ->assertJsonPath('rental_inspections_available', false);
    }

    /** Sale listings never advertise the feature, and the endpoint refuses. */
    public function test_sale_property_is_excluded(): void
    {
        $property = $this->makeProperty(['listing_type' => 'sale', 'price' => 2495000]);

        $show = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}");
        $this->assertFalse($show->json('property.rental_inspections_available'));

        $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}/rental-images")
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_a_rental');
    }

    /** A rental that isn't live yet (draft) is gated until it goes live. */
    public function test_draft_rental_is_gated_until_live(): void
    {
        $property = $this->makeProperty(['status' => 'draft']);

        $show = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}");
        $this->assertFalse($show->json('property.rental_inspections_available'));

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/save", [
                'action'  => 'set_date',
                'section' => 'in_inspection',
                'date'    => '2026-06-27',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_live');
    }

    /** Uploading to in_inspection appends images and returns absolute URLs. */
    public function test_upload_appends_and_absolutises(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection',
                'images'  => [
                    UploadedFile::fake()->image('move-in-1.jpg'),
                    UploadedFile::fake()->image('move-in-2.jpg'),
                ],
            ]);

        $res->assertStatus(201);

        $images = $res->json('rental_images.in_inspection.images');
        $this->assertCount(2, $images);
        foreach ($images as $url) {
            $this->assertStringStartsWith('https://corex.test/storage/properties/', $url);
        }

        // Persisted on the model (stored relative; absolutised only on the way out).
        $stored = $property->fresh()->rentalImagesStructure();
        $this->assertCount(2, $stored['in_inspection']['images']);
        $this->assertStringStartsWith('/storage/properties/', $stored['in_inspection']['images'][0]);
    }

    /** A single per-photo upload (image + client_upload_id) appends one image. */
    public function test_per_photo_upload_appends_single(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section'          => 'in_inspection',
                'image'            => UploadedFile::fake()->image('move-in.jpg', 800, 600),
                'client_upload_id' => 'rk-1',
            ]);

        $res->assertStatus(201);
        $res->assertJson(['duplicate' => false]);
        $this->assertCount(1, $res->json('rental_images.in_inspection.images'));
    }

    /**
     * Idempotency parity with the main gallery: a retried per-photo upload with
     * the same client_upload_id must not add a second image or a second file —
     * it returns the existing record (200, duplicate:true).
     */
    public function test_per_photo_upload_is_idempotent_on_retry(): void
    {
        $property = $this->makeProperty();

        $first = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section'          => 'in_inspection',
                'image'            => UploadedFile::fake()->image('m.jpg', 800, 600),
                'client_upload_id' => 'rk-dup',
            ]);
        $first->assertStatus(201)->assertJson(['duplicate' => false]);

        $retry = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section'          => 'in_inspection',
                'image'            => UploadedFile::fake()->image('m.jpg', 800, 600),
                'client_upload_id' => 'rk-dup',
            ]);
        $retry->assertStatus(200)->assertJson(['duplicate' => true]);

        // One image in the section, one file on disk — the retry duplicated nothing.
        $stored = $property->fresh()->rentalImagesStructure();
        $this->assertCount(1, $stored['in_inspection']['images']);
        $this->assertCount(1, Storage::disk('public')->files("properties/{$property->id}"));
    }

    /** Set a date, add a custom section (server mints id), then upload into it. */
    public function test_save_date_and_add_custom_section(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/save", [
                'action'  => 'set_date',
                'section' => 'out_inspection',
                'date'    => '2026-07-01',
            ])
            ->assertOk()
            ->assertJsonPath('rental_images.out_inspection.date', '2026-07-01');

        $add = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/save", [
                'action' => 'add_section',
                'name'   => 'Garden handover',
            ]);
        $add->assertOk();

        $custom = $add->json('rental_images.custom');
        $this->assertCount(1, $custom);
        $this->assertSame('Garden handover', $custom[0]['name']);
        $sectionId = $custom[0]['id'];

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section'   => 'custom',
                'custom_id' => $sectionId,
                'images'    => [UploadedFile::fake()->image('garden.jpg')],
            ])
            ->assertStatus(201)
            ->assertJsonPath('rental_images.custom.0.images', fn ($imgs) => count($imgs) === 1);
    }

    /** Deleting an image removes exactly that entry and its file. */
    public function test_delete_image(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection',
                'images'  => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])->assertStatus(201);

        $before  = $property->fresh()->rentalImagesStructure()['in_inspection']['images'];
        $relPath = str_replace('/storage/', '', $before[0]);
        Storage::disk('public')->assertExists($relPath);

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/delete", [
                'section' => 'in_inspection',
                'index'   => 0,
            ]);

        $res->assertOk();
        $this->assertCount(1, $res->json('rental_images.in_inspection.images'));
        Storage::disk('public')->assertMissing($relPath);
    }

    /** HEIC (phone camera default) is accepted, not rejected by the image rule. */
    public function test_heic_upload_is_accepted(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection',
                'images'  => [UploadedFile::fake()->create('move-in.heic', 200, 'image/heic')],
            ])
            ->assertStatus(201)
            ->assertJsonPath('rental_images.in_inspection.images', fn ($imgs) => count($imgs) === 1);
    }

    /** A date in any parseable format comes back canonicalised to Y-m-d. */
    public function test_date_is_normalised_to_ymd(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/save", [
                'action'  => 'set_date',
                'section' => 'in_inspection',
                'date'    => '2026/07/01',
            ])
            ->assertOk()
            ->assertJsonPath('rental_images.in_inspection.date', '2026-07-01');
    }

    /** A stale custom_id is a 404 on upload, save (rename) and delete alike. */
    public function test_stale_custom_id_is_404(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section'   => 'custom',
                'custom_id' => 'nope12',
                'images'    => [UploadedFile::fake()->image('x.jpg')],
            ])->assertStatus(404);

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/save", [
                'action'    => 'rename_section',
                'custom_id' => 'nope12',
                'name'      => 'Renamed',
            ])->assertStatus(404);

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/delete", [
                'section'   => 'custom',
                'custom_id' => 'nope12',
                'index'     => 0,
            ])->assertStatus(404);
    }

    /**
     * A same-agency agent who doesn't own the listing and has only 'own' scope
     * is rejected by the scope guard (403). (Cross-agency access is a 404 — the
     * agency global scope hides the row before the controller is reached.)
     */
    public function test_out_of_scope_user_rejected(): void
    {
        $property = $this->makeProperty();

        $colleague = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->user->branch_id,
            'role'      => 'agent',
        ]);

        $this->actingAs($colleague)
            ->getJson("/api/v1/mobile/properties/{$property->id}/rental-images")
            ->assertStatus(403);
    }
}
