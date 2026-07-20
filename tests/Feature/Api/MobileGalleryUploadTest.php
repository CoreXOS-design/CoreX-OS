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
 * Web-side robustness for the mobile gallery upload
 * (POST /api/v1/mobile/properties/{id}/images):
 *  - a valid tag sent with a different case must NOT 422 (it is resolved
 *    case-insensitively to the property's canonical tag);
 *  - an unknown tag still 422s cleanly (not 500);
 *  - a tagless upload lands in the unsorted bucket.
 */
class MobileGalleryUploadTest extends TestCase
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
            'suburb'        => 'Uvongo',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ], $overrides));
    }

    public function test_a_valid_tag_with_different_casing_is_accepted_and_canonicalised(): void
    {
        // The property's tag library holds "Kitchen"; the app sends "kitchen".
        $property = $this->makeProperty(['gallery_custom_tags' => ['Kitchen']]);

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images", [
                'image'    => UploadedFile::fake()->image('photo.jpg', 1200, 900),
                'room_tag' => 'kitchen',
            ]);

        $res->assertStatus(201);

        $property->refresh();
        $cats = collect($property->gallery_categories_json['categories'] ?? []);
        $this->assertTrue($cats->contains('name', 'Kitchen'),
            'Photo must file under the canonical "Kitchen" category, not a new "kitchen".');
        $this->assertFalse($cats->contains('name', 'kitchen'),
            'A differently-cased duplicate category must never be created.');
    }

    public function test_an_unknown_tag_is_rejected_with_422_not_500(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images", [
                'image'    => UploadedFile::fake()->image('photo.jpg', 800, 600),
                'room_tag' => 'Definitely Not A Space',
            ]);

        $res->assertStatus(422);
        $res->assertJsonStructure(['message', 'errors' => ['room_tag'], 'available_tags']);
    }

    public function test_a_tagless_upload_lands_in_unsorted(): void
    {
        $property = $this->makeProperty();

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images", [
                'image' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            ]);

        $res->assertStatus(201);

        $property->refresh();
        $this->assertCount(1, $property->gallery_categories_json['unsorted'] ?? []);
    }
}
