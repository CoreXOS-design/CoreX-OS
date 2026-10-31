<?php

namespace Tests\Feature\TrainingVideos;

use App\Jobs\TrainingVideos\GenerateTrainingVideoJob;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TrainingPlatformVoice;
use App\Models\TrainingSetting;
use App\Models\TrainingVideo;
use App\Models\User;
use App\Services\TrainingVideos\Auto\TrainingVideoResolver;
use App\Services\TrainingVideos\Tts\SystemVoiceChange;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-525 (spec section 9.24) - ONE system voice. The system owner makes CoreX voices and picks THE voice (a CoreX or a Kokoro one); agencies do not choose; a change queues the
 * regeneration of everything in the background and the old videos keep playing until each new one is ready.
 */
final class CoreXVoicesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $admin;
    private Agency $agency;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->dir = sys_get_temp_dir() . '/tv-cb-' . uniqid();
        mkdir($this->dir);
        foreach (['book-a-viewing' => '/corex/x', 'mic-work' => '/corex/m'] as $key => $url) {
            file_put_contents($this->dir . '/' . $key . '.php', '<?php return ' . var_export(['key' => $key, 'title' => $key, 'route' => null, 'url' => $url, 'approved' => true, 'related' => [],
                'steps' => [['selector' => 'a', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y']]], true) . ';');
        }
        config(['training-videos.auto.scripts_dir' => $this->dir]);
        $this->app->forgetInstance(\App\Services\TrainingVideos\Auto\TrainingScriptRepository::class);

        $this->agency = Agency::create(['name' => 'Voices', 'slug' => 'voices-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        Role::unguarded(fn () => Role::create(['name' => 'sysowner', 'label' => 'Owner', 'is_owner' => true]));
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agency->id]);
        foreach (['training_videos.view', 'training_videos.generate'] as $k) {
            RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => $k, 'agency_id' => $this->agency->id], []);
        }
        $this->owner = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'sysowner']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function wav(float $seconds = 6.0): UploadedFile
    {
        $p = $this->dir . '/ref-' . uniqid() . '.wav';
        exec('ffmpeg -y -v error -f lavfi -i "sine=frequency=220:duration=' . $seconds . '" ' . escapeshellarg($p) . ' 2>&1');
        if (! is_file($p)) {
            $this->markTestSkipped('ffmpeg is not available here.');
        }

        return new UploadedFile($p, 'ref.wav', 'audio/wav', null, true);
    }

    private function make(string $name = 'Thandi', bool $consent = true, ?UploadedFile $f = null)
    {
        return $this->actingAs($this->owner)->post(route('corex.training-library.voices.store'), ['name' => $name, 'about' => 'South African, calm', 'consent' => $consent ? '1' : null, 'reference' => $f ?? $this->wav()]);
    }

    private function video(string $screen, string $voice, string $status = 'ready'): TrainingVideo
    {
        return TrainingVideo::create(['source' => 'generated', 'status' => $status, 'screen_key' => $screen, 'voice_key' => $voice, 'title' => $screen,
            'file_name' => $status === 'ready' ? $screen . '-' . $voice : null, 'duration_seconds' => 60]);
    }

    public function test_only_the_system_owner_reaches_the_voices_page_and_agencies_have_no_voice_choice(): void
    {
        $this->actingAs($this->admin)->get(route('corex.training-library.voices'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('corex.training-library.voices.use'), ['voice' => 'bf_lily'])->assertForbidden();
        $this->actingAs($this->admin)->get(route('corex.training-library.voice-sample', 'bf_emma'))->assertForbidden();
        $this->actingAs($this->owner)->get(route('corex.training-library.voices'))->assertOk()->assertSee('data-system-voice', false);

        // an agency admin's library has no voice picker at all; the system owner's has a note and the link
        $adminHtml = $this->actingAs($this->admin)->get(route('corex.training-library.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-voice-picker', $adminHtml);
        $this->assertStringNotContainsString('data-voices-link', $adminHtml);
        $this->assertStringNotContainsString('Use this voice', $adminHtml);
        $ownerHtml = $this->actingAs($this->owner)->get(route('corex.training-library.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-voices-link', $ownerHtml);
        $this->assertStringContainsString('The system voice is', $ownerHtml);
        foreach (['voice.choose', 'regenerate-all', 'voices.default'] as $gone) {
            $this->assertFalse(\Illuminate\Support\Facades\Route::has('corex.training-library.' . $gone), $gone . ' is gone');
        }
    }

    public function test_a_voice_needs_the_consent_tick_and_a_long_enough_recording(): void
    {
        Bus::fake();
        $this->make('NoConsent', false)->assertSessionHasErrors('consent');
        $this->make('TooShort', true, $this->wav(2.0))->assertSessionHasErrors('reference');
        $this->assertSame(0, TrainingPlatformVoice::count());
        Bus::assertNothingDispatched();
    }

    public function test_a_saved_voice_keeps_its_reference_private_and_is_not_the_system_voice_until_the_owner_says_so(): void
    {
        Bus::fake();
        $this->make('Thandi')->assertRedirect(route('corex.training-library.voices'));
        $v = TrainingPlatformVoice::firstOrFail();

        $this->assertSame('corex_thandi', $v->voice_key);
        $this->assertFalse((bool) $v->is_default);
        $this->assertNotNull($v->consent_confirmed_at);
        $this->assertSame($this->owner->id, $v->consent_by_user_id);
        Storage::disk('local')->assertExists($v->reference_file);
        Bus::assertDispatched(\App\Jobs\TrainingVideos\MakeVoiceSampleJob::class, fn ($j) => $j->voiceKey === 'corex_thandi');
        $this->assertSame('bf_emma', app(TrainingVoices::class)->systemKey(), 'making a voice does not change what is spoken');

        $html = $this->actingAs($this->admin)->get(route('corex.training-library.index'))->getContent();
        $this->assertStringNotContainsString('corex_thandi', $html);
        $this->assertStringNotContainsString($v->reference_file, $html);
    }

    public function test_use_as_the_system_voice_saves_it_and_queues_every_approved_video_in_the_background(): void
    {
        Bus::fake();
        $this->video('book-a-viewing', 'bf_emma');   // the current voice's videos
        $this->video('mic-work', 'bf_emma');

        $this->actingAs($this->owner)->post(route('corex.training-library.voices.use'), ['voice' => 'bf_lily'])->assertSessionHas('success');
        $this->assertSame('bf_lily', app(TrainingVoices::class)->systemKey());
        $this->assertSame('bf_lily', app(TrainingVoices::class)->keyFor($this->agency), 'every agency hears the one voice');
        Bus::assertDispatchedTimes(GenerateTrainingVideoJob::class, 2);
        Bus::assertDispatched(GenerateTrainingVideoJob::class, fn ($j) => $j->screenKey === 'mic-work' && $j->voiceKey === 'bf_lily' && $j->queue === 'training-videos');

        // the same voice again queues nothing; an unknown voice is refused
        Bus::fake();
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.use'), ['voice' => 'bf_lily'])->assertSessionHas('success');
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.use'), ['voice' => 'nobody'])->assertNotFound();
        Bus::assertNothingDispatched();
    }

    public function test_a_corex_voice_can_be_the_system_voice_and_an_agencys_old_choice_is_ignored(): void
    {
        Bus::fake();
        $this->make('Thandi');
        $this->agency->forceFill(['training_video_voice' => 'af_bella'])->save();   // a left-over choice from the old design
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.use'), ['voice' => 'corex_thandi'])->assertSessionHas('success');
        $voices = app(TrainingVoices::class);
        $this->assertSame('corex_thandi', $voices->systemKey());
        $this->assertSame('corex_thandi', $voices->keyFor($this->agency->fresh()));
        $this->assertSame('Thandi', $voices->labelFor('chatterbox:corex_thandi'));
    }

    public function test_the_old_videos_keep_playing_until_each_new_one_is_ready_and_progress_is_shown(): void
    {
        Bus::fake();
        $resolver = app(TrainingVideoResolver::class);
        $old = $this->video('book-a-viewing', 'bf_emma');
        $this->video('mic-work', 'bf_emma');
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.use'), ['voice' => 'bf_lily']);

        $this->assertSame($old->id, $resolver->forScreen('book-a-viewing', 'bf_lily')->id, 'no new video yet: the old one plays');
        $p = app(SystemVoiceChange::class)->progress();
        $this->assertSame(['bf_lily', 2, 0, 2, true], [$p['voice'], $p['total'], $p['ready'], $p['making'], $p['in_progress']]);
        $html = $this->actingAs($this->owner)->get(route('corex.training-library.voices'))->getContent();
        $this->assertStringContainsString('0 of 2 videos are ready in this voice', $html);

        $new = $this->video('book-a-viewing', 'bf_lily');
        $this->assertSame($new->id, $resolver->forScreen('book-a-viewing', 'bf_lily')->id, 'the new one plays once it is ready');
        $p = app(SystemVoiceChange::class)->progress();
        $this->assertSame([1, 1, 50], [$p['ready'], $p['making'], $p['percent']]);
        $this->video('mic-work', 'bf_lily');
        $this->assertFalse(app(SystemVoiceChange::class)->progress()['in_progress']);
        $this->assertStringContainsString('All 2 videos are in this voice', $this->actingAs($this->owner)->get(route('corex.training-library.voices'))->getContent());
    }

    public function test_the_system_voice_cannot_be_archived_but_another_voice_can(): void
    {
        Bus::fake();
        $this->make('Thandi');
        $this->make('Ava');
        $thandi = TrainingPlatformVoice::where('voice_key', 'corex_thandi')->firstOrFail();
        $ava = TrainingPlatformVoice::where('voice_key', 'corex_ava')->firstOrFail();
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.use'), ['voice' => 'corex_thandi']);

        $this->actingAs($this->owner)->post(route('corex.training-library.voices.archive', $thandi))->assertSessionHasErrors('voice');
        $this->assertNull($thandi->fresh()->deleted_at);
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.archive', $ava))->assertSessionHas('success');
        $this->assertSoftDeleted('training_platform_voices', ['voice_key' => 'corex_ava']);
        $this->assertSame('corex_thandi', app(TrainingVoices::class)->systemKey());
    }

    public function test_an_archived_or_missing_system_voice_falls_back_to_the_configured_default(): void
    {
        TrainingSetting::write('system_voice', 'corex_gone');
        $this->assertSame('bf_emma', app(TrainingVoices::class)->systemKey());
    }
}
