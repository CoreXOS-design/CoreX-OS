<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\Leave\PublicHoliday;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * .ai/specs/lease-notices.md §5.3 - what the agent sees beside a notice: the lease's notice terms in force and the FLAGS that say
 * whether the notice fits the contract and the law. Flags never block by themselves - the agent decides - but accepting while a
 * warning is raised needs a note (LeaseNoticeService::accept). Nothing here is a number typed in code: the notice period is the
 * lease's own (else the agency's default), the CPA business days are the agency's setting (default 20).
 */
class LeaseNoticeReviewService
{
    public const SEV_WARN = 'warn';
    public const SEV_INFO = 'info';

    public function __construct(private readonly LeaseNoticeTermsService $terms)
    {
    }

    /**
     * The terms that apply to THIS lease: what it holds, else the agency's defaults.
     *
     * @return array{period:int,period_unit:string,period_text:string,earliest_notice_date:?string,earliest_termination_date:?string,
     *   early_allowed:bool,early_notice:?int,early_notice_unit:?string,early_notice_text:?string,penalty:?string,
     *   confirmed:bool,own_terms:bool,cpa_business_days:int,fixed_term:bool,lease_start:?string,lease_end:?string,month_to_month:bool}
     */
    public function termsInForce(Lease $lease): array
    {
        $agencyId = (int) $lease->agency_id;
        $stored = $this->terms->forLease($lease);
        $defaults = $this->terms->defaultsFor($agencyId, $lease->start_date);

        $period = (int) ($stored['notice_period'] ?: $defaults['notice_period']);
        $unit = (string) ($stored['notice_period_unit'] ?: $defaults['notice_period_unit']);
        $allowedValue = $stored['early_cancellation_allowed'] ?: $defaults['early_cancellation_allowed'];
        $early = $allowedValue !== 'no';
        $ecNotice = $stored['early_cancellation_notice'] ?: ($early ? $defaults['early_cancellation_notice'] : null);
        $ecUnit = $stored['early_cancellation_notice_unit'] ?: ($early ? $defaults['early_cancellation_notice_unit'] : null);

        $ownTerms = $this->terms->hasAny($lease);

        return [
            'period' => $period,
            'period_unit' => $unit,
            'period_text' => (string) $this->terms->periodText($period, $unit),
            'earliest_notice_date' => $stored['earliest_notice_date'] ?? $defaults['earliest_notice_date'],
            'earliest_termination_date' => $stored['earliest_termination_date'],
            'early_allowed' => $early,
            'early_notice' => $ecNotice ? (int) $ecNotice : null,
            'early_notice_unit' => $ecUnit ?: null,
            'early_notice_text' => $ecNotice ? $this->terms->periodText((int) $ecNotice, $ecUnit ?: $unit) : null,
            'penalty' => $stored['early_cancellation_penalty'] ?: ($early ? $defaults['early_cancellation_penalty'] : null),
            'confirmed' => $this->terms->isConfirmed($lease),
            'own_terms' => $ownTerms,
            'cpa_business_days' => LeaseSetting::cpaEarlyCancelBusinessDaysFor($agencyId),
            'fixed_term' => $lease->end_date !== null && ! $lease->is_month_to_month,
            'lease_start' => $lease->start_date?->toDateString(),
            'lease_end' => $lease->end_date?->toDateString(),
            'month_to_month' => (bool) $lease->is_month_to_month,
        ];
    }

