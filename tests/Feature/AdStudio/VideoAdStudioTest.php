<?php

namespace Tests\Feature\AdStudio;

use App\Jobs\AdStudio\GenerateVideoAdJob;
use App\Models\AdStudioSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\VideoAd;
use App\Services\AdStudio\AdBrand;
use App\Services\AdStudio\AdRenderGateway;
use App\Services\AdStudio\AdScriptRepository;
use App\Services\AdStudio\FakeAdRenderGateway;
use App\Services\AdStudio\VideoAdService;
use App\Services\TrainingVideos\Auto\TrainingScriptRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AT-527 Ad Studio (spec .ai/specs/training-videos.md 9.21): System Owner only, the editor's rules, "Ad N" with a frozen snapshot, Duplicate, history CRUD, brand
 * settings round-trip. The renderer is a fake - nothing is rendered or posted.
 */
final class VideoAdStudioTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/corex/admin/video-ads';

    private User $owner;
    private User $admin;
    private FakeAdRenderGateway $gateway;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $ownerRole = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $ownerRole->is_owner = true;
        $ownerRole->save();
        Role::firstOrCreate(['name' => 'admin'], ['label' => 'Agency Admin', 'sort_order' => 2]);
        Role::clearCache();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
        $this->admin = User::factory()->create(['role' => 'admin']);

        // A tiny training script + ad script so the tests do not depend on the shipped ones.
        $this->dir = sys_get_temp_dir() . '/ads-' . uniqid();
        mkdir($this->dir . '/scripts', 0777, true);
        mkdir($this->dir . '/ads', 0777, true);
        file_put_contents($this->dir . '/scripts/demo-screen.php', "<?php return " . var_export([
            'key' => 'demo-screen', 'title' => 'Demo screen', 'route' => null, 'url' => '/corex/demo', 'approved' => true, 'login_as' => 'agent',
            'steps' => [
                ['selector' => '[data-a]', 'action' => 'highlight', 'narration' => 'Look at the boxes.', 'why' => 'So you know where to start.'],
                ['selector' => '[data-b]', 'action' => 'click', 'narration' => 'Press the button.', 'why' => 'It opens the house.'],
            ],
        ], true) . ';');
        $this->ad('demo', true);
        config(['training-videos.auto.scripts_dir' => $this->dir . '/scripts', 'ad-studio.scripts_dir' => $this->dir . '/ads']);
        $this->app->forgetInstance(TrainingScriptRepository::class);
        $this->app->forgetInstance(AdScriptRepository::class);

        $this->gateway = new FakeAdRenderGateway();
        $this->app->instance(AdRenderGateway::class, $this->gateway);
        $this->app->forgetInstance(VideoAdService::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function ad(string $key, bool $approved): void
    {
        file_put_contents($this->dir . '/ads/' . $key . '.php', "<?php return " . var_export([
            'type' => 'ad', 'key' => $key, 'title' => 'Demo feature', 'screen' => 'demo-screen', 'approved' => $approved,
            'questions' => ['a' => 'Still *guessing* which owners to phone?', 'b' => 'Why wait for sellers to *call* you?'],
            'shots' => [
                ['id' => 's1', 'caption' => 'See every house in your area.', 'step' => 0, 'action' => 'highlight', 'zoom' => 1.3, 'proof' => false],
                ['id' => 's2', 'caption' => 'Phone the neighbours the day a house sells.', 'step' => 1, 'action' => 'click', 'zoom' => 1.5, 'proof' => false],
            ],
            'defaults' => ['length' => 30, 'formats' => ['9:16', '1:1'], 'voice' => null],
            'post' => ['caption' => "{question}\n\n{cta} {website}", 'hashtags' => ['CoreX', 'RealEstate']],
        ], true) . ';');
    }

    /** The editor's posted draft, as the page sends it. */
    private function draft(array $over = []): array
    {
        return $over + [
            'feature' => 'demo', 'question' => 'Still *guessing* which owners to phone?',
            'shots' => [
                ['caption' => 'See every house in your area.', 'step' => 0, 'action' => 'highlight', 'zoom' => 1.3, 'proof' => false],
                ['caption' => 'Phone the neighbours the day a house sells.', 'step' => 1, 'action' => 'click', 'zoom' => 1.5, 'proof' => false],
            ],
            'cta' => ['join_line' => '', 'cta_line' => ''], 'length' => 30, 'formats' => ['9:16', '1:1'], 'voice' => null,
            'post' => ['caption' => "{question}\n\n{cta} {website}", 'hashtags' => ['CoreX', 'RealEstate']],
        ];
    }

    private function generate(array $draft): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->post(self::BASE, ['draft' => json_encode($draft)]);
    }

    // ---- access: System Owner only -----------------------------------------------------------------------------

    public function test_every_route_is_403_for_an_agency_admin_and_works_for_the_owner(): void
    {
        Bus::fake();
        $ad = $this->generate($this->draft());
        $ad = VideoAd::firstOrFail();

        $gets = [self::BASE, self::BASE . '/brand', self::BASE . '/new/demo', self::BASE . '/duplicate/' . $ad->id, self::BASE . '/' . $ad->id,
            self::BASE . '/' . $ad->id . '/file/9x16/mp4', self::BASE . '/' . $ad->id . '/download/9x16', '/api/v1/admin/video-ads/progress'];
        foreach ($gets as $url) {
            $this->actingAs($this->admin)->get($url)->assertForbidden();
        }
        $this->actingAs($this->admin)->post(self::BASE, ['draft' => json_encode($this->draft())])->assertForbidden();
        $this->actingAs($this->admin)->put(self::BASE . '/brand', [])->assertForbidden();
        $this->actingAs($this->admin)->delete(self::BASE . '/' . $ad->id)->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/' . $ad->id . '/restore')->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/' . $ad->id . '/retry')->assertForbidden();
        $this->actingAs($this->admin)->postJson('/api/v1/admin/video-ads/validate', ['draft' => $this->draft()])->assertForbidden();
        $this->assertNull(VideoAd::onlyTrashed()->first());

        foreach ([self::BASE, self::BASE . '/brand', self::BASE . '/new/demo', self::BASE . '/duplicate/' . $ad->id, self::BASE . '/' . $ad->id] as $url) {
            $this->actingAs($this->owner)->get($url)->assertOk();
        }
    }

    // ---- the editor's rules ------------------------------------------------------------------------------------

    public function test_the_live_check_flags_plain_word_build_valuation_length_and_accent_problems(): void
    {
        $bad = $this->draft([
            'question' => 'Is your valuation database slow? It is not.',
            'shots' => [
                ['caption' => 'We are building the best tile panel for you', 'step' => 0, 'action' => 'highlight', 'zoom' => 1.0],
                ['caption' => 'One two three four five six seven eight nine ten eleven twelve thirteen', 'step' => 1, 'action' => 'click', 'zoom' => 3.0],
            ],
            'length' => 15,
        ]);
        $r = $this->actingAs($this->owner)->postJson('/api/v1/admin/video-ads/validate', ['draft' => $bad])->assertOk()->json();
        $text = implode(' | ', $r['problems']);
        $this->assertStringContainsString('"valuation"', $text);
        $this->assertStringContainsString('evaluation', $text);
        $this->assertStringContainsString('"database"', $text);
        $this->assertStringContainsString('"tile"', $text);
        $this->assertStringContainsString('never say "building"', $text);
        $this->assertStringContainsString('asterisks', $text);          // no cyan word marked
        $this->assertStringContainsString('too long to read', $text);   // caption over the safe-area limit
        $this->assertStringContainsString('zoom must be between', $text);
        $this->assertGreaterThan(0, $r['seconds']);
    }

    public function test_a_good_draft_passes_and_an_overlong_one_is_refused_for_its_length(): void
    {
        $ok = $this->actingAs($this->owner)->postJson('/api/v1/admin/video-ads/validate', ['draft' => $this->draft()])->assertOk()->json();
        $this->assertSame([], $ok['problems']);

        $long = $this->draft(['length' => 15, 'shots' => array_fill(0, 6, ['caption' => 'See every house in your area now.', 'step' => 0, 'action' => 'highlight', 'zoom' => 1.3])]);
        $r = $this->actingAs($this->owner)->postJson('/api/v1/admin/video-ads/validate', ['draft' => $long])->json();
        $this->assertStringContainsString('over the 15 second length', implode(' ', $r['problems']));
    }

    public function test_an_unapproved_feature_script_cannot_be_generated(): void
    {
        Bus::fake();
        $this->ad('demo', false);
        $this->app->forgetInstance(AdScriptRepository::class);
        $this->generate($this->draft())->assertSessionHasErrors('draft');
        $this->assertSame(0, VideoAd::count());
        Bus::assertNotDispatched(GenerateVideoAdJob::class);
    }

    // ---- Generate, the frozen snapshot, Ad N, Duplicate --------------------------------------------------------

    public function test_generate_saves_ad_1_with_a_frozen_snapshot_and_queues_the_render(): void
    {
        Bus::fake();
        $this->generate($this->draft())->assertRedirect();
        $ad = VideoAd::firstOrFail();

        $this->assertSame('Ad 1', $ad->name);
        $this->assertSame('queued', $ad->status);
        $this->assertSame('STILL GUESSING WHICH OWNERS TO PHONE?', mb_strtoupper($ad->snapshot['question']['text']));
        $this->assertSame('guessing', $ad->snapshot['question']['accent']);
        $this->assertSame('[data-a]', $ad->snapshot['shots'][0]['selector']);   // resolved from the training script step
        $this->assertSame('[data-b]', $ad->snapshot['shots'][1]['selector']);
        $this->assertSame(['9:16', '1:1'], $ad->formats);
        $this->assertStringContainsString('corexweb.co.za', $ad->post_caption);
        $this->assertStringContainsString('Book a demo:', $ad->post_caption);
        $this->assertSame(['CoreX', 'RealEstate'], $ad->post_hashtags);
        $this->assertSame("{$ad->post_caption}\n\n#CoreX #RealEstate", $ad->postText());
        Bus::assertDispatched(GenerateVideoAdJob::class, fn ($j) => $j->adId === $ad->id);

        $this->generate($this->draft())->assertRedirect();
        $this->assertSame(['Ad 1', 'Ad 2'], VideoAd::orderBy('ad_number')->pluck('name')->all());
        VideoAd::first()->delete();   // an archived number is never reused
        $this->generate($this->draft())->assertRedirect();
        $this->assertSame(3, VideoAd::withTrashed()->max('ad_number'));
    }

    public function test_editing_the_script_the_brand_or_the_training_script_never_changes_a_generated_ad(): void
    {
        Bus::fake();
        $this->generate($this->draft());
        $ad = VideoAd::firstOrFail();
        $frozen = $ad->snapshot;

        // The script, the brand and the training step all change afterwards.
        file_put_contents($this->dir . '/ads/demo.php', str_replace('See every house in your area.', 'COMPLETELY NEW WORDS', file_get_contents($this->dir . '/ads/demo.php')));
        AdStudioSetting::put('website', 'https://example.test');
        AdStudioSetting::put('phone', '000');
        $this->app->forgetInstance(AdScriptRepository::class);

        $this->assertEquals($frozen, $ad->fresh()->snapshot);
        $this->assertSame('https://corexweb.co.za', $ad->fresh()->snapshot['brand']['website']);
        // ...and a NEW ad picks the new brand up.
        $this->generate($this->draft());
        $this->assertSame('https://example.test', VideoAd::where('ad_number', 2)->first()->snapshot['brand']['website']);
    }

    public function test_duplicate_prefills_the_editor_from_the_snapshot_and_the_next_generate_is_ad_n_plus_1(): void
    {
        Bus::fake();
        $this->generate($this->draft(['question' => 'Why wait for sellers to *call* you?', 'cta' => ['join_line' => 'Join us today', 'cta_line' => '']]));
        $ad = VideoAd::firstOrFail();

        $page = $this->actingAs($this->owner)->get(self::BASE . '/duplicate/' . $ad->id)->assertOk();
        $page->assertSee('Why wait for sellers to *call* you?', false);
        $page->assertSee('Join us today', false);
        $page->assertSee('Generate Ad 2', false);
        $this->assertSame(1, VideoAd::count());   // no row until Generate

        $draft = app(\App\Services\AdStudio\AdSnapshotBuilder::class)->toDraft($ad->snapshot);
        $draft['question'] = 'Why wait for sellers to *ring* you?';
        $this->generate($draft)->assertRedirect();
        $this->assertSame('Ad 2', VideoAd::where('ad_number', 2)->value('name'));
        $this->assertSame('Why wait for sellers to call you?', VideoAd::where('ad_number', 1)->first()->snapshot['question']['text']);   // Ad 1 untouched
        $this->assertSame('Why wait for sellers to ring you?', VideoAd::where('ad_number', 2)->first()->snapshot['question']['text']);
    }

    // ---- the render hand-over ----------------------------------------------------------------------------------

    public function test_the_job_hands_the_frozen_request_to_the_renderer_and_the_poll_reaches_ready(): void
    {
        $this->generate($this->draft());   // sync queue in tests -> the job has run
        $ad = VideoAd::firstOrFail();
        $this->assertSame('rendering', $ad->status);
        $this->assertSame('batch-1', $ad->render_batch_id);

        $req = $this->gateway->queued[0];
        $this->assertSame($ad->id, $req['ad_ref']);
        $this->assertSame(30, $req['length']);
        $this->assertSame(['9:16', '1:1'], $req['formats']);
        $this->assertSame('Still guessing which owners to phone?', $req['script']['hook']['text']);
        $this->assertSame('guessing', $req['script']['hook']['accent']);
        $this->assertCount(2, $req['script']['beats']);
        $this->assertEquals(['script_key' => 'demo-screen', 'step' => 0], $req['script']['beats'][0]['screen']);
        $this->assertSame('Join CoreX', $req['script']['cta']['line']);
        $this->assertSame('Book a demo', $req['script']['cta']['sub']);
        $this->assertSame('info@corexweb.co.za', $req['brand']['contact_email']);
        $this->assertSame('#33c4e0', $req['brand']['colours']['accent']);
        $this->assertSame(['facebook' => 'https://www.facebook.com/profile.php?id=61593249039756'], $req['brand']['socials']);   // only what is filled in
        $this->assertSame('Follow us on Facebook', $req['script']['cta']['follow']);

        $this->gateway->statuses['batch-1'] = ['state' => 'rendering', 'percent' => 40, 'stage' => '9:16', 'failure' => null, 'files' => []];
        $this->actingAs($this->owner)->getJson('/api/v1/admin/video-ads/progress')->assertOk()->assertJsonPath('working.0.percent', 40);

        $this->gateway->statuses['batch-1'] = ['state' => 'ready', 'percent' => 100, 'stage' => null, 'failure' => null,
            'files' => ['9:16' => ['mp4' => storage_path('app/x.mp4'), 'poster' => storage_path('app/x.jpg'), 'seconds' => 24.0, 'width' => 1080, 'height' => 1920, 'size' => 10]]];
        $this->actingAs($this->owner)->getJson('/api/v1/admin/video-ads/progress')->assertOk()->assertJsonCount(0, 'working');
        $this->assertSame('ready', $ad->fresh()->status);
        $this->assertSame(['9:16'], array_keys($ad->fresh()->files));
    }

    public function test_a_render_failure_keeps_its_reason_and_try_again_resends_the_same_request(): void
    {
        $this->gateway->failWith = 'The video renderer is not installed on this server yet.';
        $this->generate($this->draft());
        $ad = VideoAd::firstOrFail();
        $this->assertSame('failed', $ad->status);
        $this->assertSame('The video renderer is not installed on this server yet.', $ad->failure_reason);

        $this->gateway->failWith = null;
        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/retry')->assertRedirect();
        $this->assertSame('rendering', $ad->fresh()->status);
        $this->assertSame($ad->id, $this->gateway->queued[0]['ad_ref']);
    }

    // ---- history list: search, sort, filters, archive/restore, downloads ----------------------------------------

    public function test_history_search_sort_filter_archive_and_restore(): void
    {
        Bus::fake();
        $this->generate($this->draft());
        $this->generate($this->draft(['question' => 'Why wait for sellers to *call* you?', 'length' => 60]));
        [$one, $two] = [VideoAd::where('ad_number', 1)->first(), VideoAd::where('ad_number', 2)->first()];
        $two->update(['status' => 'ready', 'files' => ['9:16' => ['mp4' => 'x', 'poster' => 'y']]]);

        $list = fn (string $q = '') => $this->actingAs($this->owner)->get(self::BASE . $q);
        $list()->assertOk()->assertSee('Ad 1')->assertSee('Ad 2')->assertSee('Demo feature');
        $list('?q=Why+wait')->assertSee('Ad 2')->assertDontSee('data-ad-id="' . $one->id . '"', false);
        $list('?q=zzzz-nothing')->assertSee('No ads match these filters');
        $list('?status=ready')->assertSee('data-ad-id="' . $two->id . '"', false)->assertDontSee('data-ad-id="' . $one->id . '"', false);
        $list('?format=1:1')->assertSee('data-ad-id="' . $one->id . '"', false);
        $list('?sort=length&dir=desc')->assertOk();
        $list('?sort=name&dir=asc')->assertOk();
        $list('?from=2999-01-01')->assertSee('No ads match these filters');

        $this->actingAs($this->owner)->delete(self::BASE . '/' . $one->id)->assertRedirect();
        $this->assertSoftDeleted('video_ads', ['id' => $one->id]);
        $list()->assertDontSee('data-ad-id="' . $one->id . '"', false);
        $list('?status=archived')->assertSee('data-ad-id="' . $one->id . '"', false)->assertSee('Restore');
        $this->actingAs($this->owner)->post(self::BASE . '/' . $one->id . '/restore')->assertRedirect();
        $this->assertNull($one->fresh()->deleted_at);
    }

    public function test_download_streams_only_files_under_storage_and_gives_an_attachment(): void
    {
        Bus::fake();
        $this->generate($this->draft());
        $ad = VideoAd::firstOrFail();
        $dir = storage_path('app/video-ads-test');
        File::ensureDirectoryExists($dir);
        file_put_contents($dir . '/a.mp4', 'fakevideo');
        file_put_contents($dir . '/a.jpg', 'fakeposter');
        $outside = sys_get_temp_dir() . '/outside-' . uniqid() . '.mp4';
        file_put_contents($outside, 'secret');
        $ad->update(['status' => 'ready', 'files' => ['9:16' => ['mp4' => $dir . '/a.mp4', 'poster' => $dir . '/a.jpg'], '1:1' => ['mp4' => $outside, 'poster' => $outside]]]);

        $r = $this->actingAs($this->owner)->get(self::BASE . '/' . $ad->id . '/download/9x16')->assertOk();
        $this->assertStringContainsString('attachment', (string) $r->headers->get('content-disposition'));
        $this->assertStringContainsString('.mp4', (string) $r->headers->get('content-disposition'));
        $this->actingAs($this->owner)->get(self::BASE . '/' . $ad->id . '/file/9x16/jpg')->assertOk();
        $this->actingAs($this->owner)->get(self::BASE . '/' . $ad->id . '/download/1x1')->assertNotFound();   // outside storage: never served
        $this->actingAs($this->owner)->get(self::BASE . '/' . $ad->id . '/download/16x9')->assertNotFound();   // not made
        $this->actingAs($this->owner)->get(self::BASE . '/' . $ad->id)->assertOk()->assertSee('Copy post text')->assertSee('Download 9:16');

        File::deleteDirectory($dir);
        @unlink($outside);
    }

    // ---- brand settings ----------------------------------------------------------------------------------------

    public function test_brand_defaults_show_when_empty_and_settings_round_trip(): void
    {
        $brand = AdBrand::current();
        $this->assertSame('info@corexweb.co.za', $brand['email']);
        $this->assertSame('+27 76 618 5578', $brand['phone']);
        $this->assertSame('#33c4e0', $brand['colours']['accent']);
        $this->assertSame('Join CoreX', $brand['join_line']);
        $this->assertSame(['facebook' => 'https://www.facebook.com/profile.php?id=61593249039756', 'instagram' => '', 'linkedin' => '', 'youtube' => '', 'tiktok' => ''], $brand['social']);
        $this->assertNull($brand['music']);
        $this->assertSame('The operating system for South African estate agencies. Listings, compliance, deals - one system.', $brand['tagline']);
        $this->assertSame('Follow us on Facebook', $brand['follow_line']);
        $this->assertSame(['Facebook' => 'https://www.facebook.com/profile.php?id=61593249039756'], AdBrand::filledSocials($brand));

        $this->actingAs($this->owner)->get(self::BASE . '/brand')->assertOk()->assertSee('Brand settings')->assertSee('info@corexweb.co.za');

        $this->actingAs($this->owner)->put(self::BASE . '/brand', [
            'website' => 'https://example.test', 'email' => 'hello@example.test', 'phone' => '+27 11 000 0000', 'join_line' => 'Come aboard', 'cta_line' => 'Ring us', 'tagline' => 'A tagline',
            'colours' => ['primary' => '#112233', 'secondary' => '', 'accent' => '#ff0066', 'text' => '#ffffff'],
            'social' => ['facebook' => 'https://facebook.com/corex', 'instagram' => '@corex', 'linkedin' => '', 'youtube' => '', 'tiktok' => ''],
        ])->assertRedirect(self::BASE . '/brand');

        $b = AdBrand::current();
        $this->assertSame('https://example.test', $b['website']);
        $this->assertSame('Come aboard', $b['join_line']);
        $this->assertSame('#ff0066', $b['colours']['accent']);
        $this->assertSame('#0e5a7a', $b['colours']['secondary']);   // blank = the default
        $this->assertSame(['Facebook' => 'https://facebook.com/corex', 'Instagram' => '@corex'], AdBrand::filledSocials($b));

        // clearing a field goes back to its default; bad input is refused
        $this->actingAs($this->owner)->put(self::BASE . '/brand', ['email' => '', 'colours' => ['accent' => 'cyan']])->assertSessionHasErrors('colours.accent');
        $this->actingAs($this->owner)->put(self::BASE . '/brand', ['email' => '', 'website' => ''])->assertRedirect();
        $this->assertSame('info@corexweb.co.za', AdBrand::current()['email']);
    }

    public function test_the_shipped_ad_scripts_are_valid_and_wait_for_johans_approval(): void
    {
        config(['training-videos.auto.scripts_dir' => resource_path('training/scripts'), 'ad-studio.scripts_dir' => resource_path('training/ads')]);
        $this->app->forgetInstance(TrainingScriptRepository::class);
        $this->app->forgetInstance(AdScriptRepository::class);
        $repo = app(AdScriptRepository::class);
        $this->assertSame(['book-a-viewing', 'mic-work'], array_keys($repo->all()));
        foreach ($repo->all() as $key => $script) {
            $this->assertFalse($script->approved(), "{$key} must wait for approval");
            $draft = app(VideoAdService::class)->starterDraft($script);
            foreach (array_keys($script->questions()) as $variant) {
                $draft = app(VideoAdService::class)->starterDraft($script, $variant);
                $problems = app(\App\Services\AdStudio\AdScriptValidator::class)->check($draft)['problems'];
                // the only problem a shipped script may have is "not approved yet"
                $this->assertSame(["The ad script for {$script->title()} is not approved yet. Johan approves it first."], $problems, "{$key} variant {$variant}");
            }
        }
    }

    public function test_the_sub_line_and_the_stat_badge_follow_andres_style_rules(): void
    {
        $call = fn (array $over) => $this->actingAs($this->owner)->postJson('/api/v1/admin/video-ads/validate', ['draft' => $this->draft($over)])->json('problems');

        $this->assertSame([], $call(['subline' => 'Every agent. Every morning. A call list ready.']));
        $this->assertStringContainsString('sub-line is too long', implode(' ', $call(['subline' => 'one two three four five six seven eight nine ten eleven twelve'])));
        $this->assertStringContainsString('"database"', implode(' ', $call(['subline' => 'Your database, ready'])));
        // a number needs its source, or to be marked as an example
        $this->assertStringContainsString('must be a real number with its source', implode(' ', $call(['badge' => ['text' => '3 in 10', 'example' => false, 'source' => '']])));
        $this->assertSame([], $call(['badge' => ['text' => '3 in 10', 'example' => true, 'source' => '']]));
        $this->assertSame([], $call(['badge' => ['text' => '3 in 10', 'example' => false, 'source' => 'PPRA report 2026']]));

        Bus::fake();
        $this->generate($this->draft(['subline' => 'Every agent. Every morning.', 'badge' => ['text' => '3 in 10', 'example' => true, 'source' => '']]))->assertRedirect();
        $ad = VideoAd::firstOrFail();
        $this->assertSame('Every agent. Every morning.', $ad->snapshot['request']['script']['hook']['sub']);
        $this->assertEquals(['text' => '3 in 10', 'example' => true, 'source' => ''], $ad->snapshot['request']['script']['hook']['badge']);
        $draft = app(\App\Services\AdStudio\AdSnapshotBuilder::class)->toDraft($ad->snapshot);
        $this->assertSame('Every agent. Every morning.', $draft['subline']);   // Duplicate keeps them
    }
}
