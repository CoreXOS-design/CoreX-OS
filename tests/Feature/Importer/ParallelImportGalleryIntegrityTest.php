<?php

namespace Tests\Feature\Importer;

use App\Jobs\ConfirmP24PropertyRowJob;
use App\Jobs\DownloadP24RowImagesJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24OnboardingPortal;
use App\Models\Property;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The parallel P24 importer's ONE non-negotiable: going faster must never lose a
 * photo. This locks the guarantees that make that true —
 *
 *  - the image job is self-healing: it fetches only what's missing, rebuilds the
 *    gallery from disk, and can NEVER report `complete` while short;
 *  - an unchanged re-import refetches nothing (inbound signature);
 *  - the confirm job no longer blocks on the CDN — it queues the download async
 *    on the narrow p24images lane and itself rides the wide p24import lane;
 *  - Import All fans out as one Bus batch, not a browser loop;
 *  - the owner can prove "zero galleries short" from the reconciliation endpoint.
 */
class ParallelImportGalleryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $owner;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerRole = Role::firstOrCreate(['name' => 'system_owner'], ['label' => 'System Owner']);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'agent'], ['label' => 'Agent']);
        Role::clearCache();

        $this->agency = Agency::create(['name' => 'Margate Realty', 'slug' => 'margate-realty']);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->owner = User::factory()->create(['role' => 'system_owner', 'agency_id' => null]);
        $this->agent = User::factory()->create([
            'role' => 'agent', 'agency_id' => $this->agency->id, 'p24_agent_id' => 7001,
        ]);

        Storage::fake('public');
    }

    // ---- The self-healing image job -----------------------------------------

    public function test_full_gallery_downloads_and_marks_complete(): void
    {
        Http::fake(fn () => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']));
        $property = $this->property();
        $urls = $this->urls(3);

        (new DownloadP24RowImagesJob($property->id, $urls))->handle();

        $property->refresh();
        $this->assertSame('complete', $property->gallery_import_status);
        $this->assertSame(3, $property->gallery_expected_count);
        $this->assertSame(3, $property->gallery_stored_count);
        $this->assertCount(3, $property->gallery_images_json);
        foreach ([1, 2, 3] as $ord) {
            Storage::disk('public')->assertExists("properties/{$property->id}/{$ord}.jpg");
        }
    }

    public function test_a_short_gallery_can_never_report_complete_and_logs_loudly(): void
    {
        Log::spy();
        // Ordinal 2's URL always returns a placeholder under the 500-byte floor —
        // a rate-limited/hotlink-blocked response, the exact silent-loss case.
        Http::fake([
            '*/img-2.jpg' => Http::response('tiny', 200, ['Content-Type' => 'image/jpeg']),
            '*'           => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $property = $this->property();

        (new DownloadP24RowImagesJob($property->id, $this->urls(3)))->handle();

        $property->refresh();
        $this->assertNotSame('complete', $property->gallery_import_status);
        $this->assertSame('incomplete', $property->gallery_import_status);
        $this->assertSame(3, $property->gallery_expected_count);
        $this->assertSame(2, $property->gallery_stored_count);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($msg) => str_contains($msg, 'INCOMPLETE'))->atLeast()->once();
    }

    public function test_retry_fetches_only_the_missing_ordinal_and_heals_to_complete(): void
    {
        $property = $this->property();
        $urls = $this->urls(3);

        // Ordinal 2 is rate-limited on the first pass (a sub-500 placeholder) then
        // recovers on the second — the exact heal-on-retry case. 1 and 3 land on
        // the first pass. One fake with a sequence, because Http::fake() MERGES
        // stubs across calls rather than replacing them.
        Http::fake([
            '*/img-2.jpg' => Http::sequence()
                ->push('tiny', 200, ['Content-Type' => 'image/jpeg'])
                ->push($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        (new DownloadP24RowImagesJob($property->id, $urls))->handle();
        $this->assertSame('incomplete', $property->fresh()->gallery_import_status);

        // Second pass must fetch ONLY the missing ordinal 2 — never re-pull 1 and 3
        // that are already on disk.
        (new DownloadP24RowImagesJob($property->id, $urls))->handle();

        // Fetch-only-missing: 1 and 3 were pulled once (pass 1 only); 2 was pulled
        // twice (both passes). Pass 2 never re-touched the images already on disk.
        $sent = fn ($needle) => Http::recorded(fn ($req) => str_contains($req->url(), $needle))->count();
        $this->assertSame(1, $sent('img-1.jpg'));
        $this->assertSame(1, $sent('img-3.jpg'));
        $this->assertSame(2, $sent('img-2.jpg'));

        $property->refresh();
        $this->assertSame('complete', $property->gallery_import_status);
        $this->assertSame(3, $property->gallery_stored_count);
        // Recovered ordinal 2 sits BETWEEN 1 and 3, not appended.
        $this->assertStringContainsString('/2.jpg', $property->gallery_images_json[1]);
    }

    public function test_unchanged_reimport_refetches_nothing(): void
    {
        Http::fake(fn () => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']));
        $property = $this->property();
        $urls = $this->urls(2);

        (new DownloadP24RowImagesJob($property->id, $urls))->handle();
        $this->assertSame('complete', $property->fresh()->gallery_import_status);

        // Re-run with the identical URL set — signature matches + already complete.
        Http::fake(fn () => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']));
        (new DownloadP24RowImagesJob($property->id, $urls))->handle();

        Http::assertNothingSent();
    }

    public function test_a_changed_gallery_reimport_refetches_every_ordinal_not_just_missing(): void
    {
        // First import of URL set A — two images land on disk, marked complete.
        Http::fake(fn () => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']));
        $property = $this->property();
        $setA = ['https://images.prop24.com/gallery/a-1.jpg', 'https://images.prop24.com/gallery/a-2.jpg'];
        (new DownloadP24RowImagesJob($property->id, $setA))->handle();
        $this->assertSame('complete', $property->fresh()->gallery_import_status);

        // P24 replaces the gallery with a DIFFERENT set B (same size). The files
        // 1.jpg/2.jpg on disk belong to set A, so fetch-only-missing would pull
        // nothing and leave the listing showing the OLD photos while marked
        // complete. force=true (which the confirm job sets on a signature change)
        // must drop the stale ordinals and refetch every position of the new set.
        Http::fake(fn () => Http::response($this->jpegBytes(), 200, ['Content-Type' => 'image/jpeg']));
        $setB = ['https://images.prop24.com/gallery/b-1.jpg', 'https://images.prop24.com/gallery/b-2.jpg'];
        (new DownloadP24RowImagesJob($property->id, $setB, true))->handle();

        // Every ordinal of the NEW set was fetched — none skipped as "present".
        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), 'b-1.jpg'))->count());
        $this->assertSame(1, Http::recorded(fn ($r) => str_contains($r->url(), 'b-2.jpg'))->count());

        $property->refresh();
        $this->assertSame('complete', $property->gallery_import_status);
        $this->assertSame(2, $property->gallery_stored_count);
        // Signature now reflects set B, so the next unchanged re-import skips.
        $this->assertSame(DownloadP24RowImagesJob::signatureFor($setB), $property->p24_source_image_signature);
    }

    public function test_a_genuinely_imageless_listing_is_complete_not_stuck_pending(): void
    {
        Http::fake();
        $property = $this->property();

        (new DownloadP24RowImagesJob($property->id, []))->handle();

        $property->refresh();
        $this->assertSame('complete', $property->gallery_import_status);
        $this->assertSame(0, $property->gallery_expected_count);
        Http::assertNothingSent();
    }

    // ---- The confirm job: split lanes + async images ------------------------

    public function test_confirm_queues_images_async_on_the_image_lane_and_itself_rides_the_import_lane(): void
    {
        Queue::fake();
        $row = $this->listingRow($this->urls(4));

        (new ConfirmP24PropertyRowJob($row->id, $this->owner->id))->handle();

        // The confirm did the property write inline and did NOT block on the CDN —
        // the download is queued async on p24images.
        Queue::assertPushedOn('p24images', DownloadP24RowImagesJob::class);

        $row->refresh();
        $property = Property::withoutGlobalScopes()->find($row->target_id);
        $this->assertNotNull($property);
        $this->assertSame('confirmed', $row->status);
        $this->assertSame(4, $property->gallery_expected_count);
        $this->assertSame('pending', $property->gallery_import_status);
        $this->assertNotNull($property->p24_source_image_signature);

        // The confirm job declares the wide lane.
        $this->assertSame('p24import', (new ConfirmP24PropertyRowJob($row->id))->queue);
    }

    public function test_confirm_of_an_unchanged_complete_property_does_not_requeue_images(): void
    {
        Queue::fake();
        $urls = $this->urls(2);
        $row = $this->listingRow($urls);

        // Pre-existing property already fully imported from the SAME url set.
        Property::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Existing', 'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
            'p24_listing_number' => $row->external_id,
            'gallery_import_status' => 'complete',
            'gallery_expected_count' => 2,
            'gallery_stored_count' => 2,
            'p24_source_image_signature' => DownloadP24RowImagesJob::signatureFor($urls),
        ]);

        (new ConfirmP24PropertyRowJob($row->id, $this->owner->id))->handle();

        Queue::assertNotPushed(DownloadP24RowImagesJob::class);
    }

    public function test_confirm_of_a_changed_gallery_requeues_the_download_with_force(): void
    {
        Queue::fake();
        $newUrls = $this->urls(3);
        $row = $this->listingRow($newUrls);

        // Pre-existing property imported from a DIFFERENT (older, smaller) url set,
        // so its stored signature no longer matches the incoming gallery.
        Property::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'Existing', 'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
            'p24_listing_number' => $row->external_id,
            'gallery_import_status' => 'complete',
            'gallery_expected_count' => 2,
            'gallery_stored_count' => 2,
            'p24_source_image_signature' => DownloadP24RowImagesJob::signatureFor($this->urls(2)),
        ]);

        (new ConfirmP24PropertyRowJob($row->id, $this->owner->id))->handle();

        // A changed gallery re-queues the download WITH force=true so stale
        // ordinals are dropped, not healed against.
        Queue::assertPushed(DownloadP24RowImagesJob::class, fn ($job) => $job->force === true);

        // And the stale stored-count is reset so it doesn't read as spurious
        // progress before the refetch overwrites it.
        $property = Property::withoutGlobalScopes()->where('p24_listing_number', $row->external_id)->first();
        $this->assertSame(0, (int) $property->gallery_stored_count);
        $this->assertSame('pending', $property->gallery_import_status);
    }

    // ---- Import All = one Bus batch -----------------------------------------

    public function test_confirm_job_is_batchable_so_import_all_can_dispatch(): void
    {
        // Bus::batch() throws "does not use the Batchable trait" at dispatch if
        // the job lacks it — but Bus::fake() (used by the batch test below) does
        // NOT enforce that, so assert the trait directly. This is the regression
        // that shipped an Import All which 500'd on the real dispatch path.
        $this->assertContains(
            \Illuminate\Bus\Batchable::class,
            class_uses_recursive(ConfirmP24PropertyRowJob::class),
            'ConfirmP24PropertyRowJob must use Batchable or Bus::batch() throws at dispatch.'
        );
    }

    public function test_import_all_dispatches_one_batch_and_marks_rows_processing(): void
    {
        Bus::fake();
        $portal = $this->portal();
        $rows = collect(range(1, 5))->map(fn () => $this->listingRow($this->urls(3)));

        $resp = $this->postJson("/onboarding/{$portal->urlKey()}/rows/confirm-all");

        $resp->assertOk()->assertJson(['ok' => true, 'count' => 5]);
        $this->assertNotNull($resp->json('batch_id'));
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 5
            && $batch->jobs->first() instanceof ConfirmP24PropertyRowJob);

        // Rows are marked processing up front so a double-click can't double-enqueue.
        foreach ($rows as $row) {
            $this->assertNotNull($row->fresh()->processing_at);
        }
    }

    public function test_status_endpoint_reports_gallery_progress(): void
    {
        $portal = $this->portal();
        $row = $this->listingRow($this->urls(3));
        $property = $this->property(['gallery_import_status' => 'complete', 'gallery_expected_count' => 3, 'gallery_stored_count' => 3]);
        $row->update(['status' => 'confirmed', 'target_id' => $property->id]);

        $resp = $this->getJson("/onboarding/{$portal->urlKey()}/status");

        $resp->assertOk()
            ->assertJsonPath('galleries.total', 1)
            ->assertJsonPath('galleries.complete', 1)
            ->assertJsonPath('galleries.images_stored', 3);
    }

    // ---- Owner reconciliation ----------------------------------------------

    public function test_reconciliation_endpoint_surfaces_short_galleries_for_owner_only(): void
    {
        $run = $this->makeRun();
        // One complete, one permanently short.
        $good = $this->property(['gallery_import_status' => 'complete', 'gallery_expected_count' => 5, 'gallery_stored_count' => 5]);
        $bad  = $this->property(['gallery_import_status' => 'incomplete', 'gallery_expected_count' => 10, 'gallery_stored_count' => 3]);
        $this->confirmedRow($run, $good);
        $this->confirmedRow($run, $bad);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/importer/gallery-reconciliation')
            ->assertOk()
            ->assertJsonPath('totals.short', 1)
            ->assertJsonPath('totals.incomplete', 1);

        // An agency admin must not see cross-agency import health.
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $this->agency->id]);
        $this->actingAs($admin)
            ->getJson('/api/v1/importer/gallery-reconciliation')
            ->assertForbidden();
    }

    // ---- helpers ------------------------------------------------------------

    private function jpegBytes(): string
    {
        // > 500 bytes so it clears the placeholder floor.
        return "\xFF\xD8\xFF\xE0" . str_repeat('x', 900);
    }

    private function urls(int $n): array
    {
        return collect(range(1, $n))->map(fn ($i) => "https://images.prop24.com/gallery/img-{$i}.jpg")->all();
    }

    private function property(array $overrides = []): Property
    {
        return Property::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->agency->id,
            'agent_id' => $this->agent->id,
            'external_id' => (string) Str::uuid(),
            'title' => 'P ' . Str::random(5),
            'property_type' => 'house',
            'status' => 'active',
            'price' => 1000000,
        ], $overrides));
    }

    private function makeRun(string $status = 'importing'): P24ImportRun
    {
        // 'importing' — an ACTIVE run. The confirm job deliberately refuses to
        // process rows of a completed/cancelled/failed run, so a fixture that
        // uses a terminal status would silently make every confirm a no-op.
        return P24ImportRun::create([
            'user_id' => $this->owner->id,
            'agency_id' => $this->agency->id,
            'kind' => 'listings_images',
            'status' => $status,
        ]);
    }

    private function listingRow(array $urls): P24ImportRow
    {
        $run = $this->makeRun();
        $ext = (string) random_int(1000000, 9999999);
        return P24ImportRow::create([
            'run_id' => $run->id,
            'row_type' => 'listing',
            'external_id' => $ext,
            'status' => 'pending',
            'resolved_agent_id' => $this->agent->id,
            'mapped_json' => [
                'p24_listing_number' => $ext,
                'title' => 'Listing ' . $ext,
                'listing_type' => 'Sale',
                'status' => 'active',
                'price' => 1500000,
            ],
            'image_urls_json' => $urls,
        ]);
    }

    private function confirmedRow(P24ImportRun $run, Property $property): P24ImportRow
    {
        return P24ImportRow::create([
            'run_id' => $run->id,
            'row_type' => 'listing',
            'external_id' => (string) random_int(1000000, 9999999),
            'status' => 'confirmed',
            'target_id' => $property->id,
            'mapped_json' => [],
        ]);
    }

    private function portal(): P24OnboardingPortal
    {
        return P24OnboardingPortal::create([
            'agency_id' => $this->agency->id,
            'token' => P24OnboardingPortal::generateToken(),
            'slug' => P24OnboardingPortal::generateSlug('Margate', $this->agency->id),
            'created_by' => $this->owner->id,
            'expires_at' => now()->addDays(30),
        ]);
    }
}
