<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Property 15936, 2026-08-28. An agent shot 35 photos on the mobile app and
 * reported that "some didn't upload and the tagging didn't work". All 35 had
 * uploaded. 27 of them went up from the app's offline queue, which does not
 * carry room_tag, so they arrived untagged — and the gallery payload dropped
 * the unsorted bucket entirely, so those 27 appeared under no room anywhere in
 * the app. There was also no endpoint to file a photo after upload, so they
 * could not be rescued from the phone at all.
 *
 * These tests lock the web-side half of that fix:
 *   - untagged photos are RETURNED (they can never be invisible again);
 *   - an already-uploaded photo can be filed, re-filed and un-filed;
 *   - every space type in the catalogue can be used as a tag, not just the
 *     eleven that used to be hard-coded.
 */
class MobileGalleryTaggingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

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
            'title'         => 'Sea-view 3 bed',
            'suburb'        => 'Margate',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ], $overrides));
    }

    /** Upload one untagged photo and return its stored gallery URL. */
    private function uploadUntagged(Property $property, string $name = 'photo.jpg'): string
    {
        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images", [
                'image' => UploadedFile::fake()->image($name, 800, 600),
            ])->assertStatus(201);

        return $property->fresh()->gallery_images_json[count($property->fresh()->gallery_images_json) - 1];
    }

    public function test_untagged_photos_are_returned_in_the_gallery_payload(): void
    {
        $property = $this->makeProperty();
        $this->uploadUntagged($property);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/mobile/properties/{$property->id}");

        $res->assertStatus(200);

        // The regression: this key did not exist, so a photo with no room tag
        // was on the property but on no screen.
        $res->assertJsonCount(1, 'gallery_categories.unsorted');
    }

    public function test_an_uploaded_photo_can_be_filed_under_a_room_afterwards(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [['type' => 'Kitchen', 'count' => 1]]],
        ]);
        $url = $this->uploadUntagged($property);

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                'images'   => [$url],
                'room_tag' => 'Kitchen',
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('moved', 1);

        $property->refresh();
        $cats = collect($property->gallery_categories_json['categories'] ?? []);
        $this->assertSame([$url], $cats->firstWhere('name', 'Kitchen')['images']);
        $this->assertSame([], $property->gallery_categories_json['unsorted']);
    }

    public function test_refiling_moves_the_photo_and_never_duplicates_it(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [
                ['type' => 'Kitchen', 'count' => 1],
                ['type' => 'Lounge',  'count' => 1],
            ]],
        ]);
        $url = $this->uploadUntagged($property);

        foreach (['Kitchen', 'Lounge'] as $tag) {
            $this->actingAs($this->user)
                ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                    'images'   => [$url],
                    'room_tag' => $tag,
                ])->assertStatus(200);
        }

        $property->refresh();
        $cats = collect($property->gallery_categories_json['categories'] ?? []);

        $this->assertSame([$url], $cats->firstWhere('name', 'Lounge')['images']);
        $this->assertSame([], $cats->firstWhere('name', 'Kitchen')['images'] ?? [],
            'A re-filed photo must leave its previous room, not live in both.');
        $this->assertSame([], $property->gallery_categories_json['unsorted']);
    }

    public function test_a_photo_can_be_returned_to_unsorted(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [['type' => 'Kitchen', 'count' => 1]]],
        ]);
        $url = $this->uploadUntagged($property);

        $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                'images' => [$url], 'room_tag' => 'Kitchen',
            ])->assertStatus(200);

        $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                'images' => [$url], 'room_tag' => null,
            ])->assertStatus(200);

        $property->refresh();
        $this->assertSame([$url], $property->gallery_categories_json['unsorted']);
    }

    public function test_assign_rejects_a_tag_that_is_not_on_the_property(): void
    {
        $property = $this->makeProperty();
        $url = $this->uploadUntagged($property);

        $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                'images'   => [$url],
                'room_tag' => 'Definitely Not A Space',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['room_tag'], 'available_tags']);
    }

    public function test_assign_reports_an_image_that_is_not_on_this_property(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [['type' => 'Kitchen', 'count' => 1]]],
        ]);

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                'images'   => ['/storage/properties/999999/not-mine.jpg'],
                'room_tag' => 'Kitchen',
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('moved', 0);
        $res->assertJsonCount(1, 'unknown_images');
    }

    public function test_a_space_outside_the_legacy_eleven_can_be_tagged(): void
    {
        // Entrance Hall is in config('property-spaces.all_space_types') and was
        // NOT in the hard-coded whitelist — the agent could add the room but
        // then had no tag to file its photos under.
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [['type' => 'Entrance Hall', 'count' => 1]]],
        ]);

        $this->assertContains('Entrance Hall', $property->getAvailableGalleryTags());

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images", [
                'image'    => UploadedFile::fake()->image('hall.jpg', 800, 600),
                'room_tag' => 'Entrance Hall',
            ])->assertStatus(201);

        $cats = collect($property->fresh()->gallery_categories_json['categories'] ?? []);
        $this->assertCount(1, $cats->firstWhere('name', 'Entrance Hall')['images']);
    }
}
