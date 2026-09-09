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
 * .ai/specs/mobile-gallery-sort.md — drag-reorder photos (master grid or one
 * tag's bucket) and drag-reorder the tag list itself, from the mobile app.
 * Both were previously web-only: the web sorter wrote gallery_images_json /
 * gallery_categories_json / gallery_tag_order in one combined save, but mobile
 * had no write endpoint for either kind of order.
 */
class MobileGallerySortTest extends TestCase
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

    public function test_reorders_the_master_gallery_grid(): void
    {
        $property = $this->makeProperty();
        $a = $this->uploadUntagged($property, 'a.jpg');
        $b = $this->uploadUntagged($property, 'b.jpg');
        $c = $this->uploadUntagged($property, 'c.jpg');

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/reorder", [
                'images' => [$c, $a, $b],
            ]);

        $res->assertStatus(200);
        $this->assertSame([$c, $a, $b], $property->fresh()->gallery_images_json);
    }

    public function test_a_missing_url_is_kept_and_appended_not_deleted(): void
    {
        $property = $this->makeProperty();
        $a = $this->uploadUntagged($property, 'a.jpg');
        $b = $this->uploadUntagged($property, 'b.jpg');

        // Client only sends b — a stale/partial array must never drop a.
        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/reorder", [
                'images' => [$b],
            ]);

        $res->assertStatus(200);
        $this->assertSame([$b, $a], $property->fresh()->gallery_images_json);
    }

    public function test_an_unknown_url_is_reported_not_silently_accepted(): void
    {
        $property = $this->makeProperty();
        $a = $this->uploadUntagged($property, 'a.jpg');

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/reorder", [
                'images' => [$a, '/storage/properties/999999/not-mine.jpg'],
            ]);

        $res->assertStatus(200);
        $res->assertJsonCount(1, 'unknown_images');
        $this->assertSame([$a], $property->fresh()->gallery_images_json);
    }

    public function test_reorders_photos_within_one_tag_without_touching_the_master_grid(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [['type' => 'Kitchen', 'count' => 1]]],
        ]);
        $a = $this->uploadUntagged($property, 'a.jpg');
        $b = $this->uploadUntagged($property, 'b.jpg');

        $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/assign", [
                'images'   => [$a, $b],
                'room_tag' => 'Kitchen',
            ])->assertStatus(200);

        $originalGrid = $property->fresh()->gallery_images_json;

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/reorder", [
                'images'   => [$b, $a],
                'room_tag' => 'Kitchen',
            ]);

        $res->assertStatus(200);
        $fresh = $property->fresh();
        $cats  = collect($fresh->gallery_categories_json['categories'] ?? []);
        $this->assertSame([$b, $a], $cats->firstWhere('name', 'Kitchen')['images']);
        $this->assertSame($originalGrid, $fresh->gallery_images_json, 'Master grid order must be untouched.');
    }

    public function test_reorder_rejects_a_tag_that_is_not_on_the_property(): void
    {
        $property = $this->makeProperty();
        $a = $this->uploadUntagged($property, 'a.jpg');

        $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/reorder", [
                'images'   => [$a],
                'room_tag' => 'Definitely Not A Space',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['room_tag'], 'available_tags']);
    }

    public function test_reorder_rejects_a_stale_fingerprint(): void
    {
        $property = $this->makeProperty();
        $a = $this->uploadUntagged($property, 'a.jpg');
        $b = $this->uploadUntagged($property, 'b.jpg');

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/reorder", [
                'images'              => [$b, $a],
                'gallery_fingerprint' => 'not-the-real-fingerprint',
            ]);

        $res->assertStatus(409);
        $res->assertJsonPath('stale', true);
        $this->assertSame([$a, $b], $property->fresh()->gallery_images_json, 'A stale save must change nothing.');
    }

    public function test_reorders_the_tag_list(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [
                ['type' => 'Kitchen', 'count' => 1],
                ['type' => 'Lounge',  'count' => 1],
                ['type' => 'Patio',   'count' => 1],
            ]],
        ]);

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/tags/reorder", [
                'tags' => ['Patio', 'Kitchen', 'Lounge'],
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('available_tags', ['Patio', 'Kitchen', 'Lounge']);
        $this->assertSame(['Patio', 'Kitchen', 'Lounge'], $property->fresh()->gallery_tag_order);
    }

    public function test_a_tag_omitted_from_the_order_is_appended_not_stranded(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [
                ['type' => 'Kitchen', 'count' => 1],
                ['type' => 'Lounge',  'count' => 1],
            ]],
        ]);

        // Only Lounge sent — Kitchen must still show up, appended at the end.
        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/tags/reorder", [
                'tags' => ['Lounge'],
            ]);

        $res->assertStatus(200);
        $res->assertJsonPath('available_tags', ['Lounge', 'Kitchen']);
    }

    public function test_tag_reorder_rejects_an_unknown_tag_and_changes_nothing(): void
    {
        $property = $this->makeProperty([
            'spaces_json' => ['spaces' => [['type' => 'Kitchen', 'count' => 1]]],
        ]);

        $res = $this->actingAs($this->user)
            ->putJson("/api/v1/mobile/properties/{$property->id}/gallery/tags/reorder", [
                'tags' => ['Kitchen', 'Not A Real Tag'],
            ]);

        $res->assertStatus(422);
        $res->assertJsonStructure(['message', 'errors' => ['tags'], 'available_tags']);
        $this->assertSame([], $property->fresh()->gallery_tag_order ?? []);
    }
}