    /**
     * The flags for a notice given on `$noticeDate` that ends the lease on `$endDate`.
     *
     * @return list<array{code:string,severity:string,title:string,detail:string,suggested_end_date:?string}>
     */
    public function flags(Lease $lease, string $givenBy, CarbonInterface $noticeDate, CarbonInterface $endDate): array
    {
        $t = $this->termsInForce($lease);
        $notice = Carbon::instance($noticeDate)->startOfDay();
        $end = Carbon::instance($endDate)->startOfDay();
        $today = Carbon::today();
        $flags = [];

        // ── wrong date ──────────────────────────────────────────────────────────
        if ($end->lt($today)) {
            $flags[] = $this->flag('end_in_past', self::SEV_WARN, 'The end date has already passed', 'The date the notice ends the lease (' . $this->d($end) . ') is in the past.');
        }
        if ($end->lte($notice)) {
            $flags[] = $this->flag('end_before_notice', self::SEV_WARN, 'The end date is not after the date notice was given', 'Notice was given on ' . $this->d($notice) . ' but ends the lease on ' . $this->d($end) . '.');
        }
        if ($notice->gt($today)) {
            $flags[] = $this->flag('notice_in_future', self::SEV_WARN, 'The notice is dated in the future', 'The date notice was given (' . $this->d($notice) . ') is after today.');
        }
        if ($t['lease_start'] && $notice->lt(Carbon::parse($t['lease_start']))) {
            $flags[] = $this->flag('notice_before_start', self::SEV_WARN, 'The notice is dated before the lease started', 'The lease starts on ' . $this->d(Carbon::parse($t['lease_start'])) . '.');
        }
        if ($end->gt($today->copy()->addYears(2))) {
            $flags[] = $this->flag('end_too_far', self::SEV_WARN, 'The end date is more than two years away', 'Check the year of ' . $this->d($end) . '.');
        }

        // ── the contract: notice given too early ───────────────────────────────
        if ($t['earliest_notice_date'] && $notice->lt(Carbon::parse($t['earliest_notice_date']))) {
            $flags[] = $this->flag('notice_too_early', self::SEV_WARN, 'Notice was given earlier than the contract allows', 'The lease says notice may not be given before ' . $this->d(Carbon::parse($t['earliest_notice_date'])) . '.');
        }

        // ── the contract: notice period ────────────────────────────────────────
        $fixedAndEarly = $t['fixed_term'] && $end->lt(Carbon::parse($t['lease_end']));
        $periodEnd = $this->terms->addPeriod($notice, $t['period'], $t['period_unit']);

        if ($givenBy === Lease::NOTICE_BY_LANDLORD && $fixedAndEarly) {
            $flags[] = $this->flag('landlord_ends_fixed_term', self::SEV_WARN, 'A landlord cannot end a fixed term early on notice alone',
                'The lease runs to ' . $this->d(Carbon::parse($t['lease_end'])) . ' and this notice ends it on ' . $this->d($end) . '. Unless the tenant is in breach, a fixed-term lease runs to its end date.',
                Carbon::parse($t['lease_end'])->toDateString());
        } elseif ($givenBy === Lease::NOTICE_BY_TENANT && $fixedAndEarly) {
            // ── CPA: a tenant cancelling a fixed term early ───────────────────
            $cpaEnd = $this->addBusinessDays($notice, $t['cpa_business_days']);
            $needed = $cpaEnd;
            $detail = 'The lease runs to ' . $this->d(Carbon::parse($t['lease_end'])) . '; the tenant is cancelling early. The Consumer Protection Act lets a tenant cancel a fixed-term lease on '
                . $t['cpa_business_days'] . ' business days\' written notice: from ' . $this->d($notice) . ' that is ' . $this->d($cpaEnd) . '.';
            if (! $t['early_allowed']) {
                $detail .= ' The lease itself says early cancellation is not allowed - check it against the Act before accepting.';
            } elseif ($t['early_notice']) {
                $contractEnd = $this->terms->addPeriod($notice, $t['early_notice'], $t['early_notice_unit'] ?: $t['period_unit']);
                $detail .= ' The lease asks for ' . $t['early_notice_text'] . ' (' . $this->d($contractEnd) . ').';
                if ($contractEnd->gt($needed)) {
                    $needed = $contractEnd;
                }
            }
            if ($t['penalty']) {
                $detail .= ' Early-cancellation penalty in the lease: ' . $t['penalty'];
            }
            $flags[] = $this->flag('cpa_early_cancellation', self::SEV_WARN, 'Tenant cancelling early - Consumer Protection Act applies', $detail, $needed->toDateString());
            if ($end->lt($cpaEnd)) {
                $flags[] = $this->flag('cpa_notice_too_short', self::SEV_WARN, 'Shorter than the ' . $t['cpa_business_days'] . ' business days the Act requires',
                    'Given ' . $this->d($notice) . ', ending ' . $this->d($end) . ' is fewer than ' . $t['cpa_business_days'] . ' business days. The earliest end date is ' . $this->d($cpaEnd) . '.', $cpaEnd->toDateString());
            }
            if ($t['early_allowed'] && $t['early_notice']) {
                $contractEnd = $this->terms->addPeriod($notice, $t['early_notice'], $t['early_notice_unit'] ?: $t['period_unit']);
                if ($end->lt($contractEnd)) {
                    $flags[] = $this->flag('contract_early_notice_short', self::SEV_WARN, 'Shorter than the early-cancellation notice in the lease',
                        'The lease asks for ' . $t['early_notice_text'] . ' to cancel early: the earliest end date is ' . $this->d($contractEnd) . '.', $contractEnd->toDateString());
                }
            }
        } elseif ($end->lt($periodEnd)) {
            // an ordinary notice (month-to-month, or ending the lease on/after its end date): the contract's notice period
            $flags[] = $this->flag('notice_period_short', self::SEV_WARN, 'Notice period is shorter than the contract allows',
                'The lease needs ' . $t['period_text'] . ' notice. Given ' . $this->d($notice) . ', the earliest end date is ' . $this->d($periodEnd) . ' - this notice ends it on ' . $this->d($end) . '.', $periodEnd->toDateString());
        }

        // ── the terms themselves ───────────────────────────────────────────────
        if (! $t['confirmed']) {
            $flags[] = $this->flag('terms_unconfirmed', self::SEV_INFO, 'The lease\'s notice terms have not been confirmed',
                ($t['own_terms'] ? 'They were not checked against the signed lease.' : 'The lease holds none of its own - the agency\'s defaults are used.') . ' Check the signed lease before relying on them.');
        }
        if (! $this->holidaysKnown($notice)) {
            $flags[] = $this->flag('holidays_not_loaded', self::SEV_INFO, 'Public holidays for ' . $notice->year . ' are not loaded', 'Business days were counted as weekdays only.');
        }

        return $flags;
    }

