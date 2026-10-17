<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Exceptions\AI\AiPausedException;
use App\Http\Controllers\Tools\ArticleLibraryController;
use App\Models\AgentArticle;
use App\Models\AI\AiUsageEvent;
use App\Models\ArticlePool;
use App\Models\ArticleSource;
use App\Models\DevSetting;
use App\Models\LibraryArticle;
use App\Models\Role;
use App\Models\User;
use App\Services\AI\AiSpendGuard;
use App\Services\Articles\ArticleLibraryService;
use App\Services\Articles\ArticleScraperService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-456 Part C — the Article Library: pipeline, owner screens, agency tab, and the system AI bucket.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.
 *
 * Every Anthropic call and every feed is Http::fake()d. The agency tab is called as a controller (like
 * AdTemplateManagerTest) because `tools.ad-manager*` routes sit behind a `feature:ad-manager` flag a
 * fresh test agency does not have; the owner screens are hit through their real routes.
 */
final class ArticleLibraryTest extends TestCase
{
    use RefreshDatabase;

    /** Every request the faked world received that went to Anthropic, decoded. @var array<int, array<string, mixed>> */
    private array $aiCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Pipeline
    // ═════════════════════════════════════════════════════════════════════

    public function test_run_creates_ready_articles_with_credit_and_a_working_source_link(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([
            ['Transfer duty explained', 'https://news.test/a1', 'How transfer duty works for buyers.'],
            ['Rental market cools', 'https://news.test/a2', 'Rents are flat this quarter.'],
        ])]);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['created']);
        $a = LibraryArticle::where('source_url', 'https://news.test/a1')->sole();
        $this->assertSame(LibraryArticle::STATUS_READY, $a->status);
        $this->assertSame('Prop News', $a->source_name);
        $this->assertSame(hash('sha256', 'https://news.test/a1'), $a->source_url_hash);
        $this->assertNotEmpty($a->body);
        $this->assertGreaterThan(0, (float) $a->cost_zar);
        $this->assertNotNull($a->generated_at);
        $this->assertStringContainsString('https://news.test/a1', $a->creditLine());

        // Nothing is ever fetched from the article's own page — feed only (+ Anthropic).
        Http::assertNotSent(fn ($r) => str_starts_with($r->url(), 'https://news.test/'));

        // The source row records the outcome.
        $src = ArticleSource::sole();
        $this->assertSame('ok', $src->last_status);
        $this->assertSame(2, $src->last_new_articles);
        $this->assertNull($src->last_error);
    }

    public function test_briefs_use_the_quality_model_and_the_filter_the_fast_model_and_both_are_system_calls(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Bond rates', 'https://news.test/b1', 'Rates unchanged.']])]);

        app(ArticleLibraryService::class)->run();

        $models = array_column($this->aiCalls, 'model');
        $this->assertSame([config('services.anthropic.models.fast'), config('services.anthropic.models.quality')], $models);
        $this->assertSame(2, AiUsageEvent::where('source', AiUsageEvent::SOURCE_ARTICLE_LIBRARY)->count());
    }

    public function test_an_archived_source_is_skipped_and_never_fetched(): void
    {
        $this->onlySources(['Live' => 'https://feeds.test/live', 'Gone' => 'https://feeds.test/gone']);
        ArticleSource::where('name', 'Gone')->first()->delete();
        $this->fakeWorld([
            'https://feeds.test/live' => $this->feed([['Live story', 'https://news.test/live1', 'Live.']]),
            'https://feeds.test/gone' => $this->feed([['Gone story', 'https://news.test/gone1', 'Gone.']]),
        ]);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame(1, $result['sources_attempted']);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://feeds.test/gone');
        $this->assertSame(['https://news.test/live1'], LibraryArticle::pluck('source_url')->all());
    }

    public function test_a_paused_source_is_skipped_too(): void
    {
        $this->onlySources(['Live' => 'https://feeds.test/live', 'Paused' => 'https://feeds.test/paused']);
        ArticleSource::where('name', 'Paused')->update(['is_active' => false]);
        $this->fakeWorld([
            'https://feeds.test/live'   => $this->feed([['Live story', 'https://news.test/live1', 'Live.']]),
            'https://feeds.test/paused' => $this->feed([['Paused story', 'https://news.test/p1', 'Paused.']]),
        ]);

        app(ArticleLibraryService::class)->run();

        Http::assertNotSent(fn ($r) => $r->url() === 'https://feeds.test/paused');
        $this->assertSame(1, LibraryArticle::count());
    }

    public function test_max_per_run_is_respected_newest_first(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        DevSetting::set(ArticleLibraryService::KEY_MAX_PER_RUN, '2');
        $items = [];
        foreach (range(1, 5) as $i) {
            $items[] = ["Story $i", "https://news.test/s$i", "Snippet $i", now()->subDays($i)->toRfc2822String()];
        }
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed($items)]);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame(2, $result['created']);
        $this->assertSame(5, $result['kept']);
        // The two newest.
        $this->assertEqualsCanonicalizing(['https://news.test/s1', 'https://news.test/s2'], LibraryArticle::pluck('source_url')->all());
    }

    public function test_a_duplicate_source_url_is_not_summarised_again(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Same story', 'https://news.test/same', 'Same.']])]);

        app(ArticleLibraryService::class)->run();
        $callsAfterFirst = count($this->aiCalls);
        $this->assertSame(1, LibraryArticle::count());

        $second = app(ArticleLibraryService::class)->run();

        $this->assertSame($callsAfterFirst, count($this->aiCalls), 'The second run must not call the AI for an article it already has.');
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, LibraryArticle::count());
    }

    public function test_a_hidden_article_is_never_rewritten_either(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Hidden story', 'https://news.test/h1', 'Hidden.']])]);
        app(ArticleLibraryService::class)->run();
        LibraryArticle::query()->update(['status' => LibraryArticle::STATUS_HIDDEN]);
        $calls = count($this->aiCalls);

        app(ArticleLibraryService::class)->run();

        $this->assertSame($calls, count($this->aiCalls));
        $this->assertSame(1, LibraryArticle::count());
    }

    public function test_irrelevant_items_are_dropped_and_not_judged_again_next_run(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(
            ['https://feeds.test/prop' => $this->feed([
                ['Property tax changes', 'https://news.test/k1', 'Relevant.'],
                ['Celebrity wedding', 'https://news.test/x1', 'Not relevant.'],
                ['Cricket result', 'https://news.test/x2', 'Not relevant.'],
            ])],
            keep: fn (array $titles) => [array_search('Property tax changes', $titles, true) + 1],
        );

        $first = app(ArticleLibraryService::class)->run();

        $this->assertSame(1, $first['created']);
        $this->assertSame(['https://news.test/k1'], LibraryArticle::pluck('source_url')->all());

        $calls = count($this->aiCalls);
        app(ArticleLibraryService::class)->run();
        $this->assertSame($calls, count($this->aiCalls), 'Rejected items must not be re-judged (and re-billed) every run.');
    }

    public function test_the_brief_is_written_from_the_feed_text_only_and_never_includes_a_fetch_of_the_page(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([
            ['Full text story', 'https://news.test/full', 'Short snippet.', null, '<p>The full body text from the feed itself.</p>'],
        ])]);

        app(ArticleLibraryService::class)->run();

        $briefCall = json_encode($this->aiCalls[1]);
        $this->assertStringContainsString('The full body text from the feed itself.', $briefCall);
        $this->assertStringContainsString('Do not copy sentences', $briefCall);
        $this->assertStringContainsString('No legal, tax or financial advice', $briefCall);
        Http::assertNotSent(fn ($r) => $r->url() === 'https://news.test/full');
    }

    public function test_links_that_are_not_http_are_never_stored(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([
            ['Sneaky', 'javascript:alert(1)', 'Nope.'],
            ['Fine', 'https://news.test/fine', 'Yes.'],
        ])]);

        app(ArticleLibraryService::class)->run();

        $this->assertSame(['https://news.test/fine'], LibraryArticle::pluck('source_url')->all());
    }

    public function test_a_brief_that_is_far_too_short_is_thrown_away_and_reported(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Thin', 'https://news.test/thin', 'Thin.']])], words: 20);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString('words long', $result['errors'][0]);
        $this->assertSame(0, LibraryArticle::count());
    }

    public function test_html_in_ai_output_is_stripped(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Markup', 'https://news.test/m', 'Markup.']])], injectHtml: true);

        app(ArticleLibraryService::class)->run();

        $a = LibraryArticle::sole();
        $this->assertStringNotContainsString('<', $a->body);
        $this->assertStringNotContainsString('<', $a->title);
        $this->assertStringNotContainsString('alert', $a->body);
    }

    public function test_a_source_that_fails_to_load_is_recorded_and_the_others_still_run(): void
    {
        $this->onlySources(['Down' => 'https://feeds.test/down', 'Up' => 'https://feeds.test/up']);
        $this->fakeWorld(['https://feeds.test/up' => $this->feed([['Up story', 'https://news.test/up1', 'Up.']])]);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame(1, $result['sources_failed']);
        $this->assertSame(1, $result['created']);
        $down = ArticleSource::where('name', 'Down')->sole();
        $this->assertSame('failed', $down->last_status);
        $this->assertStringContainsString('HTTP 404', (string) $down->last_error);
    }

    public function test_a_run_stops_early_when_its_time_budget_is_spent(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['One', 'https://news.test/o1', '.'], ['Two', 'https://news.test/o2', '.']])]);

        $result = app(ArticleLibraryService::class)->run(null, -1);

        $this->assertSame('stopped_early', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString('press Run now again', $result['message']);
    }

    public function test_a_second_run_cannot_start_while_one_is_in_progress(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['One', 'https://news.test/o1', '.']])]);
        $lock = Cache::lock('article-library-run', 60);
        $this->assertTrue($lock->get());

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame('busy', $result['status']);
        Http::assertNothingSent();
        $lock->release();
    }

    // ═════════════════════════════════════════════════════════════════════
    // System AI bucket — the correctness point
    // ═════════════════════════════════════════════════════════════════════

    public function test_spend_is_recorded_as_agency_null_even_when_a_user_with_an_agency_presses_run_now(): void
    {
        [$agencyId] = $this->agencyWithUser();
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);

        // A System Owner who is signed in with an agency of their own (the case the brief warned about).
        $owner = $this->owner($agencyId);
        $this->assertSame($agencyId, (int) $owner->effectiveAgencyId(), 'Precondition: the signed-in user resolves to a real agency.');

        $this->actingAs($owner)->post(route('admin.article-library.run'))->assertRedirect();

        $events = AiUsageEvent::where('source', AiUsageEvent::SOURCE_ARTICLE_LIBRARY)->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn ($e) => $e->agency_id === null), 'Library spend must be charged to the platform bucket.');
        $this->assertTrue($events->every(fn ($e) => (int) $e->user_id === $owner->id), 'The ledger still says who pressed the button.');

        $guard = app(AiSpendGuard::class);
        $this->assertSame(0.0, $guard->windowSpend($agencyId), 'Nothing is charged to the user\'s agency.');
        $this->assertGreaterThan(0.0, $guard->windowSpend(null));
    }

    public function test_an_ordinary_agent_running_the_service_is_not_charged_to_their_agency_either(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser();
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);

        $this->actingAs($agent);
        app(ArticleLibraryService::class)->run();

        $this->assertSame(0, AiUsageEvent::where('agency_id', $agencyId)->count());
        $this->assertSame(2, AiUsageEvent::whereNull('agency_id')->count());
    }

    public function test_a_paused_system_bucket_refuses_run_now_with_the_plain_message_and_no_http_call(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);
        DevSetting::set(AiSpendGuard::KEY_SYSTEM_PAUSED_AT, now()->toDateTimeString());

        $res = $this->actingAs($this->owner())->post(route('admin.article-library.run'));

        $res->assertRedirect()->assertSessionHas('error', fn ($v) => str_contains($v, AiPausedException::MESSAGE));
        Http::assertNothingSent();
        $this->assertSame(0, LibraryArticle::count());
        $this->assertSame(0, AiUsageEvent::count());
    }

    public function test_the_system_check_ignores_the_signed_in_users_own_agency_pause(): void
    {
        // The agency of the person pressing the button is paused — the library is platform spend, so it must still run.
        [$agencyId] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $agencyId)->update(['ai_paused_at' => now()]);
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);

        $this->actingAs($this->owner($agencyId))->post(route('admin.article-library.run'))->assertRedirect();

        $this->assertSame(1, LibraryArticle::count());
    }

    public function test_the_guard_system_flag_checks_only_the_platform_bucket(): void
    {
        [$agencyId, $agent] = $this->agencyWithUser();
        $guard = app(AiSpendGuard::class);
        $this->actingAs($agent);

        // Platform bucket on, agency paused: system check passes, the default (agency) check refuses.
        DB::table('agencies')->where('id', $agencyId)->update(['ai_paused_at' => now()]);
        $guard->assertAllowed(null, true);
        $this->assertNull($guard->resolveAgencyId(null, true));
        $this->expectException(AiPausedException::class);
        $guard->assertAllowed(null);
    }

    public function test_the_guard_system_flag_refuses_when_the_platform_bucket_is_paused(): void
    {
        [, $agent] = $this->agencyWithUser();
        $this->actingAs($agent);
        DevSetting::set(AiSpendGuard::KEY_SYSTEM_PAUSED_AT, now()->toDateTimeString());

        $this->expectException(AiPausedException::class);
        app(AiSpendGuard::class)->assertAllowed(null, true);
    }

    public function test_library_spend_can_trip_the_platform_cutout(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);
        DevSetting::set(AiSpendGuard::KEY_CUTOUT_ZAR, '0.01');
        DevSetting::set(AiSpendGuard::KEY_ALERT_ZAR, '0.005');
        DevSetting::set(AiSpendGuard::KEY_RECIPIENTS, json_encode([]));

        app(ArticleLibraryService::class)->run();

        $this->assertTrue(app(AiSpendGuard::class)->isPaused(null), 'Counts toward the system AI guard.');
    }

    public function test_a_cutout_part_way_stops_the_run_and_keeps_what_was_written(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['One', 'https://news.test/1', '.'], ['Two', 'https://news.test/2', '.']])]);
        DevSetting::set(AiSpendGuard::KEY_CUTOUT_ZAR, '0.01');
        DevSetting::set(AiSpendGuard::KEY_ALERT_ZAR, '0.005');
        DevSetting::set(AiSpendGuard::KEY_RECIPIENTS, json_encode([]));

        $result = app(ArticleLibraryService::class)->run();

        // The filter call itself already tripped the cut-out, so the first brief is refused.
        $this->assertSame('paused', $result['status']);
        $this->assertSame(0, $result['created']);
        $this->assertStringContainsString(AiPausedException::MESSAGE, $result['message']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Seeded sources + presentations' scraper
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_five_old_hardcoded_feeds_are_seeded_and_the_presentations_scraper_still_reads_exactly_those(): void
    {
        $expected = [
            'BusinessTech Property'   => 'https://businesstech.co.za/news/category/property/feed/',
            'Daily Investor Property' => 'https://dailyinvestor.com/category/south-africa/property/feed/',
            'IOL Property'            => 'https://www.iol.co.za/news/south-africa/rss',
            'Property24 Advice'       => 'https://www.property24.com/articles/rss',
            'MyBroadband Property'    => 'https://mybroadband.co.za/news/category/property/feed/',
        ];
        $this->assertSame($expected, ArticleSource::orderBy('id')->pluck('url', 'name')->all());
        $this->assertSame(5, ArticleSource::where('is_active', true)->count());

        Http::fake(['*' => Http::response($this->feed([['A story', 'https://news.test/p', 'Snippet about a house.']]), 200)]);

        $stats = (new ArticleScraperService())->scrapeAll();

        $this->assertSame(5, $stats['feeds_attempted']);
        $this->assertSame(5, $stats['feeds_succeeded']);
        $this->assertSame([], $stats['errors']);
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->sort()->values()->all();
        $this->assertSame(collect($expected)->values()->sort()->values()->all(), $sent);
        // Same pool rows as before: one per URL (they share a link here), labelled with a source name.
        $this->assertSame(1, ArticlePool::where('url', 'https://news.test/p')->count());
        $this->assertContains(ArticlePool::where('url', 'https://news.test/p')->value('source'), array_keys($expected));
        // No AI involved in presentations' scrape.
        $this->assertSame(0, AiUsageEvent::count());
    }

    public function test_the_seed_is_idempotent_and_the_presentations_scrape_skips_archived_and_paused_sources(): void
    {
        $migration = require base_path('database/migrations/2026_11_08_100000_at456_c_article_library.php');
        $migration->up(); // running the seed again must not duplicate or fail
        $this->assertSame(5, ArticleSource::count());

        ArticleSource::where('name', 'IOL Property')->first()->delete();
        ArticleSource::where('name', 'Property24 Advice')->update(['is_active' => false]);
        Http::fake(['*' => Http::response($this->feed([['A story', 'https://news.test/p', 'x']]), 200)]);

        $stats = (new ArticleScraperService())->scrapeAll();

        $this->assertSame(3, $stats['feeds_attempted']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'iol.co.za') || str_contains($r->url(), 'property24.com'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Owner screens
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_owner_screens_are_owner_only(): void
    {
        Http::fake();
        [$agencyId, $admin] = $this->agencyWithUser(['role' => 'admin']);
        $owner = $this->owner();
        $article = $this->libraryArticle(['title' => 'Secret']);
        $source = ArticleSource::first();

        $gets = [
            route('admin.article-library.index'),
            route('admin.article-library.articles'),
            route('admin.article-library.settings'),
        ];
        foreach ($gets as $url) {
            $this->actingAs($admin)->get($url)->assertForbidden();
            $this->actingAs($owner)->get($url)->assertOk();
        }

        $posts = [
            ['post', route('admin.article-library.run')],
            ['post', route('admin.article-library.sources.run', $source->id)],
            ['post', route('admin.article-library.sources.store'), ['name' => 'X', 'url' => 'https://x.test/feed']],
            ['put', route('admin.article-library.sources.update', $source->id), ['name' => 'X', 'url' => 'https://x.test/feed']],
            ['delete', route('admin.article-library.sources.archive', $source->id)],
            ['post', route('admin.article-library.articles.hide', $article->id)],
            ['post', route('admin.article-library.articles.unhide', $article->id)],
            ['put', route('admin.article-library.settings.update'), ['schedule' => 'nightly', 'max_per_run' => 5]],
        ];
        foreach ($posts as $row) {
            $this->actingAs($admin)->{$row[0]}($row[1], $row[2] ?? [])->assertForbidden();
        }

        // None of it took effect.
        $this->assertSame(5, ArticleSource::count());
        $this->assertSame(LibraryArticle::STATUS_READY, $article->fresh()->status);
        $this->assertSame('off', app(ArticleLibraryService::class)->settings()['schedule']);
        Http::assertNothingSent();
    }

    public function test_sources_crud_archive_restore_and_validation(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $this->post(route('admin.article-library.sources.store'), ['name' => 'New Feed', 'url' => 'https://new.test/feed', 'is_active' => '1'])
            ->assertRedirect(route('admin.article-library.index'));
        $source = ArticleSource::where('name', 'New Feed')->sole();
        $this->assertSame($owner->id, $source->created_by);
        $this->assertSame('rss', $source->type);

        // Same link twice is refused — also when the first one is archived.
        $this->post(route('admin.article-library.sources.store'), ['name' => 'Dup', 'url' => 'https://new.test/feed'])->assertSessionHasErrors('url');
        $this->post(route('admin.article-library.sources.store'), ['name' => 'Bad', 'url' => 'ftp://nope.test/feed'])->assertSessionHasErrors('url');
        $this->post(route('admin.article-library.sources.store'), ['name' => '', 'url' => 'https://other.test/feed'])->assertSessionHasErrors('name');

        $this->put(route('admin.article-library.sources.update', $source->id), ['name' => 'Renamed', 'url' => 'https://new.test/feed2', 'is_active' => '0'])->assertRedirect();
        $source->refresh();
        $this->assertSame('Renamed', $source->name);
        $this->assertFalse($source->is_active);

        // Archive = soft delete, never removed; restore brings it back.
        $this->delete(route('admin.article-library.sources.archive', $source->id))->assertRedirect();
        $this->assertSoftDeleted('article_sources', ['id' => $source->id]);
        $this->post(route('admin.article-library.sources.store'), ['name' => 'Again', 'url' => 'https://new.test/feed2'])->assertSessionHasErrors('url');
        $this->post(route('admin.article-library.sources.restore', $source->id))->assertRedirect();
        $this->assertNull(ArticleSource::find($source->id)->deleted_at);
    }

    public function test_sources_list_search_sort_filter_and_empty_states(): void
    {
        $this->onlySources(['Alpha News' => 'https://a.test/feed', 'Bravo Daily' => 'https://b.test/feed', 'Charlie Post' => 'https://c.test/feed']);
        ArticleSource::where('name', 'Charlie Post')->first()->delete();
        $owner = $this->owner();

        $names = fn (array $q) => collect($this->actingAs($owner)->get(route('admin.article-library.index', $q))->viewData('sources')->items())->pluck('name')->all();

        $this->assertSame(['Alpha News', 'Bravo Daily'], $names([]));                       // default: active, name A-Z
        $this->assertSame(['Bravo Daily', 'Alpha News'], $names(['sort' => 'name', 'direction' => 'desc']));
        $this->assertSame(['Charlie Post'], $names(['status' => 'archived']));
        $this->assertSame(['Alpha News', 'Bravo Daily', 'Charlie Post'], $names(['status' => 'all']));
        $this->assertSame(['Bravo Daily'], $names(['q' => 'b.test']));                      // search matches the link too
        $this->assertSame([], $names(['q' => 'nothing-like-this']));

        $this->actingAs($owner)->get(route('admin.article-library.index', ['q' => 'nothing-like-this']))
            ->assertSee('No sources match these filters')->assertSee('Clear filters');
        ArticleSource::query()->delete();
        $this->actingAs($owner)->get(route('admin.article-library.index'))->assertSee('No active sources');
        $this->actingAs($owner)->get(route('admin.article-library.index', ['status' => 'archived', 'q' => '']))->assertOk();
    }

    public function test_run_now_from_the_screen_runs_synchronously_and_shows_the_result_and_the_source_status(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('admin.article-library.run'))->assertRedirect()
            ->assertSessionHas('success', fn ($v) => str_contains($v, '1 new article added'));

        $page = $this->actingAs($owner)->get(route('admin.article-library.index'));
        $page->assertOk()->assertSee('Read OK')->assertSee('1 new article last run');
        $this->assertSame(1, LibraryArticle::count());
    }

    public function test_run_now_for_one_source_only_fetches_that_source_and_refuses_a_paused_or_archived_one(): void
    {
        $this->onlySources(['One' => 'https://feeds.test/one', 'Two' => 'https://feeds.test/two']);
        $this->fakeWorld([
            'https://feeds.test/one' => $this->feed([['One story', 'https://news.test/one', '.']]),
            'https://feeds.test/two' => $this->feed([['Two story', 'https://news.test/two', '.']]),
        ]);
        $owner = $this->owner();
        $one = ArticleSource::where('name', 'One')->first();
        $two = ArticleSource::where('name', 'Two')->first();

        $this->actingAs($owner)->post(route('admin.article-library.sources.run', $one->id))->assertRedirect();
        Http::assertNotSent(fn ($r) => $r->url() === 'https://feeds.test/two');
        $this->assertSame(['https://news.test/one'], LibraryArticle::pluck('source_url')->all());

        $two->update(['is_active' => false]);
        $this->actingAs($owner)->post(route('admin.article-library.sources.run', $two->id))->assertRedirect()
            ->assertSessionHas('error', fn ($v) => str_contains($v, 'paused'));

        $one->delete();
        $this->actingAs($owner)->post(route('admin.article-library.sources.run', $one->id))->assertNotFound();
    }

    public function test_articles_screen_lists_filters_sorts_hides_and_unhides(): void
    {
        $src = ArticleSource::first();
        $a = $this->libraryArticle(['title' => 'Bond rates hold', 'source_name' => 'Zed News', 'source_id' => $src->id, 'source_published_at' => now()->subDays(1)]);
        $b = $this->libraryArticle(['title' => 'Rental yields', 'source_name' => 'Alpha Post', 'source_id' => $src->id, 'source_published_at' => now()->subDays(5)]);
        $owner = $this->owner();
        $titles = fn (array $q) => collect($this->actingAs($owner)->get(route('admin.article-library.articles', $q))->viewData('articles')->items())->pluck('title')->all();

        $this->assertSame(['Bond rates hold', 'Rental yields'], $titles([]));                             // newest first
        $this->assertSame(['Rental yields', 'Bond rates hold'], $titles(['sort' => 'date', 'direction' => 'asc']));
        $this->assertSame(['Rental yields', 'Bond rates hold'], $titles(['sort' => 'source']));          // Alpha before Zed
        $this->assertSame(['Rental yields'], $titles(['q' => 'alpha']));
        $this->assertSame(['Bond rates hold'], $titles(['from' => now()->subDays(2)->toDateString()]));
        $this->assertSame(['Rental yields'], $titles(['to' => now()->subDays(3)->toDateString()]));

        $this->actingAs($owner)->post(route('admin.article-library.articles.hide', $a->id))->assertRedirect();
        $this->assertSame(LibraryArticle::STATUS_HIDDEN, $a->fresh()->status);
        $this->assertSame(['Bond rates hold'], $titles(['status' => 'hidden']));
        $this->assertSame(['Rental yields'], $titles(['status' => 'ready']));
        $this->actingAs($owner)->post(route('admin.article-library.articles.unhide', $a->id))->assertRedirect();
        $this->assertSame(LibraryArticle::STATUS_READY, $a->fresh()->status);

        // Nothing is ever hard-deleted by these screens.
        $this->assertSame(2, LibraryArticle::count());
        $this->actingAs($owner)->get(route('admin.article-library.articles', ['q' => 'zzz-none']))->assertSee('No articles match these filters');
        LibraryArticle::query()->delete();
        $this->actingAs($owner)->get(route('admin.article-library.articles'))->assertSee('The library is empty');
    }

    public function test_settings_save_and_validate_and_the_schedule_decides_when_a_run_is_due(): void
    {
        $owner = $this->owner();
        $svc = app(ArticleLibraryService::class);

        $this->assertSame(['schedule' => 'off', 'max_per_run' => 10], array_intersect_key($svc->settings(), ['schedule' => 1, 'max_per_run' => 1]));

        $this->actingAs($owner)->put(route('admin.article-library.settings.update'), ['schedule' => 'nightly', 'max_per_run' => 7])->assertRedirect();
        Cache::flush();
        $this->assertSame('nightly', $svc->settings()['schedule']);
        $this->assertSame(7, $svc->settings()['max_per_run']);

        foreach ([['schedule' => 'hourly', 'max_per_run' => 5], ['schedule' => 'nightly', 'max_per_run' => 0], ['schedule' => 'nightly', 'max_per_run' => 51], ['schedule' => 'nightly', 'max_per_run' => 'abc']] as $bad) {
            $this->actingAs($owner)->put(route('admin.article-library.settings.update'), $bad)->assertSessionHasErrors();
        }
        Cache::flush();
        $this->assertSame(7, $svc->settings()['max_per_run'], 'A refused save changes nothing.');

        // Due logic.
        DevSetting::set(ArticleLibraryService::KEY_SCHEDULE, 'off');
        $this->assertFalse($svc->isDue());
        DevSetting::set(ArticleLibraryService::KEY_SCHEDULE, 'nightly');
        $this->assertTrue($svc->isDue());
        DevSetting::set(ArticleLibraryService::KEY_SCHEDULE, 'every_3_days');
        $this->assertTrue($svc->isDue(), 'Never run before → due.');
        DevSetting::set(ArticleLibraryService::KEY_LAST_RUN_AT, now()->subDay()->toDateTimeString());
        $this->assertFalse($svc->isDue());
        DevSetting::set(ArticleLibraryService::KEY_LAST_RUN_AT, now()->subDays(3)->subMinutes(5)->toDateTimeString());
        $this->assertTrue($svc->isDue());
    }

    public function test_the_scheduled_command_respects_the_schedule_setting_and_force(): void
    {
        $this->onlySources(['Prop News' => 'https://feeds.test/prop']);
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/a', 'x']])]);

        DevSetting::set(ArticleLibraryService::KEY_SCHEDULE, 'off');
        $this->artisan('article-library:run')->assertSuccessful();
        Http::assertNothingSent();

        DevSetting::set(ArticleLibraryService::KEY_SCHEDULE, 'nightly');
        $this->artisan('article-library:run')->assertSuccessful();
        $this->assertSame(1, LibraryArticle::count());
        $this->assertSame(2, AiUsageEvent::whereNull('agency_id')->count());
    }

    public function test_the_schedule_entry_is_registered(): void
    {
        \Illuminate\Support\Facades\Artisan::call('schedule:list');
        $line = collect(explode("\n", \Illuminate\Support\Facades\Artisan::output()))
            ->filter(fn ($l) => str_contains($l, 'article-library:run'))->values();

        $this->assertCount(1, $line);
        $this->assertMatchesRegularExpression('/30\s+2\s+\*\s+\*\s+\*/', $line->first());
    }

    // ═════════════════════════════════════════════════════════════════════
    // Agency tab
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_agency_tab_hides_hidden_articles_and_articles_from_archived_sources(): void
    {
        [, $user] = $this->agencyWithUser();
        $live = ArticleSource::first();
        $gone = ArticleSource::create(['name' => 'Gone Feed', 'url' => 'https://gone.test/feed']);
        $this->libraryArticle(['title' => 'Visible', 'source_id' => $live->id]);
        $hidden = $this->libraryArticle(['title' => 'Hidden one', 'source_id' => $live->id]);
        $this->libraryArticle(['title' => 'From gone source', 'source_id' => $gone->id]);

        $this->assertEqualsCanonicalizing(['Visible', 'Hidden one', 'From gone source'], $this->titles($user));

        $hidden->update(['status' => LibraryArticle::STATUS_HIDDEN]);
        $gone->delete();

        $this->assertSame(['Visible'], $this->titles($user));
        // …and not by direct id either.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->use($user, $hidden->id);
    }

    public function test_a_hidden_article_cannot_be_used_by_id_even_with_a_crafted_request(): void
    {
        [, $user] = $this->agencyWithUser();
        $article = $this->libraryArticle(['status' => LibraryArticle::STATUS_HIDDEN]);

        try {
            $this->use($user, $article->id);
            $this->fail('Expected a 404.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(0, AgentArticle::withoutGlobalScopes()->count());
        }
    }

    public function test_two_agencies_using_the_same_article_each_get_their_own_private_draft(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        [$b, $ub] = $this->agencyWithUser();
        $article = $this->libraryArticle(['title' => 'Shared', 'body' => 'The brief body.', 'source_name' => 'Prop News', 'source_url' => 'https://news.test/shared', 'tags_json' => ['bonds', 'rates']]);

        $this->use($ua, $article->id);
        $this->use($ub, $article->id);

        $copies = AgentArticle::withoutGlobalScopes()->where('library_article_id', $article->id)->orderBy('id')->get();
        $this->assertCount(2, $copies);
        $this->assertSame([$a, $b], $copies->pluck('agency_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([$ua->id, $ub->id], $copies->pluck('user_id')->map(fn ($v) => (int) $v)->all());
        foreach ($copies as $c) {
            $this->assertFalse((bool) $c->is_published, 'Never published for them.');
            $this->assertNull($c->published_at);
            $this->assertSame('Shared', $c->title);
            $this->assertStringContainsString('The brief body.', $c->body);
            $this->assertStringContainsString('Source: Prop News — read the original: https://news.test/shared', $c->body);
            $this->assertSame('https://news.test/shared', $c->link_url);
            $this->assertSame('bonds, rates', $c->tags);
        }

        // Neither agency can see the other's copy.
        $this->actingAs($ua);
        $this->assertSame([$copies[0]->id], AgentArticle::query()->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->actingAs($ub);
        $this->assertSame([$copies[1]->id], AgentArticle::query()->pluck('id')->map(fn ($v) => (int) $v)->all());
    }

    public function test_the_same_user_using_an_article_twice_gets_no_duplicate(): void
    {
        [, $user] = $this->agencyWithUser();
        $article = $this->libraryArticle();

        $first = $this->use($user, $article->id);
        $second = $this->use($user, $article->id);

        $this->assertSame(1, AgentArticle::withoutGlobalScopes()->where('user_id', $user->id)->count());
        $this->assertStringContainsString('already have this article', (string) session('success'));
        $this->assertNotNull($first);
        $this->assertNotNull($second);
    }

    public function test_a_user_who_deleted_their_copy_can_use_the_article_again(): void
    {
        [, $user] = $this->agencyWithUser();
        $article = $this->libraryArticle();
        $this->use($user, $article->id);
        AgentArticle::withoutGlobalScopes()->where('user_id', $user->id)->first()->delete();

        $this->use($user, $article->id);

        $this->assertSame(1, AgentArticle::withoutGlobalScopes()->whereNull('deleted_at')->where('user_id', $user->id)->count());
        $this->assertSame(2, AgentArticle::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_agency_tab_search_matches_title_source_and_tags(): void
    {
        [, $user] = $this->agencyWithUser();
        $this->libraryArticle(['title' => 'Transfer duty thresholds', 'source_name' => 'BusinessTech', 'tags_json' => ['tax']]);
        $this->libraryArticle(['title' => 'Rental trends', 'source_name' => 'Daily Investor', 'tags_json' => ['rentals', 'landlords']]);

        $this->assertSame(['Transfer duty thresholds'], $this->titles($user, ['q' => 'duty']));
        $this->assertSame(['Rental trends'], $this->titles($user, ['q' => 'investor']));
        $this->assertSame(['Rental trends'], $this->titles($user, ['q' => 'landlords']));
        $this->assertSame([], $this->titles($user, ['q' => 'zzz']));
    }

    public function test_agency_tab_sort_filters_and_pagination(): void
    {
        [, $user] = $this->agencyWithUser();
        $s1 = ArticleSource::first();
        $s2 = ArticleSource::create(['name' => 'Second Feed', 'url' => 'https://second.test/feed']);
        $this->libraryArticle(['title' => 'Bravo', 'source_name' => 'Zulu', 'source_id' => $s1->id, 'source_published_at' => now()->subDays(1), 'tags_json' => ['bonds']]);
        $this->libraryArticle(['title' => 'Alpha', 'source_name' => 'Yankee', 'source_id' => $s2->id, 'source_published_at' => now()->subDays(10), 'tags_json' => ['rentals']]);

        $this->assertSame(['Bravo', 'Alpha'], $this->titles($user));                                // default newest first
        $this->assertSame(['Alpha', 'Bravo'], $this->titles($user, ['order' => 'date-asc']));
        $this->assertSame(['Alpha', 'Bravo'], $this->titles($user, ['order' => 'title-asc']));
        $this->assertSame(['Bravo', 'Alpha'], $this->titles($user, ['order' => 'title-desc']));
        $this->assertSame(['Alpha', 'Bravo'], $this->titles($user, ['order' => 'source-asc']));      // Yankee before Zulu
        $this->assertSame(['Bravo', 'Alpha'], $this->titles($user, ['order' => 'rubbish']));         // bad input → default
        $this->assertSame(['Alpha'], $this->titles($user, ['source' => $s2->id]));
        $this->assertSame(['Bravo'], $this->titles($user, ['tag' => 'bonds']));
        $this->assertSame(['Bravo'], $this->titles($user, ['from' => now()->subDays(3)->toDateString()]));
        $this->assertSame(['Alpha'], $this->titles($user, ['to' => now()->subDays(5)->toDateString()]));

        foreach (range(1, 16) as $i) {
            $this->libraryArticle(['title' => sprintf('Filler %02d', $i), 'source_published_at' => now()->subDays(100 + $i)]);
        }
        $page1 = $this->indexData($user, ['q' => 'Filler']);
        $this->assertSame(15, count($page1['articles']->items()));
        $this->assertSame(16, $page1['articles']->total());
        $this->assertSame(1, count($this->indexData($user, ['q' => 'Filler', 'page' => 2])['articles']->items()));
    }

    public function test_not_yet_used_by_me_filter_is_per_user(): void
    {
        [$agencyId, $me] = $this->agencyWithUser();
        $colleague = $this->userIn($agencyId);
        $one = $this->libraryArticle(['title' => 'Used by me']);
        $this->libraryArticle(['title' => 'Untouched']);
        $this->use($me, $one->id);

        $this->assertEqualsCanonicalizing(['Used by me', 'Untouched'], $this->titles($me));
        $this->assertSame(['Untouched'], $this->titles($me, ['unused' => '1']));
        // The colleague has not used it, so for them it still counts as unused.
        $this->assertEqualsCanonicalizing(['Used by me', 'Untouched'], $this->titles($colleague, ['unused' => '1']));
        $this->assertTrue($this->indexData($me)['mine']->has($one->id));
    }

    public function test_agency_tab_empty_states_and_the_view_renders(): void
    {
        [, $user] = $this->agencyWithUser();

        $html = $this->render($user);
        $this->assertStringContainsString('The Article Library is empty for now', $html);
        $this->assertStringContainsString('Article Library', $html);
        $this->assertTrue($this->indexData($user)['libraryEmpty']);

        $this->libraryArticle(['title' => 'Only one', 'source_name' => 'Prop News']);
        $html = $this->render($user, ['q' => 'no-such-thing']);
        $this->assertStringContainsString('No articles match these filters', $html);
        $this->assertStringContainsString('Clear filters', $html);

        $html = $this->render($user);
        $this->assertStringContainsString('Only one', $html);
        $this->assertStringContainsString('Read original', $html);
        $this->assertStringContainsString('Use this', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
        // The third pill of the Ad Manager switcher.
        $this->assertStringContainsString(route('tools.ad-manager.articles'), $html);
    }

    public function test_the_agency_routes_are_gated(): void
    {
        $route = app('router')->getRoutes()->getByName('tools.ad-manager.articles');
        $this->assertNotNull($route);
        $mw = $route->gatherMiddleware();
        $this->assertContains('permission:access_ad_manager', $mw);
        $this->assertContains('feature:ad-manager', $mw);
        $this->assertContains('agency.required', $mw);
        $this->assertContains('permission:access_ad_manager', app('router')->getRoutes()->getByName('tools.ad-manager.articles.use')->gatherMiddleware());

        // Signed-out visitors never reach it.
        $this->get('/tools/ad-manager/articles')->assertRedirect();
    }

    // ═════════════════════════════════════════════════════════════════════
    // AI usage page column (deferred from Part A §A.5)
    // ═════════════════════════════════════════════════════════════════════

    public function test_ai_usage_page_shows_library_articles_used_per_agency_and_sorts_by_it(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        [$b, $ub] = $this->agencyWithUser();
        [$c] = $this->agencyWithUser();
        $one = $this->libraryArticle();
        $two = $this->libraryArticle();
        $this->use($ua, $one->id);
        $this->use($ua, $two->id);
        $this->use($ub, $one->id);
        AgentArticle::withoutGlobalScopes()->where('user_id', $ub->id)->first()->delete(); // deleted copy still counts as "used"
        DB::table('agent_articles')->insert([ // a hand-written article (no library link) is not counted
            'agency_id' => $c, 'user_id' => $this->userIn($c)->id, 'title' => 'Mine', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $owner = $this->owner();
        $res = $this->actingAs($owner)->get(route('admin.ai-usage.index'));
        $res->assertOk()->assertSee('Library articles used');
        $rows = collect($res->viewData('agencies')->items())->keyBy('id');
        $this->assertSame(2, $rows[$a]['library_used']);
        $this->assertSame(1, $rows[$b]['library_used']);
        $this->assertSame(0, $rows[$c]['library_used']);

        $order = fn (array $q) => collect($this->actingAs($owner)->get(route('admin.ai-usage.index', $q))->viewData('agencies')->items())
            ->whereIn('id', [$a, $b, $c])->pluck('id')->all();
        $this->assertSame([$a, $b, $c], $order(['sort' => 'library_used']));
        $this->assertSame([$c, $b, $a], $order(['sort' => 'library_used', 'direction' => 'asc']));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Navigation
    // ═════════════════════════════════════════════════════════════════════

    public function test_dev_settings_has_an_article_library_link_and_the_sidebar_item_stays_lit(): void
    {
        $owner = $this->owner();

        $hub = $this->actingAs($owner)->get(route('admin.dev-settings.index'));
        $hub->assertOk()->assertSee(route('admin.article-library.index'), false)->assertSee('Article Library');

        $page = $this->actingAs($owner)->get(route('admin.article-library.index'))->getContent();
        // The Dev Settings sidebar entry carries "active" on the library's own pages.
        $this->assertMatchesRegularExpression(
            '/<a href="' . preg_quote(route('admin.dev-settings.index'), '/') . '" class="corex-nav-item active"/',
            $page
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

    /** Replace the five seeded sources with exactly these. @param array<string, string> $sources */
    private function onlySources(array $sources): void
    {
        ArticleSource::withTrashed()->forceDelete();
        foreach ($sources as $name => $url) {
            ArticleSource::create(['name' => $name, 'url' => $url]);
        }
    }

    /**
     * Fake feeds + Anthropic. Anything not in $feeds is a 404.
     *
     * @param array<string, string> $feeds url => RSS body
     * @param (callable(array<int, string>): array<int, int>)|null $keep titles => 1-based numbers the filter keeps (default: all)
     */
    private function fakeWorld(array $feeds, ?callable $keep = null, int $words = 170, bool $injectHtml = false): void
    {
        $this->aiCalls = [];
        $fast = config('services.anthropic.models.fast');

        Http::fake(function ($request) use ($feeds, $keep, $words, $injectHtml, $fast) {
            $url = $request->url();

            if (str_contains($url, 'api.anthropic.com')) {
                $body = json_decode($request->body(), true);
                $this->aiCalls[] = $body;
                $prompt = (string) ($body['messages'][0]['content'] ?? '');

                if (($body['model'] ?? '') === $fast) {
                    preg_match_all('/^\d+\. (.+?)(?: — .*)?$/m', $prompt, $m);
                    $titles = $m[1];
                    $numbers = $keep ? $keep($titles) : array_keys(array_fill(1, count($titles), 1));

                    return Http::response([
                        'content' => [['type' => 'text', 'text' => json_encode(['keep' => array_values($numbers)])]],
                        'usage' => ['input_tokens' => 1500, 'output_tokens' => 40],
                    ], 200);
                }

                preg_match('/Headline: (.+)/', $prompt, $h);
                $para = rtrim(str_repeat('word ', intdiv($words, 2)));
                $brief = $para . "\n\n" . $para;
                if ($injectHtml) {
                    $brief = '<script>alert(1)</script>' . $brief;
                }

                return Http::response([
                    'content' => [['type' => 'text', 'text' => json_encode([
                        'title' => ($injectHtml ? '<b>' : '') . 'Brief: ' . ($h[1] ?? 'story') . ($injectHtml ? '</b>' : ''),
                        'excerpt' => 'A one line summary.',
                        'brief' => $brief,
                        'tags' => ['Property', '#Bonds', 'x', 'a very long tag that goes on and on beyond thirty characters'],
                    ])]],
                    'usage' => ['input_tokens' => 900, 'output_tokens' => 450],
                ], 200);
            }

            return isset($feeds[$url]) ? Http::response($feeds[$url], 200) : Http::response('not found', 404);
        });
    }

    /** @param array<int, array{0: string, 1: string, 2: string, 3?: ?string, 4?: ?string}> $items */
    private function feed(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title>';
        foreach ($items as $i) {
            $xml .= '<item><title>' . htmlspecialchars($i[0]) . '</title><link>' . htmlspecialchars($i[1]) . '</link>'
                . '<description>' . htmlspecialchars($i[2]) . '</description>'
                . '<pubDate>' . ($i[3] ?? now()->toRfc2822String()) . '</pubDate>'
                . (! empty($i[4]) ? '<content:encoded><![CDATA[' . $i[4] . ']]></content:encoded>' : '')
                . '</item>';
        }

        return $xml . '</channel></rss>';
    }

    /** @param array<string, mixed> $attrs */
    private function libraryArticle(array $attrs = []): LibraryArticle
    {
        $n = Str::random(8);
        $url = $attrs['source_url'] ?? "https://news.test/$n";

        return LibraryArticle::create(array_merge([
            'source_id'           => ArticleSource::query()->value('id'),
            'source_name'         => 'Prop News',
            'source_url'          => $url,
            'source_url_hash'     => hash('sha256', $url),
            'title'               => "Title $n",
            'excerpt'             => 'Excerpt.',
            'body'                => "Body paragraph one.\n\nBody paragraph two.",
            'tags_json'           => ['property'],
            'source_published_at' => now()->subDay(),
            'status'              => LibraryArticle::STATUS_READY,
            'generated_at'        => now(),
            'cost_zar'            => 0.3,
        ], $attrs));
    }

    /** @return array<string, mixed> */
    private function indexData(User $user, array $query = []): array
    {
        $request = Request::create('/tools/ad-manager/articles', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        $this->actingAs($user);

        return app(ArticleLibraryController::class)->index($request)->getData();
    }

    private function render(User $user, array $query = []): string
    {
        $request = Request::create('/tools/ad-manager/articles', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        $this->actingAs($user);

        return app(ArticleLibraryController::class)->index($request)->render();
    }

    /** @return array<int, string> */
    private function titles(User $user, array $query = []): array
    {
        return collect($this->indexData($user, $query)['articles']->items())->pluck('title')->all();
    }

    private function use(User $user, int $articleId): \Illuminate\Http\RedirectResponse
    {
        $request = Request::create("/tools/ad-manager/articles/$articleId/use", 'POST');
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        $this->actingAs($user);

        return app(ArticleLibraryController::class)->use($request, $articleId);
    }

    /** @return array{0:int, 1:User} */
    private function agencyWithUser(array $userAttrs = []): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $this->userIn($agencyId, $userAttrs)];
    }

    private function userIn(int $agencyId, array $attrs = []): User
    {
        $branchId = DB::table('branches')->where('agency_id', $agencyId)->value('id')
            ?? (int) DB::table('branches')->insertGetId([
                'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
            ]);

        return User::factory()->create(array_merge(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent'], $attrs));
    }

    private function owner(?int $agencyId = null): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['agency_id' => $agencyId, 'role' => 'super_admin']);
    }
}
