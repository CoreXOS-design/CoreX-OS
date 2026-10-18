<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Events\Marketing\MarketingPostPublished;
use App\Events\Marketing\MarketingPostScheduleFailed;
use App\Models\AgentSocialAccount;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\RolePermission;
use App\Models\User;
use App\Notifications\ScheduledPostFailedNotification;
use App\Services\Marketing\ScheduleException;
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
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 Part D — the Post Scheduler: scheduling rules, the per-minute publisher, the Scheduled Posts
 * screen and its scoping, image housekeeping, and the permission key.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §D.
 *
 * Meta is faked at the Guzzle layer (MetaPublishingService / MetaOAuthService take a Guzzle client, they
 * do not use Laravel's Http facade): a MockHandler queue answers each call, and a call beyond the queue
 * throws — so "nothing was sent to Meta" is asserted by leaving the queue empty.
 *
 * Clock frozen at 2026-10-20 08:00 UTC = 10:00 in the agency timezone (Africa/Johannesburg, UTC+2).
 */
final class ScheduledPostsTest extends TestCase
{
    use RefreshDatabase;

    private MockHandler $metaQueue;

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

    // ═════════════════════════════════════════════════════════════════════
    // Scheduling rules
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_post_less_than_five_minutes_ahead_is_refused_and_one_over_sixty_days_too(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);
        $path = $this->storedImage($agent);
        $this->actingAs($agent);

        // 10:03 local = 3 minutes ahead.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, $path, '2026-10-20', '10:03'))
            ->assertStatus(422)->assertJsonPath('ok', false)
            ->assertJsonFragment(['error' => 'Pick a time at least 5 minutes from now.']);

        // 61 days ahead.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, $path, '2026-12-21', '10:00'))
            ->assertStatus(422)->assertJsonFragment(['error' => 'Posts can be scheduled up to 60 days ahead.']);

        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());

        // Exactly 6 minutes ahead is fine.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, $path, '2026-10-20', '10:06'))
            ->assertOk()->assertJsonPath('scheduled', 1);
    }

    public function test_time_is_stored_in_utc_and_shown_in_the_agency_timezone(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent, 'Sunset Villa');
        $path = $this->storedImage($agent);

        $this->actingAs($agent)->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, $path, '2026-10-21', '09:00'))->assertOk();

        // 09:00 in Johannesburg is 07:00 UTC, in the column as plain UTC.
        $this->assertSame('2026-10-21 07:00:00', DB::table('property_marketing_posts')->value('scheduled_for'));
        $post = PropertyMarketingPost::sole();
        $this->assertSame('UTC', $post->scheduled_for->timezoneName);
        $this->assertSame('07:00', $post->scheduled_for->format('H:i'));

        $this->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('Wed 21 Oct 2026, 09:00', false)
            ->assertSee('Sunset Villa');
    }

    public function test_status_enum_keeps_old_values_and_accepts_the_new_ones(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $property = $this->property($agency, $branch, $agent);
        foreach (['draft', 'published', 'failed', 'scheduled', 'publishing', 'cancelled'] as $status) {
            $this->insertPost($agency, $agent, $property, ['status' => $status, 'scheduled_for' => null]);
        }
        $this->assertSame(6, PropertyMarketingPost::withoutGlobalScopes()->count());
        $this->assertSame(['cancelled', 'draft', 'failed', 'published', 'publishing', 'scheduled'],
            PropertyMarketingPost::withoutGlobalScopes()->pluck('status')->sort()->values()->all());
    }

    public function test_no_connected_account_is_refused_in_plain_words_and_the_screen_says_so(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $property = $this->property($agency, $branch, $agent);
        $path = $this->storedImage($agent);
        $this->actingAs($agent);

        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, $path, '2026-10-21', '09:00'))
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Facebook is not connected for you yet. Connect it under My Portal, Tools, Social Media first, then schedule again.']);

        $this->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('Facebook and Instagram are not connected for you yet,', false)
            ->assertSee('Connect now');
    }

    public function test_only_a_listing_in_the_users_scope_and_on_the_market_can_be_scheduled(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $other = $this->userIn($agency, $branch, 'agent');
        $this->connect($agent, 'facebook');
        $path = $this->storedImage($agent);

        $notMine  = $this->property($agency, $branch, $other, 'Not Mine');
        $sold     = $this->property($agency, $branch, $agent, 'Sold House', ['status' => 'sold']);
        [$agency2, $branch2, $agent2] = $this->world();
        $foreign  = $this->property($agency2, $branch2, $agent2, 'Foreign');

        $this->actingAs($agent);
        foreach ([$notMine, $foreign] as $p) {
            $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($p, $path, '2026-10-21', '09:00'))
                ->assertStatus(422)->assertJsonFragment(['error' => 'One of those listings is not yours to post for.']);
        }
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($sold, $path, '2026-10-21', '09:00'))
            ->assertStatus(422)->assertJsonFragment(['error' => 'Sold House is not on the market, so it cannot be advertised.']);
        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    public function test_a_picture_that_is_not_the_users_own_upload_is_refused(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $other = $this->userIn($agency, $branch, 'agent');
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);

        $this->actingAs($agent);
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, $this->storedImage($other), '2026-10-21', '09:00'))
            ->assertStatus(422);
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($property, 'marketing-scheduled/../../.env', '2026-10-21', '09:00'))
            ->assertStatus(422);
        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Bulk "one a day"
    // ═════════════════════════════════════════════════════════════════════

    public function test_bulk_one_a_day_spreads_the_ads_over_consecutive_days_at_the_same_local_time(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $this->connect($agent, 'instagram');
        $props = [$this->property($agency, $branch, $agent, 'A'), $this->property($agency, $branch, $agent, 'B'), $this->property($agency, $branch, $agent, 'C')];
        $this->actingAs($agent);

        $items = array_map(fn ($p) => ['property_id' => $p->id, 'copy' => 'Come see ' . $p->title, 'image_path' => $this->storedImage($agent)], $props);
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), [
            'items' => $items, 'platforms' => ['facebook', 'instagram'], 'mode' => 'daily', 'date' => '2026-10-22', 'time' => '08:30',
        ])->assertOk()->assertJsonPath('scheduled', 6);

        // Each ad gets its own day; both platforms of one ad share its slot. 08:30 local = 06:30 UTC.
        $byTitle = PropertyMarketingPost::query()->with('property')->get()
            ->groupBy(fn ($p) => $p->property->title)
            ->map(fn ($g) => $g->pluck('scheduled_for')->map->format('Y-m-d H:i')->unique()->values()->all());
        $this->assertSame(['2026-10-22 06:30'], $byTitle['A']);
        $this->assertSame(['2026-10-23 06:30'], $byTitle['B']);
        $this->assertSame(['2026-10-24 06:30'], $byTitle['C']);
        $this->assertSame(6, PropertyMarketingPost::where('status', 'scheduled')->count());
    }

    public function test_bulk_is_all_or_nothing_when_the_last_day_is_beyond_sixty_days(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $props = [$this->property($agency, $branch, $agent), $this->property($agency, $branch, $agent), $this->property($agency, $branch, $agent)];
        $this->actingAs($agent);

        $items = array_map(fn ($p) => ['property_id' => $p->id, 'copy' => 'Hi', 'image_path' => $this->storedImage($agent)], $props);
        // Starts 59 days ahead; the third ad lands on day 61.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), [
            'items' => $items, 'platforms' => ['facebook'], 'mode' => 'daily', 'date' => '2026-12-18', 'time' => '10:00',
        ])->assertStatus(422)->assertJsonFragment(['error' => 'Posts can be scheduled up to 60 days ahead.']);
        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    public function test_the_ad_picture_upload_stores_a_jpeg_in_the_users_folder_and_rejects_non_pictures(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->actingAs($agent);

        $res = $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => $this->pngDataUrl(2000, 1000)])->assertOk();
        $path = $res->json('path');
        $this->assertStringStartsWith("marketing-scheduled/{$agency}/{$agent->id}/", $path);
        Storage::disk('public')->assertExists($path);
        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(1440, $info[0], 'Wider than Instagram allows, so it is scaled down.');

        $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => 'data:image/png;base64,' . base64_encode('this is not a picture')])
            ->assertStatus(422);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Publishing
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_due_post_publishes_and_shows_published_with_the_platform_id(): void
    {
        Event::fake([MarketingPostPublished::class]);
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);
        $id = $this->insertPost($agency, $agent, $property, ['scheduled_for' => '2026-10-20 07:59:00']);
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'photo1', 'post_id' => 'PAGE_9001']))]);

        $this->artisan('marketing:publish-due')
            ->expectsOutputToContain('1 published, 0 failed')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('published', $post->status);
        $this->assertSame('PAGE_9001', $post->platform_post_id);
        $this->assertNotNull($post->published_at);
        $this->assertNull($post->failure_reason);
        $this->assertSame(0, $this->metaQueue->count(), 'Exactly one Meta call was made.');
        Event::assertDispatched(MarketingPostPublished::class, fn ($e) => $e->post->id === $id);

        $this->actingAs($agent)->get(route('tools.ad-manager.scheduled'))->assertOk()->assertSee('Published')->assertSee('Posted ');
    }

    public function test_a_post_that_is_not_yet_due_is_left_alone(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 08:10:00']);

        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published')->assertSuccessful();

        $this->assertSame('scheduled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
    }

    public function test_instagram_publishes_through_container_then_publish(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'instagram');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['platform' => 'instagram', 'scheduled_for' => '2026-10-20 07:00:00']);
        $this->fakeMeta([
            new Response(200, [], json_encode(['id' => 'container1'])),
            new Response(200, [], json_encode(['id' => 'IG_MEDIA_77'])),
        ]);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('published', $post->status);
        $this->assertSame('IG_MEDIA_77', $post->platform_post_id);
    }

    public function test_two_overlapping_runs_publish_a_post_once(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);
        $publisher = app(ScheduledPostPublisher::class);

        // Two runs reach the same post at the same moment: only the first claim wins.
        $this->assertTrue($publisher->claim($id));
        $this->assertFalse($publisher->claim($id));

        // A third run that starts while the first is mid-publish skips it and sends nothing.
        $this->fakeMeta([]);   // any Meta call from the second run would throw
        $second = app(ScheduledPostPublisher::class)->runDue();
        $this->assertSame(['published' => 0, 'failed' => 0, 'skipped' => 0, 'interrupted' => 0], $second);

        // The first run finishes: one call to Meta, post published.
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'p', 'post_id' => 'ONCE_1']))]);
        $this->assertTrue(app(ScheduledPostPublisher::class)->publishClaimed(PropertyMarketingPost::withoutGlobalScopes()->find($id)));
        $this->assertSame(0, $this->metaQueue->count());

        // And a later run finds nothing left to do.
        $this->assertSame(0, app(ScheduledPostPublisher::class)->runDue()['published']);
        $this->assertSame(1, PropertyMarketingPost::withoutGlobalScopes()->where('platform_post_id', 'ONCE_1')->count());
    }

    public function test_a_run_that_loses_the_claim_race_counts_it_as_skipped(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);

        // Simulate the other run claiming between our SELECT and our UPDATE.
        $publisher = new class(app(MetaPublishingService::class), app(MetaOAuthService::class), app(ScheduledPostImages::class)) extends ScheduledPostPublisher {
            public function claim(int $postId): bool
            {
                DB::table('property_marketing_posts')->where('id', $postId)->update(['status' => 'publishing']);

                return parent::claim($postId);
            }
        };

        $this->assertSame(['published' => 0, 'failed' => 0, 'skipped' => 1, 'interrupted' => 0], $publisher->runDue());
    }

    public function test_an_expired_connection_fails_with_a_readable_reason_a_notification_and_an_event(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook', now()->subDay());
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent, 'Beach House'), ['scheduled_for' => '2026-10-20 07:00:00']);
        // The token refresh attempt is refused by Meta, as it would be for a dead token.
        $this->fakeMeta([], oauth: [new Response(400, [], json_encode(['error' => ['message' => 'Error validating access token']]))]);

        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 1 failed')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertSame('Your Facebook connection has expired. Reconnect it under My Portal, Tools, Social Media, then schedule the post again.', $post->failure_reason);
        $this->assertNull($post->platform_post_id);
        $this->assertSame(0, $this->metaQueue->count());

        // The agent is told in the bell.
        $note = $agent->fresh()->notifications()->where('type', ScheduledPostFailedNotification::class)->sole();
        $this->assertSame('scheduled_post_failed', $note->data['type']);
        $this->assertStringContainsString('expired', $note->data['body']);
        $this->assertStringContainsString('Beach House', $note->data['body']);
    }

    public function test_a_failure_emits_the_schedule_failed_event(): void
    {
        Event::fake([MarketingPostScheduleFailed::class]);
        [$agency, $branch, $agent] = $this->world();
        // No connected account at all.
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        Event::assertDispatched(MarketingPostScheduleFailed::class, function ($e) use ($id, $agency, $agent) {
            return $e->post->id === $id && $e->agencyId() === $agency && $e->actorUserId() === $agent->id
                && str_starts_with($e->reason, 'No connected Facebook account was found.');
        });
        $this->assertSame('failed', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
    }

    public function test_a_revoked_token_reported_by_meta_is_turned_into_a_reconnect_message_without_leaking_the_token(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);
        $this->fakeMeta([new Response(400, [], json_encode(['error' => ['message' => 'Error validating access token: The user has not authorized application.', 'type' => 'OAuthException', 'code' => 190]]))]);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('Reconnect it under My Portal', $post->failure_reason);
        $this->assertStringNotContainsString('EAAB', $post->failure_reason);
        $this->assertStringNotContainsString('access_token', $post->failure_reason);
    }

    public function test_any_other_meta_error_is_recorded_with_its_message_and_the_post_is_not_retried(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00']);
        $this->fakeMeta([new Response(500, [], json_encode(['error' => ['message' => 'Service temporarily unavailable']]))]);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertStringStartsWith('Facebook did not accept the post:', $post->failure_reason);

        // Next minute's run must not touch it again (a retry could double-post).
        $this->fakeMeta([]);
        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 0 failed')->assertSuccessful();
        $this->assertSame('failed', $post->fresh()->status);
    }

    public function test_a_listing_that_went_off_the_market_fails_the_post_instead_of_advertising_it(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $property = $this->property($agency, $branch, $agent);
        $id = $this->insertPost($agency, $agent, $property, ['scheduled_for' => '2026-10-20 07:00:00']);
        DB::table('properties')->where('id', $property->id)->update(['status' => 'sold']);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertSame('The listing is no longer on the market, so the post was not published.', $post->failure_reason);
    }

    public function test_a_missing_picture_fails_the_post_with_a_reason(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 07:00:00', 'image_missing' => true]);

        $this->artisan('marketing:publish-due')->assertSuccessful();

        $this->assertSame('The picture for this post is no longer available. Make the ad again and schedule it fresh.',
            PropertyMarketingPost::withoutGlobalScopes()->find($id)->failure_reason);
    }

    public function test_a_post_stuck_publishing_is_failed_not_retried(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), [
            'status' => 'publishing', 'scheduled_for' => '2026-10-20 06:00:00', 'updated_at' => '2026-10-20 07:00:00',
        ]);

        $this->artisan('marketing:publish-due')->expectsOutputToContain('1 interrupted')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('may or may not have gone out', $post->failure_reason);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Edit / cancel / reschedule
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_cancelled_post_is_never_published(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-20 08:30:00']);

        $this->actingAs($agent)->post(route('tools.ad-manager.scheduled.cancel', $id))->assertRedirect()->assertSessionHas('success');
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);

        // Time passes beyond the slot; the run does nothing and sends nothing to Meta.
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 9, 0, 0, 'UTC'));
        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 0 failed')->assertSuccessful();
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
        $this->assertNull(PropertyMarketingPost::withoutGlobalScopes()->find($id)->platform_post_id);
        $this->assertNotNull(PropertyMarketingPost::withoutGlobalScopes()->find($id), 'Cancel never deletes.');
    }

    public function test_a_post_already_being_published_cannot_be_cancelled_or_edited(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['status' => 'publishing', 'scheduled_for' => '2026-10-20 07:59:00']);
        $this->actingAs($agent);

        $this->post(route('tools.ad-manager.scheduled.cancel', $id))->assertSessionHas('error');
        $this->post(route('tools.ad-manager.scheduled.update', $id), ['copy' => 'x', 'date' => '2026-10-25', 'time' => '09:00'])->assertSessionHas('error');
        $this->assertSame('publishing', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
    }

    public function test_a_cancelled_post_can_be_rescheduled_and_then_publishes(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['status' => 'cancelled', 'scheduled_for' => '2026-10-20 07:00:00']);
        $this->actingAs($agent);

        // Too soon is refused.
        $this->post(route('tools.ad-manager.scheduled.reschedule', $id), ['date' => '2026-10-20', 'time' => '10:02'])->assertSessionHas('error');
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);

        $this->post(route('tools.ad-manager.scheduled.reschedule', $id), ['date' => '2026-10-21', 'time' => '11:15'])->assertSessionHas('success');
        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('scheduled', $post->status);
        $this->assertSame('2026-10-21 09:15', $post->scheduled_for->format('Y-m-d H:i'));

        Carbon::setTestNow(Carbon::create(2026, 10, 21, 9, 16, 0, 'UTC'));
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'x', 'post_id' => 'BACK_1']))]);
        $this->artisan('marketing:publish-due')->assertSuccessful();
        $this->assertSame('published', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
    }

    public function test_only_a_cancelled_post_can_be_rescheduled(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['status' => 'failed', 'scheduled_for' => '2026-10-20 07:00:00']);

        $this->actingAs($agent)->post(route('tools.ad-manager.scheduled.reschedule', $id), ['date' => '2026-10-22', 'time' => '09:00'])
            ->assertSessionHas('error', 'Only a cancelled post can be put back on the schedule.');
    }

    public function test_editing_the_text_and_time_of_a_waiting_post(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-22 07:00:00']);

        $this->actingAs($agent)->post(route('tools.ad-manager.scheduled.update', $id), ['copy' => 'New wording', 'date' => '2026-10-23', 'time' => '14:00'])
            ->assertSessionHas('success');

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('New wording', $post->ad_copy);
        $this->assertSame('2026-10-23 12:00', $post->scheduled_for->format('Y-m-d H:i'));
        $this->assertSame('scheduled', $post->status);

        // A bad time on edit changes nothing.
        $this->post(route('tools.ad-manager.scheduled.update', $id), ['copy' => 'Other', 'date' => '2026-10-20', 'time' => '10:01'])->assertSessionHas('error');
        $this->assertSame('New wording', $post->fresh()->ad_copy);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Scoping
    // ═════════════════════════════════════════════════════════════════════

    public function test_an_agent_sees_only_their_own_posts_a_branch_manager_only_their_branch_and_an_admin_everything(): void
    {
        [$agency, $branchA, $agentA] = $this->world();
        $branchB = $this->branch($agency, 'B');
        $agentA2 = $this->userIn($agency, $branchA, 'agent');
        $agentB  = $this->userIn($agency, $branchB, 'agent');
        $manager = $this->userIn($agency, $branchA, 'branch_manager');
        $admin   = $this->userIn($agency, $branchA, 'admin');

        $this->insertPost($agency, $agentA,  $this->property($agency, $branchA, $agentA,  'ZZZ-A1-House'),  ['scheduled_for' => '2026-10-25 07:00:00']);
        $this->insertPost($agency, $agentA2, $this->property($agency, $branchA, $agentA2, 'ZZZ-A2-House'),  ['scheduled_for' => '2026-10-25 07:00:00']);
        $this->insertPost($agency, $agentB,  $this->property($agency, $branchB, $agentB,  'ZZZ-B1-House'),  ['scheduled_for' => '2026-10-25 07:00:00']);

        $this->actingAs($agentA)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('ZZZ-A1-House')->assertDontSee('ZZZ-A2-House')->assertDontSee('ZZZ-B1-House');

        $this->actingAs($manager)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('ZZZ-A1-House')->assertSee('ZZZ-A2-House')->assertDontSee('ZZZ-B1-House');

        $this->actingAs($admin)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('ZZZ-A1-House')->assertSee('ZZZ-A2-House')->assertSee('ZZZ-B1-House');
    }

    public function test_another_agencys_posts_never_appear_and_a_forged_id_is_a_404(): void
    {
        [$agency1, $branch1, $agent1] = $this->world();
        [$agency2, $branch2, $agent2] = $this->world();
        $theirs = $this->insertPost($agency2, $agent2, $this->property($agency2, $branch2, $agent2, 'ZZZ-Foreign-House'), ['scheduled_for' => '2026-10-25 07:00:00']);
        $admin1 = $this->userIn($agency1, $branch1, 'admin');

        $this->actingAs($admin1)->get(route('tools.ad-manager.scheduled'))->assertOk()->assertDontSee('ZZZ-Foreign-House');

        foreach (['cancel', 'update', 'reschedule'] as $action) {
            $this->post(route("tools.ad-manager.scheduled.$action", $theirs), ['copy' => 'x', 'date' => '2026-10-27', 'time' => '09:00'])->assertNotFound();
        }
        $this->assertSame('scheduled', PropertyMarketingPost::withoutGlobalScopes()->find($theirs)->status);
    }

    public function test_an_agent_cannot_touch_a_colleagues_post_by_id(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $colleague = $this->userIn($agency, $branch, 'agent');
        $theirs = $this->insertPost($agency, $colleague, $this->property($agency, $branch, $colleague), ['scheduled_for' => '2026-10-25 07:00:00']);

        $this->actingAs($agent)->post(route('tools.ad-manager.scheduled.cancel', $theirs))->assertNotFound();
        $this->post(route('tools.ad-manager.scheduled.update', $theirs), ['copy' => 'x', 'date' => '2026-10-27', 'time' => '09:00'])->assertNotFound();
        $this->assertSame('scheduled', PropertyMarketingPost::withoutGlobalScopes()->find($theirs)->status);
    }

    public function test_a_branch_manager_cannot_touch_another_branchs_post_by_id(): void
    {
        [$agency, $branchA, $agentA] = $this->world();
        $branchB = $this->branch($agency, 'B');
        $agentB  = $this->userIn($agency, $branchB, 'agent');
        $manager = $this->userIn($agency, $branchA, 'branch_manager');
        $theirs  = $this->insertPost($agency, $agentB, $this->property($agency, $branchB, $agentB), ['scheduled_for' => '2026-10-25 07:00:00']);

        $this->actingAs($manager)->post(route('tools.ad-manager.scheduled.cancel', $theirs))->assertNotFound();
    }

    public function test_search_filters_and_sort_on_the_list(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $p1 = $this->property($agency, $branch, $agent, 'ZZZ-Alpha-House', ['suburb' => 'Margate']);
        $p2 = $this->property($agency, $branch, $agent, 'ZZZ-Beta-House', ['suburb' => 'Uvongo']);
        $this->insertPost($agency, $agent, $p1, ['scheduled_for' => '2026-10-30 07:00:00', 'ad_copy' => 'Sea views galore']);
        $this->insertPost($agency, $agent, $p2, ['scheduled_for' => '2026-10-25 07:00:00', 'platform' => 'instagram', 'status' => 'cancelled', 'ad_copy' => 'Garden living']);
        $this->actingAs($agent);

        // Default sort: soonest first.
        $this->get(route('tools.ad-manager.scheduled'))->assertOk()->assertSeeInOrder(['ZZZ-Beta-House', 'ZZZ-Alpha-House']);
        $this->get(route('tools.ad-manager.scheduled', ['sort' => 'scheduled', 'dir' => 'desc']))->assertSeeInOrder(['ZZZ-Alpha-House', 'ZZZ-Beta-House']);

        $this->get(route('tools.ad-manager.scheduled', ['q' => 'Sea views']))->assertSee('ZZZ-Alpha-House')->assertDontSee('ZZZ-Beta-House');
        $this->get(route('tools.ad-manager.scheduled', ['q' => 'Uvongo']))->assertSee('ZZZ-Beta-House')->assertDontSee('ZZZ-Alpha-House');
        $this->get(route('tools.ad-manager.scheduled', ['status' => 'cancelled']))->assertSee('ZZZ-Beta-House')->assertDontSee('ZZZ-Alpha-House');
        $this->get(route('tools.ad-manager.scheduled', ['platform' => 'facebook']))->assertSee('ZZZ-Alpha-House')->assertDontSee('ZZZ-Beta-House');
        // Date range is in agency-local days: 30 Oct only.
        $this->get(route('tools.ad-manager.scheduled', ['from' => '2026-10-28', 'to' => '2026-10-31']))->assertSee('ZZZ-Alpha-House')->assertDontSee('ZZZ-Beta-House');

        // No match → the "no match" empty state, with a way out.
        $this->get(route('tools.ad-manager.scheduled', ['q' => 'nothing like this']))->assertSee('No posts match these filters')->assertSee('Clear filters');
    }

    public function test_the_empty_state_for_a_user_with_nothing_scheduled(): void
    {
        [$agency, $branch, $agent] = $this->world();

        $this->actingAs($agent)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('Nothing scheduled yet')->assertSee('Schedule a post');
    }

    public function test_the_list_paginates_at_fifteen(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $property = $this->property($agency, $branch, $agent);
        for ($i = 0; $i < 17; $i++) {
            $this->insertPost($agency, $agent, $property, ['scheduled_for' => Carbon::create(2026, 10, 25, 7, 0, 0, 'UTC')->addMinutes($i)->format('Y-m-d H:i:s')]);
        }
        $this->actingAs($agent);

        $cancelButtons = fn (int $page) => substr_count($this->get(route('tools.ad-manager.scheduled', ['page' => $page]))->assertOk()->getContent(), '>Cancel</button>');
        $this->assertSame(15, $cancelButtons(1));
        $this->assertSame(2, $cancelButtons(2));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Permission key
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_permission_key_is_registered_for_role_manager_with_sensible_role_defaults(): void
    {
        $perms = collect(config('corex-permissions.permissions'))->firstWhere('key', 'ad_manager.schedule_posts');
        $this->assertNotNull($perms);
        $this->assertSame('ad_manager', $perms['module']);

        foreach (['branch_manager', 'agent', 'office_admin'] as $role) {
            $this->assertContains('ad_manager.schedule_posts', config("corex-permissions.role_defaults.$role.include"), $role);
        }
        $this->assertNotContains('ad_manager.schedule_posts', config('corex-permissions.role_defaults.viewer.include'));
        $this->assertNotContains('ad_manager.schedule_posts', config('corex-permissions.role_defaults.admin.exclude'));
    }

    public function test_without_the_key_every_scheduler_route_is_forbidden_and_the_pill_is_hidden(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $withKey = $this->userIn($agency, $branch, 'agent');
        $withKey->forceFill(['role' => 'sched_yes'])->save();
        $agent->forceFill(['role' => 'sched_no'])->save();
        // Seeded grants: switches the permission system out of the test-suite allow-all posture.
        foreach (['sched_no' => ['access_ad_manager'], 'sched_yes' => ['access_ad_manager', 'ad_manager.schedule_posts']] as $role => $keys) {
            foreach ($keys as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => null, 'agency_id' => $agency]);
            }
            RolePermission::create(['role' => $role, 'permission_key' => 'properties.view', 'scope' => 'own', 'agency_id' => $agency]);
        }
        PermissionService::clearCache();

        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['scheduled_for' => '2026-10-25 07:00:00']);

        $this->actingAs($agent->fresh());
        $this->get(route('tools.ad-manager.scheduled'))->assertForbidden();
        $this->postJson(route('api.v1.ad-manager.scheduled.image'), ['image' => $this->pngDataUrl(10, 10)])->assertForbidden();
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), [])->assertForbidden();
        $this->post(route('tools.ad-manager.scheduled.cancel', $id))->assertForbidden();
        $this->post(route('tools.ad-manager.scheduled.update', $id), [])->assertForbidden();
        $this->post(route('tools.ad-manager.scheduled.reschedule', $id), [])->assertForbidden();
        // Still allowed into the Ad Manager itself, but without the pill or the Schedule action.
        $this->get(route('tools.ad-manager'))->assertOk()->assertDontSee('Scheduled Posts')->assertDontSee('Schedule this post');
        $this->assertSame('scheduled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);

        $this->actingAs($withKey->fresh());
        $this->get(route('tools.ad-manager.scheduled'))->assertOk();
        $this->get(route('tools.ad-manager'))->assertOk()->assertSee('Scheduled Posts')->assertSee('Schedule this post');
    }

    public function test_the_migration_grants_the_key_to_existing_roles_that_hold_the_ad_manager(): void
    {
        [$agency] = $this->world();
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_ad_manager', 'scope' => null, 'agency_id' => $agency]);
        RolePermission::create(['role' => 'viewer', 'permission_key' => 'access_ad_manager', 'scope' => null, 'agency_id' => $agency]);
        RolePermission::create(['role' => 'admin', 'permission_key' => 'access_calculators', 'scope' => null, 'agency_id' => $agency]);

        $migration = require base_path('database/migrations/2026_11_09_100100_at456_d_grant_schedule_posts_permission.php');
        $migration->up();
        $migration->up();   // idempotent

        $granted = RolePermission::where('permission_key', 'ad_manager.schedule_posts')->get();
        $this->assertCount(1, $granted);
        $this->assertSame('agent', $granted->first()->role);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Images
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_cleanup_command_keeps_pictures_until_published_plus_thirty_days(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $property = $this->property($agency, $branch, $agent);
        $disk = Storage::disk('public');

        $make = function (array $attrs) use ($agency, $agent, $property) {
            $id = $this->insertPost($agency, $agent, $property, $attrs);
            $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);

            return [$id, app(ScheduledPostImages::class)->pathFromUrl($post->image_urls[0])];
        };

        [, $oldPublished]   = $make(['status' => 'published', 'published_at' => '2026-09-10 08:00:00', 'scheduled_for' => '2026-09-10 07:00:00']);   // 40 days
        [, $freshPublished] = $make(['status' => 'published', 'published_at' => '2026-10-10 08:00:00', 'scheduled_for' => '2026-10-10 07:00:00']);   // 10 days
        [, $stillWaiting]   = $make(['status' => 'scheduled', 'scheduled_for' => '2026-10-25 07:00:00', 'updated_at' => '2026-08-01 00:00:00']);       // old row, but still to publish
        [, $oldCancelled]   = $make(['status' => 'cancelled', 'scheduled_for' => '2026-09-01 07:00:00', 'updated_at' => '2026-09-05 00:00:00']);
        $disk->put("marketing-scheduled/{$agency}/{$agent->id}/orphan-old.jpg", 'x');
        $disk->put("marketing-scheduled/{$agency}/{$agent->id}/orphan-new.jpg", 'x');
        touch($disk->path("marketing-scheduled/{$agency}/{$agent->id}/orphan-old.jpg"), now()->subDays(3)->getTimestamp());
        touch($disk->path("marketing-scheduled/{$agency}/{$agent->id}/orphan-new.jpg"), now()->getTimestamp());

        $this->artisan('marketing:purge-scheduled-images')->expectsOutputToContain('2 for finished posts, 1 unused')->assertSuccessful();

        $disk->assertMissing($oldPublished);
        $disk->assertMissing($oldCancelled);
        $disk->assertExists($freshPublished);
        $disk->assertExists($stillWaiting);
        $disk->assertMissing("marketing-scheduled/{$agency}/{$agent->id}/orphan-old.jpg");
        $disk->assertExists("marketing-scheduled/{$agency}/{$agent->id}/orphan-new.jpg");
    }

    public function test_the_cleanup_and_publish_commands_are_registered_in_the_scheduler(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $publish = $events->first(fn ($e) => str_contains((string) $e->command, 'marketing:publish-due'));
        $purge   = $events->first(fn ($e) => str_contains((string) $e->command, 'marketing:purge-scheduled-images'));

        $this->assertNotNull($publish);
        $this->assertSame('* * * * *', $publish->expression);
        $this->assertTrue($publish->withoutOverlapping);
        $this->assertTrue($publish->onOneServer);
        $this->assertNotNull($purge);
    }

    public function test_a_rescheduled_post_whose_picture_was_cleared_is_refused(): void
    {
        [$agency, $branch, $agent] = $this->world();
        $this->connect($agent, 'facebook');
        $id = $this->insertPost($agency, $agent, $this->property($agency, $branch, $agent), ['status' => 'cancelled', 'scheduled_for' => '2026-10-20 07:00:00', 'image_missing' => true]);

        $this->actingAs($agent)->post(route('tools.ad-manager.scheduled.reschedule', $id), ['date' => '2026-10-22', 'time' => '09:00'])
            ->assertSessionHas('error');
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);
    }

    // ═════════════════════════════════════════════════════════════════════
    // The Ad Manager's own Schedule action
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_ad_manager_shows_the_schedule_action_and_the_switcher_pill_to_a_user_with_the_key(): void
    {
        [$agency, $branch, $agent] = $this->world();

        $this->actingAs($agent)->get(route('tools.ad-manager'))->assertOk()
            ->assertSee('Scheduled Posts')
            ->assertSee('Schedule this post')
            ->assertSee('storeUrl', false);
    }

    public function test_the_service_rules_directly(): void
    {
        $svc = app(ScheduledPostService::class);
        $this->assertSame('Africa/Johannesburg', $svc->timezone());
        $this->assertSame('2026-10-21 07:00', $svc->toUtc('2026-10-21', '09:00')->format('Y-m-d H:i'));

        $this->expectException(ScheduleException::class);
        $svc->toUtc('2026-02-30', '09:00');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:User} [agencyId, branchId, agent] */
    private function world(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = $this->branch($agency, 'Main');

        return [$agency, $branch, $this->userIn($agency, $branch, 'agent')];
    }

    private function branch(int $agency, string $name): int
    {
        return (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userIn(int $agency, int $branch, string $role): User
    {
        return User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => $role]);
    }

    private function property(int $agency, int $branch, User $agent, string $title = 'Test House', array $extra = []): Property
    {
        $p = Property::create(array_merge([
            'agency_id' => $agency, 'branch_id' => $branch, 'agent_id' => $agent->id, 'title' => $title,
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
        ], $extra));
        // Marketing-ready: a compliance snapshot exists (MarketingReadinessService::isMarketable).
        DB::table('properties')->where('id', $p->id)->update(['compliance_snapshot_at' => now()]);

        return $p->fresh();
    }

    private function connect(User $user, string $platform, ?Carbon $expires = null): AgentSocialAccount
    {
        return AgentSocialAccount::create([
            'agency_id' => $user->agency_id, 'user_id' => $user->id, 'platform' => $platform,
            'platform_page_id' => '1000' . $user->id, 'platform_page_name' => 'Test Page',
            'access_token' => 'EAABsecrettokenvalue1234567890', 'token_expires_at' => $expires ?? now()->addDays(30), 'is_active' => true,
        ]);
    }

    /** Insert a post row directly (bypasses the scheduling window and the acting-user agency stamp). */
    private function insertPost(int $agency, User $user, Property $property, array $attrs = []): int
    {
        $missing = ! empty($attrs['image_missing']);
        unset($attrs['image_missing']);

        $path = "marketing-scheduled/{$agency}/{$user->id}/" . Str::uuid() . '.jpg';
        if (! $missing) {
            Storage::disk('public')->put($path, 'jpeg-bytes');
        }

        return (int) DB::table('property_marketing_posts')->insertGetId(array_merge([
            'agency_id' => $agency, 'property_id' => $property->id, 'user_id' => $user->id, 'platform' => 'facebook',
            'ad_copy' => 'Lovely home for sale', 'image_urls' => json_encode([Storage::disk('public')->url($path)]),
            'status' => 'scheduled', 'scheduled_for' => '2026-10-25 07:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** A picture the user "uploaded" through the image endpoint. */
    private function storedImage(User $user): string
    {
        $path = "marketing-scheduled/{$user->agency_id}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return $path;
    }

    /** @return array<string,mixed> */
    private function payload(Property $property, string $path, string $date, string $time, array $platforms = ['facebook']): array
    {
        return [
            'items'     => [['property_id' => $property->id, 'copy' => 'Come and see this home', 'image_path' => $path]],
            'platforms' => $platforms, 'mode' => 'at', 'date' => $date, 'time' => $time,
        ];
    }

    private function pngDataUrl(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        ob_start();
        imagepng($im);

        return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    }

    /**
     * Bind Meta clients whose answers come from queues; a call beyond the queue throws.
     *
     * @param  list<Response>  $publish  answers for MetaPublishingService
     * @param  list<Response>  $oauth    answers for MetaOAuthService (token refresh)
     */
    private function fakeMeta(array $publish, array $oauth = []): void
    {
        $this->metaQueue = new MockHandler($publish);
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => HandlerStack::create($this->metaQueue)])));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService(new Client(['handler' => HandlerStack::create(new MockHandler($oauth))])));
    }
}
