<?php

namespace Tests\Feature\Agents;

use App\Jobs\RemoveAgentPhotoBackgroundJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Images\AgentProfilePhotoService;
use App\Services\Images\BackgroundRemoval\BackgroundRemovalManager;
use App\Services\Images\BackgroundRemoval\PhotoroomDriver;
use App\Services\Images\BackgroundRemoval\RembgDriver;
use App\Services\Images\BackgroundRemoval\RemoveBgDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AI background-removal pipeline (ad-manager.md §15.2). Covers: success
 * (cutout stored, driver/cost recorded), full failure handling (bad/missing
 * key never breaks the upload, original photo remains the only photo,
 * terminal failure recorded), the agency kill switch, the superseded-photo
 * race guard, and that the provider is a pure config choice.
 *
 * Every setup call to AgentProfilePhotoService::set() below runs under
 * Queue::fake() — phpunit.xml sets QUEUE_CONNECTION=sync for tests, so
 * without faking, set()'s own dispatch() would execute
 * RemoveAgentPhotoBackgroundJob for real, immediately, with whatever
 * Http::fake()/config state happens to exist at that moment. Faking lets
 * each test drive the job's config/Http state and timing explicitly, then
 * invoke the job once, deliberately.
 */
class AgentPhotoBackgroundRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgent(bool $bgRemovalEnabled = true): User
    {
        $agency = Agency::create([
            'name' => 'Coastal',
            'slug' => 'coastal',
            'ad_bg_removal_api_enabled' => $bgRemovalEnabled,
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
    }

    private function profilePhotoDoc(User $user): ?UserDocument
    {
        return $user->documents()
            ->where('document_type', UserDocument::DOCUMENT_TYPE_PROFILE_PHOTO)
            ->latest()->first();
    }

    /**
     * A distinctly-coloured, real JPEG upload. UploadedFile::fake()->image()
     * produces a blank/uniform image regardless of filename or dimensions —
     * two fakes for the same 1000×1000 size normalise to IDENTICAL bytes,
     * which is useless for proving a content-hash guard actually detects a
     * REPLACED photo. A solid colour fill guarantees the normalised WebP
     * differs between two calls with different colours.
     */
    private function coloredJpegUpload(array $rgb): UploadedFile
    {
        $img = imagecreatetruecolor(1000, 1000);
        imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
        $tmp = tempnam(sys_get_temp_dir(), 'bgr') . '.jpg';
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        return new UploadedFile($tmp, 'photo.jpg', 'image/jpeg', null, true);
    }

    /** Upload a photo without letting set()'s own dispatch execute the job. Returns [path, contentHash]. */
    private function uploadWithoutRunningTheJob(User $user, string $filename = 'photo.jpg'): array
    {
        Queue::fake();

        $path = app(AgentProfilePhotoService::class)->set($user, UploadedFile::fake()->image($filename, 1000, 1000));
        $hash = md5(Storage::disk('public')->get($path));

        return [$path, $hash];
    }

    public function test_rembg_successful_call_stores_the_cutout_via_the_local_service(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        [$path, $hash] = $this->uploadWithoutRunningTheJob($user);

        // rembg is the default driver (§15.3) — no explicit config() override needed.
        Http::fake([
            '127.0.0.1:3106/remove-background' => Http::response('fake-png-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        (new RemoveAgentPhotoBackgroundJob($user->id, $path, $hash))->handle(app(BackgroundRemovalManager::class));

        $doc = $this->profilePhotoDoc($user->fresh());
        $this->assertSame('done', $doc->bg_removal_status);
        $this->assertSame('rembg', $doc->bg_removal_driver);
        $this->assertNull($doc->bg_removal_error);
        $this->assertSame("agents/{$user->id}/photo-cutout.png", $doc->bg_removal_cutout_path);
        Storage::disk('public')->assertExists($doc->bg_removal_cutout_path);
        Storage::disk('public')->assertExists($path); // original untouched
        $this->assertSame('fake-png-bytes', Storage::disk('public')->get($doc->bg_removal_cutout_path));

        $this->assertNotNull($user->fresh()->profilePhotoCutoutUrl());
    }

    public function test_rembg_service_down_fails_cleanly_and_the_original_photo_still_resolves(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        [$path, $hash] = $this->uploadWithoutRunningTheJob($user);

        // Simulates `systemctl stop corex-bgremoval` — connection refused,
        // caught by RembgDriver's try/catch around Http::post() exactly like
        // a timeout or any other transport failure.
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $job = new RemoveAgentPhotoBackgroundJob($user->id, $path, $hash);
        try {
            $job->handle(app(BackgroundRemovalManager::class));
            $this->fail('Expected a BackgroundRemovalException to propagate for retry.');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $fresh = $user->fresh();
        $doc = $this->profilePhotoDoc($fresh);
        $this->assertSame('failed', $doc->bg_removal_status);
        $this->assertSame('rembg', $doc->bg_removal_driver);
        $this->assertNotNull($doc->bg_removal_error);
        $this->assertNull($doc->bg_removal_cutout_path);

        $this->assertNull($fresh->profilePhotoCutoutUrl());
        $this->assertNotNull($fresh->profilePhotoUrl());
        Storage::disk('public')->assertExists($path);
    }

    public function test_paid_api_fallback_still_works_photoroom_success(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        [$path, $hash] = $this->uploadWithoutRunningTheJob($user);

        config(['services.bg_removal.driver' => 'photoroom', 'services.bg_removal.photoroom.api_key' => 'test-key']);
        Http::fake([
            'sdk.photoroom.com/*' => Http::response('fake-png-bytes', 200, [
                'Content-Type' => 'image/png',
                'X-Credits-Charged' => '1',
            ]),
        ]);

        (new RemoveAgentPhotoBackgroundJob($user->id, $path, $hash))->handle(app(BackgroundRemovalManager::class));

        $doc = $this->profilePhotoDoc($user->fresh());
        $this->assertSame('done', $doc->bg_removal_status);
        $this->assertSame('photoroom', $doc->bg_removal_driver);
        $this->assertNull($doc->bg_removal_error);
        $this->assertSame("agents/{$user->id}/photo-cutout.png", $doc->bg_removal_cutout_path);
        Storage::disk('public')->assertExists($doc->bg_removal_cutout_path);
        Storage::disk('public')->assertExists($path); // original untouched
        $this->assertSame('fake-png-bytes', Storage::disk('public')->get($doc->bg_removal_cutout_path));

        $this->assertNotNull($user->fresh()->profilePhotoCutoutUrl());
    }

    public function test_missing_api_key_fails_cleanly_and_the_original_photo_still_resolves(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        [$path, $hash] = $this->uploadWithoutRunningTheJob($user);

        config(['services.bg_removal.driver' => 'photoroom', 'services.bg_removal.photoroom.api_key' => null]);

        $job = new RemoveAgentPhotoBackgroundJob($user->id, $path, $hash);

        try {
            $job->handle(app(BackgroundRemovalManager::class));
            $this->fail('Expected a BackgroundRemovalException to propagate for retry.');
        } catch (\Throwable $e) {
            $job->failed($e); // simulate the worker exhausting retries
        }

        $fresh = $user->fresh();
        $doc = $this->profilePhotoDoc($fresh);
        $this->assertSame('failed', $doc->bg_removal_status);
        $this->assertNotNull($doc->bg_removal_error);
        $this->assertNull($doc->bg_removal_cutout_path);

        // The whole point: no cutout, but the ORIGINAL photo still resolves —
        // an ad can never render blank because of this failure.
        $this->assertNull($fresh->profilePhotoCutoutUrl());
        $this->assertNotNull($fresh->profilePhotoUrl());
        Storage::disk('public')->assertExists($path);
    }

    public function test_provider_down_or_timeout_fails_cleanly_too(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent();
        [$path, $hash] = $this->uploadWithoutRunningTheJob($user);

        config(['services.bg_removal.driver' => 'remove_bg', 'services.bg_removal.remove_bg.api_key' => 'test-key']);
        Http::fake([
            'api.remove.bg/*' => Http::response(['errors' => [['title' => 'Service Unavailable']]], 503),
        ]);

        $job = new RemoveAgentPhotoBackgroundJob($user->id, $path, $hash);
        try {
            $job->handle(app(BackgroundRemovalManager::class));
            $this->fail('Expected a BackgroundRemovalException to propagate for retry.');
        } catch (\Throwable $e) {
            $job->failed($e);
        }

        $fresh = $user->fresh();
        $doc = $this->profilePhotoDoc($fresh);
        $this->assertSame('failed', $doc->bg_removal_status);
        $this->assertSame('remove_bg', $doc->bg_removal_driver);
        $this->assertNull($fresh->profilePhotoCutoutUrl());
        $this->assertNotNull($fresh->profilePhotoUrl());
    }

    public function test_agency_toggle_off_skips_dispatch_entirely(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->makeAgent(bgRemovalEnabled: false);
        app(AgentProfilePhotoService::class)->set($user, UploadedFile::fake()->image('me.jpg', 1000, 1000));

        Queue::assertNotPushed(RemoveAgentPhotoBackgroundJob::class);
    }

    public function test_agency_toggle_off_also_guards_inside_the_job_for_a_mid_flight_change(): void
    {
        Storage::fake('public');
        $user = $this->makeAgent(bgRemovalEnabled: true);
        [$path, $hash] = $this->uploadWithoutRunningTheJob($user);

        // Toggle flips off AFTER dispatch but before the job runs.
        $user->agency->update(['ad_bg_removal_api_enabled' => false]);

        Http::fake(); // any call here would be a bug — asserted below
        (new RemoveAgentPhotoBackgroundJob($user->id, $path, $hash))->handle(app(BackgroundRemovalManager::class));

        Http::assertNothingSent();
        $doc = $this->profilePhotoDoc($user->fresh());
        $this->assertNull($doc->bg_removal_status);
    }

    public function test_a_superseded_photo_never_gets_its_cutout_attached_to_the_new_one(): void
    {
        Storage::fake('public');
        Queue::fake();
        $svc = app(AgentProfilePhotoService::class);
        $user = $this->makeAgent();

        // AgentPhotoNormalizer always writes "agents/{id}/photo.webp" for a
        // given user — the SAME path — so the race this guards is same-path,
        // DIFFERENT bytes, not a different path. Capture the OLD content's
        // hash before the second upload overwrites it in place. Distinct
        // colours guarantee the normalised WebP bytes actually differ (two
        // UploadedFile::fake()->image() calls of the same size normalise to
        // identical bytes, which would prove nothing here).
        $oldPath = $svc->set($user->fresh(), $this->coloredJpegUpload([200, 60, 60]));
        $oldHash = md5(Storage::disk('public')->get($oldPath));
        $svc->set($user->fresh(), $this->coloredJpegUpload([60, 120, 200]));
        $this->assertNotSame($oldHash, md5(Storage::disk('public')->get($oldPath)), 'the second upload must actually change the bytes at that path');

        config(['services.bg_removal.driver' => 'photoroom', 'services.bg_removal.photoroom.api_key' => 'test-key']);
        Http::fake(); // any call here would be a bug — asserted below
        (new RemoveAgentPhotoBackgroundJob($user->id, $oldPath, $oldHash))->handle(app(BackgroundRemovalManager::class));

        Http::assertNothingSent();
    }

    public function test_driver_is_a_pure_config_choice(): void
    {
        // The environment's own .env sets BG_REMOVAL_DRIVER=rembg — asserted
        // BEFORE any config() override in this test, so this is the real
        // fresh-boot default, not a simulated one. (Config\Repository's
        // offsetUnset() is implemented as set($key, null), NOT a true
        // removal — a nested dotted key that's been explicitly nulled is
        // still "exists()", so Arr::get returns the stored null rather than
        // falling through to a default. There's no clean way to simulate
        // "key absent" through the public Repository API for a nested key.)
        $this->assertSame('rembg', app(BackgroundRemovalManager::class)->driverName(), 'rembg must be the default');
        $this->assertInstanceOf(RembgDriver::class, app(BackgroundRemovalManager::class)->driver());

        config(['services.bg_removal.driver' => 'photoroom']);
        $this->assertInstanceOf(PhotoroomDriver::class, app(BackgroundRemovalManager::class)->driver());

        config(['services.bg_removal.driver' => 'remove_bg']);
        $this->assertInstanceOf(RemoveBgDriver::class, app(BackgroundRemovalManager::class)->driver());
    }

    public function test_a_fresh_upload_clears_any_previous_photos_cutout_state(): void
    {
        Storage::fake('public');
        Queue::fake();
        $svc = app(AgentProfilePhotoService::class);
        $manager = app(BackgroundRemovalManager::class);
        $user = $this->makeAgent();

        $firstPath = $svc->set($user->fresh(), UploadedFile::fake()->image('one.jpg', 1000, 1000));
        $firstHash = md5(Storage::disk('public')->get($firstPath));

        config(['services.bg_removal.driver' => 'photoroom', 'services.bg_removal.photoroom.api_key' => 'test-key']);
        Http::fake([
            'sdk.photoroom.com/*' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png']),
        ]);
        (new RemoveAgentPhotoBackgroundJob($user->id, $firstPath, $firstHash))->handle($manager);
        $this->assertSame('done', $this->profilePhotoDoc($user->fresh())->bg_removal_status);

        // Replacing the photo must reset bg_removal_* — the new file has no
        // cutout yet. Queue::fake() is already active (set at the top of this
        // test) so this second upload's own dispatch does not run the job.
        $svc->set($user->fresh(), UploadedFile::fake()->image('two.jpg', 1000, 1000));
        $doc = $this->profilePhotoDoc($user->fresh());
        $this->assertNull($doc->bg_removal_status);
        $this->assertNull($doc->bg_removal_cutout_path);
    }
}
