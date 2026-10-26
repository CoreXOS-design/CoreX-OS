<?php

namespace App\Services\PlatformEsign\Agreement;

use Carbon\Carbon;

/**
 * The take-on month (spec §11.19) — set by RR on the send form, never by the agency. THE one place the agreement's dates are derived:
 *   agreement start date = the 1st of the take-on month
 *   billing start / first debit = the 1st of the month `free_months` after the take-on month (default 1: the take-on month itself is free)
 * e.g. take-on October 2026 → starts 1 October, billing from 1 November; take-on December 2026 → billing from 1 January 2027.
 *
 * The free months and the collection day are PLATFORM SETTINGS (AgreementSettings, defaults 1 and 1), frozen onto the agreement at send
 * (`rr_data.take_on_free_months` / `rr_data.collection_day`) so a later settings change never moves a document already sent. An agreement
 * sent before they existed has neither key and keeps the original behaviour (1 free month, collected on the 1st) — see params().
 */
class AgreementTakeOn
{
    /** Document fields RR sets and the recipient cannot touch once a take-on month is chosen. */
    public const FIELDS = ['start_date' => 'start_date', 'm_first_payment' => 'billing_start', 'm_day' => 'collection_day', 'm_amount' => 'monthly_fee'];

    /** What an agreement sent before the platform settings existed (no frozen values) behaves as: one free month, collected on the 1st. */
    public const LEGACY_FREE_MONTHS = 1;
    public const COLLECTION_DAY = '1';

    /**
     * The free months and collection day that govern ONE agreement: its own frozen values, else the legacy behaviour.
     *
     * @param array<string,mixed>|null $rr the agreement's rr_data
     * @return array{free_months:int,collection_day:int}
     */
    public static function params(?array $rr = null): array
    {
        $free = (int) ($rr['take_on_free_months'] ?? self::LEGACY_FREE_MONTHS);
        $day = (int) ($rr['collection_day'] ?? self::COLLECTION_DAY);

        return ['free_months' => max(1, min(12, $free)), 'collection_day' => max(1, min(28, $day))];
    }

    /** The values frozen onto a NEW agreement: the platform settings, with an optional per-agreement collection day. @return array{take_on_free_months:int,collection_day:int} */
    public static function freeze(?int $collectionDay = null): array
    {
        return ['take_on_free_months' => AgreementSettings::get('free_months'), 'collection_day' => $collectionDay ?? AgreementSettings::get('collection_day')];
    }

    public static function valid(?string $month, ?Carbon $now = null): bool
    {
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return false;
        }

        return self::first($month)->gte(($now ?? now())->copy()->startOfMonth()); // the current month is allowed, a past month is not
    }

    /**
     * True once the first collection date (the 1st of the month AFTER the take-on month) is today or already behind us — a document
     * signed now would carry a first debit that can no longer be collected as written. The current month is still fine until it ends
     * (send accepts it); on the 1st of the following month the take-on month has lapsed. Uses the app timezone (Africa/Johannesburg).
     */
    public static function lapsed(?string $month, ?Carbon $now = null, ?array $rr = null): bool
    {
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            return false; // no take-on month on this document: nothing to lapse
        }

        return self::derive($month, $rr)['billing_start'] <= ($now ?? now())->copy()->toDateString();
    }

    /** @return array{month:string,start_date:string,billing_start:string} ISO dates */
    public static function derive(string $month, ?array $rr = null): array
    {
        $first = self::first($month);
        $free = self::params($rr)['free_months'];

        return [
            'month' => $first->format('Y-m'),
            'start_date' => $first->toDateString(),
            'billing_start' => $first->copy()->addMonthsNoOverflow($free)->startOfMonth()->toDateString(),
        ];
    }

    /** The document values for a stored take-on month: start date, first payment date and the collection day. @return array<string,string> */
    public static function values(string $month, ?array $rr = null): array
    {
        $d = self::derive($month, $rr);

        return ['start_date' => $d['start_date'], 'm_first_payment' => $d['billing_start'], 'm_day' => (string) self::params($rr)['collection_day']];
    }

    /** The one-line summary the sticky header and the send form show, or null when there is no take-on month. */
    public static function summary(?array $rr): ?string
    {
        $m = (string) ($rr['take_on_month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $m)) {
            return null;
        }
        $d = self::derive($m, $rr);

        return 'Starts ' . self::label($m) . ' · first debit ' . Carbon::parse($d['billing_start'])->format('j F Y') . ' · collected on the ' . self::ordinal(self::params($rr)['collection_day']) . ' of each month';
    }

    /** 1 → "1st", 2 → "2nd", 11 → "11th", 23 → "23rd" */
    public static function ordinal(int $n): string
    {
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['1' => 'st', '2' => 'nd', '3' => 'rd'][(string) ($n % 10)] ?? 'th');

        return $n . $suffix;
    }

    /** "October 2026" */
    public static function label(string $month): string
    {
        return self::first($month)->format('F Y');
    }

    /** Choices for the send form: this month and the following $count − 1 months. @return array<int,array{value:string,label:string,start:string,billing:string}> */
    public static function options(int $count = 18, ?Carbon $now = null, ?array $rr = null): array
    {
        $out = [];
        $m = ($now ?? now())->copy()->startOfMonth();
        for ($i = 0; $i < $count; $i++, $m->addMonthNoOverflow()) {
            $d = self::derive($m->format('Y-m'), $rr);
            $out[] = ['value' => $d['month'], 'label' => $m->format('F Y'), 'start' => Carbon::parse($d['start_date'])->format('j F Y'), 'billing' => Carbon::parse($d['billing_start'])->format('j F Y')];
        }

        return $out;
    }

    private static function first(string $month): Carbon
    {
        return Carbon::createFromFormat('!Y-m-d', $month . '-01')->startOfDay();
    }
}
