<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Events\Marketing\MarketingPostPublished;
use App\Models\AgentSocialAccount;
use App\Models\CompanyAd;
use App\Models\Property;
use App\Models\PropertyMarketingPost;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Notifications\ScheduledPostFailedNotification;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Company Ads, step E-3 (.ai/specs/company-ads.md §7): Post now / Schedule a company ad through the Post Scheduler.
 *
 * Meta is faked at the Guzzle layer, as in ScheduledPostsTest: a MockHandler queue answers each call and a call
 * beyond the queue throws, so "nothing was sent to Meta" is an empty queue left untouched.
 * Clock frozen at 2026-10-20 08:00 UTC = 10:00 in the agency timezone.
 */
final class CompanyAdPostingTest extends TestCase
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
        Role::clearCache();
        parent::tearDown();
    }

    // ── Schedule / post now ────────────────────────────────────────────────

    public function test_a_company_ad_is_scheduled_one_row_per_platform_with_no_listing(): void
    {
        [$agency, $branch, $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $this->connect($manager, 'instagram');
        $ad = $this->ad($agency, $manager, 'Christmas 2026');
        $this->actingAs($manager);

        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($ad, $this->storedImage($manager), 'at', ['facebook', 'instagram'], '2026-10-22', '09:00'))
            ->assertOk()->assertJsonPath('scheduled', 2);

        $rows = PropertyMarketingPost::withoutGlobalScopes()->orderBy('platform')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row->property_id);
            $this->assertSame($ad->id, (int) $row->company_ad_id);
            $this->assertSame($agency, (int) $row->agency_id);
            $this->assertSame('scheduled', $row->status);
            $this->assertSame('2026-10-22 07:00:00', $row->scheduled_for->utc()->format('Y-m-d H:i:s'));
        }
        // Same time rules as any post.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($ad, $this->storedImage($manager), 'at', ['facebook'], '2026-10-20', '10:02'))
            ->assertStatus(422)->assertJsonFragment(['error' => 'Pick a time at least 5 minutes from now.']);
    }

    public function test_post_now_publishes_straight_away_and_fires_the_published_event_for_the_agency(): void
    {
        Event::fake([MarketingPostPublished::class]);
        [$agency, , $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $ad = $this->ad($agency, $manager, 'Happy Easter');
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'photo1', 'post_id' => 'PAGE_777']))]);
        $this->actingAs($manager);

        $res = $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($ad, $this->storedImage($manager), 'now', ['facebook']))
            ->assertOk()->assertJsonPath('posted', 1)->assertJsonPath('results.0.ok', true)->assertJsonPath('results.0.platform', 'Facebook');

        $post = PropertyMarketingPost::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('published', $post->status);
        $this->assertSame('PAGE_777', $post->platform_post_id);
        $this->assertSame(0, $this->metaQueue->count(), 'exactly one Meta call');
        Event::assertDispatched(MarketingPostPublished::class, fn ($e) => $e->post->id === $post->id && $e->agencyId() === $agency);

        // A second publish run finds nothing to do: post now cannot publish twice.
        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 0 failed')->assertSuccessful();
    }

    public function test_a_failed_post_now_is_recorded_reported_and_notified_with_the_company_ads_name(): void
    {
        [$agency, , $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $ad = $this->ad($agency, $manager, 'Closed for the holidays');
        $this->fakeMeta([new Response(400, [], json_encode(['error' => ['message' => 'Invalid parameter', 'code' => 100]]))]);
        $this->actingAs($manager);

        $data = $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($ad, $this->storedImage($manager), 'now', ['facebook']))
            ->assertOk()->assertJsonPath('posted', 0)->json();

        $this->assertFalse($data['results'][0]['ok']);
        $this->assertStringContainsString('Facebook did not accept the post', $data['results'][0]['error']);
        $post = PropertyMarketingPost::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $post->status);

        $note = $manager->fresh()->notifications()->where('type', ScheduledPostFailedNotification::class)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('Company ad: Closed for the holidays', $note->data['body']);
        $this->assertSame($ad->id, $note->data['company_ad_id']);
    }

    public function test_post_now_is_only_for_company_ads_and_an_item_is_one_thing(): void
    {
        [$agency, $branch, $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $ad = $this->ad($agency, $manager, 'Welcome');
        $property = $this->property($agency, $branch, $manager);
        $path = $this->storedImage($manager);
        $this->actingAs($manager);

        // A listing cannot be posted "now" from here (unchanged Post Scheduler rule for listings).
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), [
            'items' => [['property_id' => $property->id, 'copy' => 'x', 'image_path' => $path]], 'platforms' => ['facebook'], 'mode' => 'now',
        ])->assertStatus(422)->assertJsonFragment(['error' => 'Only a company ad can be posted straight away from here.']);

        // Both a listing and a company ad in one item is refused.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), [
            'items' => [['property_id' => $property->id, 'company_ad_id' => $ad->id, 'copy' => 'x', 'image_path' => $path]],
            'platforms' => ['facebook'], 'mode' => 'at', 'date' => '2026-10-22', 'time' => '09:00',
        ])->assertStatus(422);

        // The text is required, as for property posts.
        $this->postJson(route('api.v1.ad-manager.scheduled.store'), ['items' => [['company_ad_id' => $ad->id, 'copy' => '', 'image_path' => $path]],
            'platforms' => ['facebook'], 'mode' => 'now'])->assertStatus(422);

        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
        $this->assertSame(0, $this->metaQueue->count());
    }

    public function test_another_agencys_or_an_archived_company_ad_cannot_be_posted(): void
    {
        [$agency, , $manager] = $this->world('branch_manager');
        [$other, , $otherManager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $theirs   = $this->ad($other, $otherManager, 'Their ad');
        $archived = $this->ad($agency, $manager, 'Old ad', true);
        $this->actingAs($manager);

        foreach ([$theirs, $archived] as $ad) {
            $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($ad, $this->storedImage($manager), 'now', ['facebook']))
                ->assertStatus(422)->assertJsonFragment(['error' => 'That company ad is no longer available (it may have been archived).']);
        }
        $this->assertSame(0, PropertyMarketingPost::withoutGlobalScopes()->count());
    }

    public function test_without_the_company_ads_key_posting_is_refused_and_the_button_is_hidden(): void
    {
        [$agency, , $agent] = $this->world('agent');
        $this->connect($agent, 'facebook');
        $ad = $this->ad($agency, $agent, 'Brand ad');
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency]);
        foreach (['access_ad_manager', 'ad_manager.schedule_posts'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $agency], []);
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();
        $this->actingAs($agent);

        $this->postJson(route('api.v1.ad-manager.scheduled.store'), $this->payload($ad, $this->storedImage($agent), 'now', ['facebook']))
            ->assertStatus(422)->assertJsonFragment(['error' => 'You are not allowed to post company ads.']);
        $this->assertSame(0, $this->metaQueue->count());

        $html = $this->get(route('tools.ad-manager.templates.company'))->assertOk()->getContent();
        $this->assertStringContainsString('Brand ad', $html);
        $this->assertStringNotContainsString('Post / Schedule', $html);
    }

    // ── Publish-time checks ────────────────────────────────────────────────

    public function test_a_scheduled_company_ad_that_was_archived_fails_instead_of_posting(): void
    {
        [$agency, , $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $ad = $this->ad($agency, $manager, 'Heritage Day');
        $id = $this->insertPost($agency, $manager, $ad, ['scheduled_for' => '2026-10-20 07:59:00']);
        DB::table('company_ads')->where('id', $ad->id)->update(['deleted_at' => now()]);

        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 1 failed')->assertSuccessful();

        $post = PropertyMarketingPost::withoutGlobalScopes()->find($id);
        $this->assertSame('failed', $post->status);
        $this->assertStringContainsString('company ad for this post was archived', $post->failure_reason);
        $this->assertSame(0, $this->metaQueue->count(), 'nothing was sent to Meta');
    }

    public function test_a_due_company_ad_publishes_and_an_owner_who_lost_the_key_does_not(): void
    {
        [$agency, , $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $ad = $this->ad($agency, $manager, 'New Year');
        $ok = $this->insertPost($agency, $manager, $ad, ['scheduled_for' => '2026-10-20 07:59:00']);
        $this->fakeMeta([new Response(200, [], json_encode(['id' => 'p', 'post_id' => 'PAGE_1']))]);

        $this->artisan('marketing:publish-due')->expectsOutputToContain('1 published, 0 failed')->assertSuccessful();
        $this->assertSame('published', PropertyMarketingPost::withoutGlobalScopes()->find($ok)->status);

        // The same owner without "Create company ads" any more: the next one is refused at publish time.
        $later = $this->insertPost($agency, $manager, $ad, ['scheduled_for' => '2026-10-20 07:59:30']);
        Role::create(['name' => 'branch_manager', 'label' => 'Branch manager', 'agency_id' => $agency]);
        foreach (['access_ad_manager', 'ad_manager.schedule_posts'] as $key) {
            RolePermission::updateOrCreate(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $agency], []);
        }
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->artisan('marketing:publish-due')->expectsOutputToContain('0 published, 1 failed')->assertSuccessful();
        $this->assertStringContainsString('no longer has access', PropertyMarketingPost::withoutGlobalScopes()->find($later)->failure_reason);
    }

    public function test_a_cancelled_company_ad_post_can_be_rescheduled(): void
    {
        [$agency, , $manager] = $this->world('branch_manager');
        $this->connect($manager, 'facebook');
        $ad = $this->ad($agency, $manager, 'Diwali');
        $id = $this->insertPost($agency, $manager, $ad, ['status' => 'cancelled']);
        $this->actingAs($manager);

        $this->post(route('tools.ad-manager.scheduled.reschedule', $id), ['date' => '2026-10-23', 'time' => '08:30'])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('scheduled', PropertyMarketingPost::withoutGlobalScopes()->find($id)->status);

        // An archived ad cannot be put back.
        $again = $this->insertPost($agency, $manager, $ad, ['status' => 'cancelled']);
        DB::table('company_ads')->where('id', $ad->id)->update(['deleted_at' => now()]);
        $this->post(route('tools.ad-manager.scheduled.reschedule', $again), ['date' => '2026-10-23', 'time' => '08:30'])->assertSessionHas('error');
        $this->assertSame('cancelled', PropertyMarketingPost::withoutGlobalScopes()->find($again)->status);
    }

    // ── The Scheduled Posts screen ─────────────────────────────────────────

    public function test_scheduled_posts_shows_company_ads_with_a_type_filter_search_and_scope(): void
    {
        [$agency, $branch, $managerA] = $this->world('branch_manager');
        $managerB = $this->userIn($agency, $branch, 'branch_manager');
        $admin    = $this->userIn($agency, $branch, 'admin');
        $agent    = $this->userIn($agency, $branch, 'agent');
        $this->insertPost($agency, $managerA, $this->ad($agency, $managerA, 'ZZZ-Xmas-Ad'));
        $this->insertPost($agency, $managerB, $this->ad($agency, $managerB, 'ZZZ-Easter-Ad'));
        $listing = (int) DB::table('property_marketing_posts')->insertGetId([
            'agency_id' => $agency, 'property_id' => $this->property($agency, $branch, $agent, 'ZZZ-House')->id, 'user_id' => $agent->id,
            'platform' => 'facebook', 'ad_copy' => 'x', 'image_urls' => json_encode([]), 'status' => 'scheduled',
            'scheduled_for' => '2026-10-25 07:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // All-scope sees every company-ad post, labelled as such.
        $this->actingAs($admin)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('Company ad: ZZZ-Xmas-Ad')->assertSee('Company ad: ZZZ-Easter-Ad')->assertSee('ZZZ-House');
        $this->get(route('tools.ad-manager.scheduled', ['type' => 'company_ads']))->assertOk()
            ->assertSee('ZZZ-Xmas-Ad')->assertDontSee('ZZZ-House');
        $this->get(route('tools.ad-manager.scheduled', ['type' => 'listings']))->assertOk()
            ->assertSee('ZZZ-House')->assertDontSee('ZZZ-Xmas-Ad');
        $this->get(route('tools.ad-manager.scheduled', ['q' => 'Easter-Ad']))->assertOk()
            ->assertSee('ZZZ-Easter-Ad')->assertDontSee('ZZZ-Xmas-Ad')->assertDontSee('ZZZ-House');

        // Branch / own scope: only the company-ad posts they scheduled themselves.
        $this->actingAs($managerA)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertSee('ZZZ-Xmas-Ad')->assertDontSee('ZZZ-Easter-Ad');
        $this->actingAs($agent)->get(route('tools.ad-manager.scheduled'))->assertOk()
            ->assertDontSee('ZZZ-Xmas-Ad')->assertSee('ZZZ-House');
        $this->assertGreaterThan(0, $listing);
    }

    // ── Model, migration, list page ────────────────────────────────────────

    public function test_a_post_is_for_exactly_one_listing_or_one_company_ad(): void
    {
        [$agency, $branch, $manager] = $this->world('branch_manager');
        $ad = $this->ad($agency, $manager, 'X');
        $property = $this->property($agency, $branch, $manager);
        $this->assertTrue(Schema::hasColumn('property_marketing_posts', 'company_ad_id'));

        foreach ([[], ['property_id' => $property->id, 'company_ad_id' => $ad->id]] as $subject) {
            try {
                PropertyMarketingPost::create($subject + ['agency_id' => $agency, 'user_id' => $manager->id, 'platform' => 'facebook', 'ad_copy' => 'x', 'status' => 'draft']);
                $this->fail('Saved a post for ' . json_encode($subject));
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $ok = PropertyMarketingPost::create(['agency_id' => $agency, 'company_ad_id' => $ad->id, 'user_id' => $manager->id, 'platform' => 'facebook', 'ad_copy' => 'x', 'status' => 'draft']);
        $this->assertNull($ok->property_id);
        $this->assertSame('X', $ok->companyAd->name);
    }

    public function test_the_list_offers_post_and_schedule_to_a_user_who_may_post(): void
    {
        [$agency, , $manager] = $this->world('branch_manager');
        $this->ad($agency, $manager, 'Brand ad');
        $this->connect($manager, 'facebook');

        $html = $this->actingAs($manager)->get(route('tools.ad-manager.templates.company'))->assertOk()->getContent();

        $this->assertStringContainsString('Post / Schedule', $html);
        $this->assertStringContainsString('data-cadpost="overlay"', $html);
        $this->assertStringContainsString('Post now', $html);
        $this->assertStringContainsString('Schedule for later', $html);
        $this->assertStringContainsString('x-on:company-ad-post.window="openPost($event.detail)"', $html);
        $this->assertStringContainsString('Post or schedule', $html, 'the AI pop-up offers it after saving');
        $this->assertStringContainsString("company_ad_id: p.entry.id", $html);
        $this->assertStringContainsString('not connected', $html, 'Instagram is not connected for this user');
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:User} */
    private function world(string $role): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return [$agency, $branch, $this->userIn($agency, $branch, $role)];
    }

    private function userIn(int $agency, int $branch, string $role): User
    {
        return User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => $role]);
    }

    private function ad(int $agency, User $user, string $name, bool $archived = false): CompanyAd
    {
        $id = (int) DB::table('company_ads')->insertGetId([
            'agency_id' => $agency, 'user_id' => $user->id, 'name' => $name, 'occasion' => null, 'canvas' => 'facebook',
            'layout_json' => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'canvasPreset' => 'facebook', 'elements' => []]),
            'caption' => 'From all of us', 'ai_generated' => false, 'created_at' => now(), 'updated_at' => now(),
            'deleted_at' => $archived ? now() : null,
        ]);

        return CompanyAd::withoutGlobalScopes()->findOrFail($id);
    }

    private function property(int $agency, int $branch, User $agent, string $title = 'Test House'): Property
    {
        $p = Property::create(['agency_id' => $agency, 'branch_id' => $branch, 'agent_id' => $agent->id, 'title' => $title,
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house']);
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

    private function storedImage(User $user): string
    {
        $path = "marketing-scheduled/{$user->agency_id}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return $path;
    }

    private function insertPost(int $agency, User $user, CompanyAd $ad, array $attrs = []): int
    {
        $path = "marketing-scheduled/{$agency}/{$user->id}/" . Str::uuid() . '.jpg';
        Storage::disk('public')->put($path, 'jpeg-bytes');

        return (int) DB::table('property_marketing_posts')->insertGetId(array_merge([
            'agency_id' => $agency, 'company_ad_id' => $ad->id, 'user_id' => $user->id, 'platform' => 'facebook',
            'ad_copy' => 'Season\'s greetings', 'image_urls' => json_encode([Storage::disk('public')->url($path)]),
            'status' => 'scheduled', 'scheduled_for' => '2026-10-25 07:00:00', 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function payload(CompanyAd $ad, string $path, string $mode, array $platforms, ?string $date = null, ?string $time = null): array
    {
        return array_filter([
            'items'     => [['company_ad_id' => $ad->id, 'copy' => 'Merry Christmas from our team', 'image_path' => $path]],
            'platforms' => $platforms, 'mode' => $mode, 'date' => $date, 'time' => $time,
        ], fn ($v) => $v !== null);
    }

    /** @param list<Response> $publish */
    private function fakeMeta(array $publish): void
    {
        $this->metaQueue = new MockHandler($publish);
        $this->app->instance(MetaPublishingService::class, new MetaPublishingService(new Client(['handler' => HandlerStack::create($this->metaQueue)])));
        $this->app->instance(MetaOAuthService::class, new MetaOAuthService(new Client(['handler' => HandlerStack::create(new MockHandler([]))])));
    }
}
