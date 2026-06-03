<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityDefinitionCalendarClass;
use App\Models\DailyActivityEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SPINE-1 — sole writer of auto_instant daily-activity rows.
 *
 * Where M6.3's ProvisionalPointService is the calendar-source writer
 * (provisional → confirmed via feedback), this is the system-wide
 * instant-acquired writer. Every domain source (Contact captured,
 * Property published, Deal registered, MIC claim taken, etc.) calls
 * one method: credit($slug, $agent, $subject).
 *
 * GOVERNING PRINCIPLE (Johan's V1 rule, frozen into the API):
 *   SCORE THE ACTION, NOT THE OUTCOME. credit() writes point_state=
 *   CONFIRMED immediately. Outcomes (won/lost, approved/rejected,
 *   accepted/declined) do NOT gate or revoke. Only genuine reversals
 *   call revoke(): un-register a registered deal, un-publish, soft-
 *   delete, deliberate claim release. A declined deal, rejected FICA,
 *   lost presentation → the agent KEEPS the points.
 *
 * NON-NEGOTIABLE SAFETY GUARANTEE:
 *   No call to credit() or revoke() may throw to its caller. Every
 *   public entry point is wrapped in try { ... } catch (\Throwable $e)
 *   { Log::warning(...); } — the exception is logged and SWALLOWED.
 *   This is observe-only infrastructure: the agent's actual action
 *   (the Deal save, the Contact create, the Pitch send) MUST complete
 *   even when the points layer crashes. Identical pattern to the
 *   M6.3/M6.4 calendar engine.
 *
 * HARD GUARDS (all fail closed inside credit()):
 *   1. NULL-actor guard:  $agent === null  → no credit (cron / Chrome
 *      capture / system import has no agent to credit).
 *   2. Testing/demo guard:  app()->environment('testing') OR
 *      $context['demo'] === true → no credit (test runs + demo seeders
 *      must not pollute the activity ledger).
 *   3. Slug resolution:  no active mapping for (slug, agency) →
 *      silent no-op (slug not enabled for this agency).
 *   4. Daily cap:  mapping.daily_cap !== null AND count(user, slug,
 *      date) >= cap → silent no-op (anti-spam).
 *   5. Idempotency:  Eloquent updateOrCreate keyed on (user_id,
 *      activity_date, activity_definition_id, subject_type, subject_id)
 *      — re-firing for the same real action never double-credits.
 *
 * DOES NOT touch daily-total math (M6.5). DOES NOT subscribe to any
 * domain event yet (SPINE-2+). This file is the foundation; the
 * subscribers come later.
 */
final class InstantPointService
{
    /**
     * Credit an instant action. NEVER throws — every failure is logged
     * and swallowed so the caller's primary save always completes.
     *
     * @param string         $slug     dotted slug e.g. 'contact.captured'
     * @param User|null      $agent    crediting agent; null → skip (no-op)
     * @param Model|null     $subject  the entity the action concerns
     *                                  (Contact, Property, Deal, etc.)
     * @param array<string, mixed> $context optional metadata; supports
     *                                  ['demo' => true] to suppress credit
     *                                  in demo/seed contexts.
     */
    public function credit(string $slug, ?User $agent, ?Model $subject = null, array $context = []): void
    {
        try {
            // ── Guard 1: null actor — system/cron/import has no agent to credit
            if ($agent === null) {
                return;
            }

            // ── Guard 2: testing + demo isolation
            if (app()->environment('testing')) {
                return;
            }
            if (($context['demo'] ?? false) === true) {
                return;
            }

            // ── Guard 3: slug resolution (coalesce agency override + system default)
            $agencyId = (int) ($agent->agency_id ?? 0);
            if ($agencyId === 0) {
                // Agent without an agency cannot earn points.
                return;
            }
            $mapping = ActivityDefinitionCalendarClass::resolveForInstant($agencyId, $slug);
            if ($mapping === null) {
                return;
            }

            $today = now()->toDateString();

            // ── Guard 4: daily cap (per user × slug × date)
            if ($mapping->daily_cap !== null) {
                $count = DailyActivityEntry::query()
                    ->where('user_id', $agent->id)
                    ->where('activity_date', $today)
                    ->where('activity_definition_id', $mapping->activity_definition_id)
                    ->count();
                if ($count >= (int) $mapping->daily_cap) {
                    return;
                }
            }

            // ── Guard 5: idempotency (key on user × date × def × subject)
            // Pure no-FK polymorphic — DB doesn't enforce subject_type/id;
            // app-layer updateOrCreate is the contract.
            $subjectType = $subject ? $subject::class : null;
            $subjectId   = $subject ? (int) $subject->getKey() : null;

            DailyActivityEntry::updateOrCreate(
                [
                    'user_id'                => $agent->id,
                    'activity_date'          => $today,
                    'activity_definition_id' => $mapping->activity_definition_id,
                    'subject_type'           => $subjectType,
                    'subject_id'             => $subjectId,
                ],
                [
                    'period'      => now()->format('Y-m'),
                    'agency_id'   => $agencyId,
                    'branch_id'   => $agent->branch_id,
                    'value'       => (int) $mapping->value_per_event,
                    'point_state' => DailyActivityEntry::STATE_CONFIRMED,
                    'source'      => DailyActivityEntry::SOURCE_AUTO_INSTANT,
                    'created_by'  => $agent->id,
                    'updated_by'  => $agent->id,
                ]
            );
        } catch (Throwable $e) {
            // SAFETY GUARANTEE: never propagate to the caller.
            Log::warning('SPINE-1 InstantPointService::credit swallowed exception', [
                'slug'         => $slug,
                'agent_id'     => $agent?->id,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id'   => $subject ? $subject->getKey() : null,
                'message'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Revoke a previously credited instant action. Used for the genuine
     * reversal pairs (deal un-registered, property un-published, deal/
     * contact soft-deleted, deliberate manual claim release). NEVER
     * called for negative-outcome events — those are not reversals,
     * the agent did the work.
     *
     * Idempotent: finds the original credit row by (slug, subject,
     * agency) and flips state to revoked via the M6.4 PointStateService.
     * If no row exists or it's already revoked, this is a silent no-op.
     */
    public function revoke(string $slug, Model $subject, string $reason): void
    {
        try {
            // Resolve every mapping (agency-specific + system default)
            // that could have produced a credit for this subject, then
            // find any matching daily_activity_entries row regardless of
            // which agent earned it.
            $defIds = ActivityDefinitionCalendarClass::query()
                ->instant()
                ->where('slug', $slug)
                ->pluck('activity_definition_id')
                ->all();

            if (empty($defIds)) {
                return;
            }

            $entries = DailyActivityEntry::query()
                ->whereIn('activity_definition_id', $defIds)
                ->where('source', DailyActivityEntry::SOURCE_AUTO_INSTANT)
                ->where('subject_type', $subject::class)
                ->where('subject_id', (int) $subject->getKey())
                ->whereNotIn('point_state', [
                    DailyActivityEntry::STATE_REVOKED,
                ])
                ->get();

            if ($entries->isEmpty()) {
                return;
            }

            $stateSvc = app(PointStateService::class);
            foreach ($entries as $entry) {
                $stateSvc->revoke($entry, $reason);
            }
        } catch (Throwable $e) {
            Log::warning('SPINE-1 InstantPointService::revoke swallowed exception', [
                'slug'         => $slug,
                'subject_type' => $subject::class,
                'subject_id'   => $subject->getKey(),
                'reason'       => $reason,
                'message'      => $e->getMessage(),
            ]);
        }
    }
}