    /** Does any flag need the agent's explanation before the notice is accepted as it stands? */
    public function hasWarning(array $flags): bool
    {
        foreach ($flags as $f) {
            if (($f['severity'] ?? null) === self::SEV_WARN) {
                return true;
            }
        }

        return false;
    }

    /** The earliest end date the flags suggest (the latest of them - it satisfies every rule), or null. */
    public function suggestedEndDate(array $flags): ?string
    {
        $dates = array_filter(array_column($flags, 'suggested_end_date'));

        return $dates === [] ? null : max($dates);
    }

    /** Add business days: weekends and the South African public holidays in the leave module's table do not count. */
    public function addBusinessDays(CarbonInterface $from, int $days): Carbon
    {
        $date = Carbon::instance($from)->startOfDay();
        $holidays = PublicHoliday::forCountry('ZA')->between($date->copy()->addDay(), $date->copy()->addDays(max(60, $days * 3)))->pluck('holiday_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->all();
        $added = 0;
        while ($added < $days) {
            $date->addDay();
            if ($date->isWeekend() || in_array($date->toDateString(), $holidays, true)) {
                continue;
            }
            $added++;
        }

        return $date;
    }

    private function holidaysKnown(CarbonInterface $on): bool
    {
        return PublicHoliday::forCountry('ZA')->whereYear('holiday_date', $on->year)->exists();
    }

    private function has(array $flags, string $code): bool
    {
        return in_array($code, array_column($flags, 'code'), true);
    }

    private function d(CarbonInterface $date): string
    {
        return $date->format('j M Y');
    }

    /** @return array{code:string,severity:string,title:string,detail:string,suggested_end_date:?string} */
    private function flag(string $code, string $severity, string $title, string $detail, ?string $suggested = null): array
    {
        return ['code' => $code, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'suggested_end_date' => $suggested];
    }
}
