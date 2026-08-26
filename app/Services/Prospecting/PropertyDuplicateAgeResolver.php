<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\SuggestedActionThresholds;
use Illuminate\Support\Carbon;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21).
 *
 * TWO INDEPENDENT HARD BLOCKERS, either one alone is enough (2 Venice Drive incident,
 * 2026-08-21 — Johan: "so 2 blockers - active, and we are not 7 days past
 * 2027/08/16?"):
 *   1. STATUS — isOnMarket() true, or draft. "Currently active" / "Currently a draft".
 *      Someone is actively working the property either way.
 *   2. MANDATE — expiry_date is genuinely still in the future, REGARDLESS OF STATUS.
 *      A status can be stale, wrong, or never updated; a future expiry_date is hard
 *      evidence a mandate may still be live. "Mandate runs to 16 Aug 2027". Evaluated
 *      directly off the property's OWN expiry_date, independent of which clock the
 *      per-status logic below would otherwise pick — a stale status_changed date can
 *      never mask an unexpired mandate. Deliberately narrow: a mandate that expired a
 *      FEW DAYS AGO is already the ordinary no_go band's job below (now made safe by
 *      daysAgo()'s signed/clamped arithmetic) — this blocker exists specifically for
 *      the one thing plain day-counting arithmetic can never catch on its own, a date
 *      that hasn't arrived yet.
 * Both reasons are collected and shown together when both apply — never just one,
 * silently hiding the other.
 *
 * THE NEGATIVE-AGE BUG this closes (2026-08-21 incident): the per-status clocks below
 * used to feed expiry_date straight into App\Support\HumanDiff::daysBetween(), which
 * is explicitly documented as "Absolute... Never negative" — it wraps everything in
 * abs(). A FUTURE expiry_date (mandate not yet due) produced a large POSITIVE day
 * count instead of a negative one, landing in auto_take by accident. Blocker #2 above
 * now intercepts every future expiry_date before any age is ever computed, and
 * daysAgo() below (used for the per-status clocks that remain) computes its own
 * signed, clamped value instead of calling that abs()-based helper — so a future date
 * can never again reach the banding arithmetic silently, by luck or otherwise.
 *
 * "THE AGE CLOCK DEPENDS ON THE STATUS" (once neither hard blocker fires):
 *   - expired: clock = expiry_date. That status is set BY expiry_date (see
 *     ExpireMandates), so it needs no fallback chain — confirmed 100% populated
 *     on live for expired-status properties.
 *   - every other off-market status (withdrawn, archived, cancelled, not_selling,
 *     sold, ...): clock = the most recent status_changed audit entry. Only 4.2% of
 *     live's off-market book has one (most arrived pre-dead via bulk import, like
 *     47 Howard) — falls back to expiry_date (99.98% coverage on that group), then
 *     created_at as the last resort.
 *
 * Never presents a guessed age as a known one: PropertyDuplicateAgeResult::$isFallback
 * tells the caller exactly which of the above happened, and $dateField says which
 * column the count actually came from — both are meant to render on screen.
 */
class PropertyDuplicateAgeResolver
{
    public function resolve(Property $property): PropertyDuplicateAgeResult
    {
        $status = strtolower(trim((string) $property->status));
        $thresholds = SuggestedActionThresholds::getOrCreateForAgency((int) $property->agency_id);
        $noGoDays = (int) $thresholds->deeds_duplicate_no_go_days;

        $blockReasons = [];
        if ($property->isOnMarket()) {
            $blockReasons[] = 'Currently active';
        } elseif ($status === 'draft') {
            $blockReasons[] = 'Currently a draft';
        }

        $mandateReason = $this->mandateBlockReason($property);
        if ($mandateReason !== null) {
            $blockReasons[] = $mandateReason;
        }

        if ($blockReasons !== []) {
            return new PropertyDuplicateAgeResult(null, null, false, PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED, $blockReasons);
        }

        [$date, $field, $isFallback] = match (true) {
            $status === 'expired' => $this->expiredClock($property),
            default => $this->statusChangedClock($property),
        };

        $days = $date ? $this->daysAgo($date) : null;
        $band = $this->bandFor($days, $noGoDays, (int) $thresholds->deeds_duplicate_auto_take_days);

        return new PropertyDuplicateAgeResult($days, $field, $isFallback, $band);
    }

