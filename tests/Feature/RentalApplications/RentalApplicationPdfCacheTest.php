<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationGeneration;
use App\Services\RentalApplications\RentalApplicationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reopen/resubmit follow-up, 2026-09-09 — RentalApplicationPdfService::
 * generate() previously re-rendered a byte-identical PDF through headless
 * Chromium (~9s, measured live) on every single read-only-view visit or
 * Download PDF click, even for a signed, submitted application whose
 * content can never change again. Now cached per SEALED GENERATION on the
 * `data_volume` disk (config/filesystems.php — the mounted volume, never
 * root). Real timing measured directly against a live application (not
 * this suite, which mocks the renderer per the established pattern in
 * FiledDocumentNamingTest — see that file's own docblock for why): 8.96s
 * cold, 0.002s cached, on rental application 15 (a real record, not
 * created by this build).
 *
 * The renderer itself is mocked here (Mockery on SigningController,
 * exactly FiledDocumentNamingTest's own pattern) — these tests exist to
 * lock in the CACHING logic (correct key, correct hit/miss decision,
 * failure-safety), not to re-prove Puppeteer produces a PDF.
 */
final class RentalApplicationPdfCacheTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private int $renderCount = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'ZZZ PDF Cache Test Agency ' . Str::random(6), 'slug' => 'zzz-pdfcache-' . Str::random(8)]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'ZZZ Branch']);
        Storage::fake(RentalApplicationPdfService::CACHE_DISK);
        $this->mockRenderer();
    }

    /** Counts invocations so a test can assert the renderer ran exactly once despite N requests. */
    private function mockRenderer(): void
    {
        $signerMock = \Mockery::mock(SigningController::class)->makePartial();
        $signerMock->shouldReceive('generatePdfFromHtml')->andReturnUsing(function () {
            $this->renderCount++;
            $tmp = tempnam(sys_get_temp_dir(), 'zzzfakepdf') . '.pdf';
            file_put_contents($tmp, 'fake-pdf-bytes-render-' . $this->renderCount);
            return $tmp;
        });
        $this->app->instance(SigningController::class, $signerMock);
    }

    private function submittedApplication(int $generation = 1): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'ZZZ', 'last_name' => 'PDF Cache Tenant', 'email' => 'zzz-pdfcache@example.test',
        ]);

        $application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'status' => 'returned', 'submitted_at' => now(), 'current_generation' => $generation,
            'full_name' => 'ZZZ PDF Cache Tenant',
        ]);

        RentalApplicationGeneration::create([
            'rental_application_id' => $application->id,
            'generation' => $generation,
            'agency_id' => $this->agency->id,
            'snapshot_json' => ['full_name' => $application->full_name],
            'submitted_at' => now(),
            'content_hash' => RentalApplicationGeneration::computeHash(null, ['full_name' => $application->full_name]),
            'prev_hash' => null,
        ]);

        return $application;
    }

    public function test_cache_miss_renders_and_stores_then_serves_from_cache(): void
    {
        $application = $this->submittedApplication();
        $service = app(RentalApplicationPdfService::class);

        $path1 = $service->generate($application);
        $this->assertSame(1, $this->renderCount, 'First call must render.');
        $this->assertSame('fake-pdf-bytes-render-1', file_get_contents($path1));
        unlink($path1);

        Storage::disk(RentalApplicationPdfService::CACHE_DISK)
            ->assertExists("rental-applications/{$application->id}/generations/1.pdf");

        // Second call — same generation — must be a cache hit, never call the renderer again.
        $path2 = $service->generate($application);
        $this->assertSame(1, $this->renderCount, 'Second call for the same sealed generation must NOT re-render.');
        $this->assertSame('fake-pdf-bytes-render-1', file_get_contents($path2), 'Cached content must be byte-identical to what was originally rendered.');
        unlink($path2);
    }

    public function test_in_progress_application_never_caches(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'ZZZ', 'last_name' => 'In Progress Tenant', 'email' => 'zzz-inprogress@example.test',
        ]);
        $application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'status' => 'in_progress', 'full_name' => 'ZZZ In Progress Tenant',
        ]);
        $this->assertFalse($application->isSubmitted());

        $service = app(RentalApplicationPdfService::class);
        unlink($service->generate($application));
        unlink($service->generate($application));

        $this->assertSame(2, $this->renderCount, 'An unsubmitted application must re-render every single time — never cached.');
        Storage::disk(RentalApplicationPdfService::CACHE_DISK)->assertDirectoryEmpty("rental-applications/{$application->id}");
    }

    public function test_reopened_status_still_serves_the_last_sealed_generation_from_cache(): void
    {
        // Reopen/resubmit — while status is 'reopened', the live row's own
        // fields still exactly match the last sealed generation (reopen()
        // never touches them) — this must still be a cache hit, not treated
        // as "in progress" just because status has moved off 'returned'.
        $application = $this->submittedApplication();
        $application->status = 'reopened';
        $application->save();

        $service = app(RentalApplicationPdfService::class);
        unlink($service->generate($application));
        unlink($service->generate($application));

        $this->assertSame(1, $this->renderCount, 'A reopened-but-not-yet-resubmitted application must still serve its last sealed generation from cache.');
    }

    public function test_a_resubmit_caches_under_its_own_new_generation_number(): void
    {
        $application = $this->submittedApplication(generation: 1);
        $service = app(RentalApplicationPdfService::class);
        unlink($service->generate($application));
        $this->assertSame(1, $this->renderCount);

        // Simulate a resubmit — bump the generation and seal a new one, exactly
        // what RentalApplicationSigningController::submit() does atomically.
        $application->current_generation = 2;
        $application->full_name = 'ZZZ PDF Cache Tenant (corrected)';
        $application->save();
        RentalApplicationGeneration::create([
            'rental_application_id' => $application->id, 'generation' => 2, 'agency_id' => $this->agency->id,
            'snapshot_json' => ['full_name' => $application->full_name], 'submitted_at' => now(),
            'content_hash' => RentalApplicationGeneration::computeHash('x', ['full_name' => $application->full_name]), 'prev_hash' => 'x',
        ]);

        $path = $service->generate($application);
        $this->assertSame(2, $this->renderCount, 'A new generation must render fresh — it has never been cached before.');
        unlink($path);

        Storage::disk(RentalApplicationPdfService::CACHE_DISK)->assertExists("rental-applications/{$application->id}/generations/1.pdf");
        Storage::disk(RentalApplicationPdfService::CACHE_DISK)->assertExists("rental-applications/{$application->id}/generations/2.pdf");
        $this->assertNotSame(
            Storage::disk(RentalApplicationPdfService::CACHE_DISK)->get("rental-applications/{$application->id}/generations/1.pdf"),
            Storage::disk(RentalApplicationPdfService::CACHE_DISK)->get("rental-applications/{$application->id}/generations/2.pdf"),
            'Each generation must cache its own distinct content — never share or overwrite the other.'
        );
    }

    public function test_a_cache_write_failure_never_blocks_the_render(): void
    {
        $application = $this->submittedApplication();

        // Swap in a disk that always throws on write — simulates a full disk
        // or a permissions problem on the real mounted volume.
        $throwingDisk = \Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $throwingDisk->shouldReceive('exists')->andReturn(false);
        $throwingDisk->shouldReceive('put')->andThrow(new \RuntimeException('simulated disk full'));
        Storage::set(RentalApplicationPdfService::CACHE_DISK, $throwingDisk);

        $service = app(RentalApplicationPdfService::class);
        $path = $service->generate($application);

        $this->assertSame(1, $this->renderCount);
        $this->assertSame('fake-pdf-bytes-render-1', file_get_contents($path), 'The user must still get their PDF even though caching it failed.');
        unlink($path);
    }

    public function test_archiving_the_application_removes_its_cached_pdfs(): void
    {
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $application = $this->submittedApplication();
        $application->created_by_user_id = $agent->id;
        $application->save();

        $service = app(RentalApplicationPdfService::class);
        unlink($service->generate($application));
        Storage::disk(RentalApplicationPdfService::CACHE_DISK)->assertExists("rental-applications/{$application->id}/generations/1.pdf");

        $this->actingAs($agent)->delete(route('corex.rental-applications.destroy', $application))->assertRedirect();

        Storage::disk(RentalApplicationPdfService::CACHE_DISK)->assertMissing("rental-applications/{$application->id}/generations/1.pdf");
        // The record itself is archived, not gone — non-negotiable #1.
        $this->assertNotNull($application->fresh()->deleted_at);
        $this->assertDatabaseHas('rental_applications', ['id' => $application->id]);
        // The sealed generation ROW (the actual evidence) is completely untouched by cache cleanup.
        $this->assertDatabaseHas('rental_application_generations', ['rental_application_id' => $application->id, 'generation' => 1]);
    }
}
