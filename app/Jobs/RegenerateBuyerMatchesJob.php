<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContactMatch;
use App\Services\PropertyMatchScoringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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
 *
 * Chunking (2026-08-23): the agency-wide, truncate=false path (what
 * PropertyObserver actually dispatches on every property save) never fit
 * 380+ contacts in the 600s timeout at the measured ~10s/contact — see
 * isChunkedAgencyRun() and AGENCY_REGEN_MAX_PER_RUN. Bounds each invocation
 * and self-chains a continuation until every contact has been touched at
 * least once for the rotation. Every other call shape (single-contact,
 * cross-agency rebuild, explicit truncate=true) is unchanged.
 *
 * ShouldBeUniqueUntilProcessing, not plain ShouldBeUnique (2026-08-24 fix):
 * the chunking design above needs the uniqueness lock released BEFORE
 * handle() runs, because the last thing handle() does — inside its own
 * finally block — is self::dispatch() a continuation under the SAME
 * uniqueId. Plain ShouldBeUnique releases the lock only after handle()
 * returns (confirmed in Laravel's own CallQueuedHandler::call(), not
 * assumed), so that self-dispatch was trying to acquire a lock the very
 * same execution still held, and Dispatchable::dispatch() silently no-ops
 * when a unique lock can't be acquired — no exception, nothing in the
 * logs. Shipped 2026-08-23 with the wrong interface; the continuation
 * never reached the queue. See
 * .ai/audits/2026-08-23-live-error-reduction-scan-deals-oversight-p24-buyer-matches.md
 * for the full diagnosis. ShouldBeUniqueUntilProcessing releases the lock
 * before processing starts, which is the semantic this was always
 * designed around.
 */
class RegenerateBuyerMatchesJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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

    /**
     * 2026-08-23 — bounds each invocation of the agency-wide, truncate=false
     * regenerate (the routine PropertyObserver-triggered path, and the ONLY
     * path this bounds — see isChunkedAgencyRun() below) to this many
     * contacts, self-chaining to a follow-up dispatch until every contact in
     * the agency has been touched at least once for this rotation. Measured
     * on staging: ~10s/contact (recomputeProspectingMatchesForBuyer's
     * per-listing scoring loop dominates — see .ai/audits/2026-08-23-* for
     * the full measurement), so 40 contacts is a ~400s budget inside the
     * 600s job timeout, comfortable margin even for a slower run.
     *
     * Before this: 380-388 contacts x ~10s never fit in 600s, and — because
     * truncate=false never wipes anything and every invocation started from
     * buildContactIdQuery()'s natural (unordered-by-recency) order — whichever
     * contacts sorted first got refreshed on EVERY trigger while the rest sat
     * on stale-but-present matches indefinitely, with no way to ever reach
     * them. Chunking does not introduce staleness that wasn't already there;
     * it replaces "some contacts refreshed forever, the rest never" with
     * "every contact refreshed within a few chained ticks."
     */
    public const AGENCY_REGEN_MAX_PER_RUN = 40;

    /**
     * Chunking only ever applies to the agency-wide, non-truncating path —
     * the one PropertyObserver actually dispatches in production, and the
     * only shape where "fetch once per agency" (see below) and "in-place,
     * per-contact writes" are both already true. A single-contact dispatch
     * (ContactMatchObserver) is already fast and untouched. An explicit
     * truncate=true agency-wide rebuild (manual admin action via
     * WishlistRegenerateMatches) is a deliberate full-wipe-and-rebuild the
     * requester is presumably watching — chunking THAT would strand the
     * agency in a wiped, holed state for however long the rotation takes,
     * which is exactly the regression to avoid. It keeps today's unchunked
     * behaviour unchanged.
     */
    private function isChunkedAgencyRun(): bool
    {
        return $this->contactId === null && $this->agencyId !== null && !$this->truncate;
    }

    public function __construct(
        public readonly ?int $agencyId = null,
        public readonly ?int $contactId = null,
        public readonly bool $truncate = true,
        public readonly ?string $traceId = null,
        public readonly ?\Illuminate\Support\Carbon $rotationStartedAt = null,
    ) {
        // 2026-08-05 incident (Johan, ca906ba4) — this job runs 10-15min per
        // invocation and was sharing the `default` queue with
        // DeliverAgencyWebhook, starving website webhook delivery for ~20
        // minutes. Dedicated lane so a long/failing run here never blocks
        // anything else. See .ai/specs/unified-buyer-wishlist-spec.md §9.1
        // incident note.
        $this->onQueue('buyer-matching');
    }

    public function handle(PropertyMatchScoringService $scoring): void
    {
        $chunked = $this->isChunkedAgencyRun();
        $rotationStartedAt = $this->rotationStartedAt ?? now();

        Cache::put('corex.matches.regenerating', true, now()->addMinutes(15));

        $traceId = $this->traceId ?? Uuid::uuid4()->toString();
        $startedAt = now();
        $startEventId = $this->auditLog('wishlist.regeneration.started', $traceId, [
            'agency_id'  => $this->agencyId,
            'contact_id' => $this->contactId,
            'truncate'   => $this->truncate,
            'chunked'    => $chunked,
        ]);

        $contactsProcessed = 0;
        $errors = [];
        $chainContinuation = false;

        try {
            if ($this->truncate) {
                $this->truncateScope();
            }

            $allContactIds = $this->buildContactIdQuery()->pluck('contact_id')->unique()->values();

            if ($chunked) {
                // STALE-FIRST + BOUNDED, self-chained until the whole agency is
                // covered for this rotation — same idiom as
                // Property24SyndicationService::syncAllActivations() (2026-08-23).
                // truncate is always false on this path (enforced by
                // isChunkedAgencyRun()), so a contact not yet reached this
                // rotation simply keeps its existing, unchanged matches —
                // exactly today's behaviour on this path, just now guaranteed
                // to eventually reach every contact instead of restarting from
                // the same front of the list every time.
                $doneThisRotation = \App\Models\Contact::withoutGlobalScopes()
                    ->whereIn('id', $allContactIds)
                    ->where('buyer_matches_last_regenerated_at', '>=', $rotationStartedAt)
                    ->pluck('id');
                $remaining = $allContactIds->diff($doneThisRotation)->values();
                $contactIds = $remaining->take(self::AGENCY_REGEN_MAX_PER_RUN)->values();
                $chainContinuation = $remaining->count() > $contactIds->count();
            } else {
                $contactIds = $allContactIds;
            }

            // Fetch each agency-wide pool ONCE and share it across every contact
            // below, instead of recomputeForBuyer()/recomputeProspectingMatchesForBuyer()
            // each re-running their own agency-scoped query per contact — neither
            // pool depends on contactId, only on agencyId, so per-contact refetching
            // bought nothing. Measured on staging (agency 1, 380 contacts, same scale
            // as live's 388): 32,972 active prospecting listings re-fetched fresh on
            // every contact was the dominant cost. Only pre-fetches when this job is
            // scoped to a single agency (the failing case in production, and the only
            // shape where "fetch once per agency" is a bounded, known-size pool) —
            // the null-agencyId cross-agency super-admin rebuild path is untouched.
            $candidatePool = null;
            $listingsPool  = null;
            if ($this->agencyId !== null) {
                $candidatePool = app(\App\Services\Matching\MatchingService::class)->matchableCandidatePool($this->agencyId);
                $listingsPool  = \App\Models\ProspectingListing::withoutGlobalScopes()
                    ->where('agency_id', $this->agencyId)
                    ->where('is_active', 1)
                    ->whereNull('deleted_at')
                    ->get();
            }

            foreach ($contactIds as $cid) {
                try {
                    $scoring->recomputeForBuyer((int) $cid, $candidatePool);
                    $scoring->recomputeProspectingMatchesForBuyer((int) $cid, $listingsPool);
                    $contactsProcessed++;
                } catch (Throwable $e) {
                    $errors[] = ['contact_id' => (int) $cid, 'error' => $e->getMessage()];
                    Log::error('RegenerateBuyerMatchesJob: contact failed', [
                        'contact_id' => $cid,
                        'error'      => $e->getMessage(),
                    ]);
                } finally {
                    if ($chunked) {
                        // ATTEMPT cursor — stamped success or failure, same
                        // reasoning as Property24's activation cursor: a
                        // chronically-failing contact must still rotate away
                        // rather than sorting first forever and blocking the
                        // rest of the agency from ever completing.
                        \App\Models\Contact::withoutGlobalScopes()->where('id', $cid)
                            ->update(['buyer_matches_last_regenerated_at' => now()]);
                    }
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
                'chunked'            => $chunked,
                'chain_continuation' => $chainContinuation,
                'duration_seconds'   => (int) abs(now()->diffInSeconds($startedAt)),
                'parent_event_id'    => $startEventId,
            ]);
            Cache::forget('corex.matches.regenerating');

            if ($chainContinuation) {
                // ShouldBeUniqueUntilProcessing's lock releases as soon as a
                // job starts processing (before handle() runs) — dispatching
                // our own continuation from inside handle() does not
                // self-deadlock.
                self::dispatch($this->agencyId, null, false, $this->traceId, $rotationStartedAt);
            }
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