    /**
     * Blocker #2 — the mandate safety net. Fires ONLY when expiry_date is
     * genuinely still in the future (a signed, never-abs()'d day count is
     * negative) — hard evidence the mandate has not actually expired,
     * independent of whatever status the record currently carries. Deliberately
     * narrow: a mandate that expired a few days ago is already correctly
     * handled by the ordinary no_go band below (daysAgo() makes that arithmetic
     * safe too) — this blocker exists for the case that arithmetic can never
     * catch on its own, a date that hasn't arrived yet.
     */
    private function mandateBlockReason(Property $property): ?string
    {
        if (!$property->expiry_date) {
            return null;
        }

        $signedDaysSinceExpiry = $this->signedDaysSinceExpiry($property->expiry_date);
        if ($signedDaysSinceExpiry >= 0) {
            return null; // already expired — the ordinary no_go band handles this
        }

        return 'Mandate runs to ' . Carbon::parse($property->expiry_date)->format('j M Y');
    }

    /**
     * Positive = expiry_date is in the past (days since it lapsed). Negative = the
     * mandate has not expired yet. Deliberately NOT App\Support\HumanDiff::
     * daysBetween() — that helper explicitly abs()'s its result (see its own
     * docblock: "Never negative"), which is exactly what turned a future
     * expiry_date into a large positive "days off market" reading. Direction
     * matters here; that helper was built for contexts where it doesn't.
     */
    private function signedDaysSinceExpiry(string|\Carbon\CarbonInterface $expiryDate): int
    {
        return (int) Carbon::parse($expiryDate)->startOfDay()->diffInDays(Carbon::now()->startOfDay(), false);
    }

    /**
     * "Days ago" for the per-status clocks below, once neither hard blocker has
     * fired. Clamped at 0 — never negative — as a second, structural line of
     * defence: even if some future date somehow reached this point, it could never
     * register as aged and could never reach auto_take.
     */
    private function daysAgo(string|\Carbon\CarbonInterface $date): int
    {
        return max(0, $this->signedDaysSinceExpiry($date));
    }

    /** @return array{0: string|\Carbon\CarbonInterface|null, 1: string, 2: bool} */
    private function expiredClock(Property $property): array
    {
        if ($property->expiry_date) {
            return [$property->expiry_date, 'expiry_date', false];
        }

        // Defensive only — every live expired-status property has an expiry_date
        // (it's the field that put it there), but never present a null as a date.
        return [$property->created_at, 'created_at', true];
    }

    /** @return array{0: string|\Carbon\CarbonInterface|null, 1: string, 2: bool} */
    private function statusChangedClock(Property $property): array
    {
        $lastStatusChange = PropertyAuditLog::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->where('event_type', 'status_changed')
            ->max('created_at');

        if ($lastStatusChange) {
            return [$lastStatusChange, 'status_changed_at', false];
        }

        if ($property->expiry_date) {
            return [$property->expiry_date, 'expiry_date', true];
        }

        return [$property->created_at, 'created_at', true];
    }

    private function bandFor(?int $days, int $noGoDays, int $autoTakeDays): string
    {
        if ($days === null) {
            // Should be unreachable (both hard blockers already returned above), but
            // never silently pick a band for an unknown age.
            return PropertyDuplicateAgeResult::BAND_NO_GO;
        }
        if ($days < $noGoDays) {
            return PropertyDuplicateAgeResult::BAND_NO_GO;
        }
        if ($days < $autoTakeDays) {
            return PropertyDuplicateAgeResult::BAND_NEEDS_APPROVAL;
        }

        return PropertyDuplicateAgeResult::BAND_AUTO_TAKE;
    }
}
