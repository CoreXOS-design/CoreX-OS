<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyTracker;

use App\View\Components\PeriodSelector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * agency-tracker-rehome.md step 1 — <x-period-selector>: renders with and without ?period=,
 * defaults to the current month, never lets a garbage value through, carries the page's other
 * query values, and offers a history dropdown when given the months that have figures.
 */
final class PeriodSelectorComponentTest extends TestCase
{
    private string $thisMonth;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 09:00:00');
        $this->thisMonth = '2026-10';
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function render(string $uri, string $tag = '<x-period-selector />'): string
    {
        $this->app->instance('request', \Illuminate\Http\Request::create($uri, 'GET'));
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');

        return Blade::render($tag);
    }

    public function test_renders_with_current_month_when_period_is_missing(): void
    {
        $html = $this->render('/somewhere');

        $this->assertStringContainsString('name="period"', $html);
        $this->assertStringContainsString('value="2026-10"', $html);
        $this->assertStringContainsString('period=2026-09', $html, 'previous-month link');
    }

    public function test_renders_with_the_requested_period(): void
    {
        $html = $this->render('/somewhere?period=2026-06');

        $this->assertStringContainsString('value="2026-06"', $html);
        $this->assertStringContainsString('period=2026-05', $html);
        $this->assertStringContainsString('period=2026-07', $html, 'next-month link');
    }

    public function test_next_link_is_disabled_on_the_current_month(): void
    {
        $html = $this->render('/somewhere?period=2026-10');

        $this->assertStringNotContainsString('title="Next month"', $html);
        $this->assertStringContainsString('This is the latest month', $html);
    }

    public function test_a_garbage_period_falls_back_to_the_current_month(): void
    {
        foreach (['garbage', '2026-13', '2026-00', '26-10', '2026-1', '2026-10-05', '', "2026-10'\"><script>"] as $bad) {
            $html = $this->render('/somewhere?' . http_build_query(['period' => $bad]));

            $this->assertStringContainsString('value="2026-10"', $html, "bad value [$bad]");
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    public function test_period_array_in_the_query_is_absorbed(): void
    {
        $html = $this->render('/somewhere?period[]=2026-05');

        $this->assertStringContainsString('value="2026-10"', $html);
    }

    public function test_other_query_values_are_carried_in_hidden_inputs_and_page_is_dropped(): void
    {
        $html = $this->render('/somewhere?period=2026-06&branch=3&page=4&filter[status]=active');

        $this->assertStringContainsString('<input type="hidden" name="branch" value="3">', $html);
        $this->assertStringContainsString('<input type="hidden" name="filter[status]" value="active">', $html);
        $this->assertStringNotContainsString('name="page"', $html);
        $this->assertStringNotContainsString('type="hidden" name="period"', $html);
    }

    public function test_history_months_render_as_a_dropdown_newest_first_with_this_month_always_offered(): void
    {
        $html = $this->render('/somewhere?period=2026-08', '<x-period-selector :months="[\'2026-06\', \'2026-08\', \'junk\']" />');

        $this->assertStringContainsString('<select', $html);
        $this->assertStringNotContainsString('type="month"', $html);
        $this->assertStringContainsString('October 2026 (this month)', $html);
        $this->assertStringContainsString('<option value="2026-08" selected>August 2026</option>', $html);
        $this->assertStringNotContainsString('junk', $html);
        $this->assertLessThan(strpos($html, 'value="2026-06"'), strpos($html, 'value="2026-10"'), 'newest first');
    }

    public function test_a_selected_month_outside_the_history_is_still_shown(): void
    {
        $html = $this->render('/somewhere?period=2025-01', '<x-period-selector :months="[\'2026-08\']" />');

        $this->assertStringContainsString('<option value="2025-01" selected>January 2025</option>', $html);
    }

    public function test_empty_history_still_offers_the_current_month(): void
    {
        $html = $this->render('/somewhere', '<x-period-selector :months="[]" />');

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('October 2026 (this month)', $html);
    }

    public function test_custom_name_and_label(): void
    {
        $html = $this->render('/somewhere?month=2026-03', '<x-period-selector name="month" label="Statement month" />');

        $this->assertStringContainsString('name="month"', $html);
        $this->assertStringContainsString('value="2026-03"', $html);
        $this->assertStringContainsString('Statement month', $html);
    }

    public function test_keep_values_travel_with_the_month_and_override_the_url(): void
    {
        $html = $this->render('/somewhere?sub=old&period=2026-06', '<x-period-selector :keep="[\'sub\' => \'targets\']" />');

        $this->assertStringContainsString('<input type="hidden" name="sub" value="targets">', $html);
        $this->assertStringNotContainsString('value="old"', $html);
        $this->assertStringContainsString('sub=targets', $html, 'previous / next links keep the panel');
    }

    public function test_controllers_resolve_the_same_way(): void
    {
        $this->assertSame('2026-06', PeriodSelector::monthFrom('2026-06'));
        $this->assertSame('2026-10', PeriodSelector::monthFrom(null));
        $this->assertSame('2026-10', PeriodSelector::monthFrom('nonsense'));
        $this->assertSame('2026-10', PeriodSelector::monthFrom(['2026-06']));
    }
}
