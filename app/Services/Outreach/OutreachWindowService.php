<?php

namespace App\Services\Outreach;

use App\Models\Agency;
use App\Services\Leave\PublicHolidayService;
use Carbon\Carbon;

/**
 * AT-117 §4a — the ONE canonical "is outreach sending allowed right now?" gate.
 *
 * Every outreach dispatch surface (contact inline, the Seller-Outreach composer,
 * the map launches, the MIC launches, and the future queue) calls THIS service —
 * none reimplements the time logic. It reads the agency-configurable send-window
 * (Agency::outreachSendWindow(), defaults legal but editable), evaluates against
 * the agency timezone, and honours the public-holiday-off flag via the existing
 * PublicHolidayService.
 *
 * Doctrine: this gates WHEN an agent may dispatch. WHO may be marketed to is a
 * separate concern (MarketingConsentService, §4b). Keep them separate.
 */
class OutreachWindowService
{
    private const DAY_KEYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    private const DAY_LABELS = [
        'mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu',
        'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun',
    ];

    public function __construct(private PublicHolidayService $holidays)
    {
    }

    /**
     * Is dispatch permitted at $at (default: now, in the agency timezone)?
     */
    public function isSendAllowed(Agency $agency, ?Carbon $at = null): bool
    {
        $tz = $agency->outreachTimezone();
        $at = $at ? $at->copy()->setTimezone($tz) : Carbon::now($tz);
        $window = $agency->outreachSendWindow();

        if ($this->isHolidayBlocked($window, $at)) {
            return false;
        }

        $cfg = $this->dayConfig($window, $at);
        if (empty($cfg['enabled'])) {
            return false;
        }

        $open = $this->timeOn($at, $cfg['start'] ?? null);
        $close = $this->timeOn($at, $cfg['end'] ?? null);
        if ($open === null || $close === null) {
            return false;
        }

        // Inclusive both ends: 08:00–20:00 permits a dispatch at 08:00 and at 20:00.
        return $at->betweenIncluded($open, $close);
    }

    /**
     * The next datetime (in the agency timezone) at which the window opens, scanning
     * forward up to 14 days. Returns the passed/current moment if it is already open.
     * Null only if no day is enabled at all (degenerate config).
     */
    public function nextOpensAt(Agency $agency, ?Carbon $from = null): ?Carbon
    {
        $tz = $agency->outreachTimezone();
        $from = $from ? $from->copy()->setTimezone($tz) : Carbon::now($tz);
        $window = $agency->outreachSendWindow();

        for ($i = 0; $i <= 14; $i++) {
            $day = $from->copy()->addDays($i);
            if ($this->isHolidayBlocked($window, $day)) {
                continue;
            }
            $cfg = $this->dayConfig($window, $day);
            if (empty($cfg['enabled'])) {
                continue;
            }
            $open = $this->timeOn($day, $cfg['start'] ?? null);
            $close = $this->timeOn($day, $cfg['end'] ?? null);
            if ($open === null || $close === null) {
                continue;
            }

            if ($i === 0) {
                if ($from->lt($open)) {
                    return $open;            // earlier today → opens at start
                }
                if ($from->lte($close)) {
                    return $from->copy();    // already open right now
                }
                continue;                    // after close → look at later days
            }
            return $open;
        }

        return null;
    }

    /**
     * Human-readable permitted-times summary, e.g.
     * "Mon–Fri 08:00–20:00, Sat 09:00–13:00". Consecutive days sharing identical
     * hours are collapsed into a range.
     */
    public function describeWindow(Agency $agency): string
    {
        $window = $agency->outreachSendWindow();
        $segments = [];
        $runStart = null;
        $runEnd = null;
        $runHours = null;

        $flush = function () use (&$segments, &$runStart, &$runEnd, &$runHours) {
            if ($runStart === null) {
                return;
            }
            $label = $runStart === $runEnd
                ? self::DAY_LABELS[$runStart]
                : self::DAY_LABELS[$runStart] . '–' . self::DAY_LABELS[$runEnd];
            $segments[] = $label . ' ' . $runHours;
            $runStart = $runEnd = $runHours = null;
        };

        foreach (self::DAY_KEYS as $day) {
            $cfg = $window[$day] ?? [];
            $hours = (!empty($cfg['enabled']) && !empty($cfg['start']) && !empty($cfg['end']))
                ? ($cfg['start'] . '–' . $cfg['end'])
                : null;
            if ($hours === null) {
                $flush();
                continue;
            }
            if ($runHours === $hours) {
                $runEnd = $day;             // extend the current run
            } else {
                $flush();
                $runStart = $runEnd = $day;
                $runHours = $hours;
            }
        }
        $flush();

        if (empty($segments)) {
            return 'no permitted send times configured';
        }
        return implode(', ', $segments);
    }

    /**
     * The full user-facing message shown when a dispatch is blocked out-of-window.
     */
    public function blockedMessage(Agency $agency, ?Carbon $at = null): string
    {
        $msg = 'Outreach sending is allowed ' . $this->describeWindow($agency) . '.';
        if (!empty($agency->outreachSendWindow()['public_holidays_off'])) {
            $msg .= ' Public holidays are off.';
        }
        $next = $this->nextOpensAt($agency, $at);
        if ($next) {
            $msg .= ' Next window opens ' . $next->format('D j M, H:i') . '.';
        }
        $msg .= " You can prepare this now and it'll be ready to send then.";
        return $msg;
    }

    private function dayConfig(array $window, Carbon $date): array
    {
        $key = self::DAY_KEYS[$date->dayOfWeekIso - 1] ?? 'mon';
        return $window[$key] ?? [];
    }

    private function isHolidayBlocked(array $window, Carbon $date): bool
    {
        if (empty($window['public_holidays_off'])) {
            return false;
        }
        return $this->holidays->isPublicHoliday($date->copy(), 'ZA');
    }

    /**
     * Build a Carbon for "HH:MM" on the given date, in the date's timezone.
     * Returns null for a missing/malformed time string (absorbed, never crashes).
     */
    private function timeOn(Carbon $date, ?string $hhmm): ?Carbon
    {
        if (!is_string($hhmm) || !preg_match('/^(\d{1,2}):(\d{2})$/', $hhmm, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }
        return $date->copy()->setTime($h, $min, 0);
    }
}
