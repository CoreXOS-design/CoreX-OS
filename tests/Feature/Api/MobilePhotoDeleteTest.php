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
 * Taking a photo back off a listing from the phone.
 *
 * Needed once the app began enqueuing at the shutter and draining without
 * waiting for the camera to close: a photo the agent deletes in review may
 * already be on the server. Until this endpoint the app could add photos and
 * never remove them, and the agent was told to go open the web app.
 *
 * Spec: .ai/specs/mobile-gallery-tagging.md
 */
class MobilePhotoDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Property $property;

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
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'agent_id' => $this->user->id,
            'branch_id' => $branch->id, 'title' => 'Sea-view 3 bed', 'suburb' => 'Margate',
            'property_type' => 'house', 'listing_type' => 'sale', 'status' => 'active',
            'price' => 2495000,
        ]);
    }

    private function upload(string $clientUploadId): string
    {
        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images", [
                'image'            => UploadedFile::fake()->image('p.jpg', 800, 600),
                'client_upload_id' => $clientUploadId,
            ])->assertStatus(201);

        $g = $this->property->fresh()->gallery_images_json;

        return $g[count($g) - 1];
    }

    public function test_a_photo_can_be_deleted_by_its_client_upload_id(): void
    {
        $this->upload('shot_1');

        $res = $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images/delete", [
                'client_upload_ids' => ['shot_1'],
            ]);

        $res->assertStatus(200)->assertJsonPath('deleted', 1);
        $this->assertSame([], $this->property->fresh()->gallery_images_json);
    }

    public function test_a_photo_can_be_deleted_by_url(): void
    {
        $url = $this->upload('shot_2');

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images/delete", [
                'images' => [$url],
            ])->assertStatus(200)->assertJsonPath('deleted', 1);

        $this->assertSame([], $this->property->fresh()->gallery_images_json);
    }

    public function test_a_deleted_photo_is_not_resurrected_by_a_retry(): void
    {
        // The queue may still hold this photo and retry it. The upload key is
        // deliberately left in place so the retry short-circuits: a photo the
        // agent deleted must stay deleted even if the phone tries again.
        $this->upload('shot_3');

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images/delete", [
                'client_upload_ids' => ['shot_3'],
            ])->assertStatus(200);

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images", [
                'image'            => UploadedFile::fake()->image('p.jpg', 800, 600),
                'client_upload_id' => 'shot_3',
            ])->assertStatus(200)->assertJsonPath('duplicate', true);

        $this->assertSame([], $this->property->fresh()->gallery_images_json,
            'A retry of a deleted photo must not put it back in the gallery.');
    }

    public function test_deleting_also_clears_it_from_its_room(): void
    {
        $property = $this->property;
        $property->update(['spaces_json' => ['spaces' => [['type' => 'Kitchen', 'count' => 1]]]]);

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images", [
                'image'            => UploadedFile::fake()->image('k.jpg', 800, 600),
                'client_upload_id' => 'shot_4',
                'room_tag'         => 'Kitchen',
            ])->assertStatus(201);

        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$property->id}/images/delete", [
                'client_upload_ids' => ['shot_4'],
            ])->assertStatus(200);

        $cats = collect($property->fresh()->gallery_categories_json['categories'] ?? []);
        $this->assertSame([], $cats->firstWhere('name', 'Kitchen')['images'] ?? [],
            'A deleted photo must not linger under its room.');
    }

    public function test_an_unknown_photo_reports_404_rather_than_pretending(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images/delete", [
                'client_upload_ids' => ['never_existed'],
            ])
            ->assertStatus(404)
            ->assertJsonPath('deleted', 0);
    }

    public function test_an_empty_request_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson("/api/v1/mobile/properties/{$this->property->id}/images/delete", [])
            ->assertStatus(422);
    }
}
