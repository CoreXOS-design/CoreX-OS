<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Photo upload — the two uploaders that feed a listing's gallery:
 *   POST /corex/properties/wizard/{property}/photos   (Add Listing wizard)
 *   POST /corex/properties/{property}/upload-images   (property page)
 *
 * Regression guard. The wizard capped photos at 5MB (client filter + server
 * `max:5120`) while the property page allowed 500MB. Ordinary listing
 * photography is routinely 6-15MB, so the wizard's browser-side filter silently
 * discarded the entire selection, returned before it ever issued a request, and
 * the screen did not react at all — an agent selected her photos, clicked
 * upload, and nothing happened (reported 2026-07-13). The two uploaders must
 * accept the SAME photo, and a refusal must always be explained, never silent.
 */
final class PropertyPhotoUploadTest extends TestCase
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

        $this->agency = Agency::create([
            'name' => 'Upload Test Agency',
            'slug' => 'upload-test-' . uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'title'     => 'Upload Test Property',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    /**
     * THE bug. An 8MB photo — utterly ordinary for a listing — was rejected by
     * the wizard's `max:5120`. It must now land.
     */
    public function test_wizard_accepts_a_photo_larger_than_the_old_5mb_cap(): void
    {
        $p = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/corex/properties/wizard/{$p->id}/photos", [
                'gallery_images' => [UploadedFile::fake()->image('big.jpg')->size(8192)], // 8MB
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('uploaded', 1);

        $this->assertCount(1, $p->fresh()->gallery_images_json ?? []);
    }

    /** The property page must accept the very same photo — one limit, both uploaders. */
    public function test_property_page_accepts_a_photo_larger_than_the_old_5mb_cap(): void
    {
        $p = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/upload-images", [
                'gallery_images' => [UploadedFile::fake()->image('big.jpg')->size(8192)], // 8MB
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('added', 1);

        $this->assertCount(1, $p->fresh()->gallery_images_json ?? []);
    }

    /** Multi-select is the normal case: every photo in the batch must land. */
    public function test_wizard_stores_every_photo_in_a_multi_file_selection(): void
    {
        $p = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson("/corex/properties/wizard/{$p->id}/photos", [
                'gallery_images' => [
                    UploadedFile::fake()->image('a.jpg')->size(6000),
                    UploadedFile::fake()->image('b.jpg')->size(7000),
                    UploadedFile::fake()->image('c.jpg')->size(9000),
                ],
            ])
            ->assertOk()
            ->assertJsonPath('uploaded', 3);

        $this->assertCount(3, $p->fresh()->gallery_images_json ?? []);
    }

    /**
     * A refusal must be EXPLAINED. The JSON 422 carries the message the uploader
     * renders — silence is the failure mode we are eliminating.
     */
    public function test_wizard_rejects_a_non_image_with_a_readable_message(): void
    {
        $p = $this->makeProperty();

        $resp = $this->actingAs($this->user)
            ->postJson("/corex/properties/wizard/{$p->id}/photos", [
                'gallery_images' => [UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf')],
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('not a supported image', (string) $resp->json('message'));
        $this->assertCount(0, $p->fresh()->gallery_images_json ?? []);
    }

    public function test_property_page_rejects_a_non_image_with_a_readable_message(): void
    {
        $p = $this->makeProperty();

        $resp = $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/upload-images", [
                'gallery_images' => [UploadedFile::fake()->create('contract.pdf', 100, 'application/pdf')],
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('not a supported image', (string) $resp->json('message'));
    }

    /** Agency isolation still holds on the upload path. */
    public function test_cannot_upload_to_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']);
        $otherAgent  = User::factory()->create([
            'agency_id' => $otherAgency->id,
            'branch_id' => $otherBranch->id,
        ]);
        $foreign = Property::create([
            'title'     => 'Foreign',
            'agency_id' => $otherAgency->id,
            'agent_id'  => $otherAgent->id,
            'branch_id' => $otherBranch->id,
        ]);

        $this->actingAs($this->user)
            ->postJson("/corex/properties/wizard/{$foreign->id}/photos", [
                'gallery_images' => [UploadedFile::fake()->image('x.jpg')->size(100)],
            ])
            ->assertNotFound();
    }
}
