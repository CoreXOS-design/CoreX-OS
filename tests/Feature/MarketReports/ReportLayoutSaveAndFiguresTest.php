<?php

declare(strict_types=1);

namespace Tests\Feature\MarketReports;

use App\Jobs\MarketReports\ParseMarketReportJob;
use App\Jobs\MarketReports\SpotCheckMarketReportJob;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportAiRead;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Models\MarketReports\MarketReportType;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\MarketReports\LearnedLayoutReader;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Report recogniser, 22 Oct 2026 (mic-complete-spec §8.9 "An AI read always leaves a layout"): a 12-column
 * "Sales (Days on Market)" report whose never-listed sales leave four cells blank was read by the AI at 0.91 but
 * its layout was never saved (the learner counted cells; the rows did not all have the same count). The learner now
 * also learns a table by what is IN each row; the AI-read row in the queue offers View figures and Approve layout;
 * and the team's message links straight to that row.
 *
 * The AI is faked at the HTTP edge. The fixtures and the canned AI answers come from
 * tests/Fixtures/market_reports/make-days-on-market-reports.php.
 */
final class ReportLayoutSaveAndFiguresTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $owner;
    private User $agent;
    /** @var string[] */
    private array $staged = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.anthropic.api_key', 'test-key');
        config()->set('services.anthropic.enabled', true);
        config()->set('services.anthropic.usd_to_zar', 16.5);
        Bus::fake([SpotCheckMarketReportJob::class]);
        Notification::fake();

        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true, 'can_be_deleted' => false, 'sort_order' => 1]);
        $this->agencyId = (int) DB::table('agencies')->insertGetId(['name' => 'Alpha Realty', 'slug' => 'alpha-' . Str::random(6), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $this->owner = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'super_admin', 'name' => 'Owner One']);
        $this->agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'name' => 'Agent Anne']);

        foreach ([['other', GenericFallbackParser::class], ['learned_layout', LearnedLayoutParser::class]] as [$key, $class]) {
            MarketReportType::query()->firstOrCreate(['key' => $key], ['display_name' => $key, 'parser_class' => $class, 'expected_fields_json' => json_encode([]), 'auto_approve' => 0]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->staged as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    // ── 1. the report that broke: AI reads it, the layout IS saved, the next one is free ──

    public function test_a_report_with_blank_cells_is_read_by_ai_and_its_layout_is_saved(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('a'))]);

        $report = $this->upload('days_on_market_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        $this->assertSame('ai', $report->read_method);
        $this->assertEqualsWithDelta(0.91, (float) $report->ai_read_confidence, 0.001);
        $this->assertSame(26, DB::table('market_report_comp_rows')->where('market_report_id', $report->id)->where('row_type', 'comp')->count());

        $layout = MarketReportLearnedLayout::query()->where('learned_from_report_id', $report->id)->first();
        $this->assertNotNull($layout, 'an AI read that passes the self-test must save a layout');
        $this->assertSame('learned', $layout->status);
        $this->assertGreaterThanOrEqual(0.9, (float) $layout->selftest_score);
        $this->assertSame(2, (int) $layout->mapping['version'], 'the table was learned by what is in the row, not by counting cells');
        $this->assertNull($report->ai_read_note, 'nothing to explain: a layout was saved');
    }

    public function test_the_next_report_in_that_layout_is_read_free_and_matches_what_the_ai_would_have_read(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('a'))]);
        ParseMarketReportJob::dispatchSync($this->upload('days_on_market_a.pdf')->id);
        Http::assertSentCount(1);

        $second = $this->upload('days_on_market_b.pdf');
        ParseMarketReportJob::dispatchSync($second->id);
        $second->refresh();

        Http::assertSentCount(1); // B cost nothing
        $this->assertSame('learned', $second->read_method);
        $this->assertSame(14, (int) DB::table('market_report_comp_rows')->where('market_report_id', $second->id)->where('row_type', 'comp')->count());

        // every value of every row, including the sales that were never listed (blank list date / price / days)
        $rows = DB::table('market_report_comp_rows')->where('market_report_id', $second->id)->orderBy('row_index')->orderBy('id')->get();
        foreach ($this->truth('b')['comps'] as $i => $want) {
            $got = $rows[$i];
            $this->assertSame($want['address'], $got->address, "row $i address");
            $this->assertSame($want['sale_price'], (int) $got->sale_price, "row $i sale price");
            $this->assertSame($want['sale_date'], substr((string) $got->sale_date, 0, 10), "row $i sale date");
            $this->assertSame($want['extent_m2'], (int) $got->extent_m2, "row $i extent");
            $this->assertSame($want['r_per_m2'], (int) $got->r_per_m2, "row $i r/m2");
            $this->assertSame($want['list_price'] ?? null, $got->list_price === null ? null : (int) $got->list_price, "row $i list price");
            $this->assertSame($want['days_on_market'] ?? null, $got->days_on_market === null ? null : (int) $got->days_on_market, "row $i days on market");
        }
        $this->assertSame($this->truth('b')['valuation']['average'], (int) DB::table('market_data_points')->where('report_id', $second->id)->where('metric_key', 'cma_value_average')->value('metric_value_numeric'));
    }

    public function test_addresses_that_wrap_onto_a_second_line_are_read_whole(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('wrapped'))]);
        $report = $this->upload('days_on_market_wrapped.pdf');
        ParseMarketReportJob::dispatchSync($report->id);

        $layout = MarketReportLearnedLayout::query()->where('learned_from_report_id', $report->id)->first();
        $this->assertNotNull($layout, 'a wrapped-address table is learnable');
        $this->assertTrue((bool) $layout->mapping['tables'][0]['wrap']);

        // read it back through the layout alone (a second, identical-shaped file): the full address, not the first line
        $text = app(LearnedLayoutParser::class)->textFor(base_path('tests/Fixtures/market_reports/days_on_market_wrapped.pdf'));
        $read = app(LearnedLayoutReader::class)->read((array) $layout->mapping, $text);
        foreach ($this->truth('wrapped')['comps'] as $i => $want) {
            $this->assertSame($want['address'], $read['comps'][$i]['address'], "row $i address");
            $this->assertSame($want['sale_price'], $read['comps'][$i]['sale_price']);
        }
    }

    public function test_the_old_cell_based_learning_is_still_what_a_regular_table_gets(): void
    {
        // fake_suburb_report_a is a plain 5-column table: it must keep its version-1 (cell) mapping, untouched.
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->fakeSuburbRead())]);
        $report = $this->upload('fake_suburb_report_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);

        $layout = MarketReportLearnedLayout::query()->where('learned_from_report_id', $report->id)->first();
        $this->assertNotNull($layout);
        $this->assertSame(1, (int) $layout->mapping['version']);
        $this->assertArrayHasKey('columns', $layout->mapping['tables'][0]);
    }

    // ── 2. a read that cannot be turned into a layout says so, in plain words ──

    public function test_when_no_layout_can_be_saved_the_row_and_the_team_message_say_why(): void
    {
        $bad = $this->truth('a');
        foreach ($bad['comps'] as $i => $c) {
            $bad['comps'][$i]['sale_price'] = $c['sale_price'] + 7;   // a "read" that is not what the PDF prints
        }
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($bad)]);

        $report = $this->upload('days_on_market_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $report->refresh();

        $this->assertSame('ai', $report->read_method);
        $this->assertSame(0, MarketReportLearnedLayout::query()->count());
        $this->assertStringStartsWith('No layout was saved from this read:', (string) $report->ai_read_note);

        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n)
            => $n->eventKey === 'market_report.read_by_ai_team'
            && str_contains($n->body, 'No layout was saved from this read')
            && str_contains($n->body, 'will need the AI again')
            && !str_contains($n->body, 'add this layout permanently'));
    }

    // ── 3. the team's message goes straight to the row; the row can approve the layout ──

    public function test_the_team_message_links_to_the_report_row_and_says_a_layout_was_saved(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('a'))]);
        $report = $this->upload('days_on_market_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);

        Notification::assertSentTo($this->owner, PillarEventNotification::class, fn ($n)
            => $n->eventKey === 'market_report.read_by_ai_team'
            && $n->actionUrl === route('market-intelligence.reports.recogniser.queue', ['focus' => $report->id], false)
            && str_contains($n->body, 'A layout was saved from this read')
            && str_contains($n->body, 'approve the layout'));
        // the agent keeps the plain wording and the link to the report itself
        Notification::assertSentTo($this->agent, PillarEventNotification::class, fn ($n)
            => $n->actionUrl === route('market-intelligence.reports.show', $report->id, false));
    }

    public function test_the_queue_row_offers_view_figures_and_approve_layout_and_a_focus_link_shows_just_that_row(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('a'))]);
        $report = $this->upload('days_on_market_a.pdf', $this->agent->id);
        ParseMarketReportJob::dispatchSync($report->id);
        $other = $this->upload('not_a_market_report.pdf');
        $other->forceFill(['read_method' => 'unread', 'file_name' => 'someone-elses.pdf'])->save();
        $layout = MarketReportLearnedLayout::query()->firstOrFail();

        $page = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', ['focus' => $report->id]));
        $page->assertOk()
            ->assertSee('Showing one report')
            ->assertSee('days_on_market_a.pdf')
            ->assertDontSee('someone-elses.pdf')
            ->assertSee('View figures')
            ->assertSee('Approve layout')
            ->assertSee(route('market-intelligence.reports.recogniser.figures', $report->id), false)
            ->assertSee(route('market-intelligence.reports.recogniser.layouts.approve', $layout->id), false);

        // the whole queue still lists both, and the could-not-read row has neither button
        $all = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue'));
        $all->assertOk()->assertSee('days_on_market_a.pdf')->assertSee('someone-elses.pdf');
        $this->assertSame(1, substr_count($all->getContent(), 'View figures'));
    }

    public function test_the_approve_layout_button_is_the_same_action_as_on_learned_layouts(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('a'))]);
        $report = $this->upload('days_on_market_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $layout = MarketReportLearnedLayout::query()->firstOrFail();
        Bus::fake([SpotCheckMarketReportJob::class, \App\Jobs\MarketReports\ResolveLayoutCasesJob::class]);

        $this->actingAs($this->owner)->post(route('market-intelligence.reports.recogniser.layouts.approve', $layout->id))->assertRedirect();

        $this->assertSame('approved', $layout->refresh()->status);
        Bus::assertDispatched(\App\Jobs\MarketReports\ResolveLayoutCasesJob::class, fn ($j) => true);
        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', ['focus' => $report->id]))
            ->assertOk()->assertSee('Layout approved')->assertDontSee('>Approve layout<', false);
    }

    public function test_a_focus_link_to_a_report_that_has_left_the_queue_says_so(): void
    {
        $report = $this->upload('not_a_market_report.pdf');
        $report->forceFill(['read_method' => 'learned'])->save();

        $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.queue', ['focus' => $report->id]))
            ->assertOk()->assertSee('no longer in the queue');
    }

    // ── 4. View figures: what was read, beside the PDF ──

    public function test_view_figures_shows_what_was_read_beside_the_pdf_and_is_owner_only(): void
    {
        Http::fake(['https://api.anthropic.com/*' => $this->aiAnswer($this->truth('a'))]);
        $report = $this->upload('days_on_market_a.pdf');
        ParseMarketReportJob::dispatchSync($report->id);
        $first = $this->truth('a')['comps'][0];

        $page = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.figures', $report->id));
        $page->assertOk()
            ->assertSee('Figures read from this report')
            ->assertSee($first['address'])
            ->assertSee('R ' . number_format($first['sale_price'], 0, '.', ' '))
            ->assertSee('Days on market')
            ->assertSee('Approve layout')
            ->assertSee(route('market-intelligence.reports.recogniser.pdf', $report->id), false)
            ->assertSee('cma value average', false);

        $this->actingAs($this->agent)->get(route('market-intelligence.reports.recogniser.figures', $report->id))->assertForbidden();
        $this->actingAs($this->agent)->get(route('market-intelligence.reports.recogniser.pdf', $report->id))->assertForbidden();

        $pdf = $this->actingAs($this->owner)->get(route('market-intelligence.reports.recogniser.pdf', $report->id));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $pdf->headers->get('Content-Disposition'));
    }

    // ── 5. the pieces: typed tokens and alignment ──

    public function test_an_amount_never_swallows_a_three_digit_count_printed_right_after_it(): void
    {
        $atoms = LearnedLayoutReader::atoms('2026-02-02 R 2 005 000 106    -8%   R 3 002');
        $t = LearnedLayoutReader::typedTokens($atoms);
        $this->assertSame(['date', 'num', 'num', 'pct', 'num'], array_column($t, 'type'));
        $this->assertSame(['2026-02-02', 'R 2 005 000', '106', '-8%', 'R 3 002'], array_column($t, 'text'));
    }

    public function test_blank_columns_are_skipped_by_position_not_guessed(): void
    {
        $entries = [
            ['type' => 'date', 'c' => 10, 'field' => 'sale_date'], ['type' => 'num', 'c' => 22, 'field' => 'sale_price'],
            ['type' => 'date', 'c' => 34], ['type' => 'num', 'c' => 46, 'field' => 'list_price'], ['type' => 'num', 'c' => 80, 'field' => 'r_per_m2'],
        ];
        // a never-listed sale: date, price, then only the R/m² at the far right
        $tokens = [['type' => 'date', 'text' => 'd', 'c' => 11.0], ['type' => 'num', 'text' => 'p', 'c' => 21.0], ['type' => 'num', 'text' => 'r', 'c' => 79.0]];
        $this->assertSame([0 => 0, 1 => 1, 2 => 4], LearnedLayoutReader::alignTokens($tokens, $entries));
        // more values than columns cannot be a row of this table
        $this->assertNull(LearnedLayoutReader::alignTokens(array_merge($tokens, $tokens, $tokens), $entries));
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> the canonical read an AI would return for the fixture */
    private function truth(string $which): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/market_reports/days_on_market_{$which}.read.json")), true);
    }

    /** What a good AI reading of fake_suburb_report_a.pdf looks like (the same canned answer the recogniser suite uses). */
    private function fakeSuburbRead(): array
    {
        return [
            'publisher' => 'Coastline Property Data', 'report_title' => 'Suburb Sales Report', 'suburb' => 'Southport', 'town' => null,
            'subject' => [], 'valuation' => ['lower' => 1450000, 'middle' => 1950000, 'upper' => 2600000],
            'comps' => [
                ['kind' => 'comp', 'address' => '12 Marine Drive',   'sale_date' => '2026-08-14', 'sale_price' => 1850000, 'extent_m2' => 620,  'r_per_m2' => 2984],
                ['kind' => 'comp', 'address' => '7 Albatross Close', 'sale_date' => '2026-07-02', 'sale_price' => 2275000, 'extent_m2' => 810,  'r_per_m2' => 2809],
                ['kind' => 'comp', 'address' => '41 Dolphin Street', 'sale_date' => '2026-05-29', 'sale_price' => 1495000, 'extent_m2' => 540,  'r_per_m2' => 2769],
                ['kind' => 'comp', 'address' => '3 Kingfisher Road', 'sale_date' => '2026-04-17', 'sale_price' => 2950000, 'extent_m2' => 1020, 'r_per_m2' => 2892],
                ['kind' => 'comp', 'address' => '88 Lagoon Avenue',  'sale_date' => '2026-03-06', 'sale_price' => 1720000, 'extent_m2' => 600,  'r_per_m2' => 2867],
                ['kind' => 'comp', 'address' => '19 Pelican Way',    'sale_date' => '2026-01-23', 'sale_price' => 2140000, 'extent_m2' => 735,  'r_per_m2' => 2912],
            ],
            'suburb_stats' => [
                ['metric' => 'median_price', 'year' => 2023, 'value' => 1620000], ['metric' => 'sales_count', 'year' => 2023, 'value' => 118], ['metric' => 'annual_change_pct', 'year' => 2023, 'value' => 4.2],
                ['metric' => 'median_price', 'year' => 2024, 'value' => 1745000], ['metric' => 'sales_count', 'year' => 2024, 'value' => 131], ['metric' => 'annual_change_pct', 'year' => 2024, 'value' => 7.7],
                ['metric' => 'median_price', 'year' => 2025, 'value' => 1890000], ['metric' => 'sales_count', 'year' => 2025, 'value' => 126], ['metric' => 'annual_change_pct', 'year' => 2025, 'value' => 8.3],
            ],
            'layout' => [
                'publisher_signature' => 'Coastline Property Data',
                'column_headings' => ['Property Address', 'Registered', 'Purchase Price', 'Erf Size', 'Price per m²', 'Year', 'Median Price', 'Transfers', 'Yearly Change'],
                'header_phrases' => ['Suburb Sales Report', 'Sold Properties', 'Suburb Price Trends', 'Indicative Value Range'],
            ],
            'confidence' => 0.92, 'notes' => null,
        ];
    }

    private function upload(string $fixture, ?int $uploader = null): MarketReport
    {
        $relative = "market-reports/test/{$this->agencyId}/" . Str::random(12) . '.pdf';
        $absolute = Storage::disk('local')->path($relative);
        @mkdir(dirname($absolute), 0777, true);
        copy(base_path('tests/Fixtures/market_reports/' . $fixture), $absolute);
        $this->staged[] = $absolute;

        return MarketReport::create([
            'agency_id' => $this->agencyId, 'uploaded_by_user_id' => $uploader ?? $this->agent->id, 'report_type_id' => null,
            'file_path' => $relative, 'file_name' => $fixture, 'file_hash' => hash_file('sha256', $absolute),
            'report_date' => now()->toDateString(), 'parse_status' => MarketReport::PARSE_PENDING,
            'spot_check_status' => MarketReport::SPOT_PENDING, 'data_points_count' => 0,
        ]);
    }

    private function aiAnswer(array $json)
    {
        return Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($json)]],
            'usage'   => ['input_tokens' => 9000, 'output_tokens' => 1800],
            'model'   => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
        ], 200);
    }
}
