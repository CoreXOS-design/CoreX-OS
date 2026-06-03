<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\DailyActivityEntry;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Module 6 (M6.4) — sole owner of state transitions on existing
 * daily_activity_entries rows.
 *
 * Where M6.3's ProvisionalPointService WRITES new rows, this service
 * mutates the lifecycle of rows that already exist:
 *
 *   provisional ── feedback captured ──►  confirmed
 *        │                                    │
 *        └─── feedback stale / cancelled ──► revoked
 *                                             │
 *                                             ▼
 *                                       overridden  (BM/admin manual)
 *
 * Invariants:
 *   - All transitions are idempotent — re-firing the same transition on a
 *     row already in the target state is a silent no-op. The auto-revoke
 *     command and the feedback observer both rely on this to be safe under
 *     race / retry.
 *   - Rows are NEVER hard-deleted. revoke() and override() leave the row
 *     in place; only state + audit fields change.
 *   - Every override appends a full before/after snapshot to
 *     override_audit_json — the column is the audit trail, the
 *     overridden_by_user_id / override_reason columns mirror the most
 *     recent entry for cheap querying.
 */
final class PointStateService
{
    /**
     * Provisional → confirmed. Idempotent — confirmed/overridden/revoked
     * rows are left alone. confirmed_at is captured from the feedback's
     * captured_at where supplied, falling back to now().
     */
    public function confirm(DailyActivityEntry $entry, DateTimeInterface|string|null $capturedAt = null): void
    {
        if ($entry->point_state !== DailyActivityEntry::STATE_PROVISIONAL) {
            return;
        }

        DB::transaction(function () use ($entry, $capturedAt) {
            $entry->point_state  = DailyActivityEntry::STATE_CONFIRMED;
            $entry->confirmed_at = $capturedAt instanceof DateTimeInterface
                ? Carbon::instance($capturedAt)
                : ($capturedAt !== null ? Carbon::parse($capturedAt) : now());
            $entry->save();
        });
    }

    /**
     * Any non-revoked state → revoked. Idempotent. Used by:
     *   - CalendarEventObserver (cancel/dismiss/soft-delete) via M6.3.
     *   - AutoRevokeStaleProvisionalPoints command — when feedback never
     *     arrived inside the mapping's auto_revoke_after_hours window.
     *   - BM/admin via override() when the manual adjustment chooses to
     *     mark a row revoked rather than overridden.
     */
    public function revoke(DailyActivityEntry $entry, string $reason): void
    {
        if ($entry->point_state === DailyActivityEntry::STATE_REVOKED) {
            return;
        }

        DB::transaction(function () use ($entry, $reason) {
            $entry->point_state   = DailyActivityEntry::STATE_REVOKED;
            $entry->revoked_at    = now();
            $entry->revoke_reason = $reason;
            $entry->save();
        });
    }

    /**
     * Manual BM/admin override. Captures the row's before-state, applies
     * the requested new value + state, records who did it and why, and
     * appends an audit entry to override_audit_json.
     *
     * Repeated overrides accumulate in the audit JSON (array of entries)
     * — overridden_by_user_id + override_reason mirror the MOST RECENT
     * entry so queries don't need to crack the JSON.
     *
     * Note on state semantics: $newState may be any of the enum values.
     * For totals visibility the model's countedTowardTotal scope treats
     * confirmed AND overridden as real, provisional + revoked as not.
     * Callers wanting an override to count → pass STATE_OVERRIDDEN.
     */
    public function override(
        DailyActivityEntry $entry,
        int $newValue,
        string $newState,
        string $reason,
        User $performedBy,
    ): void {
        $this->guardState($newState);

        DB::transaction(function () use ($entry, $newValue, $newState, $reason, $performedBy) {
            $before = [
                'value'       => (int) $entry->value,
                'point_state' => (string) $entry->point_state,
            ];

            $entry->value       = $newValue;
            $entry->point_state = $newState;
            $entry->overridden_by_user_id = $performedBy->id;
            $entry->override_reason       = $reason;

            $history = is_array($entry->override_audit_json) ? $entry->override_audit_json : [];
            $history[] = [
                'before'         => $before,
                'after'          => [
                    'value'       => $newValue,
                    'point_state' => $newState,
                ],
                'reason'         => $reason,
                'performed_by'   => (int) $performedBy->id,
                'performed_at'   => now()->toIso8601String(),
            ];
            $entry->override_audit_json = $history;

            $entry->save();
        });
    }

    private function guardState(string $state): void
    {
        $valid = [
            DailyActivityEntry::STATE_PROVISIONAL,
            DailyActivityEntry::STATE_CONFIRMED,
            DailyActivityEntry::STATE_REVOKED,
            DailyActivityEntry::STATE_OVERRIDDEN,
        ];
        if (! in_array($state, $valid, true)) {
            throw new \InvalidArgumentException(
                "PointStateService::override: invalid \$newState '{$state}'. "
                . 'Must be one of: ' . implode(', ', $valid) . '.'
            );
        }
    }
}
