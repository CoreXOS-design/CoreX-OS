<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\SuggestedActionThresholds;
use App\Support\HumanDiff;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21).
 *
 * "THE AGE CLOCK DEPENDS ON THE STATUS" (revised 2026-08-21 — draft demoted from a
 * clock to a block: "active and draft stock - agent 1 is working with this. done."):
 *   - on-market (isOnMarket() true — active, under_offer, for_sale, to_let, ...) OR
 *     draft: ALWAYS blocked. No clock, no bands, no exceptions. Someone is actively
 *     working the property either way. Johan named ACTIVE explicitly, but the
 *     reasoning ("live stock, currently on the portals") applies identically to
 *     every on-market status, so this reads isOnMarket() — the ONE existing
 *     mechanism — rather than hardcoding a check for the literal string 'active'.
 *     draft is checked alongside it for the same "someone is working this" reason,
 *     just via the status string directly (draft is off-market by isOnMarket()'s
 *     own definition, so it needs its own explicit check here).
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

        if ($property->isOnMarket() || $status === 'draft') {
            return new PropertyDuplicateAgeResult(null, null, false, PropertyDuplicateAgeResult::BAND_ACTIVE_BLOCKED);
        }

        [$date, $field, $isFallback] = match (true) {
            $status === 'expired' => $this->expiredClock($property),
            default => $this->statusChangedClock($property),
        };

        $days = $date ? HumanDiff::daysBetween($date) : null;

        $thresholds = SuggestedActionThresholds::getOrCreateForAgency((int) $property->agency_id);
        $band = $this->bandFor($days, (int) $thresholds->deeds_duplicate_no_go_days, (int) $thresholds->deeds_duplicate_auto_take_days);

        return new PropertyDuplicateAgeResult($days, $field, $isFallback, $band);
    }

    /** @return array{0: ?string, 1: string, 2: bool} */
    private function expiredClock(Property $property): array
    {
        if ($property->expiry_date) {
            return [$property->expiry_date, 'expiry_date', false];
        }

        // Defensive only — every live expired-status property has an expiry_date
        // (it's the field that put it there), but never present a null as a date.
        return [$property->created_at, 'created_at', true];
    }

    /** @return array{0: ?string, 1: string, 2: bool} */
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
            // Should be unreachable (on-market already short-circuits above), but
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
