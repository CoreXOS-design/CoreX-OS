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
 *   others        = no monthly target exists (Q9) - the bar shows the actual only, no colour
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
            $should = $this->shouldBe($key, $agent, $day, $track);
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
