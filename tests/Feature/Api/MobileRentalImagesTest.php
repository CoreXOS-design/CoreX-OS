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
        Storage::fake('local');

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

    /** Audit B7: In/Out uploads land on the inspection under way, so the tests that upload there start one. */
    private function startInspection(Property $property, string $type = 'in'): \App\Models\RentalInspection
    {
        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->user->branch_id, 'property_id' => $property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->user->id,
        ]);

        return \App\Models\RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $lease->id, 'type' => $type, 'created_by_user_id' => $this->user->id,
        ]);
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
        $inspection = $this->startInspection($property);

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection',
                'images'  => [
                    UploadedFile::fake()->image('move-in-1.jpg'),
                    UploadedFile::fake()->image('move-in-2.jpg'),
                ],
            ]);

        $res->assertStatus(201);

        // Audit B7: the photos are the inspection's own (private, signed links - already absolute), not the old flat list.
        $images = $res->json('rental_images.in_inspection.images');
        $this->assertCount(2, $images);
        foreach ($images as $url) {
            $this->assertStringStartsWith('http', $url); // signed links are built absolute
            $this->assertStringContainsString('/private-image/', $url);
        }
        $this->assertSame(2, \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());
        $this->assertSame([], $property->fresh()->rentalImagesStructure()['in_inspection']['images']);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    /** A single per-photo upload (image + client_upload_id) appends one image. */
    public function test_per_photo_upload_appends_single(): void
    {
        $property = $this->makeProperty();
        $this->startInspection($property);

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
        $inspection = $this->startInspection($property);

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

        // One photo, one file on disk — the retry duplicated nothing.
        $this->assertSame(1, \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());
        $this->assertCount(1, Storage::disk('local')->allFiles("private-images/rental-inspections/{$this->agency->id}/{$inspection->id}"));
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

    /** Audit B27: deleting an image takes exactly that entry off the list - ARCHIVED, the file stays (no hard deletes). */
    public function test_delete_image(): void
    {
        $property = $this->makeProperty();
        Storage::disk('public')->put("properties/{$property->id}/a.jpg", 'x');
        Storage::disk('public')->put("properties/{$property->id}/b.jpg", 'y');
        $property->forceFill(['rental_images_json' => [
            'in_inspection' => ['date' => null, 'images' => ["/storage/properties/{$property->id}/a.jpg", "/storage/properties/{$property->id}/b.jpg"]],
            'out_inspection' => ['date' => null, 'images' => []], 'custom' => [],
        ]])->save();

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/delete", [
                'section' => 'in_inspection',
                'index'   => 0,
            ]);

        $res->assertOk();
        $this->assertCount(1, $res->json('rental_images.in_inspection.images'));
        Storage::disk('public')->assertExists("properties/{$property->id}/a.jpg");
        $archived = $property->fresh()->rentalImagesStructure()['archived'];
        $this->assertSame("/storage/properties/{$property->id}/a.jpg", $archived[0]['url']);
    }

    /** Audit B7: an upload with no inspection under way is refused plainly, not stored in a list nothing reads. */
    public function test_in_upload_with_no_inspection_under_way_is_refused(): void
    {
        $property = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection', 'image' => UploadedFile::fake()->image('a.jpg'),
            ])->assertStatus(409)->assertJsonPath('code', 'no_inspection_under_way');
    }

    /** Audit B7: a LET property (off-market, but exactly when its inspections happen) is eligible. */
    public function test_a_let_property_is_eligible_and_its_photos_reach_the_inspection(): void
    {
        $property = $this->makeProperty(['status' => 'let_out']);
        $inspection = $this->startInspection($property);

        $this->actingAs($this->user)->getJson("/api/v1/mobile/properties/{$property->id}")->assertOk()
            ->assertJsonPath('property.rental_inspections_available', true);
        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection', 'image' => UploadedFile::fake()->image('a.jpg'),
            ])->assertStatus(201);
        $this->assertSame(1, \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());
    }

    /**
     * HEIC (phone camera default) is accepted by the upload rule, but audit L3: one this server cannot re-encode to JPEG could never be shown
     * (the picture route serves only JPEG/PNG/WebP/GIF), so it is refused with a clear message instead of stored as a broken tile.
     */
    public function test_an_unreadable_heic_upload_is_refused_with_a_clear_message_and_stores_nothing(): void
    {
        $property = $this->makeProperty();
        $inspection = $this->startInspection($property);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection',
                // Real bytes, not create(): that only REPORTS a size over an empty temp file. (Not NUL-led: PHP would misread those as a WBMP.)
                'images'  => [UploadedFile::fake()->createWithContent('move-in.heic', str_repeat('not-a-real-HEIC-', 128))],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unreadable_image');

        $this->assertStringContainsString('HEIC', (string) $response->json('message'));
        $this->assertSame(0, \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count(), 'no file was stored');
    }

    /** Audit L1 - offline evidence for a COMPLETED inspection goes down the same flagged late-evidence path as the web. */
    public function test_offline_photos_for_a_completed_inspection_are_stored_flagged_not_refused(): void
    {
        $property = $this->makeProperty();
        $inspection = $this->startInspection($property);
        $inspection->forceFill(['status' => \App\Models\RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection',
                'image' => UploadedFile::fake()->image('late.jpg'),
                'captured_at' => now()->subHour()->toIso8601String(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('added_after_signing', true);

        $photo = \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->firstOrFail();
        $this->assertNotNull($photo->added_after_signing_at);
    }

    /** ...but a LIVE photo (no real capture time, or one taken after completion) still gets the old "nothing under way" answer. */
    public function test_a_live_photo_for_a_property_whose_inspection_is_completed_still_gets_nothing_under_way(): void
    {
        $property = $this->makeProperty();
        $inspection = $this->startInspection($property);
        $inspection->forceFill(['status' => \App\Models\RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection', 'image' => UploadedFile::fake()->image('live.jpg'),
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_inspection_under_way');
        $this->assertSame(0, \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());
    }

    /** Audit L2 - a delete names the photo by its stable id, so a list that shifted cannot archive a different one. */
    public function test_delete_by_photo_id_archives_that_photo_even_when_the_list_has_shifted(): void
    {
        $property = $this->makeProperty();
        $inspection = $this->startInspection($property);
        foreach (['a', 'b'] as $name) {
            $this->actingAs($this->user)->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/upload", [
                'section' => 'in_inspection', 'image' => UploadedFile::fake()->image("{$name}.jpg"),
            ])->assertStatus(201);
        }
        [$first, $second] = \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->orderBy('id')->pluck('id')->all();

        $list = $this->actingAs($this->user)->getJson("/api/v1/mobile/properties/{$property->id}/rental-images")->assertOk()
            ->assertJsonPath('rental_images.in_inspection.image_ids', [$first, $second]);

        // a third photo arrives from another device before the delete lands: an index would now be ambiguous, the id is not
        \App\Models\RentalInspectionPhoto::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'storage_path' => 'private:private-images/x/' . uniqid() . '.jpg', 'uploaded_by_user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/api/v1/mobile/properties/{$property->id}/rental-images/delete", [
            'section' => 'in_inspection', 'photo_id' => $second,
        ])->assertOk();

        $this->assertNull(\App\Models\RentalInspectionPhoto::find($second), 'the named photo is archived');
        $this->assertNotNull(\App\Models\RentalInspectionPhoto::withTrashed()->find($second), 'archived, never deleted');
        $this->assertNotNull(\App\Models\RentalInspectionPhoto::find($first), 'the other photos are untouched');
        $this->assertSame(2, \App\Models\RentalInspectionPhoto::where('rental_inspection_id', $inspection->id)->count());
    }

    /** A photo id from ANOTHER property (or the wrong section) archives nothing. */
    public function test_delete_by_photo_id_never_reaches_another_propertys_photo(): void
    {
        $mine = $this->makeProperty();
        $this->startInspection($mine);
        $theirs = $this->makeProperty(['title' => 'Other']);
        $otherInspection = $this->startInspection($theirs);
        $photo = \App\Models\RentalInspectionPhoto::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $otherInspection->id, 'storage_path' => 'private:private-images/y/' . uniqid() . '.jpg', 'uploaded_by_user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/api/v1/mobile/properties/{$mine->id}/rental-images/delete", [
            'section' => 'in_inspection', 'photo_id' => $photo->id,
        ])->assertOk();
        $this->assertNotNull(\App\Models\RentalInspectionPhoto::find($photo->id));

        // and the by-position path (kept for older builds) still works
        $this->actingAs($this->user)->postJson("/api/v1/mobile/properties/{$theirs->id}/rental-images/delete", [
            'section' => 'in_inspection', 'index' => 0,
        ])->assertOk();
        $this->assertNull(\App\Models\RentalInspectionPhoto::find($photo->id));
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
