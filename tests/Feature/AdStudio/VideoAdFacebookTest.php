<?php

namespace Tests\Feature\AdStudio;

use App\Models\AgentSocialAccount;
use App\Models\Role;
use App\Models\User;
use App\Models\VideoAd;
use App\Models\VideoAdPost;
use App\Services\AdStudio\VideoAdPostService;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AT-527 (spec .ai/specs/training-videos.md 9.22) - connect the CoreX Facebook page, Post now / Schedule a video ad, the due-posts pass, dry run. EVERY Graph call
 * here goes through a Guzzle MockHandler: no test ever talks to Facebook, and an empty queue makes any unexpected call fail loudly.
 */
final class VideoAdFacebookTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/corex/admin/video-ads';

    private User $owner;
    private User $admin;
    private array $history = [];
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
        config(['services.meta.app_id' => 'app-id', 'services.meta.app_secret' => 'secret', 'services.meta.redirect_uri' => 'https://example.test/corex/social/oauth/callback']);
        $this->dir = storage_path('app/video-ads-fb-test');
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** A Guzzle client that answers from a queue and records every request. */
    private function client(array $responses): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private function useGraph(array $responses): void
    {
        $c = $this->client($responses);
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService($c));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService($c));
        $this->app->forgetInstance(VideoAdPostService::class);
    }

    private function resp(array $d): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($d));
    }

    private function connectedPage(): AgentSocialAccount
    {
        return AgentSocialAccount::withoutGlobalScopes()->create([
            'user_id' => $this->owner->id, 'agency_id' => null, 'platform' => 'facebook', 'platform_page_id' => '999', 'platform_page_name' => 'CoreX Test Page',
            'access_token' => 'page-token-secret', 'token_expires_at' => now()->addDays(50), 'is_active' => true, 'is_platform' => true,
        ]);
    }

    private function readyAd(array $formats = ['1:1', '9:16']): VideoAd
    {
        $files = [];
        foreach ($formats as $f) {
            $name = $this->dir . '/ad-' . str_replace(':', 'x', $f) . '.mp4';
            file_put_contents($name, 'fakevideo-' . $f);
            $files[$f] = ['mp4' => $name, 'poster' => $name, 'seconds' => 24.0, 'width' => 1080, 'height' => 1080, 'size' => 10];
        }

        return VideoAd::create([
            'feature_key' => 'mic-work', 'ad_number' => 1, 'name' => 'Ad 1', 'snapshot' => ['question' => ['text' => 'Q?'], 'feature_title' => 'Market Intelligence'], 'length_seconds' => 30,
            'formats' => $formats, 'status' => 'ready', 'files' => $files, 'post_caption' => 'Still guessing?', 'post_hashtags' => ['CoreX'],
        ]);
    }

    // ---- access ------------------------------------------------------------------------------------------------

    public function test_every_facebook_and_posts_route_is_403_for_an_agency_admin(): void
    {
        $ad = $this->readyAd();
        $post = VideoAdPost::create(['video_ad_id' => $ad->id, 'format' => '1:1', 'message' => 'x', 'status' => 'scheduled', 'scheduled_for' => now()->addDay()]);
        foreach ([self::BASE . '/facebook', self::BASE . '/facebook/connect', self::BASE . '/facebook/choose-page', self::BASE . '/posts'] as $url) {
            $this->actingAs($this->admin)->get($url)->assertForbidden();
            $this->actingAs($this->owner)->get($url)->assertStatus(in_array($url, [self::BASE . '/facebook/connect', self::BASE . '/facebook/choose-page'], true) ? 302 : 200);
        }
        $this->actingAs($this->admin)->post(self::BASE . '/facebook/disconnect')->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/facebook/choose-page', ['page_id' => '1'])->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/' . $ad->id . '/post-now')->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/' . $ad->id . '/schedule', ['format' => '1:1', 'scheduled_at' => now()->addDay()->toDateTimeString()])->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/posts/' . $post->id . '/cancel')->assertForbidden();
        $this->actingAs($this->admin)->post(self::BASE . '/posts/' . $post->id . '/reschedule', ['scheduled_at' => now()->addDay()->toDateTimeString()])->assertForbidden();
        $this->assertSame('scheduled', $post->fresh()->status);
    }

    // ---- connecting the page through Facebook's own login -------------------------------------------------------

    public function test_connect_sends_the_owner_to_facebook_with_a_platform_state_and_a_nonce(): void
    {
        $r = $this->actingAs($this->owner)->get(self::BASE . '/facebook/connect');
        $r->assertRedirect();
        $url = $r->headers->get('Location');
        $this->assertStringStartsWith('https://www.facebook.com/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $state = json_decode(base64_decode($q['state']), true);
        $this->assertSame('platform', $state['context']);
        $this->assertSame($this->owner->id, $state['user_id']);
        $this->assertSame(session('video_ads_fb_nonce'), $state['nonce']);
        $this->assertSame('app-id', $q['client_id']);
    }

    private function callbackState(string $nonce = 'N1'): string
    {
        return base64_encode(json_encode(['user_id' => $this->owner->id, 'platform' => 'facebook', 'context' => 'platform', 'nonce' => $nonce]));
    }

    private function loginResponses(array $pages): array
    {
        return [$this->resp(['access_token' => 'short']), $this->resp(['access_token' => 'long']), $this->resp(['data' => $pages]), $this->resp(['data' => []])];
    }

    public function test_the_callback_with_one_page_connects_it_as_the_platform_page_not_an_agency_one(): void
    {
        $this->useGraph($this->loginResponses([['id' => '999', 'name' => 'CoreX Test Page', 'access_token' => 'page-token']]));
        $this->actingAs($this->owner)->withSession(['video_ads_fb_nonce' => 'N1'])
            ->get('/corex/social/oauth/callback?code=abc&state=' . urlencode($this->callbackState()))
            ->assertRedirect(self::BASE . '/facebook');

        $acc = AgentSocialAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertTrue($acc->is_platform);
        $this->assertNull($acc->agency_id);
        $this->assertSame('999', $acc->platform_page_id);
        $this->assertSame('page-token', $acc->access_token);   // encrypted at rest, decrypted here
        $this->assertNotSame('page-token', \Illuminate\Support\Facades\DB::table('agent_social_accounts')->value('access_token'));
        $this->assertSame($acc->id, app(VideoAdPostService::class)->account()->id);
        $this->actingAs($this->owner)->get(self::BASE . '/facebook')->assertSee('CoreX Test Page');
    }

    public function test_a_wrong_nonce_connects_nothing(): void
    {
        $this->useGraph($this->loginResponses([['id' => '999', 'name' => 'P', 'access_token' => 't']]));
        $this->actingAs($this->owner)->withSession(['video_ads_fb_nonce' => 'REAL'])
            ->get('/corex/social/oauth/callback?code=abc&state=' . urlencode($this->callbackState('FORGED')))
            ->assertRedirect(self::BASE . '/facebook')->assertSessionHasErrors('facebook');
        $this->assertSame(0, AgentSocialAccount::withoutGlobalScopes()->count());
    }

    public function test_with_several_pages_the_owner_picks_one_and_disconnect_stops_posting(): void
    {
        $this->useGraph($this->loginResponses([['id' => '1', 'name' => 'Other Page', 'access_token' => 't1'], ['id' => '2', 'name' => 'CoreX Page', 'access_token' => 't2']]));
        $this->actingAs($this->owner)->withSession(['video_ads_fb_nonce' => 'N1'])
            ->get('/corex/social/oauth/callback?code=abc&state=' . urlencode($this->callbackState()))
            ->assertRedirect(self::BASE . '/facebook/choose-page');
        $this->actingAs($this->owner)->get(self::BASE . '/facebook/choose-page')->assertOk()->assertSee('Other Page')->assertSee('CoreX Page');
        $this->actingAs($this->owner)->post(self::BASE . '/facebook/choose-page', ['page_id' => '2'])->assertRedirect(self::BASE . '/facebook');
        $this->assertSame('CoreX Page', app(VideoAdPostService::class)->account()->platform_page_name);

        $this->actingAs($this->owner)->post(self::BASE . '/facebook/disconnect')->assertRedirect();
        $this->assertNull(app(VideoAdPostService::class)->account());
        $this->assertSame(1, AgentSocialAccount::withoutGlobalScopes()->withTrashed()->count());   // archived, not removed
    }

    // ---- dry run (external publishing off, as on QA1) -----------------------------------------------------------

    public function test_post_now_is_a_dry_run_when_external_publishing_is_off_and_sends_nothing(): void
    {
        config(['corex.external_publishing' => false]);
        $this->connectedPage();
        $ad = $this->readyAd();
        $this->useGraph([]);   // ANY Graph call would throw: the queue is empty

        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/post-now')->assertRedirect()->assertSessionHas('status');
        $post = VideoAdPost::firstOrFail();
        $this->assertSame('published', $post->status);
        $this->assertTrue($post->dry_run);
        $this->assertStringStartsWith('dry-run-', $post->platform_post_id);
        $this->assertSame('1:1', $post->format);       // the square feed video by default
        $this->assertSame([], $this->history);

        $this->actingAs($this->owner)->get(self::BASE)->assertSee('Dry run (QA)');
        $this->actingAs($this->owner)->get(self::BASE . '/' . $ad->id)->assertSee('Dry run (QA)');
        $this->actingAs($this->owner)->get(self::BASE . '/posts')->assertSee('Dry run - not posted');
    }

    public function test_the_dry_run_badge_is_gone_when_posting_is_on(): void
    {
        config(['corex.external_publishing' => true]);
        $this->readyAd();
        $this->actingAs($this->owner)->get(self::BASE)->assertDontSee('Dry run (QA)');
    }

    // ---- real posting path (fake Graph) -------------------------------------------------------------------------

    public function test_a_small_video_is_one_upload_to_the_page_videos_endpoint(): void
    {
        config(['corex.external_publishing' => true]);
        $this->connectedPage();
        $ad = $this->readyAd();
        $this->useGraph([$this->resp(['id' => 'vid-1'])]);

        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/post-now', ['format' => '9:16'])->assertRedirect();
        $post = VideoAdPost::firstOrFail();
        $this->assertSame('published', $post->status);
        $this->assertFalse($post->dry_run);
        $this->assertSame('vid-1', $post->platform_post_id);
        $this->assertCount(1, $this->history);
        $req = $this->history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringEndsWith('/999/videos', (string) $req->getUri());
        $body = (string) $req->getBody();
        $this->assertStringContainsString('fakevideo-9:16', $body);
        $this->assertStringContainsString('Still guessing?', $body);
        $this->assertStringContainsString('page-token-secret', $body);   // the page token goes to Facebook, and only there
    }

    public function test_a_large_video_uses_the_resumable_upload_start_transfer_finish(): void
    {
        config(['corex.external_publishing' => true]);
        $this->connectedPage();
        $ad = $this->readyAd(['1:1']);
        $f = fopen($ad->files['1:1']['mp4'], 'r+');
        ftruncate($f, MetaPublishingService::SIMPLE_VIDEO_MAX + 1024);   // a sparse file: cheap, but over the one-request limit
        fclose($f);
        $this->useGraph([
            $this->resp(['upload_session_id' => 's1', 'video_id' => 'v1', 'start_offset' => '0', 'end_offset' => '5']),
            $this->resp(['start_offset' => '5', 'end_offset' => '5']),
            $this->resp(['success' => true]),
        ]);

        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/post-now')->assertRedirect();
        $post = VideoAdPost::firstOrFail();
        $this->assertSame('published', $post->status);
        $this->assertSame('v1', $post->platform_post_id);
        $this->assertCount(3, $this->history);
        $this->assertStringContainsString('upload_phase=start', (string) $this->history[0]['request']->getBody());
        $this->assertStringContainsString('upload_phase', (string) $this->history[1]['request']->getBody());
        $this->assertStringContainsString('transfer', (string) $this->history[1]['request']->getBody());
        $this->assertStringContainsString('upload_phase=finish', (string) $this->history[2]['request']->getBody());
    }

    public function test_a_facebook_error_fails_the_post_with_a_reason_and_never_leaks_the_token(): void
    {
        config(['corex.external_publishing' => true]);
        $this->connectedPage();
        $ad = $this->readyAd();
        $this->useGraph([new Response(400, [], json_encode(['error' => ['message' => 'Invalid parameter']]))]);

        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/post-now')->assertRedirect();
        $post = VideoAdPost::firstOrFail();
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Failed to post the video to Facebook', $post->failure_reason);
        $this->assertStringNotContainsString('page-token-secret', (string) $post->failure_reason);
    }

    // ---- guards, scheduling, the cron pass, the list -----------------------------------------------------------

    public function test_nothing_can_be_posted_without_a_connected_page_or_a_finished_ad(): void
    {
        $ad = $this->readyAd();
        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/post-now')->assertSessionHasErrors('post');
        $this->connectedPage();
        $ad->update(['status' => 'failed']);
        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/post-now')->assertSessionHasErrors('post');
        $this->assertSame(0, VideoAdPost::count());
    }

    public function test_schedule_stores_utc_refuses_the_past_and_the_cron_pass_posts_only_what_is_due(): void
    {
        config(['corex.external_publishing' => false]);
        $this->connectedPage();
        $ad = $this->readyAd();
        $this->useGraph([]);

        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/schedule', ['format' => '1:1', 'scheduled_at' => now()->subHour()->toDateTimeString()])->assertSessionHasErrors('post');
        $this->actingAs($this->owner)->post(self::BASE . '/' . $ad->id . '/schedule', ['format' => '1:1', 'scheduled_at' => '2999-03-01T09:30'])->assertRedirect(self::BASE . '/posts');
        $future = VideoAdPost::firstOrFail();
        $this->assertSame('2999-03-01 07:30:00', $future->getRawOriginal('scheduled_for'));   // 09:30 in South Africa = 07:30 UTC

        $due = VideoAdPost::create(['video_ad_id' => $ad->id, 'format' => '9:16', 'message' => 'due', 'status' => 'scheduled', 'scheduled_for' => now()->subMinutes(2)]);
        $this->assertSame(0, Artisan::call('marketing:publish-due'));
        $this->assertStringContainsString('CoreX video-ad posts: 1 published (dry run)', Artisan::output());
        $this->assertSame('published', $due->fresh()->status);
        $this->assertTrue($due->fresh()->dry_run);
        $this->assertSame('scheduled', $future->fresh()->status);   // not due yet
        $this->assertSame('skipped', app(VideoAdPostService::class)->publish($due->fresh()));   // already handled: never posted twice
    }

    public function test_list_filters_cancel_and_move(): void
    {
        $ad = $this->readyAd();
        $a = VideoAdPost::create(['video_ad_id' => $ad->id, 'format' => '1:1', 'message' => 'alpha text', 'status' => 'scheduled', 'scheduled_for' => now()->addDays(2)]);
        $b = VideoAdPost::create(['video_ad_id' => $ad->id, 'format' => '9:16', 'message' => 'beta text', 'status' => 'failed', 'failure_reason' => 'Boom', 'scheduled_for' => now()->subDay()]);
        $list = fn (string $q = '') => $this->actingAs($this->owner)->get(self::BASE . '/posts' . $q);

        $list()->assertOk()->assertSee('alpha text')->assertSee('beta text')->assertSee('Boom');
        $list('?status=failed')->assertSee('data-post-id="' . $b->id . '"', false)->assertDontSee('data-post-id="' . $a->id . '"', false);
        $list('?q=alpha')->assertSee('data-post-id="' . $a->id . '"', false)->assertDontSee('data-post-id="' . $b->id . '"', false);
        $list('?q=zzz')->assertSee('No posts match these filters');
        $list('?sort=status&dir=asc')->assertOk();

        $this->actingAs($this->owner)->post(self::BASE . '/posts/' . $a->id . '/reschedule', ['scheduled_at' => '2999-05-05T10:00'])->assertSessionHasNoErrors();
        $this->assertSame('2999-05-05 08:00:00', $a->fresh()->getRawOriginal('scheduled_for'));
        $this->actingAs($this->owner)->post(self::BASE . '/posts/' . $a->id . '/cancel')->assertRedirect();
        $this->assertSame('cancelled', $a->fresh()->status);
        $this->actingAs($this->owner)->post(self::BASE . '/posts/' . $a->id . '/reschedule', ['scheduled_at' => '2999-05-06T10:00'])->assertSessionHasErrors('post');   // cancelled: cannot move
    }
}
