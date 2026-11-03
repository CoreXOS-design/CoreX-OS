<?php

declare(strict_types=1);

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\User;
use App\Services\Leave\PublicHolidayService;
use App\Support\AppDate;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * road-to-sold.md 23.30: the time an appointment form opens with. Never a time that has already gone: it is the next whole hour from now, inside the
 * agent's working hours (the Command Center setting: the agent's own, or the agency's when the agency controls them), else the start of the next working
 * day (Monday to Friday, public holidays skipped). It is read in the agency's own timezone (AppDate::agencyTimezone), not the server's or the browser's.
 */
final class NextWorkingSlot
{
    private const FALLBACK_START = '08:00';
    private const FALLBACK_END = '17:00';

    /** @return array{start: Carbon, end: Carbon} both in the agency's timezone */
    public function for(User $user, ?CarbonInterface $now = null, int $minutes = 60): array
    {
        $agency = $user->effectiveAgencyId() ? \App\Models\Agency::query()->withoutGlobalScopes()->find($user->effectiveAgencyId()) : null;
        $tz = AppDate::agencyTimezone($agency);
        $now = ($now ? Carbon::instance($now) : Carbon::now())->setTimezone($tz);
        [$open, $close] = $this->hours($user);
        $minutes = max(15, $minutes);

        $slot = $now->copy()->addHour()->startOfHour();   // 14:24 -> 15:00; 15:00 sharp -> 16:00 (a slot starting now is already late when it is saved)
        $day = $slot->copy()->startOfDay();
        $opens = fn (Carbon $d): Carbon => $d->copy()->setTimeFromTimeString($open);
        $closes = fn (Carbon $d): Carbon => $d->copy()->setTimeFromTimeString($close);

        if ($this->isWorkingDay($day) && $slot->lt($opens($day))) {
            $slot = $opens($day);   // before the day opens: the first slot of the day
        }
        for ($i = 0; $i < 20 && (! $this->isWorkingDay($slot->copy()->startOfDay()) || $slot->copy()->addMinutes($minutes)->gt($closes($slot))); $i++) {
            $slot = $opens($slot->copy()->addDay()->startOfDay());   // after hours, a weekend or a holiday: the next working day's first slot
        }

        return ['start' => $slot, 'end' => $slot->copy()->addMinutes($minutes)];
    }

    /** @return array{0: string, 1: string} "H:i" opening and closing */
    private function hours(User $user): array
    {
        try {
            $s = UserDashboardSetting::getEffective($user);
            $open = substr((string) $s->working_hours_start, 0, 5);
            $close = substr((string) $s->working_hours_end, 0, 5);
        } catch (\Throwable) {
            $open = $close = '';
        }
        $ok = fn (string $t): bool => (bool) preg_match('/^\d{2}:\d{2}$/', $t);
        if (! $ok($open) || ! $ok($close) || $close <= $open) {
            return [self::FALLBACK_START, self::FALLBACK_END];
        }

        return [$open, $close];
    }

    private function isWorkingDay(Carbon $day): bool
    {
        if ($day->isWeekend()) {
            return false;
        }
        try {
            return ! app(PublicHolidayService::class)->isPublicHoliday($day);   // the holiday table is read by the calendar date alone
        } catch (\Throwable) {
            return true;   // no holiday table: a weekday is a working day
        }
    }
}
