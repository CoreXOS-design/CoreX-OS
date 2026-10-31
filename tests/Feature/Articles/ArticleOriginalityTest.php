<?php

declare(strict_types=1);

namespace Tests\Feature\Articles;

use App\Models\ArticlePool;
use App\Models\ArticleSource;
use App\Models\DevSetting;
use App\Models\LibraryArticle;
use App\Models\Role;
use App\Models\User;
use App\Services\Articles\ArticleLibraryService;
use App\Services\PermissionService;
use App\Support\Net\SafeFeedUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AT-509 — the Article Library's original-wording guard: a brief is compared with the feed text it was written
 * from; copying fails it, one retry quotes the copied words back, a second failure skips the item and stores nothing.
 * Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md §C.8.
 *
 * Every feed and every Anthropic call is Http::fake()d.
 */
final class ArticleOriginalityTest extends TestCase
{
    use RefreshDatabase;

    /** Quality-model (brief) prompts the faked Anthropic received, in order. @var array<int, string> */
    private array $briefPrompts = [];

    /** Briefs the fake model returns, one per call (the last repeats). @var array<int, string> */
    private array $briefs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        $this->app->instance(SafeFeedUrl::class, new SafeFeedUrl(fn () => ['93.184.216.34']));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }

    // ── The score ────────────────────────────────────────────────────────

    public function test_a_brief_that_is_the_material_verbatim_scores_full_overlap(): void
    {
        $score = app(ArticleLibraryService::class)->originalityScore($this->material(), $this->material());

        $this->assertSame(1.0, $score['overlap']);
        $this->assertGreaterThanOrEqual(100, $score['longest_run']);
        $this->assertNotSame('', $score['sample']);
    }

    public function test_one_copied_twelve_word_sentence_fails_on_longest_run_even_though_overlap_is_small(): void
    {
        $svc      = app(ArticleLibraryService::class);
        $copied   = 'The Deeds Registries Amendment Bill will change how transfers are lodged in every province.'; // 14 words
        $material = $this->material() . ' ' . $copied;
        $brief    = $this->original() . ' ' . $copied;

        $score = $svc->originalityScore($brief, $material);

        $this->assertLessThan(0.15, $score['overlap']);
        $this->assertGreaterThanOrEqual(12, $score['longest_run']);
        $this->assertFalse($svc->isOriginal($score, $svc->originalitySettings()));
    }

    public function test_a_genuine_rewrite_with_the_same_names_and_figures_passes(): void
    {
        $svc      = app(ArticleLibraryService::class);
        $material = 'Cape Town buyers paid R2.1 million on average last quarter, according to the Deeds Registries Amendment Bill briefing. '
            . 'Transfer times are expected to shorten once the new rules take effect.';
        $brief    = 'Property in Cape Town is changing hands at about R2.1 million, and anyone planning to sell should know that the '
            . 'Deeds Registries Amendment Bill is meant to speed up registrations. Agents can tell clients to expect quicker handovers '
            . 'once the legislation is in force, while reminding them to check timelines with their conveyancer.';

        $score = $svc->originalityScore($brief, $material);

        $this->assertLessThan(0.15, $score['overlap']);
        $this->assertLessThan(12, $score['longest_run']);
        $this->assertTrue($svc->isOriginal($score, $svc->originalitySettings()));
    }

    public function test_punctuation_and_capitals_cannot_hide_a_copy(): void
    {
        $score = app(ArticleLibraryService::class)->originalityScore(
            strtoupper(str_replace(['.', ','], ['!', ' —'], $this->material())),
            $this->material(),
        );

        $this->assertSame(1.0, $score['overlap']);
    }

    // ── The pipeline ─────────────────────────────────────────────────────

    public function test_a_verbatim_brief_is_retried_once_then_skipped_logged_and_nothing_is_stored(): void
    {
        Log::spy();
        $this->world($this->material(), [$this->material()]); // the model copies, both times

        $result = app(ArticleLibraryService::class)->run();

        $this->assertCount(2, $this->briefPrompts, 'one attempt + one retry');
        $this->assertStringContainsString('Your previous attempt repeated these words from the material', $this->briefPrompts[1]);
        $this->assertStringNotContainsString('Your previous attempt', $this->briefPrompts[0]);
        $this->assertSame(0, LibraryArticle::withTrashed()->count());
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped_originality']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => ($c['reason'] ?? null) === 'originality')->atLeast()->twice();

        // Not tried again on the next run…
        $this->briefPrompts = [];
        app(ArticleLibraryService::class)->run();
        $this->assertCount(0, $this->briefPrompts);

        // …unless the feed text for that item changes.
        $this->world($this->material() . ' A new development was announced on Friday.', [$this->original()]);
        $result = app(ArticleLibraryService::class)->run();
        $this->assertSame(1, $result['created']);
    }

    public function test_a_copy_that_is_rewritten_on_the_retry_is_stored_with_both_attempts_paid_for(): void
    {
        $this->world($this->material(), [$this->material(), $this->original()]);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertCount(2, $this->briefPrompts);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped_originality']);
        $a = LibraryArticle::sole();
        $this->assertLessThan(0.15, (float) $a->originality_overlap);
        $this->assertStringNotContainsString('Your previous attempt', $a->body);
    }

    public function test_an_accepted_brief_stores_its_overlap_and_longest_run(): void
    {
        $this->world($this->material(), [$this->original()]);

        app(ArticleLibraryService::class)->run();

        $a = LibraryArticle::sole();
        $this->assertNotNull($a->originality_overlap);
        $this->assertNotNull($a->originality_longest_run);
        $this->assertSame(
            app(ArticleLibraryService::class)->originalityScore($a->body, $this->material())['longest_run'],
            (int) $a->originality_longest_run,
        );
        $this->assertCount(1, $this->briefPrompts, 'no retry when the first attempt is original');
    }

    public function test_retries_can_be_set_to_zero(): void
    {
        DevSetting::set(ArticleLibraryService::KEY_ORIG_RETRIES, '0');
        $this->world($this->material(), [$this->material()]);

        $result = app(ArticleLibraryService::class)->run();

        $this->assertCount(1, $this->briefPrompts);
        $this->assertSame(1, $result['skipped_originality']);
        $this->assertSame(0, LibraryArticle::count());
    }

    // ── Existing rows ────────────────────────────────────────────────────

    public function test_rescore_hides_copied_briefs_scores_the_rest_and_never_deletes(): void
    {
        $this->onlySource();
        $bad  = $this->poolAndArticle('https://news.test/bad', $this->material());
        $good = $this->poolAndArticle('https://news.test/good', $this->original());
        $orphan = LibraryArticle::create([
            'source_id' => ArticleSource::value('id'), 'source_name' => 'Prop News', 'source_url' => 'https://news.test/none',
            'source_url_hash' => hash('sha256', 'https://news.test/none'), 'title' => 'No pool row', 'body' => $this->original(),
            'status' => LibraryArticle::STATUS_READY,
        ]);

        $this->assertSame(0, Artisan::call('article-library:rescore'));

        $this->assertSame(LibraryArticle::STATUS_HIDDEN, $bad->fresh()->status);
        $this->assertSame(LibraryArticle::STATUS_READY, $good->fresh()->status);
        $this->assertNotNull($good->fresh()->originality_overlap);
        $this->assertNull($orphan->fresh()->originality_overlap);
        $this->assertSame(LibraryArticle::STATUS_READY, $orphan->fresh()->status);
        $this->assertSame(3, LibraryArticle::withTrashed()->count());
    }

    // ── Owner screens ────────────────────────────────────────────────────

    public function test_the_articles_page_shows_the_originality_column_and_the_settings_save(): void
    {
        $this->onlySource();
        $this->poolAndArticle('https://news.test/x', $this->original(), ['originality_overlap' => 0.04, 'originality_longest_run' => 7]);
        $this->poolAndArticle('https://news.test/old', $this->original());
        $owner = $this->owner();

        $html = $this->actingAs($owner)->get(route('admin.article-library.articles'))->assertOk()->getContent();
        $this->assertStringContainsString('Overlap 4% · 7 words', $html);

        $this->actingAs($owner)->get(route('admin.article-library.articles', ['sort' => 'originality']))->assertOk();
        $this->actingAs($owner)->get(route('admin.article-library.settings'))->assertOk()->assertSee('Original wording');

        $this->actingAs($owner)->put(route('admin.article-library.settings.update'), [
            'schedule' => 'off', 'max_per_run' => 10,
            'originality_max_overlap_pct' => 20, 'originality_max_run_words' => 15, 'originality_retries' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertEquals(
            ['max_overlap' => 0.2, 'max_run_words' => 15, 'retries' => 2],
            app(ArticleLibraryService::class)->originalitySettings(),
        );

        // A post that does not carry the new fields leaves them alone.
        $this->actingAs($owner)->put(route('admin.article-library.settings.update'), ['schedule' => 'off', 'max_per_run' => 10])->assertSessionHasNoErrors();
        $this->assertSame(15, app(ArticleLibraryService::class)->originalitySettings()['max_run_words']);

        $this->actingAs($owner)->put(route('admin.article-library.settings.update'), [
            'schedule' => 'off', 'max_per_run' => 10, 'originality_max_run_words' => 1,
        ])->assertSessionHasErrors('originality_max_run_words');
    }

    public function test_defaults_come_from_config_until_the_owner_sets_them(): void
    {
        $this->assertEquals(['max_overlap' => 0.15, 'max_run_words' => 12, 'retries' => 1], app(ArticleLibraryService::class)->originalitySettings());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** A ~150-word feed body: distinct sentences, so it can be copied whole or in part. */
    private function material(): string
    {
        return 'Transfer duty thresholds were reviewed this week and sellers in coastal towns are watching the outcome closely. '
            . 'Mortgage approvals rose for a third month as lenders reported steadier demand from first time buyers. '
            . 'Estate agents on the South Coast said viewings picked up after the repo rate announcement calmed nervous households. '
            . 'Rental vacancies in Durban fell slightly while asking prices for family homes in Umhlanga held firm over the quarter. '
            . 'Conveyancers warned that registration queues at the deeds office remain long and urged clients to submit documents early. '
            . 'Municipal valuations will be updated next year, which could shift rates bills for thousands of homeowners across the province. '
            . 'Industry bodies called for clearer guidance on levies charged by sectional title schemes before the next financial year begins.';
    }

    /** ~150 words that say something else entirely. */
    private function original(): string
    {
        $p = 'Anyone thinking of listing a home this season should ask a qualified professional what costs will apply before agreeing a price. '
            . 'Clear photographs, a tidy garden and an honest description usually matter more to buyers than small cosmetic upgrades. '
            . 'Sellers who understand their own timeline can negotiate calmly and avoid being rushed into a weaker offer. ';

        return trim($p . "\n\n" . $p);
    }

    /** One source, one feed item whose full text is `$content`, and a fake model that answers with `$briefs` in turn. */
    private function world(string $content, array $briefs): void
    {
        $this->onlySource();
        $this->briefPrompts = [];
        $this->briefs       = $briefs;
        $feed = '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel><title>T</title>'
            . '<item><title>Property market update</title><link>https://news.test/a1</link><description>Market summary.</description>'
            . '<pubDate>' . now()->toRfc2822String() . '</pubDate><content:encoded><![CDATA[' . $content . ']]></content:encoded></item></channel></rss>';
        $fast = config('services.anthropic.models.fast');

        Http::fake(function ($request) use ($feed, $fast) {
            if (str_contains($request->url(), 'api.anthropic.com')) {
                $body = json_decode($request->body(), true);
                if (($body['model'] ?? '') === $fast) {
                    return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['keep' => [1]])]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]], 200);
                }
                $this->briefPrompts[] = (string) ($body['messages'][0]['content'] ?? '');
                $brief = $this->briefs[min(count($this->briefPrompts), count($this->briefs)) - 1];

                return Http::response(['content' => [['type' => 'text', 'text' => json_encode([
                    'title' => 'A brief', 'excerpt' => 'Summary.', 'brief' => $brief, 'tags' => ['property'],
                ])]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 450]], 200);
            }

            return $request->url() === 'https://feeds.test/prop' ? Http::response($feed, 200) : Http::response('nf', 404);
        });
    }

    private function onlySource(): void
    {
        ArticleSource::withTrashed()->forceDelete();
        ArticleSource::create(['name' => 'Prop News', 'url' => 'https://feeds.test/prop']);
    }

    /** @param array<string, mixed> $extra */
    private function poolAndArticle(string $url, string $body, array $extra = []): LibraryArticle
    {
        $hash = hash('sha256', $url);
        ArticlePool::create(['source' => 'Prop News', 'title' => 'Property market update', 'url' => $url, 'url_hash' => $hash, 'snippet' => $this->material()]);

        return LibraryArticle::create($extra + [
            'source_id' => ArticleSource::value('id'), 'source_name' => 'Prop News', 'source_url' => $url, 'source_url_hash' => $hash,
            'title' => 'Brief ' . substr($hash, 0, 6), 'body' => $body, 'status' => LibraryArticle::STATUS_READY,
            'generated_at' => now(), 'cost_zar' => 0.3,
        ]);
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
