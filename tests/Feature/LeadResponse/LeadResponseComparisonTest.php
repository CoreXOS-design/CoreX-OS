<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Services\LeadResponse\LeadResponseService;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;

/**
 * Period comparison on the Lead Response report (Johan, 2026-10-07) — "so the admin can monitor if the training etc
 * made an impact on the response time". Same selector, modes, delta line and phrase as the Buyers and Performance &
 * ROI reports; for response TIME lower is better. "Now" is Wed 2026-10-07; the comparison window used is September.
 */
final class LeadResponseComparisonTest extends LeadResponseTestCase
{
    private const COMPARE = ['compare' => 'custom', 'compare_start' => '2026-09-01', 'compare_end' => '2026-09-30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantReports();

        // October (current): Anna 10 min, Anna 3 h (late), Anna waiting; Ben waiting; Cara 5 min.
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaFast', 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:10:00']);
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaSlow', 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 12:00:00']);
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaWait', 'portal' => \App\Models\PortalLead::PORTAL_PP, 'received' => '2026-10-07 08:00:00']);
        $this->lead(['by' => $this->agentB, 'name' => 'BenWait', 'portal' => \App\Models\PortalLead::PORTAL_WEBSITE, 'received' => '2026-10-07 11:50:00']);
        $this->lead(['by' => $this->agentC, 'name' => 'CaraFast', 'received' => '2026-10-06 09:00:00', 'responded' => '2026-10-06 09:05:00']);

        // September (comparison): Anna only — one in 5 min, one after 5 h (late). Ben and Cara had no leads then.
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaPrevFast', 'received' => '2026-09-10 09:00:00', 'responded' => '2026-09-10 09:05:00']);
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaPrevSlow', 'received' => '2026-09-12 09:00:00', 'responded' => '2026-09-12 14:00:00']);
    }

    private function page(array $q = [], $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->get(route('lead-response-report.index', array_merge(['period' => 'this_month', 'scope' => 'agency'], $q)));
    }

    private function periods(): array
    {
        $tz = 'Africa/Johannesburg';

        return [
            new Period(CarbonImmutable::parse('2026-10-01', $tz)->startOfDay(), CarbonImmutable::parse('2026-10-31', $tz)->endOfDay(), 'October', 'custom'),
            new Period(CarbonImmutable::parse('2026-09-01', $tz)->startOfDay(), CarbonImmutable::parse('2026-09-30', $tz)->endOfDay(), 'September', 'custom'),
        ];
    }

    // ── The maths: direction of good, no-data ─────────────────────────────

    public function test_response_time_down_is_good_and_late_or_waiting_up_is_bad(): void
    {
        $svc = app(LeadResponseService::class);
        [$oct, $sep] = $this->periods();
        $ids = [$this->agentA->id, $this->agentB->id, $this->agentC->id];

        $c = $svc->compare($svc->report($this->agency->id, $oct, $ids), $svc->report($this->agency->id, $sep, $ids))['company'];

        // Sept avg = (5 + 300) / 2 = 153 (rounded); Oct avg = (10 + 180 + 5) / 3 = 65 — faster, so GOOD.
        $this->assertSame(153.0, $c['avg']['previous']);
        $this->assertSame(65.0, $c['avg']['value']);
        $this->assertLessThan(0, $c['avg']['delta']);
        $this->assertTrue($c['avg']['good'], 'faster is good');
        $this->assertTrue($c['median']['good'], 'a lower median is good');
        $this->assertSame('lower_is_better', $c['avg']['direction']);

        $this->assertSame(1.0, $c['in_target']['previous']);
        $this->assertTrue($c['in_target']['good'], 'more answered in target is good');
        $this->assertNull($c['late']['good'], 'late 1 → 1 is no change, neither good nor bad');
        $this->assertSame(2.0, $c['waiting']['delta']);
        $this->assertFalse($c['waiting']['good'], 'more leads still waiting is bad');
        $this->assertNull($c['received']['good'], 'more leads received is neither good nor bad');
        $this->assertSame(3.0, $c['received']['delta']);
    }

    public function test_slower_response_time_is_bad(): void
    {
        $svc = app(LeadResponseService::class);
        [$oct, $sep] = $this->periods();
        $ids = [$this->agentA->id];

        // Swap the two periods: from fast September to slow… compare September (current) against October (previous).
        $c = $svc->compare($svc->report($this->agency->id, $sep, $ids), $svc->report($this->agency->id, $oct, $ids))['company'];

        $this->assertGreaterThan(0, $c['avg']['delta'], '153 vs 65 — slower');
        $this->assertFalse($c['avg']['good'], 'slower is bad');
    }

    public function test_no_data_in_the_comparison_period_is_no_data_never_zero_or_infinity(): void
    {
        $svc = app(LeadResponseService::class);
        [$oct, $sep] = $this->periods();
        $ids = [$this->agentA->id, $this->agentB->id, $this->agentC->id];

        $c = $svc->compare($svc->report($this->agency->id, $oct, $ids), $svc->report($this->agency->id, $sep, $ids));

        foreach (['received', 'in_target', 'late', 'waiting', 'avg', 'median'] as $key) {
            $this->assertSame(['no_data' => true], $c['agents'][$this->agentC->id][$key], "Cara had no leads in September: {$key}");
            $this->assertSame(['no_data' => true], $c['agents'][$this->agentB->id][$key]);
        }
        // a whole comparison period with nothing in it
        $empty = new Period(CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2025-01-31'), 'Jan 2025', 'custom');
        $none = $svc->compare($svc->report($this->agency->id, $oct, $ids), $svc->report($this->agency->id, $empty, $ids))['company'];
        foreach ($none as $key => $shape) {
            $this->assertSame(['no_data' => true], $shape, $key);
        }
        // an agent who has leads in both: a real comparison
        $this->assertArrayNotHasKey('no_data', $c['agents'][$this->agentA->id]['received']);
    }

    public function test_an_average_needs_an_answered_lead_in_both_periods(): void
    {
        $svc = app(LeadResponseService::class);
        [$oct, $sep] = $this->periods();
        // Ben: October has one waiting lead only (no average); September nothing.
        $c = $svc->compare($svc->report($this->agency->id, $oct, [$this->agentB->id]), $svc->report($this->agency->id, $sep, [$this->agentB->id]))['company'];
        $this->assertSame(['no_data' => true], $c['avg']);
    }

    // ── The page ──────────────────────────────────────────────────────────

    public function test_off_is_exactly_the_page_it_was(): void
    {
        $html = $this->page()->assertOk()->getContent();

        $this->assertStringNotContainsString('Comparing to', $html);
        $this->assertStringNotContainsString('class="report-delta report-delta-', $html);
        $this->assertStringContainsString('name="compare"', $html, 'the shared selector offers the Compare to control');
    }

    public function test_the_page_uses_the_shared_selector_with_the_same_compare_options(): void
    {
        $html = $this->page()->assertOk()->getContent();

        foreach (['Off', 'Previous', 'Same period last year', 'Custom'] as $label) {
            $this->assertStringContainsString($label, $html, "compare option {$label}");
        }
        foreach (['previous', 'same_last_year', 'custom'] as $mode) {
            $this->assertStringContainsString('value="' . $mode . '"', $html);
        }
    }

    public function test_every_figure_and_the_agent_and_source_tables_carry_a_delta_and_faster_reads_green_down(): void
    {
        $html = $this->page(self::COMPARE)->assertOk()->getContent();

        $this->assertStringContainsString('Comparing to', $html);
        $this->assertStringContainsString('2026-09-01 → 2026-09-30', $html);
        $this->assertStringContainsString('lower is better', $html);
        $this->assertStringContainsString('vs 2026-09-01 → 2026-09-30', $html, 'the phrase every delta carries');

        // average response: 153 → 65 min = 1 h 28 min faster: arrow down, green (good)
        $this->assertMatchesRegularExpression('/report-delta report-delta-good">\s*▼\s*-1 h 28 min/u', $html);
        // not yet contacted went UP by 2 — arrow up, red (bad)
        $this->assertMatchesRegularExpression('/report-delta report-delta-bad">\s*▲\s*\+2/u', $html);
        // leads received up by 3 — arrow up, neutral colour
        $this->assertMatchesRegularExpression('/report-delta report-delta-neutral">\s*▲\s*\+3/u', $html);
        // agents / sources with nothing to compare against say so
        $this->assertStringContainsString('no data vs 2026-09-01 → 2026-09-30', $html);
    }

    public function test_previous_period_and_same_period_last_year_modes_work(): void
    {
        $this->page(['compare' => 'previous'])->assertOk()->assertSee('vs previous period');
        $this->page(['compare' => 'same_last_year'])->assertOk()->assertSee('vs same period last year');
    }

    public function test_unequal_lengths_are_stated_plainly_and_a_bad_custom_range_fails_soft(): void
    {
        $this->page(['compare' => 'custom', 'compare_start' => '2026-09-01', 'compare_end' => '2026-09-07'])
            ->assertOk()->assertSee('Unequal-length ranges');

        $this->page(['compare' => 'custom', 'compare_start' => '2026-09-30', 'compare_end' => '2026-09-01'])
            ->assertOk();
        $this->page(['compare' => 'custom'])->assertOk();
        $this->page(['compare' => 'nonsense'])->assertOk()->assertDontSee('Comparing to');
    }

    public function test_comparison_follows_the_viewers_scope_never_a_wider_cohort(): void
    {
        // Anna sees only herself — and only her own leads in BOTH periods.
        $html = $this->page(self::COMPARE, $this->agentA)->assertOk()->getContent();
        $this->assertStringContainsString('Anna Agent', $html);
        $this->assertStringNotContainsString('Ben Agent', $html);
        $this->assertStringNotContainsString('Cara Agent', $html);
        // Her figures: Oct received 3 (Anna x3) vs Sept 2 → +1, not the agency's +3.
        $this->assertMatchesRegularExpression('/report-delta report-delta-neutral">\s*▲\s*\+1 \(\+50%\)\s+vs/u', $html);
        $this->assertDoesNotMatchRegularExpression('/report-delta report-delta-neutral">\s*▲\s*\+3 \(\+150%\)/u', $html);

        // Branch manager: branch 1 only.
        $branch = $this->page(self::COMPARE, $this->manager)->assertOk()->getContent();
        $this->assertStringNotContainsString('Cara Agent', $branch);
    }

    public function test_drill_downs_are_unchanged_by_comparison_and_ignore_a_bad_compare_range(): void
    {
        $plain = $this->actingAs($this->admin)->getJson(route('lead-response-report.drilldown', ['period' => 'this_month', 'subtype' => 'received', 'scope' => 'agency']))->assertOk()->json();
        $with = $this->actingAs($this->admin)->getJson(route('lead-response-report.drilldown', array_merge(['period' => 'this_month', 'subtype' => 'received', 'scope' => 'agency'], ['compare' => 'custom', 'compare_start' => 'bad'])))->assertOk()->json();

        $this->assertSame($plain['total'], $with['total']);
        $this->assertSame(5, $plain['total'], 'the popup lists the CURRENT period\'s leads');
    }

    public function test_print_and_pdf_carry_the_comparison(): void
    {
        $print = $this->actingAs($this->admin)->get(route('lead-response-report.print', array_merge(['scope' => 'agency', 'period' => 'this_month'], self::COMPARE)))->assertOk()->getContent();

        $this->assertStringContainsString('Compared to: 2026-09-01 → 2026-09-30', $print);
        $this->assertStringContainsString('lower response time is better', $print);
        $this->assertStringContainsString('▼-1 h 28 min', $print, 'average response, faster');
        $this->assertStringContainsString('class="d up"', $print);
        $this->assertStringContainsString('class="d down"', $print, 'waiting up is bad');
        $this->assertStringContainsString('— no data vs 2026-09-01 → 2026-09-30', $print);

        $off = $this->actingAs($this->admin)->get(route('lead-response-report.print', ['scope' => 'agency', 'period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Compared to', $off);
        $this->assertStringNotContainsString('class="d ', $off);

        $this->actingAs($this->admin)->get(route('lead-response-report.pdf', array_merge(['scope' => 'agency', 'period' => 'this_month'], self::COMPARE)))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_the_shared_delta_component_still_renders_every_other_report_exactly_as_before(): void
    {
        $plain = \Illuminate\Support\Facades\Blade::render('<x-performance-delta :c="$c" phrase="vs previous period" />', ['c' => \App\Services\Performance\PeriodComparison::compute(12, 10)]);
        $this->assertStringContainsString('report-delta-good', $plain);
        $this->assertStringContainsString('+2 (+20%) vs previous period', preg_replace('/\s+/', ' ', $plain));

        $money = \Illuminate\Support\Facades\Blade::render('<x-performance-delta :c="$c" phrase="x" :money="true" />', ['c' => \App\Services\Performance\PeriodComparison::compute(1500, 1000)]);
        $this->assertStringContainsString('R 500', $money);
    }
}
