<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Jobs\DownloadListingThumbnail;
use App\Models\Agency;
use App\Models\ProspectingListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-22 item 7 — competition images not displaying.
 *
 * Covers the two confirmed breaks:
 *  (a) DownloadListingThumbnail was dispatched ONLY on the create branch, so a
 *      listing first seen without a thumbnail_url never retried → assert the
 *      UPDATE branch now dispatches when thumbnail_path is empty + url present,
 *      and does NOT re-dispatch when a thumbnail is already cached.
 *  (b) ~4032 rows have thumbnail_path set but zero files on disk (Laravel 11
 *      disk-root move) → assert prospecting:rehydrate-thumbnails re-dispatches
 *      from the stored thumbnail_source_url for missing-file rows, skips rows
 *      with no source URL, and is idempotent (no-op when the file exists).
 */
final class ThumbnailRehydrationTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'role'      => 'admin',
        ]);
    }

    private function importPayload(string $portalRef, ?string $thumbnailUrl): array
    {
        return [
            'source'         => 'p24',
            'search_context' => [
                'url'            => 'https://www.property24.com/for-sale/uvongo/123',
                'search_term'    => 'Uvongo houses',
                'total_results'  => 1,
                'pages_captured' => 1,
            ],
            'listings' => [[
                'portal_ref'    => $portalRef,
                'address'       => '12 Marine Drive, Uvongo',
                'price'         => 2_450_000,
                'portal_url'    => 'https://www.property24.com/listing/' . $portalRef,
                'suburb'        => 'Uvongo',
                'property_type' => 'House',
                'thumbnail_url' => $thumbnailUrl,
            ]],
        ];
    }

    public function test_update_branch_dispatches_download_when_thumbnail_missing(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->user);

        // First import: no thumbnail_url → row created without a thumbnail.
        $this->postJson(route('v1.prospecting.import'), $this->importPayload('P24-UPD-1', null))
            ->assertOk();

        $listing = ProspectingListing::where('portal_ref', 'P24-UPD-1')->firstOrFail();
        $this->assertEmpty($listing->thumbnail_path, 'no thumbnail downloaded yet');
        // Create branch had no url → nothing dispatched there.
        Bus::assertNotDispatched(DownloadListingThumbnail::class);

        // Second import (UPDATE branch) now carries a thumbnail_url → must
        // dispatch the download AND persist the source URL.
        $this->postJson(
            route('v1.prospecting.import'),
            $this->importPayload('P24-UPD-1', 'https://images.prop24.com/247/photo-987.jpg')
        )->assertOk();

        Bus::assertDispatched(DownloadListingThumbnail::class, function ($job) use ($listing) {
            return $job->listing->id === $listing->id
                && $job->thumbnailUrl === 'https://images.prop24.com/247/photo-987.jpg';
        });

        $this->assertSame(
            'https://images.prop24.com/247/photo-987.jpg',
            $listing->fresh()->thumbnail_source_url,
            'source URL persisted for future rehydrate'
        );
    }

    public function test_update_branch_does_not_redownload_when_already_cached(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->user);

        // Seed an existing row that ALREADY has a thumbnail cached.
        ProspectingListing::create([
            'agency_id'      => $this->agency->id,
            'captured_by_user_id' => $this->user->id,
            'portal_source'  => 'p24',
            'portal_ref'     => 'P24-CACHED-1',
            'portal_url'     => 'https://www.property24.com/listing/P24-CACHED-1',
            'address'        => '5 Beach Rd, Uvongo',
            'suburb'         => 'Uvongo',
            'price'          => 2_000_000,
            'thumbnail_path' => 'prospecting/thumbnails/p24_P24-CACHED-1.jpg',
            'first_seen_at'  => now(),
            'last_seen_at'   => now(),
            'is_active'      => true,
        ]);

        // Re-capture with a url — but thumbnail_path is already set → no re-download.
        $this->postJson(
            route('v1.prospecting.import'),
            $this->importPayload('P24-CACHED-1', 'https://images.prop24.com/247/other.jpg')
        )->assertOk();

        Bus::assertNotDispatched(DownloadListingThumbnail::class);
    }

    public function test_rehydrate_redispatches_for_missing_file_with_source_url(): void
    {
        Bus::fake();

        // Orphaned row: path set, file absent, source URL known → rehydratable.
        $orphan = ProspectingListing::create([
            'agency_id'            => $this->agency->id,
            'captured_by_user_id'  => $this->user->id,
            'portal_source'        => 'p24',
            'portal_ref'           => 'P24-ORPHAN-1',
            'portal_url'           => 'https://www.property24.com/listing/P24-ORPHAN-1',
            'address'              => '9 Marine Dr, Uvongo',
            'suburb'               => 'Uvongo',
            'price'                => 2_100_000,
            'thumbnail_path'       => 'prospecting/thumbnails/p24_P24-ORPHAN-1.jpg',
            'thumbnail_source_url' => 'https://images.prop24.com/247/orphan.jpg',
            'first_seen_at'        => now(),
            'last_seen_at'         => now(),
            'is_active'            => true,
        ]);

        // Orphaned row with NO source URL → cannot rehydrate, must be skipped.
        ProspectingListing::create([
            'agency_id'      => $this->agency->id,
            'captured_by_user_id' => $this->user->id,
            'portal_source'  => 'p24',
            'portal_ref'     => 'P24-ORPHAN-2',
            'portal_url'     => 'https://www.property24.com/listing/P24-ORPHAN-2',
            'address'        => '11 Marine Dr, Uvongo',
            'suburb'         => 'Uvongo',
            'price'          => 2_200_000,
            'thumbnail_path' => 'prospecting/thumbnails/p24_P24-ORPHAN-2.jpg',
            'first_seen_at'  => now(),
            'last_seen_at'   => now(),
            'is_active'      => true,
        ]);

        $this->artisan('prospecting:rehydrate-thumbnails')->assertSuccessful();

        // Exactly one dispatch — the row that has a source URL.
        Bus::assertDispatchedTimes(DownloadListingThumbnail::class, 1);
        Bus::assertDispatched(DownloadListingThumbnail::class, function ($job) use ($orphan) {
            return $job->listing->id === $orphan->id
                && $job->thumbnailUrl === 'https://images.prop24.com/247/orphan.jpg';
        });
    }

    public function test_rehydrate_is_idempotent_when_file_exists(): void
    {
        Bus::fake();

        // Row whose file genuinely EXISTS on the local disk → no re-fetch.
        $path = 'prospecting/thumbnails/p24_P24-PRESENT-1.jpg';
        Storage::disk('local')->put($path, 'jpeg-bytes');

        ProspectingListing::create([
            'agency_id'            => $this->agency->id,
            'captured_by_user_id'  => $this->user->id,
            'portal_source'        => 'p24',
            'portal_ref'           => 'P24-PRESENT-1',
            'portal_url'           => 'https://www.property24.com/listing/P24-PRESENT-1',
            'address'              => '13 Marine Dr, Uvongo',
            'suburb'               => 'Uvongo',
            'price'                => 2_300_000,
            'thumbnail_path'       => $path,
            'thumbnail_source_url' => 'https://images.prop24.com/247/present.jpg',
            'first_seen_at'        => now(),
            'last_seen_at'         => now(),
            'is_active'            => true,
        ]);

        $this->artisan('prospecting:rehydrate-thumbnails')->assertSuccessful();
        Bus::assertNotDispatched(DownloadListingThumbnail::class);

        Storage::disk('local')->delete($path);
    }

    public function test_rehydrate_dry_run_dispatches_nothing(): void
    {
        Bus::fake();

        ProspectingListing::create([
            'agency_id'            => $this->agency->id,
            'captured_by_user_id'  => $this->user->id,
            'portal_source'        => 'p24',
            'portal_ref'           => 'P24-DRY-1',
            'portal_url'           => 'https://www.property24.com/listing/P24-DRY-1',
            'address'              => '15 Marine Dr, Uvongo',
            'suburb'               => 'Uvongo',
            'price'                => 2_400_000,
            'thumbnail_path'       => 'prospecting/thumbnails/p24_P24-DRY-1.jpg',
            'thumbnail_source_url' => 'https://images.prop24.com/247/dry.jpg',
            'first_seen_at'        => now(),
            'last_seen_at'         => now(),
            'is_active'            => true,
        ]);

        $this->artisan('prospecting:rehydrate-thumbnails', ['--dry-run' => true])
            ->assertSuccessful();
        Bus::assertNotDispatched(DownloadListingThumbnail::class);
    }
}
