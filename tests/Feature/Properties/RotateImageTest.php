<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Gallery image rotation endpoint — POST /corex/properties/{property}/rotate-image.
 * Spec: .ai/specs/gallery-image-rotation.md
 *
 * Verifies: rotation persists to a new file + remaps the URL across the gallery
 * list AND the URL-keyed category map; path/cross-property/cross-agency safety;
 * and input validation.
 */
final class RotateImageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Unseeded role_permissions → permission granted (graceful test path).
        PermissionService::clearCache();

        $this->agency = Agency::create([
            'name' => 'Rotate Test Agency',
            'slug' => 'rotate-test-' . uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title'     => 'Rotate Test Property',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
        ], $attrs));
    }

    /** Store a real JPEG of the given dimensions and return [relativePath, publicUrl]. */
    private function storeJpeg(int $propertyId, int $w, int $h, string $name = 'orig.jpg'): array
    {
        $gd = imagecreatetruecolor($w, $h);
        imagefilledrectangle($gd, 0, 0, $w, $h, imagecolorallocate($gd, 20, 120, 200));
        ob_start();
        imagejpeg($gd);
        $bytes = (string) ob_get_clean();
        imagedestroy($gd);

        $rel = "properties/{$propertyId}/{$name}";
        Storage::disk('public')->put($rel, $bytes);

        return [$rel, Storage::disk('public')->url($rel)];
    }

    private function relFromUrl(string $url): string
    {
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return str_starts_with($path, 'storage/') ? substr($path, strlen('storage/')) : $path;
    }

    public function test_rotation_persists_to_new_file_and_remaps_urls(): void
    {
        if (! function_exists('imagerotate')) {
            $this->markTestSkipped('GD imagerotate() not available in this PHP CLI.');
        }

        $p = $this->makeProperty();
        [$oldRel, $oldUrl] = $this->storeJpeg($p->id, 100, 60);

        $p->update([
            'gallery_images_json'     => [$oldUrl],
            'gallery_categories_json' => [
                'categories' => [['name' => 'Lounge', 'images' => [$oldUrl]]],
                'unsorted'   => [],
            ],
        ]);

        $resp = $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/rotate-image", ['image_url' => $oldUrl, 'degrees' => 90]);

        $resp->assertOk()->assertJsonPath('ok', true);
        $newUrl = $resp->json('url');
        $this->assertNotSame($oldUrl, $newUrl);

        // URL swapped in the flat list AND in the URL-keyed category map.
        $p->refresh();
        $this->assertSame([$newUrl], $p->gallery_images_json);
        $this->assertSame($newUrl, $p->gallery_categories_json['categories'][0]['images'][0]);

        // Old file removed, new file written, dimensions swapped by the 90° turn.
        $this->assertFalse(Storage::disk('public')->exists($oldRel));
        $newRel = $this->relFromUrl($newUrl);
        $this->assertTrue(Storage::disk('public')->exists($newRel));
        [$nw, $nh] = getimagesize(Storage::disk('public')->path($newRel));
        $this->assertSame(60, $nw);
        $this->assertSame(100, $nh);
    }

    /**
     * The original may only be unlinked once the new URL is committed. If the
     * image is not in the gallery the swap matches nothing, so deleting it would
     * strand the listing on a missing file — which is precisely the state that
     * made PrivateProperty reject every update of property 6060 (PP120 404).
     * The rotated copy is discarded and the original survives.
     */
    public function test_rotation_that_cannot_be_persisted_leaves_the_original_intact(): void
    {
        if (! function_exists('imagerotate')) {
            $this->markTestSkipped('GD imagerotate() not available in this PHP CLI.');
        }

        $p = $this->makeProperty();
        // File exists on disk, but is NOT referenced by the gallery.
        [$orphanRel, $orphanUrl] = $this->storeJpeg($p->id, 100, 60, 'orphan.jpg');
        $p->update(['gallery_images_json' => []]);

        $before = Storage::disk('public')->files("properties/{$p->id}");

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/rotate-image", ['image_url' => $orphanUrl, 'degrees' => 90])
            ->assertStatus(409)
            ->assertJsonPath('ok', false);

        // Original still there, and no rotated leftover was created.
        $this->assertTrue(Storage::disk('public')->exists($orphanRel));
        $this->assertSame($before, Storage::disk('public')->files("properties/{$p->id}"));
        $this->assertSame([], $p->fresh()->gallery_images_json);
    }

    public function test_rejects_image_url_outside_this_property_directory(): void
    {
        $p = $this->makeProperty();
        $this->storeJpeg($p->id, 40, 40);

        // URL points at a different property's directory — must be refused.
        $foreign = Storage::disk('public')->url('properties/99999/evil.jpg');

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/rotate-image", ['image_url' => $foreign, 'degrees' => 90])
            ->assertStatus(422);
    }

    public function test_rejects_invalid_degrees(): void
    {
        $p = $this->makeProperty();
        [, $url] = $this->storeJpeg($p->id, 40, 40);

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/rotate-image", ['image_url' => $url, 'degrees' => 45])
            ->assertStatus(422);
    }

    public function test_cannot_rotate_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']);
        $otherAgent = User::factory()->create([
            'agency_id' => $otherAgency->id,
            'branch_id' => $otherBranch->id,
        ]);
        $foreignProperty = Property::create([
            'title'     => 'Foreign',
            'agency_id' => $otherAgency->id,
            'agent_id'  => $otherAgent->id,
            'branch_id' => $otherBranch->id,
        ]);
        [, $url] = $this->storeJpeg($foreignProperty->id, 40, 40);

        // AgencyScope blocks route-model binding across agencies → 404.
        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$foreignProperty->id}/rotate-image", ['image_url' => $url, 'degrees' => 90])
            ->assertNotFound();
    }
}
