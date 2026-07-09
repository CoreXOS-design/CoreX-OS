<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The invariant: a property's gallery JSON only ever references files that exist.
 *
 * Broken on property 6060 (2026-07-06). A photo was rotated — which writes a new
 * file and unlinks the original — and a stale browser tab then re-posted its
 * pre-rotation image array, resurrecting the URL of the deleted file. Nothing
 * validated the incoming array. PrivateProperty fetches photos BY URL and
 * rejects the ENTIRE UpdateListing when one 404s (PP120), so that single dead
 * reference blocked every subsequent update of the listing to the portal.
 *
 * These tests lock the three doors that were open:
 *   1. the gallery save accepting a stale copy of the array
 *   2. the gallery save accepting references to files that do not exist
 *   3. PP publishing a URL it cannot serve
 */
final class GalleryReferenceIntegrityTest extends TestCase
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

        $this->agency = Agency::create(['name' => 'Gallery Agency', 'slug' => 'gal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(): Property
    {
        return Property::create([
            'title'     => 'Gallery Property',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    /** Store a real JPEG and return its public URL. */
    private function storeJpeg(int $propertyId, string $name): string
    {
        $gd = imagecreatetruecolor(20, 20);
        ob_start();
        imagejpeg($gd);
        $bytes = (string) ob_get_clean();
        imagedestroy($gd);

        $rel = "properties/{$propertyId}/{$name}";
        Storage::disk('public')->put($rel, $bytes);

        return Storage::disk('public')->url($rel);
    }

    // ── 1. stale writes ──────────────────────────────────────────────────────

    public function test_gallery_save_with_a_stale_fingerprint_is_rejected(): void
    {
        $p = $this->makeProperty();
        $live = $this->storeJpeg($p->id, 'live.jpg');
        $p->update(['gallery_images_json' => [$live]]);

        // A second tab loaded the page, then the gallery changed underneath it.
        $staleFingerprint = 'sha1-of-some-older-gallery';

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/reorder-images", [
                'gallery_categories_json' => ['categories' => [], 'unsorted' => []],
                'gallery_images_json'     => [],
                'gallery_fingerprint'     => $staleFingerprint,
            ])
            ->assertStatus(409)
            ->assertJsonPath('stale', true);

        // The stale tab must not have been able to wipe the gallery.
        $this->assertSame([$live], $p->fresh()->gallery_images_json);
    }

    public function test_gallery_save_with_the_current_fingerprint_succeeds_and_returns_the_next_one(): void
    {
        $p = $this->makeProperty();
        $a = $this->storeJpeg($p->id, 'a.jpg');
        $b = $this->storeJpeg($p->id, 'b.jpg');
        $p->update(['gallery_images_json' => [$a, $b]]);

        $resp = $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/reorder-images", [
                'gallery_categories_json' => ['categories' => [], 'unsorted' => [$b, $a]],
                'gallery_images_json'     => [$b, $a],
                'gallery_fingerprint'     => $p->galleryFingerprint(),
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $p->refresh();
        $this->assertSame([$b, $a], $p->gallery_images_json);

        // The fingerprint handed back must be the one that now guards the row,
        // or the tab that just saved would be locked out of its own next save.
        $this->assertSame($p->galleryFingerprint(), $resp->json('fingerprint'));
    }

    // ── 2. references to files that do not exist ─────────────────────────────

    public function test_gallery_save_drops_a_reference_to_a_file_that_does_not_exist(): void
    {
        $p = $this->makeProperty();
        $real = $this->storeJpeg($p->id, 'real.jpg');
        $ghost = Storage::disk('public')->url("properties/{$p->id}/deleted-by-a-rotation.jpg");

        $p->update(['gallery_images_json' => [$real]]);

        $resp = $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/reorder-images", [
                'gallery_categories_json' => [
                    'categories' => [['name' => 'Lounge', 'images' => [$ghost]]],
                    'unsorted'   => [$real],
                ],
                'gallery_images_json' => [$real, $ghost],
                'gallery_fingerprint' => $p->galleryFingerprint(),
            ])
            ->assertOk();

        $p->refresh();
        $this->assertSame([$real], $p->gallery_images_json, 'the dangling reference must not be stored');
        $this->assertSame([], $p->gallery_categories_json['categories'][0]['images']);
        $this->assertSame(1, $resp->json('dropped'));
    }

    public function test_gallery_save_refuses_a_reference_to_another_propertys_directory(): void
    {
        $p = $this->makeProperty();
        $mine = $this->storeJpeg($p->id, 'mine.jpg');
        $theirs = $this->storeJpeg($p->id + 1, 'theirs.jpg'); // exists, but not ours

        $p->update(['gallery_images_json' => [$mine]]);

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/reorder-images", [
                'gallery_categories_json' => ['categories' => [], 'unsorted' => []],
                'gallery_images_json'     => [$mine, $theirs],
                'gallery_fingerprint'     => $p->galleryFingerprint(),
            ])
            ->assertOk();

        $this->assertSame([$mine], $p->fresh()->gallery_images_json);
    }

    public function test_gallery_save_keeps_externally_hosted_images(): void
    {
        $p = $this->makeProperty();
        $external = 'https://images.prop24.com/some/mirror/photo.jpg';
        $p->update(['gallery_images_json' => []]);

        $this->actingAs($this->user)
            ->postJson("/corex/properties/{$p->id}/reorder-images", [
                'gallery_categories_json' => ['categories' => [], 'unsorted' => []],
                'gallery_images_json'     => [$external],
                'gallery_fingerprint'     => $p->galleryFingerprint(),
            ])
            ->assertOk();

        // We cannot stat someone else's host — a portal mirror must survive.
        $this->assertSame([$external], $p->fresh()->gallery_images_json);
    }

    // ── 3. delete ordering ───────────────────────────────────────────────────

    public function test_deleting_an_image_clears_it_from_the_category_map_too(): void
    {
        $p = $this->makeProperty();
        $a = $this->storeJpeg($p->id, 'a.jpg');
        $b = $this->storeJpeg($p->id, 'b.jpg');

        $p->update([
            'gallery_images_json'     => [$a, $b],
            'gallery_categories_json' => [
                'categories' => [['name' => 'Lounge', 'images' => [$a]]],
                'unsorted'   => [$b],
            ],
        ]);

        $this->actingAs($this->user)
            ->post("/corex/properties/{$p->id}/delete-image", [
                'group' => 'gallery_images_json',
                'index' => 0,
            ]);

        $p->refresh();
        $this->assertSame([$b], $p->gallery_images_json);
        $this->assertSame([], $p->gallery_categories_json['categories'][0]['images'], 'category map must not name a deleted photo');
        $this->assertFalse(Storage::disk('public')->exists("properties/{$p->id}/a.jpg"));
    }

    // ── 4. PrivateProperty must never publish a URL it cannot serve ──────────

    public function test_pp_payload_excludes_images_with_no_file_on_disk(): void
    {
        $p = $this->makeProperty();
        $real = $this->storeJpeg($p->id, 'real.jpg');
        $ghost = Storage::disk('public')->url("properties/{$p->id}/gone.jpg");
        $external = 'https://images.prop24.com/mirror/photo.jpg';

        // Simulate the corrupted row as it existed for property 6060.
        $p->forceFill(['gallery_images_json' => [$real, $ghost, $external]])->save();

        $mapper = new PrivatePropertyListingMapper();
        $method = new \ReflectionMethod($mapper, 'buildPhotoUrls');
        $method->setAccessible(true);
        $urls = $method->invoke($mapper, $p->fresh());

        $this->assertCount(2, $urls, 'the missing file must not be sent to PP');

        $joined = implode(' ', $urls);
        $this->assertStringNotContainsString('gone.jpg', $joined);
        $this->assertStringContainsString('real.jpg', $joined);
        $this->assertStringContainsString('prop24.com', $joined, 'externally hosted mirrors must still be sent');
    }
}
