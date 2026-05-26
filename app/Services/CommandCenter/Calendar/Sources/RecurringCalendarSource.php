<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Lights up 2 agency-wide recurring computed event classes:
 *   ppra_trust_audit  — annual on 30 June
 *   salary_review     — annual on 1 March
 *
 * office_closure (#38) is deferred — its is_active flag is false and
 * the Tier 2 leave module will manage office closure events via stored dates.
 *
 * Synthetic source_ids: source_type = 'synthetic:recurring',
 * source_id = abs(crc32(event_class + year)). Stable across runs.
 */
class RecurringCalendarSource implements CalendarSourceContract
{
    private const YEAR_LOOKAHEAD = 1;
    private const PAST_GRACE_DAYS = 60;

    public function name(): string
    {
        return 'RecurringCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->ppraTrustAudit())
            ->merge($this->salaryReview());
    }

    private function ppraTrustAudit(): Collection
    {
        return $this->emitAnnualOnDate('ppra_trust_audit', 'PPRA trust audit report due', 6, 30);
    }

    private function salaryReview(): Collection
    {
        return $this->emitAnnualOnDate('salary_review', 'Annual salary review', 3, 1);
    }

    private function emitAnnualOnDate(string $eventClass, string $title, int $month, int $day): Collection
    {
        $events = collect();
        $today = now()->startOfDay();

        for ($i = 0; $i <= self::YEAR_LOOKAHEAD; $i++) {
            $year = $today->year + $i;
            $date = Carbon::create($year, $month, $day)->startOfDay();

            if ($date->isBefore($today->copy()->subDays(self::PAST_GRACE_DAYS))) {
                continue;
            }
            if ($date->isAfter($today->copy()->addDays(400))) {
                continue;
            }

            $events->push($this->syntheticEvent($eventClass, $title, $date));
        }

        return $events;
    }

    private function syntheticEvent(string $eventClass, string $title, Carbon $date): array
    {
        $key = $eventClass . '|' . $date->year;
        $sourceId = abs(crc32($key));

        return [
            'event_type'  => 'recurring',
            'category'    => $eventClass,
            'title'       => $title,
            'event_date'  => $date,
            'source_type' => 'synthetic:recurring',
            'source_id'   => $sourceId,
            'user_id'     => null,
            'agency_id'   => null,
            'branch_id'   => null,
        ];
    }
}
