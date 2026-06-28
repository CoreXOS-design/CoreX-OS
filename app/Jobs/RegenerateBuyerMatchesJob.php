<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContactMatch;
use App\Services\PropertyMatchScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Rebuilds the cached match tables (prospecting_buyer_matches, property_buyer_matches)
 * against the current ContactMatch source of truth.
 *
 * Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 9 (Match Regeneration).
 *
 * Idempotent: PropertyMatchScoringService::recomputeFor* methods are upsert-based.
 * Running the job twice produces the same final state.
 *
 * Multi-tenancy: when a non-null agencyId is supplied, all writes and deletes
 * are scoped to that agency. The cross-agency super-admin path (no agencyId)
 * is intended for the post-Prompt-08 master rebuild.
 *
 * Audit: writes directly to domain_event_log with event_name=wishlist.regeneration.*
 * (no concrete event class yet — events spec Prompt 04 may introduce one;
 * this job stays direct-write because regeneration is an operational job,
 * not a domain event).
 *
 * Failure isolation: per-contact errors are caught and logged; the job
 * continues with the remaining contacts. The finish-audit row records
 * the full error list.
 */
class RegenerateBuyerMatchesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;     // no auto-retry; manual re-run on failure
    public int $timeout = 600; // 10 minutes

    /**
     * AT-108 — coalesce duplicate dispatches. Bulk stock imports fire one
     * agency-scoped recompute per saved property; ShouldBeUnique collapses them
     * to a SINGLE pending job per (agency, contact) scope so the import stays
     * fast and the work is bounded. Lock auto-releases after the job runs or
     * uniqueFor elapses (safety valve).
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'regen-buyer-matches:' . ($this->agencyId ?? 'all') . ':' . ($this->contactId ?? 'all');
    }

    public function __construct(
        public readonly ?int $agencyId = null,
        public readonly ?int $contactId = null,
        public readonly bool $truncate = true,
        public readonly ?string $traceId = null,
    ) {}

    public function handle(PropertyMatchScoringService $scoring): void
    {
        Cache::put('corex.matches.regenerating', true, now()->addMinutes(15));

        $traceId = $this->traceId ?? Uuid::uuid4()->toString();
        $startedAt = now();
        $startEventId = $this->auditLog('wishlist.regeneration.started', $traceId, [
            'agency_id'  => $this->agencyId,
            'contact_id' => $this->contactId,
            'truncate'   => $this->truncate,
        ]);

        $contactsProcessed = 0;
        $errors = [];

        try {
            if ($this->truncate) {
                $this->truncateScope();
            }

            $contactIds = $this->buildContactIdQuery()->pluck('contact_id')->unique()->values();

            foreach ($contactIds as $cid) {
                try {
                    $scoring->recomputeForBuyer((int) $cid);
                    $scoring->recomputeProspectingMatchesForBuyer((int) $cid);
                    $contactsProcessed++;
                } catch (Throwable $e) {
                    $errors[] = ['contact_id' => (int) $cid, 'error' => $e->getMessage()];
                    Log::error('RegenerateBuyerMatchesJob: contact failed', [
                        'contact_id' => $cid,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            $finalCounts = $this->countCurrentMatches();
        } catch (Throwable $e) {
            $errors[] = ['phase' => 'pre-loop', 'error' => $e->getMessage()];
            $finalCounts = $this->countCurrentMatches();
        } finally {
            $this->auditLog('wishlist.regeneration.finished', $traceId, [
                'agency_id'          => $this->agencyId,
                'contact_id'         => $this->contactId,
                'contacts_processed' => $contactsProcessed,
                'rows_written'       => $finalCounts ?? null,
                'errors_count'       => count($errors),
                'errors'             => $errors,
                'duration_seconds'   => (int) abs(now()->diffInSeconds($startedAt)),
                'parent_event_id'    => $startEventId,
            ]);
            Cache::forget('corex.matches.regenerating');
        }
    }

    /**
     * Build the query that returns the distinct contact_ids to process.
     * Scoped by ContactMatch::active() + optional agency/contact filters.
     */
    private function buildContactIdQuery()
    {
        $q = ContactMatch::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', ContactMatch::STATUS_ACTIVE);

        if ($this->agencyId !== null) {
            $q->where('agency_id', $this->agencyId);
        }
        if ($this->contactId !== null) {
            $q->where('contact_id', $this->contactId);
        }

        return $q;
    }

    /**
     * Clear match cache rows in scope before re-populating. Scoped DELETE
     * (not TRUNCATE) when an agency or contact filter is supplied — other
     * tenants' rows must be untouched. Full TRUNCATE only on the
     * super-admin no-filter path.
     */
    private function truncateScope(): void
    {
        if ($this->contactId !== null) {
            DB::table('prospecting_buyer_matches')->where('contact_id', $this->contactId)->delete();
            DB::table('property_buyer_matches')->where('contact_id', $this->contactId)->delete();
            return;
        }

        if ($this->agencyId !== null) {
            DB::table('prospecting_buyer_matches')->where('agency_id', $this->agencyId)->delete();
            DB::table('property_buyer_matches')->where('agency_id', $this->agencyId)->delete();
            return;
        }

        // Full cross-agency truncate (post-migration master rebuild).
        DB::table('prospecting_buyer_matches')->truncate();
        DB::table('property_buyer_matches')->truncate();
    }

    /** @return array{prospecting:int,property:int} */
    private function countCurrentMatches(): array
    {
        $prospecting = DB::table('prospecting_buyer_matches');
        $property    = DB::table('property_buyer_matches');

        if ($this->contactId !== null) {
            $prospecting->where('contact_id', $this->contactId);
            $property->where('contact_id', $this->contactId);
        } elseif ($this->agencyId !== null) {
            $prospecting->where('agency_id', $this->agencyId);
            $property->where('agency_id', $this->agencyId);
        }

        return [
            'prospecting' => $prospecting->count(),
            'property'    => $property->count(),
        ];
    }

    /**
     * Append one row to domain_event_log. Returns the new row's event_id so
     * the finish row can reference it.
     *
     * @param array<string,mixed> $context
     */
    private function auditLog(string $eventName, string $traceId, array $context): string
    {
        $eventId = Uuid::uuid4()->toString();
        DB::table('domain_event_log')->insert([
            'event_id'         => $eventId,
            'trace_id'         => $traceId,
            'event_name'       => $eventName,
            'agency_id'        => $this->agencyId,
            'actor_user_id'    => null,
            'subject_type'     => null,
            'subject_id'       => null,
            'payload_snapshot' => null,
            'context'          => json_encode($context),
            'occurred_at'      => now()->format('Y-m-d H:i:s.u'),
            'created_at'       => now(),
        ]);
        return $eventId;
    }
}
