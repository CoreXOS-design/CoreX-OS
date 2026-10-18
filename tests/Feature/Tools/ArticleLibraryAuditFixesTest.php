<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\ArticleLibraryController;
use App\Models\AgentArticle;
use App\Models\ArticleSource;
use App\Models\LibraryArticle;
use App\Models\Role;
use App\Models\User;
use App\Services\Articles\ArticleLibraryService;
use App\Services\Articles\ArticleScraperService;
use App\Services\PermissionService;
use App\Support\Net\SafeFeedUrl;
use App\Support\Text\PlainText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-456 Part C audit findings (2026-10-17): feed-fetch SSRF guard, HTML that survives the
 * plain-text cleaner, content-failed briefs being re-billed, the unbounded tag-filter load.
 * DNS is faked through SafeFeedUrl's injectable resolver; every HTTP call is Http::fake()d.
 */
final class ArticleLibraryAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<int, string>> host => resolved IPs */
    private array $dns = [];

    /** Anthropic brief-model calls seen by the fake. */
    private int $briefCalls = 0;

    /** HTTP status the faked brief model answers with; tests change it between runs. */
    private int $briefStatus = 200;

    /** When true the faked brief model answers with text that is not JSON (an unreadable answer). */
    private bool $briefUnreadable = false;

    /** When true the faked relevance (fast) model answers with text that is not JSON. */
    private bool $filterUnreadable = false;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        config()->set('services.anthropic.max_retries', 1);
        $this->dns = ['feeds.test' => ['93.184.216.34'], 'news.test' => ['93.184.216.35']];
        $this->app->instance(SafeFeedUrl::class, new SafeFeedUrl(fn (string $host) => $this->dns[$host] ?? []));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }

    // ═════════════════════════════════════════════════════════════════════
    // 1. SSRF
    // ═════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function unsafeUrls(): array
    {
        return [
            'loopback v4'               => ['http://127.0.0.1/feed'],
            'loopback 127.x'            => ['https://127.1.2.3/feed'],
            'private 10.x'              => ['http://10.0.0.5/feed'],
            'private 172.16'            => ['http://172.16.9.9/feed'],
            'private 192.168'           => ['http://192.168.1.1/feed'],
            'cloud metadata'            => ['http://169.254.169.254/latest/meta-data/'],
            'CGNAT'                     => ['http://100.64.0.1/feed'],
            'unspecified'               => ['http://0.0.0.0/feed'],
            'multicast'                 => ['http://224.0.0.1/feed'],
            'reserved 240/4'            => ['http://240.0.0.1/feed'],
            'loopback v6'               => ['http://[::1]/feed'],
            'unique-local v6 (metadata)' => ['http://[fd00:ec2::254]/feed'],
            'link-local v6'             => ['http://[fe80::1]/feed'],
            'v4-mapped loopback'        => ['http://[::ffff:127.0.0.1]/feed'],
            'NAT64 loopback'            => ['http://[64:ff9b::7f00:1]/feed'],
            '6to4 loopback'             => ['http://[2002:7f00:1::]/feed'],
            'decimal-encoded loopback'  => ['http://2130706433/feed'],
            'hex-encoded loopback'      => ['http://0x7f.0.0.1/feed'],
            'localhost'                 => ['http://localhost/feed'],
            'single-label host'         => ['http://intranet/feed'],
            'custom port'               => ['http://feeds.test:8080/feed'],
            'ssh port'                  => ['http://feeds.test:22/feed'],
            'user info'                 => ['http://user:pass@feeds.test/feed'],
            'name that resolves private' => ['https://rebind.test/feed'],
            'name that resolves to one private of two' => ['https://mixed.test/feed'],
            'name that resolves to nothing' => ['https://nowhere.test/feed'],
            'ftp'                       => ['ftp://feeds.test/feed'],
        ];
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_addresses_are_refused_in_plain_words(string $url): void
    {
        $this->dns['rebind.test'] = ['10.1.2.3'];
        $this->dns['mixed.test']  = ['93.184.216.50', '192.168.0.9'];

        $error = app(SafeFeedUrl::class)->error($url);

        $this->assertNotNull($error, "Expected {$url} to be refused");
        $this->assertStringNotContainsString('Exception', $error);
    }

    #[DataProvider('safeUrls')]
    public function test_normal_public_addresses_pass(string $url): void
    {
        $this->dns['v6.test'] = ['2606:4700:4700::1111'];
        $this->assertNull(app(SafeFeedUrl::class)->error($url));
    }

    /** @return array<string, array{0: string}> */
    public static function safeUrls(): array
    {
        return [
            'https'        => ['https://feeds.test/rss.xml'],
            'http'         => ['http://feeds.test/rss.xml'],
            'explicit 443' => ['https://feeds.test:443/rss.xml'],
            'public v4 literal' => ['https://93.184.216.34/rss.xml'],
            'public v6 name'    => ['https://v6.test/rss.xml'],
            'public v6 literal' => ['https://[2606:4700:4700::1111]/rss.xml'],
        ];
    }

    public function test_the_owner_cannot_save_a_private_address_as_a_source(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        ArticleSource::withTrashed()->forceDelete();

        foreach (['http://127.0.0.1/feed', 'http://169.254.169.254/latest/', 'http://[::1]/feed', 'http://feeds.test:9200/feed'] as $bad) {
            $this->post(route('admin.article-library.sources.store'), ['name' => 'Bad', 'url' => $bad])->assertSessionHasErrors('url');
        }
        $this->assertSame(0, ArticleSource::count());

        $this->post(route('admin.article-library.sources.store'), ['name' => 'Fine', 'url' => 'https://feeds.test/ok'])->assertSessionHasNoErrors();
        $this->assertSame(1, ArticleSource::count());

        // Editing an existing source to a private address is refused too.
        $id = ArticleSource::value('id');
        $this->put(route('admin.article-library.sources.update', $id), ['name' => 'Fine', 'url' => 'http://10.0.0.1/x', 'is_active' => '1'])->assertSessionHasErrors('url');
        $this->assertSame('https://feeds.test/ok', ArticleSource::find($id)->url);
    }

    public function test_a_source_that_points_at_a_private_address_is_never_requested(): void
    {
        // A row that predates the save-time check (or was edited directly) is still refused at fetch time.
        $source = $this->source('Evil', 'http://169.254.169.254/latest/meta-data/');
        Http::fake();

        try {
            app(ArticleScraperService::class)->fetchSource($source);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('private or internal network', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(ArticleSource::STATUS_FAILED, $source->fresh()->last_status);
    }

    public function test_a_public_url_that_redirects_to_localhost_is_stopped_at_the_redirect(): void
    {
        $source = $this->source('Redirector', 'https://feeds.test/rss');
        Http::fake([
            'feeds.test/rss' => Http::response('', 302, ['Location' => 'http://127.0.0.1:80/admin']),
            '*'              => Http::response('SECRET', 200),
        ]);

        try {
            app(ArticleScraperService::class)->fetchSource($source);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('redirect', strtolower($e->getMessage()));
        }

        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
    }

    public function test_a_redirect_to_a_name_that_resolves_private_is_stopped(): void
    {
        $this->dns['internal.test'] = ['10.9.9.9'];
        $source = $this->source('Redirector', 'https://feeds.test/rss');
        Http::fake([
            'feeds.test/rss' => Http::response('', 301, ['Location' => 'https://internal.test/rss']),
            '*'              => Http::response('SECRET', 200),
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            app(ArticleScraperService::class)->fetchSource($source);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_up_to_three_redirects_are_followed_and_a_fourth_is_refused(): void
    {
        $rss = $this->feed([['Story', 'https://news.test/s1', 'Snippet.']]);

        // 3 hops → fine (relative and absolute Location values both work).
        $ok = $this->source('Hops', 'https://feeds.test/a');
        Http::fake([
            'feeds.test/a' => Http::response('', 301, ['Location' => '/b']),
            'feeds.test/b' => Http::response('', 302, ['Location' => 'https://feeds.test/c']),
            'feeds.test/c' => Http::response('', 307, ['Location' => 'https://feeds.test/d']),
            'feeds.test/d' => Http::response($rss, 200),
        ]);
        $this->assertCount(1, app(ArticleScraperService::class)->fetchSource($ok));

        // 4 hops → refused.
        $bad = $this->source('TooMany', 'https://feeds.test/a2');
        Http::fake([
            'feeds.test/a2' => Http::response('', 301, ['Location' => '/b2']),
            'feeds.test/b2' => Http::response('', 301, ['Location' => '/c2']),
            'feeds.test/c2' => Http::response('', 301, ['Location' => '/d2']),
            'feeds.test/d2' => Http::response('', 301, ['Location' => '/e2']),
            'feeds.test/e2' => Http::response($rss, 200),
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('too many times');
        app(ArticleScraperService::class)->fetchSource($bad);
    }

    public function test_an_oversized_feed_is_refused_not_downloaded_whole(): void
    {
        $source = $this->source('Huge', 'https://feeds.test/huge');
        $body   = str_repeat('x', ArticleScraperService::MAX_FEED_BYTES + 1024);
        Http::fake(['feeds.test/huge' => Http::response($body, 200)]);

        try {
            app(ArticleScraperService::class)->fetchSource($source);
            $this->fail('Expected a refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        }
        $this->assertSame(ArticleSource::STATUS_FAILED, $source->fresh()->last_status);

        // A normal-sized feed is untouched by the cap.
        $fine = $this->source('Fine', 'https://feeds.test/fine');
        Http::fake(['feeds.test/fine' => Http::response($this->feed([['Story', 'https://news.test/s1', 'Snippet.']]), 200)]);
        $this->assertCount(1, app(ArticleScraperService::class)->fetchSource($fine));
    }

    // ═════════════════════════════════════════════════════════════════════
    // 2. HTML survives the cleaner
    // ═════════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string}> */
    public static function hostilePayloads(): array
    {
        return [
            'encoded script'        => ['&lt;script&gt;alert(1)&lt;/script&gt;Hello'],
            'double encoded'        => ['&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;Hello'],
            'triple encoded img'    => ['&amp;amp;lt;img src=x onerror=alert(1)&amp;amp;gt;Hello'],
            'nested broken tag'     => ['<scr<script>ipt>alert(1)</scr</script>ipt>Hello'],
            'encoded inside tag'    => ['<b>&lt;i&gt;Hello&lt;/i&gt;</b>'],
            'numeric entities'      => ['&#60;script&#62;alert(1)&#60;/script&#62;Hello'],
            'hex entities'          => ['&#x3c;iframe src=//evil&#x3e;Hello'],
            'comment'               => ['&lt;!-- x --&gt;&lt;a href="javascript:alert(1)"&gt;Hello&lt;/a&gt;'],
        ];
    }

    #[DataProvider('hostilePayloads')]
    public function test_no_tag_survives_the_plain_text_cleaner(string $payload): void
    {
        $out = PlainText::strip($payload);

        $this->assertDoesNotMatchRegularExpression('/<\s*[a-z\/!?]/i', $out, "Tag survived: {$out}");
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        $this->assertSame('Tom & Jerry paid R1 < R2 for 3 > 2 rooms', PlainText::strip('Tom &amp; Jerry paid R1 < R2 for 3 > 2 rooms'));
    }

    public function test_encoded_html_from_the_ai_never_reaches_the_stored_article(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld([
            'https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/enc', 'Snippet.']]),
        ], title: '&lt;script&gt;alert(1)&lt;/script&gt;Bond rates', excerpt: '&amp;lt;img src=x onerror=alert(1)&amp;gt;Summary', briefPrefix: '&lt;b&gt;&lt;i&gt;');

        app(ArticleLibraryService::class)->run();

        $a = LibraryArticle::sole();
        foreach ([$a->title, $a->excerpt, $a->body] as $field) {
            $this->assertDoesNotMatchRegularExpression('/<\s*[a-z\/!?]/i', (string) $field);
        }
        $this->assertStringNotContainsString('alert', $a->title);
        $this->assertStringStartsWith('word', $a->body);
    }

    public function test_a_brief_with_encoded_page_code_is_rejected_too(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/enc2', 'Snippet.']])], briefPrefix: '&lt;script&gt;steal()&lt;/script&gt;');

        app(ArticleLibraryService::class)->run();

        $this->assertSame(0, LibraryArticle::count());
    }

    public function test_a_brief_that_contains_page_code_is_rejected_not_stored(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld([
            'https://feeds.test/prop' => $this->feed([['Story', 'https://news.test/xss', 'Snippet.']]),
        ], briefPrefix: '<script>steal()</script>');

        $result = app(ArticleLibraryService::class)->run();

        $this->assertSame(0, LibraryArticle::count());
        $this->assertSame(1, $result['failed']);
    }

    public function test_feed_snippet_and_title_are_cleaned_too(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $rss = '<?xml version="1.0"?><rss version="2.0"><channel><title>T</title><item>'
            . '<title>Bond &amp;lt;b&amp;gt;rates&amp;lt;/b&amp;gt;</title><!-- XML-decoded once it is &lt;b&gt;; a second decode would make a real tag --><link>https://news.test/t1</link>'
            . '<description>&amp;lt;script&amp;gt;alert(1)&amp;lt;/script&amp;gt;Rates held.</description></item></channel></rss>';
        Http::fake(['feeds.test/prop' => Http::response($rss, 200)]);

        $items = app(ArticleScraperService::class)->fetchSource(ArticleSource::first());

        $this->assertDoesNotMatchRegularExpression('/<\s*[a-z\/!?]/i', $items[0]['title'] . $items[0]['snippet']);
        $this->assertDoesNotMatchRegularExpression('/<\s*[a-z\/!?]/i', (string) \App\Models\ArticlePool::first()->snippet);
    }

    public function test_use_this_cleans_an_article_stored_before_the_fix(): void
    {
        [$agencyId, $user] = $this->agencyWithUser();
        $this->useOnlySource('https://feeds.test/prop');
        $article = LibraryArticle::create([
            'source_id' => ArticleSource::value('id'), 'source_name' => 'Prop News', 'source_url' => 'https://news.test/old',
            'source_url_hash' => hash('sha256', 'https://news.test/old'),
            'title' => '<script>alert(1)</script>Old title', 'excerpt' => '<b>Old</b> excerpt',
            'body' => "Para one.\n\n<script>alert(2)</script>Para two.", 'tags_json' => ['property'],
            'status' => LibraryArticle::STATUS_READY, 'generated_at' => now(), 'cost_zar' => 0.1,
        ]);

        $request = Request::create("/tools/ad-manager/articles/{$article->id}/use", 'POST');
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);
        $this->actingAs($user);
        app(ArticleLibraryController::class)->use($request, $article->id);

        $copy = AgentArticle::query()->where('user_id', $user->id)->sole();
        foreach ([$copy->title, $copy->excerpt, $copy->body] as $field) {
            $this->assertDoesNotMatchRegularExpression('/<\s*[a-z\/!?]/i', (string) $field);
        }
        $this->assertStringContainsString('Source: Prop News', $copy->body);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 8a. Content failures are remembered; transient ones are not
    // ═════════════════════════════════════════════════════════════════════

    public function test_a_brief_rejected_for_content_is_not_paid_for_again_on_the_next_run(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Thin story', 'https://news.test/thin', 'Thin.']])], words: 20);

        $first = app(ArticleLibraryService::class)->run();
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $this->briefCalls);

        $second = app(ArticleLibraryService::class)->run();
        $this->assertSame(1, $this->briefCalls, 'The same item was sent to the (expensive) brief model again.');
        $this->assertSame(0, $second['failed']);
        $this->assertSame(0, LibraryArticle::count());
    }

    public function test_an_empty_brief_is_a_content_failure_and_is_remembered(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Empty story', 'https://news.test/empty', 'Empty.']])], words: 0);

        app(ArticleLibraryService::class)->run();
        app(ArticleLibraryService::class)->run();

        $this->assertSame(1, $this->briefCalls);
    }

    public function test_a_transient_api_failure_is_not_remembered_and_is_retried_next_run(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Flaky story', 'https://news.test/flaky', 'Flaky.']])], briefStatus: 400);

        $first = app(ArticleLibraryService::class)->run();
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $this->briefCalls);

        // The API recovers: the very same item is tried again and written.
        $this->briefStatus = 200;
        $this->briefCalls  = 0;
        $second = app(ArticleLibraryService::class)->run();

        $this->assertSame(1, $this->briefCalls, 'the item was sent to the brief model again');
        $this->assertSame(1, $second['created']);
        $this->assertSame(1, LibraryArticle::count());
    }

    public function test_an_item_with_an_unreadable_answer_is_given_up_on_after_two_attempts(): void
    {
        // AT-456 audit D-L2: it used to be paid for again every run, indefinitely.
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Stuck story', 'https://news.test/stuck', 'Stuck.']])]);
        $this->briefUnreadable = true;

        app(ArticleLibraryService::class)->run();
        $this->assertSame(1, $this->briefCalls);

        app(ArticleLibraryService::class)->run();
        $this->assertSame(2, $this->briefCalls, 'a second attempt is still allowed');

        app(ArticleLibraryService::class)->run();
        app(ArticleLibraryService::class)->run();
        $this->assertSame(2, $this->briefCalls, 'after two failed attempts the item is remembered and not paid for again');
        $this->assertSame(0, LibraryArticle::count());
    }

    public function test_one_unreadable_answer_then_a_good_one_still_writes_the_article(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Wobbly story', 'https://news.test/wobbly', 'Wobbly.']])]);
        $this->briefUnreadable = true;
        app(ArticleLibraryService::class)->run();

        $this->briefUnreadable = false;
        $second = app(ArticleLibraryService::class)->run();

        $this->assertSame(1, $second['created']);
    }

    public function test_items_in_an_unreadable_relevance_answer_are_given_up_on_after_two_attempts(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $this->fakeWorld(['https://feeds.test/prop' => $this->feed([['Filter story', 'https://news.test/filter', 'Filter.']])]);
        $this->filterUnreadable = true;

        $filterCalls = 0;
        $counted = function () use (&$filterCalls) {
            $filterCalls = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.anthropic.com'))->count();
        };

        $first = app(ArticleLibraryService::class)->run();
        $this->assertSame('failed', $first['status']);
        $counted();
        $this->assertSame(1, $filterCalls);

        app(ArticleLibraryService::class)->run();
        $counted();
        $this->assertSame(2, $filterCalls);

        $third = app(ArticleLibraryService::class)->run();
        $counted();
        $this->assertSame(2, $filterCalls, 'the stuck item is no longer put in front of the (paid) relevance call');
        $this->assertSame(0, $third['new_items']);
    }

    // ═════════════════════════════════════════════════════════════════════
    // 8c. The run lock outlives every run (AT-456 audit D-L1)
    // ═════════════════════════════════════════════════════════════════════

    public function test_every_run_stops_starting_briefs_well_before_the_run_lock_expires(): void
    {
        $cls  = new \ReflectionClass(ArticleLibraryService::class);
        $max  = $cls->getReflectionConstant('MAX_RUN_SECONDS')->getValue();
        $lock = $cls->getReflectionConstant('LOCK_SECONDS')->getValue();
        $brief = $cls->getReflectionConstant('BRIEF_TIMEOUT_SECS')->getValue();

        // The last brief can start right at the deadline and take its full timeout; the lock must still be held.
        $this->assertLessThan($lock, $max + $brief, 'a scheduled run (no web budget) could outlive its lock');
    }

    // ═════════════════════════════════════════════════════════════════════
    // 8b. Tag filter list: cached, invalidated, not read per render
    // ═════════════════════════════════════════════════════════════════════

    public function test_the_tag_list_is_cached_and_follows_changes(): void
    {
        $this->useOnlySource('https://feeds.test/prop');
        $a = $this->libraryArticle(['tags_json' => ['bonds', 'rates']]);
        $this->libraryArticle(['tags_json' => ['rates', 'rentals']]);

        $this->assertSame(['bonds', 'rates', 'rentals'], LibraryArticle::visibleTags()->all());

        // Second call reads no articles at all.
        $queries = [];
        DB::listen(function ($q) use (&$queries) { $queries[] = $q->sql; });
        LibraryArticle::visibleTags();
        $this->assertSame([], array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'library_articles'))));

        // A new article, a hidden article and an archived source all show up in the list straight away.
        $this->libraryArticle(['tags_json' => ['transfer duty']]);
        $this->assertContains('transfer duty', LibraryArticle::visibleTags()->all());

        $a->update(['status' => LibraryArticle::STATUS_HIDDEN]);
        $this->assertNotContains('bonds', LibraryArticle::visibleTags()->all());

        ArticleSource::first()->delete();
        $this->assertSame([], LibraryArticle::visibleTags()->all());
        ArticleSource::onlyTrashed()->first()->restore();
        $this->assertContains('rates', LibraryArticle::visibleTags()->all());
    }

    public function test_the_agency_tab_does_not_load_every_articles_tags_per_render(): void
    {
        [$agencyId, $user] = $this->agencyWithUser();
        $this->useOnlySource('https://feeds.test/prop');
        for ($i = 0; $i < 5; $i++) {
            $this->libraryArticle(['tags_json' => ["tag$i"]]);
        }

        $render = function () use ($user) {
            $request = Request::create('/tools/ad-manager/articles', 'GET');
            $request->setUserResolver(fn () => $user);
            $this->app->instance('request', $request);
            $this->actingAs($user);

            return app(ArticleLibraryController::class)->index($request)->getData();
        };

        $first = $render();
        $this->assertSame(['tag0', 'tag1', 'tag2', 'tag3', 'tag4'], $first['tags']->all());

        $tagReads = [];
        DB::listen(function ($q) use (&$tagReads) {
            if (str_contains($q->sql, 'tags_json') && str_starts_with(strtolower($q->sql), 'select') && ! str_contains($q->sql, 'count(')) {
                $tagReads[] = $q->sql;
            }
        });
        $render();
        // The page's own query selects library_articles.* for ONE page; no extra read of every article's tags.
        $this->assertLessThanOrEqual(1, count($tagReads), implode("\n", $tagReads));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════════

    private function source(string $name, string $url): ArticleSource
    {
        return ArticleSource::create(['name' => $name, 'url' => $url]);
    }

    private function useOnlySource(string $url): void
    {
        ArticleSource::withTrashed()->forceDelete();
        $this->source('Prop News', $url);
    }

    /** @param array<int, array{0: string, 1: string, 2: string}> $items */
    private function feed(array $items): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>T</title>';
        foreach ($items as $i) {
            $xml .= '<item><title>' . htmlspecialchars($i[0]) . '</title><link>' . htmlspecialchars($i[1]) . '</link>'
                . '<description>' . htmlspecialchars($i[2]) . '</description><pubDate>' . now()->toRfc2822String() . '</pubDate></item>';
        }

        return $xml . '</channel></rss>';
    }

    /**
     * Fake feeds + Anthropic. The relevance call keeps everything; the brief call is configurable.
     *
     * @param array<string, string> $feeds
     */
    private function fakeWorld(array $feeds, int $words = 170, string $briefPrefix = '', ?string $title = null, ?string $excerpt = null, int $briefStatus = 200): void
    {
        $this->briefCalls  = 0;
        $this->briefStatus = $briefStatus;
        $this->briefUnreadable = false;
        $this->filterUnreadable = false;
        $fast = config('services.anthropic.models.fast');

        Http::fake(function ($request) use ($feeds, $words, $briefPrefix, $title, $excerpt, $fast) {
            $url = $request->url();

            if (str_contains($url, 'api.anthropic.com')) {
                $body   = json_decode($request->body(), true);
                $prompt = (string) ($body['messages'][0]['content'] ?? '');

                if (($body['model'] ?? '') === $fast) {
                    if ($this->filterUnreadable) {
                        return Http::response([
                            'content' => [['type' => 'text', 'text' => 'sorry, I cannot do that']],
                            'usage'   => ['input_tokens' => 1500, 'output_tokens' => 40],
                        ], 200);
                    }
                    preg_match_all('/^(\d+)\. /m', $prompt, $m);

                    return Http::response([
                        'content' => [['type' => 'text', 'text' => json_encode(['keep' => array_map('intval', $m[1])])]],
                        'usage'   => ['input_tokens' => 1500, 'output_tokens' => 40],
                    ], 200);
                }

                $this->briefCalls++;
                if ($this->briefUnreadable) {
                    return Http::response([
                        'content' => [['type' => 'text', 'text' => 'this is not json at all']],
                        'usage'   => ['input_tokens' => 900, 'output_tokens' => 450],
                    ], 200);
                }
                if ($this->briefStatus !== 200) {
                    return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'nope']], $this->briefStatus);
                }

                $para  = $words > 0 ? rtrim(str_repeat('word ', intdiv($words, 2))) : '';
                $brief = $briefPrefix . ($words > 0 ? $para . "\n\n" . $para : '');

                return Http::response([
                    'content' => [['type' => 'text', 'text' => json_encode([
                        'title'   => $title ?? 'A brief headline',
                        'excerpt' => $excerpt ?? 'A one line summary.',
                        'brief'   => $brief,
                        'tags'    => ['property'],
                    ])]],
                    'usage' => ['input_tokens' => 900, 'output_tokens' => 450],
                ], 200);
            }

            return isset($feeds[$url]) ? Http::response($feeds[$url], 200) : Http::response('not found', 404);
        });
    }

    /** @param array<string, mixed> $attrs */
    private function libraryArticle(array $attrs = []): LibraryArticle
    {
        $n   = Str::random(8);
        $url = $attrs['source_url'] ?? "https://news.test/$n";

        return LibraryArticle::create(array_merge([
            'source_id' => ArticleSource::query()->value('id'), 'source_name' => 'Prop News', 'source_url' => $url,
            'source_url_hash' => hash('sha256', $url), 'title' => "Title $n", 'excerpt' => 'Excerpt.',
            'body' => 'Body.', 'tags_json' => ['property'], 'source_published_at' => now()->subDay(),
            'status' => LibraryArticle::STATUS_READY, 'generated_at' => now(), 'cost_zar' => 0.3,
        ], $attrs));
    }

    /** @return array{0:int, 1:User} */
    private function agencyWithUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId(['agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        return [$agencyId, User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent'])];
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['agency_id' => null, 'role' => 'super_admin']);
    }
}
