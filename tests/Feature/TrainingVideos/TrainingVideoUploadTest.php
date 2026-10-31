<?php

namespace Tests\Feature\TrainingVideos;

use App\Jobs\TrainingVideos\CompressTrainingVideoJob;
use App\Models\Role;
use App\Models\TrainingVideo;
use App\Models\User;
use App\Services\TrainingVideos\ChunkedUploadService;
use App\Services\TrainingVideos\UnreadableVideoException;
use App\Services\TrainingVideos\VideoCompressor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Training Videos — uploaded video files, shrunk in the background.
 *
 * Spec: .ai/specs/training-videos.md §8.9 (acceptance criteria 1–9). ffmpeg is swapped for a
 * fake so the suite never needs it: content starting "NOTVIDEO" is not a video, content
 * starting "BOOM" makes the shrink step fail, anything else shrinks fine.
 */
class TrainingVideoUploadTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/corex/admin/integrations/training-videos';
    private const API  = '/api/v1/admin/training-video-uploads';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        config(['training-videos.chunk_bytes' => 10]);

        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();

        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);

        $this->app->instance(VideoCompressor::class, new class implements VideoCompressor {
            public function probe(string $path): array
            {
                if (str_starts_with((string) file_get_contents($path, false, null, 0, 8), 'NOTVIDEO')) {
                    throw new UnreadableVideoException();
                }

                return ['duration' => 125, 'width' => 1920, 'height' => 1080, 'fps' => 60.0];
            }

            public function compress(string $source, string $mp4Path, string $posterPath, array $probe, ?callable $onProgress = null): void
            {
                if ($onProgress) {
                    $onProgress(42);
                }
                if (str_starts_with((string) file_get_contents($source, false, null, 0, 4), 'BOOM')) {
                    throw new \RuntimeException('ffmpeg exploded');
                }
                file_put_contents($mp4Path, 'tiny');
                file_put_contents($posterPath, 'jpg');
            }
        });
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** Run the whole piece-by-piece upload as $user; returns the response of the final "complete" call. */
    private function upload(string $content, ?User $user = null, ?string &$uploadId = null)
    {
        $user ??= $this->owner;

        $start = $this->actingAs($user)->postJson(self::API, ['name' => 'screen.mov', 'size' => strlen($content)])->assertOk();
        $uploadId = $start->json('upload_id');
        $chunk    = $start->json('chunk_bytes');

        foreach (str_split($content, $chunk) as $i => $piece) {
            $this->actingAs($user)->post(self::API . "/{$uploadId}/chunks", [
                'index' => $i,
                'chunk' => UploadedFile::fake()->createWithContent('piece', $piece),
            ], ['Accept' => 'application/json'])->assertOk();
        }

        return $this->actingAs($user)->postJson(self::API . "/{$uploadId}/complete");
    }

    private function runJob(TrainingVideo $video): void
    {
        (new CompressTrainingVideoJob($video->id))->handle(app(VideoCompressor::class), app(ChunkedUploadService::class));
    }

    /** Upload + save through the real form endpoint, with the queue held so the job can be run by hand. */
    private function addUploadedVideo(string $content = 'a pretend video file, 40 bytes long....', array $extra = []): TrainingVideo
    {
        Queue::fake();
        $this->upload($content, null, $uploadId)->assertOk();

        $this->actingAs($this->owner)->post(self::BASE, $extra + [
            'source'    => 'upload',
            'title'     => 'Screen recording',
            'upload_id' => $uploadId,
        ])->assertRedirect(route('admin.training-videos.index'));

        return TrainingVideo::latest('id')->firstOrFail();
    }

    private function readyUpload(string $file = 'abc123'): TrainingVideo
    {
        Storage::disk('public')->put("training-videos/{$file}.mp4", 'video');
        Storage::disk('public')->put("training-videos/{$file}.jpg", 'poster');

        return TrainingVideo::create([
            'title' => 'Ready one', 'source' => 'upload', 'status' => 'ready', 'file_name' => $file,
            'size_bytes' => 9 * 1048576, 'original_size_bytes' => 50 * 1048576,
        ]);
    }

    // ── acceptance 1: upload → Processing → Ready, original discarded ────

    public function test_an_upload_is_processing_and_off_the_public_page_until_the_worker_finishes_it(): void
    {
        $video = $this->addUploadedVideo();

        Queue::assertPushed(CompressTrainingVideoJob::class, fn ($j) => $j->videoId === $video->id && $j->queue === 'training-videos');
        $this->assertSame('upload', $video->source);
        $this->assertSame('processing', $video->status);
        $this->assertNull($video->youtube_url);
        $this->assertSame(strlen('a pretend video file, 40 bytes long....'), $video->original_size_bytes);

        $this->get('/training-videos')->assertOk()->assertDontSee('Screen recording');

        $this->runJob($video);
        $video->refresh();

        $this->assertSame('ready', $video->status);
        $this->assertNull($video->pending_upload_id);
        $this->assertSame(125, $video->duration_seconds);
        $this->assertSame(1920, $video->width);
        $this->assertTrue(Storage::disk('public')->exists("training-videos/{$video->file_name}.mp4"));
        $this->assertTrue(Storage::disk('public')->exists("training-videos/{$video->file_name}.jpg"));
        $this->assertSame([], Storage::disk('local')->directories('training-video-uploads'), 'the original upload is discarded');

        $this->get('/training-videos')->assertOk()
            ->assertSee('Screen recording')
            ->assertSee('<video', false)
            ->assertSee("training-videos/{$video->file_name}.mp4", false)
            ->assertDontSee('Watch on YouTube');
    }

    // ── acceptance 2: not a video ────────────────────────────────────────

    public function test_a_file_that_is_not_a_video_is_refused_with_a_plain_message_and_removed(): void
    {
        $this->upload('NOTVIDEO: just some text pretending', null, $id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', "That file isn't a video CoreX can read. Try exporting it as MP4.");

        $this->assertSame([], Storage::disk('local')->directories('training-video-uploads'));
        $this->assertSame(0, TrainingVideo::count());
    }

    public function test_a_failed_shrink_is_failed_off_the_public_page_and_try_again_requeues_it(): void
    {
        $video = $this->addUploadedVideo('BOOM: the shrink step will fail on this one');

        $this->runJob($video);
        $video->refresh();

        $this->assertSame('failed', $video->status);
        $this->assertSame(CompressTrainingVideoJob::GENERIC_FAILURE, $video->failure_reason);
        $this->assertNotNull($video->pending_upload_id, 'the original is kept so Try again needs no re-upload');
        $this->assertTrue($video->canRetry());

        $this->get('/training-videos')->assertOk()->assertDontSee('Screen recording');
        $this->actingAs($this->owner)->get(self::BASE)->assertOk()->assertSee('Failed')->assertSee('Try again');

        // Try again re-queues it.
        Queue::fake();
        $this->actingAs($this->owner)->post(self::BASE . "/{$video->id}/retry")->assertRedirect();
        Queue::assertPushed(CompressTrainingVideoJob::class);
        $this->assertSame('processing', $video->fresh()->status);
        $this->assertNull($video->fresh()->failure_reason);
    }

    // ── acceptance 3: a dropped piece can be re-sent ─────────────────────

    public function test_a_piece_can_be_resent_and_a_wrong_sized_or_out_of_range_piece_is_refused(): void
    {
        $content = '0123456789abcdefghij12345'; // 25 bytes = pieces of 10, 10, 5
        $start = $this->actingAs($this->owner)->postJson(self::API, ['name' => 'a.mp4', 'size' => 25])->assertOk();
        $id = $start->json('upload_id');
        $this->assertSame(3, $start->json('total_chunks'));

        $send = fn (int $i, string $body) => $this->actingAs($this->owner)->post(self::API . "/{$id}/chunks", [
            'index' => $i, 'chunk' => UploadedFile::fake()->createWithContent('p', $body),
        ], ['Accept' => 'application/json']);

        $send(0, substr($content, 0, 10))->assertOk();
        $send(0, substr($content, 0, 10))->assertOk();                 // the same piece again: fine
        $send(1, 'too short')->assertStatus(422);                       // wrong size
        $send(7, '0123456789')->assertStatus(422);                      // outside this upload
        $send(1, substr($content, 10, 10))->assertOk();

        // The last piece never arrived: completing is refused, then the re-sent piece completes it.
        $this->actingAs($this->owner)->postJson(self::API . "/{$id}/complete")->assertStatus(422)->assertJsonValidationErrors('upload');
        $send(2, substr($content, 20))->assertOk();
        $this->actingAs($this->owner)->postJson(self::API . "/{$id}/complete")->assertOk()->assertJsonPath('size', 25);

        $this->assertSame($content, file_get_contents(app(ChunkedUploadService::class)->assembledPath($id)));
    }

    // ── acceptance 4: archive / restore ──────────────────────────────────

    public function test_archiving_takes_the_files_off_the_public_folder_and_restoring_brings_them_back(): void
    {
        $video = $this->readyUpload();
        $mp4 = 'training-videos/abc123.mp4';

        $this->actingAs($this->owner)->delete(self::BASE . "/{$video->id}")->assertRedirect();
        $this->assertFalse(Storage::disk('public')->exists($mp4), 'the old public address is gone');
        $this->assertFalse(Storage::disk('public')->exists('training-videos/abc123.jpg'));
        $this->assertTrue(Storage::disk('local')->exists($mp4), 'kept in private storage');
        $this->get('/training-videos')->assertDontSee('Ready one');

        $this->actingAs($this->owner)->post(self::BASE . "/{$video->id}/restore")->assertRedirect();
        $this->assertTrue(Storage::disk('public')->exists($mp4));
        $this->assertFalse(Storage::disk('local')->exists($mp4));
        $this->get('/training-videos')->assertSee('Ready one');
    }

    // ── acceptance 5: replace file ───────────────────────────────────────

    public function test_replacing_the_file_keeps_the_old_video_playing_until_the_new_one_is_ready(): void
    {
        $video = $this->readyUpload('oldfile');

        Queue::fake();
        $this->upload('a brand new recording, 40 bytes......', null, $uploadId)->assertOk();
        $this->actingAs($this->owner)->put(self::BASE . "/{$video->id}", [
            'title' => 'Ready one', 'upload_id' => $uploadId,
        ])->assertRedirect();

        $video->refresh();
        $this->assertSame('ready', $video->status, 'the old video is still live');
        $this->assertSame('oldfile', $video->file_name);
        $this->assertTrue($video->isReplacing());
        $this->get('/training-videos')->assertSee('Ready one')->assertSee('oldfile.mp4', false);

        $this->runJob($video);
        $video->refresh();

        $this->assertNotSame('oldfile', $video->file_name);
        $this->assertFalse($video->isReplacing());
        $this->assertFalse(Storage::disk('public')->exists('training-videos/oldfile.mp4'), 'the old copy goes only now');
        $this->assertTrue(Storage::disk('public')->exists("training-videos/{$video->file_name}.mp4"));
    }

    public function test_a_failed_replacement_leaves_the_old_video_playing(): void
    {
        $video = $this->readyUpload('oldfile');

        Queue::fake();
        $this->upload('BOOM: this replacement will fail', null, $uploadId)->assertOk();
        $this->actingAs($this->owner)->put(self::BASE . "/{$video->id}", ['title' => 'Ready one', 'upload_id' => $uploadId]);

        $this->runJob($video);
        $video->refresh();

        $this->assertSame('ready', $video->status);
        $this->assertSame('oldfile', $video->file_name);
        $this->assertTrue($video->replacementFailed());
        $this->assertTrue(Storage::disk('public')->exists('training-videos/oldfile.mp4'));
        $this->get('/training-videos')->assertSee('oldfile.mp4', false);
    }

    // ── acceptance 6: stuck warning ──────────────────────────────────────

    public function test_a_video_processing_for_over_an_hour_shows_the_worker_warning(): void
    {
        $video = $this->addUploadedVideo();
        $this->actingAs($this->owner)->get(self::BASE)->assertOk()
            ->assertSee('Processing')->assertDontSee('Still processing after an hour');

        $video->forceFill(['processing_started_at' => now()->subMinutes(61)])->save();
        $this->actingAs($this->owner)->get(self::BASE)->assertOk()
            ->assertSee('Still processing after an hour — the video worker may not be running on this server.');
    }

    // ── acceptance 7: abandoned uploads ──────────────────────────────────

    public function test_unfinished_uploads_older_than_a_day_are_cleaned_up_but_one_a_video_waits_on_is_kept(): void
    {
        $this->upload('abandoned upload, never saved..', null, $abandoned)->assertOk();
        $video = $this->addUploadedVideo();                    // its upload is referenced by a video
        $kept  = $video->pending_upload_id;
        $this->upload('a fresh one, still in progress', null, $fresh)->assertOk();

        foreach ([$abandoned, $kept] as $id) {
            $path = Storage::disk('local')->path("training-video-uploads/{$id}");
            foreach (array_merge([$path], glob("{$path}/*")) as $p) {
                touch($p, now()->subHours(30)->getTimestamp());
            }
        }

        $this->artisan('training-videos:purge-abandoned-uploads')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists("training-video-uploads/{$abandoned}/meta.json"));
        $this->assertTrue(Storage::disk('local')->exists("training-video-uploads/{$kept}/meta.json"), 'a video is still waiting on it');
        $this->assertTrue(Storage::disk('local')->exists("training-video-uploads/{$fresh}/meta.json"), 'under 24h old');
        $this->assertSame(1, TrainingVideo::count(), 'abandoned uploads never had a video record');
    }

    // ── acceptance 8: owner only ─────────────────────────────────────────

    public function test_a_non_owner_is_refused_on_every_upload_endpoint_even_holding_a_valid_upload_id(): void
    {
        $this->upload('the owner is mid-upload here....', null, $ownersUpload)->assertOk();

        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        $agencyAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($agencyAdmin)->postJson(self::API, ['name' => 'a.mp4', 'size' => 10])->assertForbidden();
        $this->actingAs($agencyAdmin)->post(self::API . "/{$ownersUpload}/chunks", [
            'index' => 0, 'chunk' => UploadedFile::fake()->createWithContent('p', '0123456789'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($agencyAdmin)->postJson(self::API . "/{$ownersUpload}/complete")->assertForbidden();

        // A DIFFERENT owner is refused too: an upload belongs to the user who started it.
        $otherOwner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
        $this->actingAs($otherOwner)->postJson(self::API . "/{$ownersUpload}/complete")->assertForbidden();

        // ...and cannot attach it to a video through the form either.
        $this->actingAs($otherOwner)->post(self::BASE, ['source' => 'upload', 'title' => 'Stolen', 'upload_id' => $ownersUpload])->assertForbidden();
        $this->assertSame(0, TrainingVideo::count());
    }

    public function test_a_guest_never_reaches_the_upload_endpoints(): void
    {
        $this->postJson(self::API, ['name' => 'a.mp4', 'size' => 10])->assertUnauthorized();
        $this->postJson(self::API . '/' . str_repeat('a', 32) . '/complete')->assertUnauthorized();
    }

    public function test_the_upload_endpoints_are_in_the_api_v1_catalogue(): void
    {
        foreach (['start', 'chunk', 'complete'] as $action) {
            $route = app('router')->getRoutes()->getByName("api.v1.training-video-uploads.{$action}");
            $this->assertNotNull($route, $action);
            $this->assertStringStartsWith('api/v1/', $route->uri());
        }
    }

    /**
     * REGRESSION (found on Staging 2026-10-27: "Unauthenticated." on the first request of a real upload).
     * The browser calls these with fetch() and its session cookie. Every route registered in routes/api.php
     * has the session stack stripped (bootstrap/app.php: mobile is bearer-only), so a logged-in browser got
     * 401 there — yet actingAs() in tests still passed, because it sets the user without needing the session.
     * Session-cookie /api/v1 endpoints must live in routes/web.php. This pins that, since no other test can
     * tell the difference.
     */
    public function test_the_upload_endpoints_use_the_session_stack_a_browser_needs_not_the_bearer_only_api_group(): void
    {
        foreach (['start', 'chunk', 'complete'] as $action) {
            $middleware = app('router')->getRoutes()->getByName("api.v1.training-video-uploads.{$action}")->gatherMiddleware();

            $this->assertContains('web', $middleware, "{$action} must run the web (session) middleware group");
            $this->assertNotContains('api', $middleware, "{$action} must not be in the bearer-only api group");
            $this->assertContains('owner_only', $middleware, "{$action} is owner-only");
        }
    }

    // ── acceptance 9: YouTube unchanged ──────────────────────────────────

    public function test_a_youtube_video_is_still_added_live_with_its_link_and_never_needs_the_worker(): void
    {
        Queue::fake();
        $this->actingAs($this->owner)->post(self::BASE, [
            'title' => 'On YouTube', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ])->assertRedirect();

        $video = TrainingVideo::firstOrFail();
        $this->assertSame('youtube', $video->source);
        $this->assertSame('ready', $video->status);
        Queue::assertNothingPushed();
        $this->get('/training-videos')->assertSee('On YouTube')->assertSee('Watch on YouTube')->assertDontSee('<video', false);
    }

    public function test_adding_an_upload_without_a_finished_upload_is_refused(): void
    {
        $this->actingAs($this->owner)->post(self::BASE, ['source' => 'upload', 'title' => 'No file'])
            ->assertSessionHasErrors('upload_id');
        $this->assertSame(0, TrainingVideo::count());
    }

    public function test_the_list_filters_by_kind_and_shows_the_size_saving(): void
    {
        $this->readyUpload();
        TrainingVideo::create(['title' => 'A YouTube one', 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ', 'youtube_video_id' => 'dQw4w9WgXcQ']);

        $this->actingAs($this->owner)->get(self::BASE . '?source=upload')->assertOk()
            ->assertSee('Ready one')->assertDontSee('A YouTube one')->assertSee('50 MB → 9 MB')->assertSee('Uploaded');
        $this->actingAs($this->owner)->get(self::BASE . '?source=youtube')->assertOk()
            ->assertSee('A YouTube one')->assertDontSee('Ready one');
    }

    public function test_the_add_and_edit_screens_render_the_uploader(): void
    {
        $this->actingAs($this->owner)->get(self::BASE . '/create')->assertOk()
            ->assertSee('Upload a video file')->assertSee('trainingVideoUploader', false);

        $video = $this->readyUpload();
        $this->actingAs($this->owner)->get(self::BASE . "/{$video->id}/edit")->assertOk()
            ->assertSee('Replace the video file')->assertSee('<video', false);
    }

    public function test_progress_endpoint_reports_videos_being_shrunk_and_is_owner_only(): void
    {
        $video = TrainingVideo::create([
            'title' => 'Shrinking', 'source' => 'upload', 'status' => 'processing',
            'pending_upload_id' => str_repeat('a', 32), 'progress_percent' => 40,
            'processing_started_at' => now()->subMinutes(4),
        ]);

        $json = $this->actingAs($this->owner)->getJson(route('api.v1.training-video-uploads.progress'))
            ->assertOk()->json();

        $this->assertSame(1, $json['active']);
        $this->assertSame($video->id, $json['videos'][0]['id']);
        $this->assertSame(40, $json['videos'][0]['percent']);
        $this->assertEqualsWithDelta(360, $json['videos'][0]['eta'], 5);   // 4 min for 40% → ~6 min left

        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->getJson(route('api.v1.training-video-uploads.progress'))->assertForbidden();
    }
}
