<?php

namespace App\Services\RoadToSold;

use App\Models\User;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * AT-454 - the summary bar: per step, where the agent should be by today for the month against where
 * they are. Spec: .ai/specs/road-to-sold.md section 10.6.
 *
 * Actuals come from DrilldownService::count() - the same query as the rows behind the figure.
 * Should-be-by-today:
 *   calls_made    = WorkdayService::monthTargetToDate() (weekday targets, holidays and leave carry none)
 *   listings_live = the agent's Agency Tracker listings target for the month x the share of the month's
 *                   target days that have passed (calendar days when the agent has no call target)
 *   others        = the agency's recipe target for the step when it set one (evaluations booked / done, mandates signed, listings live without an
 *                   Agency Tracker target), turned into a month figure by the working days and then pro-rated by today (v4 23.13); with no target
 *                   at all the bar shows the actual only, no colour (Q9)
 * Colour: SettingsService::statusForPct() (green on/ahead, amber under the green line, red under the red line).
 * Steps shown and their order: SettingsService::summarySteps() (each switchable by the agency).
 */
class SummaryBarService
{
    public const STEP_METRIC = [
        'calls_made'        => 'calls_made',
        'conversations'     => 'conversations',
        'evaluations_booked' => 'evaluations_booked',
        'evaluations_done'   => 'evaluations_done',
        'mandates_signed'   => 'mandates_signed',
        'listings_live'     => 'listings_live',
    ];

    public function __construct(
        private readonly DrilldownService $drill,
        private readonly WorkdayService $workdays,
        private readonly RecipeProgressService $recipe,
    ) {
    }

    /**
     * @return array{month:string, steps:array<int,array<string,mixed>>}
     */
    public function forAgent(User $agent, CarbonInterface|string $date, string $track = 'sales'): array
    {
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $day = CarbonImmutable::parse($date)->startOfDay();
        $period = new Period($day->startOfMonth(), $day->endOfDay(), 'This month so far', 'this_month');
        $settings = SettingsService::forAgency($agencyId, $track);
        $ids = $agent->dataIdentityIds();

        // the recipe's task targets, read once: they fill in "should be" for the steps that have no monthly target of their own
        $tasks = (array) ($this->recipe->forAgent($agent, $track, $day->isToday() ? now() : $day->endOfDay())['tasks'] ?? []);

        $steps = [];
        foreach ($settings->summarySteps() as $step) {
            if (empty($step['enabled'])) {
                continue;
            }
            $key = $step['key'];
            if (! isset(self::STEP_METRIC[$key])) {
                continue;
            }
            $actual = $this->drill->count(self::STEP_METRIC[$key], $ids, $period, $agencyId, $track);
            $should = $this->shouldBe($key, $agent, $day, $track) ?? $this->recipeShouldBe($key, $tasks, $agent, $day, $track);
            $pct = $should !== null && $should > 0 ? round($actual / $should * 100, 1) : null;

            $steps[] = [
                'key'       => $key,
                'label'     => $step['label'],
                'metric'    => self::STEP_METRIC[$key],
                'actual'    => $actual,
                'should_be' => $should,
                'pct'       => $pct,
                'status'    => $pct === null ? 'none' : $settings->statusForPct($pct),
            ];
        }

        return ['month' => $day->format('F Y'), 'steps' => $steps];
    }

    /**
     * The month figure the agency's recipe implies for a step, pro-rated to today: a daily target x the month's working days, a weekly one x working days / 5,
     * a monthly one as it stands; then the share of the month's working days that have passed (today included). Null when no task of the step has a target.
     *
     * @param  array<int,array<string,mixed>>  $tasks  RecipeProgressService::forAgent()['tasks']
     */
    private function recipeShouldBe(string $key, array $tasks, User $agent, CarbonImmutable $day, string $track): ?float
    {
        $keys = (array) (config('road-to-sold-belt.summary_tasks')[$key] ?? []);
        $rows = array_filter($tasks, fn ($t) => in_array($t['key'], $keys, true) && (int) $t['target'] > 0);
        if ($rows === []) {
            return null;
        }
        $total = 0;
        $passed = 0;
        for ($d = $day->startOfMonth(); $d->month === $day->month; $d = $d->addDay()) {
            if ($this->workdays->isWorkday($agent, $d, $track)) {
                $total++;
                $d->lte($day) && $passed++;
            }
        }
        if ($total === 0) {
            return null;
        }
        $month = 0.0;
        foreach ($rows as $t) {
            $month += match ($t['unit']) {
                'day' => $t['target'] * $total,
                'week' => $t['target'] * $total / 5,
                'month' => $t['target'],
                default => 0,
            };
        }

        return $month > 0 ? round($month * $passed / $total, 1) : null;
    }

    private function shouldBe(string $key, User $agent, CarbonImmutable $day, string $track): int|float|null
    {
        if ($key === 'calls_made') {
            return $this->workdays->monthTargetToDate($agent, $day, $track);
        }

        if ($key === 'listings_live') {
            $target = (int) DB::table('targets')->where('user_id', $agent->ownershipUserId())
                ->where('period', $day->format('Y-m'))->whereNull('deleted_at')->value('listings_target');
            if ($target <= 0) {
                return null;
            }
            $monthTarget = $this->workdays->monthTarget($agent, $day, $track);
            $share = $monthTarget > 0
                ? $this->workdays->monthTargetToDate($agent, $day, $track) / $monthTarget
                : $day->day / $day->daysInMonth;

            return round($target * $share, 1);
        }

        return null;
    }
}
