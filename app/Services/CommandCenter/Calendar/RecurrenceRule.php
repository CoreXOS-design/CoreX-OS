<?php

namespace App\Services\CommandCenter\Calendar;

use Carbon\Carbon;

/**
 * A small, self-contained RRULE (RFC-5545 subset) value object for the calendar.
 *
 * Supports exactly the frequencies the recurrence UI offers — DAILY / WEEKLY /
 * MONTHLY — plus INTERVAL and an END condition (COUNT or UNTIL). We hand-roll
 * this rather than pull an RRULE package: the supported set is small, bounded,
 * and stored in the existing calendar_events.recurrence_rule column verbatim, so
 * a dependency would be overkill.
 *
 *   FREQ=WEEKLY;INTERVAL=1               → never-ending weekly
 *   FREQ=DAILY;INTERVAL=2;COUNT=10       → every 2 days, 10 times
 *   FREQ=MONTHLY;INTERVAL=1;UNTIL=20261231 → monthly until 31 Dec 2026
 */
class RecurrenceRule
{
    public const FREQ_DAILY   = 'DAILY';
    public const FREQ_WEEKLY  = 'WEEKLY';
    public const FREQ_MONTHLY = 'MONTHLY';

    public const FREQUENCIES = [self::FREQ_DAILY, self::FREQ_WEEKLY, self::FREQ_MONTHLY];

    public function __construct(
        public readonly string $freq,
        public readonly int $interval = 1,
        public readonly ?int $count = null,
        public readonly ?Carbon $until = null,
    ) {}

    /** Parse a stored RRULE string. Returns null for anything unsupported/blank. */
    public static function parse(?string $rule): ?self
    {
        if (!$rule) {
            return null;
        }
        $parts = [];
        foreach (explode(';', trim($rule)) as $seg) {
            $seg = trim($seg);
            if ($seg === '' || !str_contains($seg, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $seg, 2);
            $parts[strtoupper(trim($k))] = trim($v);
        }

        $freq = strtoupper($parts['FREQ'] ?? '');
        if (!in_array($freq, self::FREQUENCIES, true)) {
            return null;
        }

        $interval = max(1, (int) ($parts['INTERVAL'] ?? 1));
        $count    = isset($parts['COUNT']) ? max(1, (int) $parts['COUNT']) : null;

        $until = null;
        if (!empty($parts['UNTIL'])) {
            try {
                // Accept YYYYMMDD or YYYYMMDDTHHMMSSZ; take the date, treat as end-of-day.
                $raw = preg_replace('/[^0-9]/', '', $parts['UNTIL']);
                $until = Carbon::createFromFormat('Ymd', substr($raw, 0, 8))->endOfDay();
            } catch (\Throwable $e) {
                $until = null;
            }
        }

        return new self($freq, $interval, $count, $until);
    }

    /**
     * Build an RRULE string from validated form inputs.
     *
     * @param string      $freq      DAILY|WEEKLY|MONTHLY
     * @param int         $interval  >= 1
     * @param string      $endType   never|until|count
     * @param string|null $untilDate Y-m-d (when endType=until)
     * @param int|null    $count     (when endType=count)
     */
    public static function build(string $freq, int $interval, string $endType, ?string $untilDate, ?int $count): ?string
    {
        $freq = strtoupper($freq);
        if (!in_array($freq, self::FREQUENCIES, true)) {
            return null;
        }
        $rule = 'FREQ=' . $freq . ';INTERVAL=' . max(1, $interval);
        if ($endType === 'count' && $count) {
            $rule .= ';COUNT=' . max(1, (int) $count);
        } elseif ($endType === 'until' && $untilDate) {
            try {
                $rule .= ';UNTIL=' . Carbon::parse($untilDate)->format('Ymd');
            } catch (\Throwable $e) {
                // ignore malformed date → never-ending
            }
        }
        return $rule;
    }

    /** Advance a cursor to the next occurrence start. */
    public function advance(Carbon $cursor): Carbon
    {
        return match ($this->freq) {
            self::FREQ_DAILY   => $cursor->copy()->addDays($this->interval),
            self::FREQ_WEEKLY  => $cursor->copy()->addWeeks($this->interval),
            // No-overflow so 31 Jan → 28/29 Feb rather than skipping to March.
            self::FREQ_MONTHLY => $cursor->copy()->addMonthsNoOverflow($this->interval),
            default            => $cursor->copy()->addDays($this->interval),
        };
    }

    /** Human label for the panel ("Every 2 weeks", "Weekly", "Monthly"). */
    public function humanLabel(): string
    {
        $unit = match ($this->freq) {
            self::FREQ_DAILY   => 'day',
            self::FREQ_WEEKLY  => 'week',
            self::FREQ_MONTHLY => 'month',
            default            => 'day',
        };
        $base = $this->interval === 1
            ? ucfirst($unit === 'day' ? 'daily' : ($unit === 'week' ? 'weekly' : 'monthly'))
            : 'Every ' . $this->interval . ' ' . $unit . 's';
        if ($this->count) {
            $base .= ', ' . $this->count . ' times';
        } elseif ($this->until) {
            $base .= ', until ' . $this->until->format('j M Y');
        }
        return $base;
    }
}
