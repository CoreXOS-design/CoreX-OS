<?php

namespace Tests\Feature\TrainingVideos;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\TrainingPlatformVoice;
use App\Models\TrainingVideo;
use App\Models\User;
use App\Services\TrainingVideos\Auto\TrainingVideoResolver;
use App\Services\TrainingVideos\Tts\TrainingVoices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-525 (spec section 9.20) - CoreX voices (the system owner's Chatterbox voices), the platform default, the agency choice, and audio made once per voice and shared.
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
        file_put_contents($this->dir . '/book-a-viewing.php', '<?php return ' . var_export(['key' => 'book-a-viewing', 'title' => 'Book', 'route' => null, 'url' => '/corex/x', 'approved' => true, 'related' => [],
            'steps' => [['selector' => 'a', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y']]], true) . ';');
        file_put_contents($this->dir . '/mic-work.php', '<?php return ' . var_export(['key' => 'mic-work', 'title' => 'MIC', 'route' => null, 'url' => '/corex/m', 'approved' => true, 'related' => [],
            'steps' => [['selector' => 'a', 'action' => 'highlight', 'narration' => 'x', 'why' => 'y']]], true) . ';');
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

    public function test_only_the_system_owner_can_open_the_voices_page_or_make_a_voice(): void
    {
        $this->actingAs($this->admin)->get(route('corex.training-library.voices'))->assertForbidden();
        $this->actingAs($this->admin)->post(route('corex.training-library.voices.store'), ['name' => 'X', 'consent' => '1', 'reference' => $this->wav()])->assertForbidden();
        $this->actingAs($this->owner)->get(route('corex.training-library.voices'))->assertOk()->assertSee('data-voices-page', false);
        $this->assertSame(0, TrainingPlatformVoice::count());
    }

    public function test_a_voice_needs_the_consent_tick_and_a_long_enough_recording(): void
    {
        Bus::fake();
        $this->make('NoConsent', false)->assertSessionHasErrors('consent');
        $this->make('TooShort', true, $this->wav(2.0))->assertSessionHasErrors('reference');
        $this->assertSame(0, TrainingPlatformVoice::count());
        Bus::assertNothingDispatched();
    }

    public function test_a_saved_voice_keeps_its_reference_private_becomes_the_first_default_and_queues_its_sample(): void
    {
        Bus::fake();
        $this->make('Thandi')->assertRedirect(route('corex.training-library.voices'));
        $v = TrainingPlatformVoice::firstOrFail();

        $this->assertSame('corex_thandi', $v->voice_key);
        $this->assertTrue($v->is_default, 'the first CoreX voice becomes the platform default');
        $this->assertNotNull($v->consent_confirmed_at);
        $this->assertSame($this->owner->id, $v->consent_by_user_id);
        Storage::disk('local')->assertExists($v->reference_file);
        $this->assertGreaterThan(5.0, $v->reference_seconds);
        Bus::assertDispatched(\App\Jobs\TrainingVideos\MakeVoiceSampleJob::class, fn ($j) => $j->voiceKey === 'corex_thandi');

        // an agency admin sees the voice in the picker but nothing of the reference clip, and there is no route that serves it
        $html = $this->actingAs($this->admin)->get(route('corex.training-library.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-voice="corex_thandi"', $html);
        $this->assertStringContainsString('data-voice-group="chatterbox"', $html);
        $this->assertStringNotContainsString('voice-refs', $html);
        $this->assertStringNotContainsString($v->reference_file, $html);
        $this->actingAs($this->admin)->get(route('corex.training-library.voices'))->assertForbidden();
    }

    public function test_the_platform_default_is_what_every_agency_uses_until_its_admin_chooses_another(): void
    {
        Bus::fake();
        $this->make('Thandi');
        $this->make('Ava');
        $voices = app(TrainingVoices::class);
        $this->assertSame('corex_thandi', $voices->defaultKey());
        $this->assertSame('corex_thandi', $voices->keyFor($this->agency), 'an agency with no choice uses the platform default');

        $ava = TrainingPlatformVoice::where('voice_key', 'corex_ava')->firstOrFail();
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.default', $ava))->assertSessionHas('success');
        $this->assertSame('corex_ava', $voices->defaultKey());
        $this->assertSame(1, TrainingPlatformVoice::where('is_default', true)->count());
        $this->assertSame('corex_ava', $voices->keyFor($this->agency->fresh()));

        $this->actingAs($this->admin)->post(route('corex.training-library.voice.choose'), ['voice' => 'bf_emma']);
        $this->assertSame('bf_emma', $voices->keyFor($this->agency->fresh()));
        $this->actingAs($this->admin)->post(route('corex.training-library.voices.default', $ava))->assertForbidden();
    }

    public function test_an_archived_voice_leaves_the_picker_and_an_agency_that_used_it_falls_back_to_the_default(): void
    {
        Bus::fake();
        $this->make('Thandi');
        $this->make('Ava');
        $ava = TrainingPlatformVoice::where('voice_key', 'corex_ava')->firstOrFail();
        $this->actingAs($this->admin)->post(route('corex.training-library.voice.choose'), ['voice' => 'corex_ava']);
        $this->actingAs($this->owner)->post(route('corex.training-library.voices.archive', $ava))->assertSessionHas('success');

        $voices = app(TrainingVoices::class);
        $this->assertSame('corex_thandi', $voices->keyFor($this->agency->fresh()));
        $this->assertStringNotContainsString('data-voice="corex_ava"', $this->actingAs($this->admin)->get(route('corex.training-library.index'))->getContent());
        $this->assertSoftDeleted('training_platform_voices', ['voice_key' => 'corex_ava']);
    }

    public function test_audio_is_made_once_per_voice_and_shared(): void
    {
        $resolver = app(TrainingVideoResolver::class);
        $mk = fn (string $screen, ?string $voice, string $status = 'ready') => TrainingVideo::create(['source' => 'generated', 'status' => $status, 'screen_key' => $screen, 'voice_key' => $voice,
            'title' => $screen, 'file_name' => $status === 'ready' ? $screen . '-' . $voice : null, 'duration_seconds' => 60]);
        $default = app(TrainingVoices::class)->defaultKey();
        $a = $mk('book-a-viewing', $default);

        // an agency on a voice that has no video yet is shown the platform default's (and is not shown nothing)
        $this->assertSame($a->id, $resolver->forScreen('book-a-viewing', 'bf_lily')->id);
        $b = $mk('book-a-viewing', 'bf_lily');
        $this->assertSame($b->id, $resolver->forScreen('book-a-viewing', 'bf_lily')->id, 'its own voice wins once it exists');
        $this->assertSame($a->id, $resolver->forScreen('book-a-viewing', null)->id);
        $this->assertTrue($resolver->exists('book-a-viewing', 'bf_lily'));
        $this->assertFalse($resolver->exists('mic-work', 'bf_lily'));

        // choosing a voice queues ONLY the screens that have no video in it yet - another agency may already have made them
        Bus::fake();
        $this->actingAs($this->admin)->post(route('corex.training-library.voice.choose'), ['voice' => 'bf_lily'])->assertSessionHas('success');
        Bus::assertDispatchedTimes(\App\Jobs\TrainingVideos\GenerateTrainingVideoJob::class, 1);
        Bus::assertDispatched(\App\Jobs\TrainingVideos\GenerateTrainingVideoJob::class, fn ($j) => $j->screenKey === 'mic-work' && $j->voiceKey === 'bf_lily');
    }

    public function test_a_second_agency_on_the_same_voice_queues_nothing(): void
    {
        $mk = fn (string $screen) => TrainingVideo::create(['source' => 'generated', 'status' => 'ready', 'screen_key' => $screen, 'voice_key' => 'bf_isabella', 'title' => $screen, 'file_name' => $screen, 'duration_seconds' => 60]);
        $mk('book-a-viewing');
        $mk('mic-work');
        Bus::fake();
        $this->actingAs($this->admin)->post(route('corex.training-library.voice.choose'), ['voice' => 'bf_isabella'])->assertSessionHas('success');
        Bus::assertNothingDispatched();
    }
}
