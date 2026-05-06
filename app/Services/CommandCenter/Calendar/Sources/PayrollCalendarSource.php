<?php

namespace App\Services\CommandCenter\Calendar\Sources;

use App\Contracts\CalendarSourceContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lights up 7 payroll-domain event classes.
 *
 * One stored source: payroll_runs.pay_date (non-finalised runs only).
 * Six computed SA statutory deadlines with deterministic synthetic source_ids.
 *
 * payroll_runs has agency_id but no branch_id.
 * Computed events have null agency/branch (agency-wide statutory obligations).
 */
class PayrollCalendarSource implements CalendarSourceContract
{
    private const ACTIVE_RUN_STATUSES = ['draft', 'processing', 'pending', 'open'];
    private const MONTHLY_LOOKAHEAD = 3;

    public function name(): string
    {
        return 'PayrollCalendarSource';
    }

    public function syncAll(): Collection
    {
        return collect()
            ->merge($this->payrollRuns())
            ->merge($this->monthlySarsEmp201())
            ->merge($this->monthlyUif())
            ->merge($this->monthlySdl())
            ->merge($this->biannualEmp501())
            ->merge($this->annualTaxYearEnd())
            ->merge($this->annualIrp5Deadline());
    }

    private function payrollRuns(): Collection
    {
        return DB::table('payroll_runs')
            ->whereNull('deleted_at')
            ->whereNotNull('pay_date')
            ->whereIn('status', self::ACTIVE_RUN_STATUSES)
            ->select('id', 'pay_date', 'agency_id', 'status', 'run_number')
            ->get()
            ->map(fn ($r) => [
                'event_type'  => 'payroll',
                'category'    => 'payroll_run',
                'title'       => "Payroll run #{$r->run_number} — pay date",
                'event_date'  => Carbon::parse($r->pay_date)->startOfDay(),
                'source_type' => \App\Models\Payroll\PayrollRun::class,
                'source_id'   => $r->id,
                'user_id'     => null,
                'agency_id'   => $r->agency_id,
                'branch_id'   => null,
            ]);
    }

    // ── Computed monthly: 7th of next N months ──

    private function monthlySarsEmp201(): Collection
    {
        return $this->emitMonthly('sars_emp201', 'SARS EMP201 due', 7);
    }

    private function monthlyUif(): Collection
    {
        return $this->emitMonthly('uif_declaration', 'UIF declaration due', 7);
    }

    private function monthlySdl(): Collection
    {
        return $this->emitMonthly('sdl_submission', 'SDL submission due', 7);
    }

    // ── Computed biannual: 31 May + 31 Oct ──

    private function biannualEmp501(): Collection
    {
        $events = collect();
        $year = now()->year;

        foreach ([$year, $year + 1] as $y) {
            foreach ([[5, 31], [10, 31]] as [$month, $day]) {
                $date = Carbon::create($y, $month, $day)->startOfDay();
                if ($date->isBefore(now()->subDays(60))) continue;
                if ($date->isAfter(now()->addDays(365))) continue;

                $events->push($this->syntheticEvent('sars_emp501', 'SARS EMP501 reconciliation due', $date));
            }
        }

        return $events;
    }

    // ── Computed annual: 28 Feb ──

    private function annualTaxYearEnd(): Collection
    {
        $events = collect();
        $year = now()->year;

        foreach ([$year, $year + 1] as $y) {
            $date = Carbon::create($y, 2, 28)->startOfDay();
            if ($date->isBefore(now()->subDays(30))) continue;
            if ($date->isAfter(now()->addDays(400))) continue;

            $events->push($this->syntheticEvent('tax_year_end', 'Tax year end', $date));
        }

        return $events;
    }

    // ── Computed annual: 31 May (IRP5 deadline) ──

    private function annualIrp5Deadline(): Collection
    {
        $events = collect();
        $year = now()->year;

        foreach ([$year, $year + 1] as $y) {
            $date = Carbon::create($y, 5, 31)->startOfDay();
            if ($date->isBefore(now()->subDays(30))) continue;
            if ($date->isAfter(now()->addDays(400))) continue;

            $events->push($this->syntheticEvent('irp5_deadline', 'IRP5 issue deadline', $date));
        }

        return $events;
    }

    // ── Helpers ──

    private function emitMonthly(string $eventClass, string $title, int $dayOfMonth): Collection
    {
        $events = collect();
        $cursor = now()->startOfMonth();

        for ($i = 0; $i <= self::MONTHLY_LOOKAHEAD; $i++) {
            $month = $cursor->copy()->addMonths($i);
            $date = Carbon::create($month->year, $month->month, $dayOfMonth)->startOfDay();

            if ($date->isBefore(now()->subDays(7))) continue;

            $events->push($this->syntheticEvent($eventClass, $title, $date));
        }

        return $events;
    }

    /**
     * Build a synthetic computed event payload.
     * source_id = crc32(event_class|ISO_date) — deterministic, stable across runs.
     */
    private function syntheticEvent(string $eventClass, string $title, Carbon $date): array
    {
        $key = $eventClass . '|' . $date->toDateString();
        $sourceId = abs(crc32($key));

        return [
            'event_type'  => 'payroll',
            'category'    => $eventClass,
            'title'       => $title,
            'event_date'  => $date,
            'source_type' => 'synthetic:payroll',
            'source_id'   => $sourceId,
            'user_id'     => null,
            'agency_id'   => null,
            'branch_id'   => null,
        ];
    }
}
