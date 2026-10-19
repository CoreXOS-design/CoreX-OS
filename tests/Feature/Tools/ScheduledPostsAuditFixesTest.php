<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Features\AgencyFeatureService;
use App\Services\Marketing\ScheduledPostImages;
use App\Services\Marketing\ScheduledPostPublisher;
use App\Services\Marketing\ScheduledPostService;
use App\Services\MetaOAuthService;
use App\Services\MetaPublishingService;
use App\Services\PermissionService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 Part D — the audit fixes (spec §D "Audit fixes"): a disconnected account never publishes, a
 * killed run cannot block publishing, a failed write after Meta accepted a post is recovered, the owner's
 * access is re-checked at publish time, pictures are pixel-capped, image URLs are rebuilt at publish time,
 * the status sort follows the lifecycle, and the direct-publish wording / logging.
 *
 * Clock frozen at 2026-10-20 08:00 UTC. Meta is faked at the Guzzle layer; a call beyond the queue throws,
 * so "nothing was sent to Meta" is asserted by an untouched queue and an empty request history.
 */
final class ScheduledPostsAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $metaQueue;

    /** @var list<array<string,mixed>> */
    private array $metaRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));
        Storage::fake('public');
        $this->fakeMeta([]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PermissionService::clearCache();
        parent::tearDown();
    }

    // ── #1 disconnected account ─────────────────────────────────────────────

    public function test_a_post_scheduled_before_the_account_was_disconnected_does_not_publish(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);

        // The agent disconnects through the real endpoint (it soft-deletes the row).
        $this->actingAs($agent)->post(route('corex.marketing.social.disconnect'), ['platform' => 'facebook'])->assertRedirect();
        $this->assertSame(0, AgentSocialAccount::query()->where('user_id', $agent->id)->count());
        $this->assertSame(1, AgentSocialAccount::withTrashed()->where('user_id', $agent->id)->count());

        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 1 failed')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertSame('No connected Facebook account was found. Connect it under My Portal, Tools, Social Media, then schedule the post again.', $post->failure_reason);
        $this->assertSame([], $this->metaRequests, 'Nothing was sent to Meta.');
    }

    // ── #2 schedule definition ──────────────────────────────────────────────

    public function test_the_publish_command_lock_expires_in_ten_minutes_and_runs_in_the_background(): void
    {
        $publish = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'marketing:publish-due'));

        $this->assertNotNull($publish);
        $this->assertTrue($publish->withoutOverlapping);
        $this->assertSame(10, $publish->expiresAt, 'A killed run must not block publishing for the 24h default.');
        $this->assertTrue($publish->runInBackground);
        $this->assertTrue($publish->onOneServer);
    }

    // ── #3 write after Meta success ─────────────────────────────────────────

    public function test_a_failed_save_after_meta_accepted_the_post_is_recovered_and_the_run_carries_on(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);
        $first  = $this->insertPost($agency, $agent, $property, ['scheduled_for' => '2026-10-20 07:00:00']);
        $second = $this->insertPost($agency, $agent, $property, ['scheduled_for' => '2026-10-20 07:01:00']);
        $this->fakeMeta([
            new Response(200, [], json_encode(['id' => 'p1', 'post_id' => 'PAGE_1'])),
            new Response(200, [], json_encode(['id' => 'p2', 'post_id' => 'PAGE_2'])),
        ]);

        // The model save throws (database trouble) — but only for the first post.
        $armed = true;
        PropertyMarketingPost::saving(function (PropertyMarketingPost $p) use (&$armed, $first) {
            if ($armed && $p->id === $first) {
                $armed = false;
                throw new \RuntimeException('simulated database failure');
            }
        });

        Log::spy();

        $out = app(ScheduledPostPublisher::class)->runDue();

        $this->assertSame(2, $out['published']);
        $a = PropertyMarketingPost::withoutGlobalScopes()->find($first);
        $this->assertSame('published', $a->status, 'Recovered by the plain update.');
        $this->assertSame('PAGE_1', $a->platform_post_id);
        $this->assertNotNull($a->published_at);
        $this->assertSame('PAGE_2', PropertyMarketingPost::withoutGlobalScopes()->find($second)->platform_post_id);
        $this->assertSame(0, $this->metaQueue->count());

        // The platform id was logged before the save was attempted.
        Log::shouldHaveReceived('info')->withArgs(fn ($m) => str_contains((string) $m, 'PAGE_1') && str_contains((string) $m, (string) $first))->once();

        // Nothing is left to be mislabelled "may or may not have gone out" 30 minutes later.
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 9, 0, 0, 'UTC'));
        $this->assertSame(0, app(ScheduledPostPublisher::class)->runDue()['interrupted']);
        $this->assertSame('published', PropertyMarketingPost::withoutGlobalScopes()->find($first)->status);
    }

    // ── #4 owner / permission / feature re-checked at publish ───────────────

    public function test_an_inactive_owner_cannot_publish(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);
        DB::table('users')->where('id', $agent->id)->update(['is_active' => 0]);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $this->assertNoAccess($id);
    }

    public function test_a_deleted_owner_cannot_publish(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);
        DB::table('users')->where('id', $agent->id)->update(['deleted_at' => now()]);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $this->assertNoAccess($id);
    }

    public function test_an_owner_who_lost_the_scheduling_permission_cannot_publish(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);

        // Seeded grants switch the permission system out of the test-suite allow-all posture; the agent's
        // role keeps the Ad Manager but not ad_manager.schedule_posts.
        $agent->forceFill(['role' => 'sched_lost'])->save();
        foreach (['access_ad_manager', 'properties.view'] as $key) {
            RolePermission::create(['role' => 'sched_lost', 'permission_key' => $key, 'scope' => $key === 'properties.view' ? 'own' : null, 'agency_id' => $agency]);
        }
        PermissionService::clearCache();

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $this->assertNoAccess($id);
    }

    public function test_an_agency_that_switched_the_ad_manager_off_does_not_publish(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);
        DB::table('agency_features')->insert([
            'agency_id' => $agency, 'feature_key' => 'ad-manager', 'enabled' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(AgencyFeatureService::class)->forget();

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertSame('The Ad Manager is no longer switched on for this agency, so the post was not published.', $post->failure_reason);
        $this->assertSame([], $this->metaRequests);
    }

    public function test_a_cancelled_post_of_a_deleted_owner_cannot_be_put_back_on_the_schedule(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['status' => 'cancelled', 'scheduled_for' => '2026-10-25 07:00:00']);
        DB::table('users')->where('id', $agent->id)->update(['deleted_at' => now()]);

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        try {
            app(ScheduledPostService::class)->reschedule($post, '2026-10-22', '09:00');
            $this->fail('A deleted owner must not be able to put a post back on the schedule.');
        } catch (\App\Services\Marketing\ScheduleException $e) {
            $this->assertStringContainsString('no longer has access', $e->getMessage());
        }
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
    }

    // ── #5 pixel cap ────────────────────────────────────────────────────────

    public function test_a_picture_with_huge_dimensions_is_refused_before_it_is_decoded(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->actingAs($agent);

        // A tiny file whose header claims 20000 x 20000 pixels (400 MP): decoding it would exhaust memory.
        $res = $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => $this->headerOnlyPngDataUrl(20000, 20000)]);
        $res->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('too large', (string) $res->json('error'));

        // Within the side limit but over 40 megapixels.
        $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => $this->headerOnlyPngDataUrl(7000, 7000)])
            ->assertStatus(422);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_normal_picture_is_still_accepted_and_the_image_field_has_a_length_limit(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->actingAs($agent);

        $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => $this->pngDataUrl(1200, 1200)])->assertOk();

        $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => str_repeat('A', 21_000_001)])
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    // ── #6 image URL rebuilt at publish time ────────────────────────────────

    public function test_the_picture_address_sent_to_meta_is_rebuilt_from_the_stored_file(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);
        $id = $this->insertPost($agency, $agent, $property, ['scheduled_for' => '2026-10-20 07:00:00']);

        // The address stored at scheduling time pointed at a host that no longer exists.
        $path = app(ScheduledPostImages::class)->pathFromUrl(json_decode((string) DB::table('property_marketing_posts')->where('id', $id)->value('image_urls'), true)[0]);
        DB::table('property_marketing_posts')->where('id', $id)->update([
            'image_urls' => json_encode(['https://old-host.example.invalid/storage/' . $path]),
        ]);
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'p1', 'post_id' => 'PAGE_7']))]);

        $this->artisan('marketing:publish-due')->expectsOutputToContain('1 published')->assertSuccessful();

        $sent = $this->metaRequests[0]['body'] ?? '';
        parse_str($sent, $fields);
        $this->assertSame(Storage::disk('public')->url($path), $fields['url'] ?? null);
        $this->assertStringNotContainsString('old-host', $sent);
    }

    // ── #7 status sort ──────────────────────────────────────────────────────

    public function test_the_status_sort_follows_the_lifecycle_not_the_database_enum_order(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $property = $this->property($agency, $branch, $agent);
        foreach (['cancelled', 'failed', 'published', 'scheduled', 'publishing'] as $i => $status) {
            $this->insertPost($agency, $agent, $property, ['status' => $status, 'scheduled_for' => '2026-10-2' . $i . ' 07:00:00']);
        }
        $this->actingAs($agent);

        $order = fn (string $dir) => array_map(
            fn ($p) => $p->status,
            $this->get(route('tools.ad-manager.scheduled', ['sort' => 'status', 'dir' => $dir]))->assertOk()->viewData('rows')->items(),
        );

        $this->assertSame(['scheduled', 'publishing', 'published', 'failed', 'cancelled'], $order('asc'));
        $this->assertSame(['cancelled', 'failed', 'published', 'publishing', 'scheduled'], $order('desc'));
    }

    // ── #8 direct publish wording + logging ─────────────────────────────────

    public function test_direct_publish_says_post_it_again_and_logs_the_original_error_without_the_token(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);

        $leak = '(#200) Permission denied for access_token=EAABsecrettokenvalue1234567890 on page';
        $this->fakeMeta([new Response(400, [], json_encode(['error' => ['message' => $leak]]))]);
        Log::spy();

        $res = $this->actingAs($agent)->postJson(route('corex.properties.marketing.publish', $property), [
            'platforms' => ['facebook'], 'copy' => 'Lovely home for sale',
        ])->assertOk();

        $error = (string) $res->json('results.facebook.error');
        $this->assertStringEndsWith('then post it again.', $error);
        $this->assertStringNotContainsString('schedule the post again', $error);

        // The log keeps the ORIGINAL Meta wording (not only the plain reason), with the token hidden.
        Log::shouldHaveReceived('error')->withArgs(function ($m) {
            $m = (string) $m;

            return str_contains($m, 'PropertyMarketingController::publish failed')
                && str_contains($m, 'Permission denied')
                && ! str_contains($m, 'EAABsecrettokenvalue');
        })->once();

        // The scheduled path keeps its own wording.
        $this->assertStringEndsWith('then schedule the post again.', app(ScheduledPostPublisher::class)->explain('facebook', 'OAuthException'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function assertNoAccess(int $id): void
    {
        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertSame('The person who scheduled this post no longer has access to schedule posts, so it was not published.', $post->failure_reason);
        $this->assertSame([], $this->metaRequests, 'Nothing was sent to Meta.');
    }

    /** @return array{0:int,1:int,2:User} */
    private function world(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return [$agency, $branch, User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'agent'])];
    }

    private function property(int $agency, int $branch, User $agent): Property
    {
        $p = Property::create([
            'agency_id' => $agency, 'branch_id' => $branch, 'agent_id' => $agent->id, 'title' => 'Test House',
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
        ]);
        DB::table('properties')->where('id', $p->id)->update(['compliance_snapshot_at' => now()]);

        return $p->fresh();
    }

    private function connect(User $user, string $platform): AgentSocialAccount
    {
        return AgentSocialAccount::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id, 'platform' => $platform,
            'platform_page_id' => '1000' . $user->id, 'platform_page_name' => 'Test Page',
            'access_token' => 'EAABsecrettokenvalue1234567890', 'token_expires_at' => now()->addDays(30), 'is_active' => true,
        ]);
    }

    private function insertPost(int $agency, User $user, Property $property, array $attrs = []): int
    {
        $path = "marketing-scheduled/{$agency}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return (int) DB::table('property_marketing_posts')->insertGetId(array_merge([
            'agency_id' => $agency, 'property_id' => $property->id, 'user_id' => $user->id, 'platform' => 'facebook',
            'ad_copy' => 'Lovely home for sale', 'image_urls' => json_encode([Storage::disk('public')->url($path)]),
            'status' => 'scheduled', 'scheduled_for' => '2026-10-25 07:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    private function pngDataUrl(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagepng($im);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /** A PNG that is only signature + header + end: tiny, but claims the given size. */
    private function headerOnlyPngDataUrl(int $w, int $h): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IEND', '');

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /** @param list<Response> $publish */
    private function fakeMeta(array $publish): void
    {
        $this->metaRequests = [];
        $this->metaQueue = new MockHandler($publish);
        $stack = HandlerStack::create($this->metaQueue);
        $stack->push(Middleware::mapRequest(function ($request) {
            $this->metaRequests[] = ['uri' => (string) $request->getUri(), 'body' => (string) $request->getBody()];

            return $request;
        }));
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => $stack])));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService(new Client(['handler' => HandlerStack::create(new MockHandler([]))])));
    }
}
