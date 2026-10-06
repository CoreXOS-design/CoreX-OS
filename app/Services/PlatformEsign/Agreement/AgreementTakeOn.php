<?php

namespace App\Services\PlatformEsign\Agreement;

use Carbon\Carbon;

/**
 * The take-on month (spec §11.19) — set by RR on the send form, never by the agency. THE one place the agreement's dates are derived:
 *   agreement start date = the 1st of the take-on month
 *   billing start / first debit = the 1st of the FOLLOWING month (the take-on month itself is the free take-on month)
 * e.g. take-on October 2026 → starts 1 October, billing from 1 November; take-on December 2026 → billing from 1 January 2027.
 */
class AgreementTakeOn
{
    /** Document fields RR sets and the recipient cannot touch once a take-on month is chosen. */
    public const FIELDS = ['start_date' => 'start_date', 'm_first_payment' => 'billing_start', 'm_day' => 'collection_day', 'm_amount' => 'monthly_fee'];

    /** Debit orders run on the 1st (the wording already says "on or about the 1st day of the month"). */
    public const COLLECTION_DAY = '1';

    public static function valid(?string $month, ?Carbon $now = null): bool
    {
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return false;
        }

        return self::first($month)->gte(($now ?? now())->copy()->startOfMonth()); // the current month is allowed, a past month is not
    }

    /** @return array{month:string,start_date:string,billing_start:string} ISO dates */
    public static function derive(string $month): array
    {
        $first = self::first($month);

        return [
            'month' => $first->format('Y-m'),
            'start_date' => $first->toDateString(),
            'billing_start' => $first->copy()->addMonthNoOverflow()->startOfMonth()->toDateString(),
        ];
    }

    /** The two document values for a stored take-on month: ['start_date' => ISO, 'm_first_payment' => ISO]. @return array<string,string> */
    public static function values(string $month): array
    {
        $d = self::derive($month);

        return ['start_date' => $d['start_date'], 'm_first_payment' => $d['billing_start']];
    }

    /** "October 2026" */
    public static function label(string $month): string
    {
        return self::first($month)->format('F Y');
    }

    /** Choices for the send form: this month and the following $count − 1 months. @return array<int,array{value:string,label:string,start:string,billing:string}> */
    public static function options(int $count = 18, ?Carbon $now = null): array
    {
        $out = [];
        $m = ($now ?? now())->copy()->startOfMonth();
        for ($i = 0; $i < $count; $i++, $m->addMonthNoOverflow()) {
            $d = self::derive($m->format('Y-m'));
            $out[] = ['value' => $d['month'], 'label' => $m->format('F Y'), 'start' => Carbon::parse($d['start_date'])->format('j F Y'), 'billing' => Carbon::parse($d['billing_start'])->format('j F Y')];
        }

        return $out;
    }

    private static function first(string $month): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $month . '-01')->startOfDay();
    }
}
