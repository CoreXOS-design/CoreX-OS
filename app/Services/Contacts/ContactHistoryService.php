<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CX-110/CX-111 (Johan, 2026-08-20) — the contact History tab used to read ONLY
 * contact_audit_log. Real history was sitting, correctly written, in five other tables the
 * whole time (buyer_activity_log, calendar_event_feedback, calendar_events,
 * contact_access_log, portal_leads) — a write-one-place/read-another gap, not data loss.
 * This service merges all six into one chronological list.
 *
 * CX-111 additions (portal leads + ownership changes) — Johan's escalation: RC Brewer sent
 * portal leads to several agents; the agency's FIRST TOUCH WINS rule makes lead order
 * evidence for who owns the buyer and, by extension, commission. So lead ORDER is treated as
 * load-bearing, not decorative: sorted on received_at (the portal's OWN enquiry timestamp,
 * verified genuine for 335/345 P24 and 145/145 PP leads live), never created_at (our ingest
 * clock) — a polling job batches leads and would silently misorder first touch if ingest time
 * were used. Where received_at could not be captured (an old row with no portal timestamp in
 * its payload — 10 such P24 rows exist live, none touching this build's test contacts), the
 * row falls back to created_at and is explicitly marked 'is_estimated' so the UI never
 * presents an estimate as a fact. Ties (two leads sharing the same received_at to the second —
 * confirmed to occur live) are marked, not silently broken by row id.
 *
 * Ownership changes reuse the EXISTING contact_audit_log query (event_type='agent_assigned'
 * rows were already being fetched and already had actor_type='user', so they already reached
 * the default view) — the only change is turning "Contact agent reassigned from #23 to #25"
 * (raw ids) into an attributed, named sentence. Two shapes, chosen by actor vs new-agent, not
 * by whether an old agent existed (self-claim can follow a real previous owner too):
 *   - actor === new agent  → "{actor} claimed this contact" (+ "from {old agent}" if one
 *     existed) — self-claim. Empirically ALL 174 live agent_assigned rows are this shape.
 *   - actor !== new agent  → "{actor} moved this contact from {old agent} to {new agent}" —
 *     third-party reassignment. No live example exists yet; the wording is ready for the
 *     first one.
 *
 * Performance: at most 8 queries total regardless of row count — one SELECT per source table
 * (6, but contact_access_log is skipped when the system trail is off, so the default view is
 * 6 queries), one notification_dispatch_log lookup for portal-lead routing (only runs when
 * portal leads exist for this contact), and one bulk actor-name lookup covering every source
 * including old/new agent ids and lead recipients. No query runs per row. Each source query is
 * capped at PER_SOURCE_CAP rows before merging. The controller resolves ONE service instance
 * and calls both paginate() and count() on it — per-instance memoization means that pair never
 * re-runs the query set a second time.
 *
 * Scope safety: every source is filtered by contact_id = the resolved $contact's id AND
 * agency_id = $contact->agency_id — including portal_leads, which carries agency_id directly
 * and no branch_id (same shape as buyer_activity_log/contact_access_log; the contact_id match
 * is the real boundary for a single-contact view, agency_id is defense in depth).
 *
 * Dedup: CalendarEventFeedbackObserver writes a buyer_activity_log 'feedback_captured' row
 * for every calendar_event_feedback row it observes (for buyer-facing events), keyed by
 * related_feedback_id = calendar_event_feedback.id. Without dedup this shows the same real
 * event twice. The buyer_activity_log row is kept (richer human summary); the raw
 * calendar_event_feedback row is suppressed when it has a matching buyer_activity_log row.
 */
class ContactHistoryService
{
    private const PER_SOURCE_CAP = 300;

    /** Per-instance memoization — resolve ONE service instance per request and call
     * rows()/counts()/paginate() on it; the underlying query set only ever runs once per
     * (contact, includeSystem) pair even though the controller needs both the paginated
     * list and the badge count off the same filtered set. */
    private array $cache = [];

    /**
     * The full merged, filtered, deduped, sorted (newest first) row set — the single source
     * of truth both the paginated list AND the tab-badge count are built from, so they can
     * never disagree.
     */
    public function rows(Contact $contact, bool $includeSystem): array
    {
        $key = $contact->id . ':' . ($includeSystem ? '1' : '0');

        return $this->cache[$key] ??= $this->buildRows($contact, $includeSystem);
    }

    private function buildRows(Contact $contact, bool $includeSystem): array
    {
        $contactId = $contact->id;
        $agencyId  = $contact->agency_id;

        // 1) contact_audit_log
        $auditRows = DB::table('contact_audit_log')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(self::PER_SOURCE_CAP)
            ->get(['id', 'user_id', 'actor_type', 'actor_label', 'source', 'event_category', 'event_type', 'human_summary', 'old_values', 'new_values', 'created_at']);

        // 2) buyer_activity_log
        $buyerRows = DB::table('buyer_activity_log')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->orderByDesc('activity_date')->limit(self::PER_SOURCE_CAP)
            ->get(['id', 'activity_type', 'activity_date', 'related_feedback_id', 'related_event_id', 'metadata', 'logged_by_user_id']);

        // 3) calendar_event_feedback — excluding rows already represented via buyer_activity_log.
        // Two different writers produce a 'feedback_captured' buyer_activity_log row:
        // CalendarEventFeedbackObserver sets related_feedback_id (the exact calendar_event_feedback
        // row); CalendarController's direct write only sets related_event_id. Dedup on BOTH keys —
        // by feedback id where present, else by event id. Event-id dedup suppresses every
        // calendar_event_feedback row for that event, which is only exactly right when the event
        // has as many buyer_activity_log 'feedback_captured' rows as calendar_event_feedback rows
        // (true for every case seen so far — one feedback capture per property on the viewing,
        // one activity-log row each); a future event with MORE feedback rows than logged activity
        // rows would over-suppress. Flagging this as a known edge case, not fixed here.
        $feedbackCapturedRows = $buyerRows->where('activity_type', 'feedback_captured');
        $dedupFeedbackIds = $feedbackCapturedRows->pluck('related_feedback_id')->filter()->values();
        $dedupEventIds = $feedbackCapturedRows->pluck('related_event_id')->filter()->values();
        $feedbackRows = DB::table('calendar_event_feedback')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->when($dedupFeedbackIds->isNotEmpty() || $dedupEventIds->isNotEmpty(), fn ($q) => $q->where(function ($w) use ($dedupFeedbackIds, $dedupEventIds) {
                if ($dedupFeedbackIds->isNotEmpty()) {
                    $w->whereNotIn('id', $dedupFeedbackIds);
                }
                if ($dedupEventIds->isNotEmpty()) {
                    $w->whereNotIn('calendar_event_id', $dedupEventIds);
                }
            }))
            ->orderByDesc('captured_at')->limit(self::PER_SOURCE_CAP)
            ->get(['id', 'feedback_kind', 'internal_notes', 'captured_by_user_id', 'captured_at', 'created_at']);

        // 4) calendar_events
        $eventRows = DB::table('calendar_events')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(self::PER_SOURCE_CAP)
            ->get(['id', 'title', 'category', 'status', 'created_by_id', 'created_at']);

        // 5) contact_access_log — page-view/compliance telemetry. Only fetched when the
        // system trail is on; skipping the query entirely when it's off is the main reason
        // the default view stays cheap for a heavily-viewed contact.
        $accessRows = $includeSystem
            ? DB::table('contact_access_log')
                ->where('contact_id', $contactId)->where('agency_id', $agencyId)
                ->orderByDesc('accessed_at')->limit(self::PER_SOURCE_CAP)
                ->get(['id', 'user_id', 'action_type', 'accessed_at'])
            : collect();

        // 6) portal_leads — CX-111. Sorted by received_at (the PORTAL's own enquiry
        // timestamp), never created_at (our ingest time) — commission rides on lead order.
        $leadRows = DB::table('portal_leads')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('received_at')->limit(self::PER_SOURCE_CAP)
            ->get(['id', 'portal', 'listing_id', 'existing_contact_agent_id', 'received_at', 'created_at']);

        // 7) Who each lead was actually routed to — the real dispatch record
        // (notification_dispatch_log), not a live recomputation of PortalLead::agentIds(),
        // which could drift if the listing's agent has since been reassigned. Falls back to
        // "unrouted" (no eligible agent at send time) when a lead has no dispatch rows.
        $leadAgentNames = [];
        if ($leadRows->isNotEmpty()) {
            $dispatchRows = DB::table('notification_dispatch_log')
                ->where('subject_type', 'LIKE', '%PortalLead%')
                ->whereIn('subject_id', $leadRows->pluck('id'))
                ->join('users', 'users.id', '=', 'notification_dispatch_log.user_id')
                ->select('notification_dispatch_log.subject_id', 'notification_dispatch_log.user_id', 'users.name')
                ->distinct()
                ->get();
            foreach ($dispatchRows as $d) {
                $leadAgentNames[$d->subject_id][$d->user_id] = $d->name;
            }
        }

        // 8) Listing addresses for the lead rows — one bulk lookup, not one per lead.
        $listingAddresses = [];
        $listingIds = $leadRows->pluck('listing_id')->filter()->unique()->values();
        if ($listingIds->isNotEmpty()) {
            $listingAddresses = DB::table('properties')->whereIn('id', $listingIds)
                ->pluck('address', 'id');
        }

        // 9) ONE bulk actor-name lookup across every source, instead of a query per row —
        // including the old/new agent ids off every agent_assigned audit row (decoded here,
        // once, rather than per-row later).
        $agentAssignedIds = collect();
        foreach ($auditRows->where('event_type', 'agent_assigned') as $r) {
            $old = json_decode((string) $r->old_values, true);
            $new = json_decode((string) $r->new_values, true);
            $agentAssignedIds->push($old['agent_id'] ?? null, $new['agent_id'] ?? null);
        }

        $userIds = collect()
            ->merge($auditRows->pluck('user_id'))
            ->merge($buyerRows->pluck('logged_by_user_id'))
            ->merge($feedbackRows->pluck('captured_by_user_id'))
            ->merge($eventRows->pluck('created_by_id'))
            ->merge($accessRows->pluck('user_id'))
            ->merge($agentAssignedIds)
            ->filter()->unique()->values();
        $names = $userIds->isEmpty() ? collect() : DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id');

        $rows = [];

        foreach ($auditRows as $r) {
            $isSystem = $r->actor_type !== 'user'; // Johan: "actor_type=system" — extended to every
            // non-'user' value (system/console/db-trigger/unknown are all machine-originated,
            // not just the literal string 'system'); flagged in the report for override.
            if (! $includeSystem && $isSystem) {
                continue;
            }
            $actor = $r->user_id ? ($names[$r->user_id] ?? 'Unknown user') : ($r->actor_label ?: 'System');
            $summary = $r->human_summary ?: ucfirst(str_replace('_', ' ', $r->event_type));

            // CX-111 — ownership changes. Was a raw-id sentence ("...from #23 to #25");
            // resolve to names and word it by WHO did it, not just what changed.
            if ($r->event_type === 'agent_assigned') {
                $old = json_decode((string) $r->old_values, true);
                $new = json_decode((string) $r->new_values, true);
                $oldAgentId = $old['agent_id'] ?? null;
                $newAgentId = $new['agent_id'] ?? null;
                $oldAgentName = $oldAgentId ? ($names[$oldAgentId] ?? 'Unknown agent') : null;
                $newAgentName = $newAgentId ? ($names[$newAgentId] ?? 'Unknown agent') : 'no one';

                // actor === new agent → self-claim (the shape of every live example today).
                // actor !== new agent → a third party moved it — name them, not just the change.
                if ($r->user_id && $newAgentId && (int) $r->user_id === (int) $newAgentId) {
                    $summary = $actor . ' claimed this contact' . ($oldAgentName ? ' from ' . $oldAgentName : '');
                } else {
                    $summary = $actor . ' moved this contact from ' . ($oldAgentName ?? 'unassigned') . ' to ' . $newAgentName;
                }
            }

            $rows[] = [
                'date'     => Carbon::parse($r->created_at),
                'actor'    => $actor,
                'summary'  => $summary,
                'category' => $isSystem ? 'system' : ($r->event_type === 'agent_assigned' ? 'ownership' : 'contact'),
                'source'   => 'contact_audit_log',
                'is_system' => $isSystem,
            ];
        }

        foreach ($buyerRows as $r) {
            $isSystem = $r->activity_type === 'contact_access';
            if (! $includeSystem && $isSystem) {
                continue;
            }
            $meta = $r->metadata ? json_decode($r->metadata, true) : [];
            $actor = $r->logged_by_user_id
                ? ($names[$r->logged_by_user_id] ?? 'Unknown user')
                : ($meta['captured_by'] ?? (isset($meta['portal_response']) ? 'Buyer (self-service portal)' : 'System'));
            $rows[] = [
                'date'      => Carbon::parse($r->activity_date),
                'actor'     => $actor,
                'summary'   => $this->buyerActivitySummary($r->activity_type, $meta),
                'category'  => $isSystem ? 'access' : 'activity',
                'source'    => 'buyer_activity_log',
                'is_system' => $isSystem,
            ];
        }

        foreach ($feedbackRows as $r) {
            $rows[] = [
                'date'      => Carbon::parse($r->captured_at ?? $r->created_at),
                'actor'     => $r->captured_by_user_id ? ($names[$r->captured_by_user_id] ?? 'Unknown user') : 'Unknown',
                'summary'   => 'Viewing feedback captured (' . str_replace('_', ' ', $r->feedback_kind) . ')' . ($r->internal_notes ? ' — ' . \Illuminate\Support\Str::limit($r->internal_notes, 80) : ''),
                'category'  => 'viewing',
                'source'    => 'calendar_event_feedback',
                'is_system' => false,
            ];
        }

        foreach ($eventRows as $r) {
            $rows[] = [
                'date'      => Carbon::parse($r->created_at),
                'actor'     => $r->created_by_id ? ($names[$r->created_by_id] ?? 'Unknown user') : 'System',
                'summary'   => $r->title . ' (' . ($r->category ?: 'event') . ' — ' . str_replace('_', ' ', $r->status) . ')',
                'category'  => 'viewing',
                'source'    => 'calendar_events',
                'is_system' => false,
            ];
        }

        if ($includeSystem) {
            foreach ($accessRows as $r) {
                $rows[] = [
                    'date'      => Carbon::parse($r->accessed_at),
                    'actor'     => $names[$r->user_id] ?? 'Unknown user',
                    'summary'   => match ($r->action_type) {
                        'view'   => 'Viewed this record',
                        'edit'   => 'Edited this record',
                        'export' => 'Exported this record',
                        'share'  => 'Shared this record',
                        'delete' => 'Deleted this record',
                        'merge'  => 'Merged this record',
                        default  => ucfirst($r->action_type) . ' this record',
                    },
                    'category'  => 'access',
                    'source'    => 'contact_access_log',
                    'is_system' => true,
                ];
            }
        }

        // CX-111 — portal leads. FIRST TOUCH WINS is the agency's ownership rule, so ORDER
        // is evidence, not decoration. Effective time = received_at (the portal's own enquiry
        // clock) unless it was never captured, in which case it equals created_at (our ingest
        // time) and the row is marked is_estimated so the UI never presents a guess as a fact.
        $portalLabels = ['p24' => 'Property24', 'pp' => 'Private Property', 'website' => 'Website'];
        $leadEntries = [];
        foreach ($leadRows as $r) {
            $isEstimated = $r->portal !== 'website' && $r->received_at === $r->created_at;
            $agentNames = array_values($leadAgentNames[$r->id] ?? []);
            $routedTo = $agentNames ? implode(', ', $agentNames) : 'no agent (none eligible at the time)';
            $address = $r->listing_id ? ($listingAddresses[$r->listing_id] ?? null) : null;
            $portalLabel = $portalLabels[$r->portal] ?? ucfirst($r->portal);

            $leadEntries[] = [
                'lead_id'      => $r->id,
                'date'         => Carbon::parse($r->received_at),
                'is_estimated' => $isEstimated,
                'actor'        => $routedTo,
                'summary'      => $portalLabel . ' enquiry' . ($address ? " on {$address}" : '') . ' — routed to ' . $routedTo,
            ];
        }

        // First touch: the earliest effective timestamp among this contact's leads. A tie (two
        // leads sharing the same second — confirmed to occur live) marks BOTH rather than
        // picking one arbitrarily; the tie is stated in the row, not silently broken.
        if (! empty($leadEntries)) {
            $earliest = min(array_map(fn ($e) => $e['date']->timestamp, $leadEntries));
            $firstTouchIds = array_column(array_filter($leadEntries, fn ($e) => $e['date']->timestamp === $earliest), 'lead_id');
            $isTied = count($firstTouchIds) > 1;

            foreach ($leadEntries as $e) {
                $isFirst = in_array($e['lead_id'], $firstTouchIds, true);
                $rows[] = [
                    'date'         => $e['date'],
                    'actor'        => $e['actor'],
                    'summary'      => $e['summary'],
                    'category'     => 'lead',
                    'source'       => 'portal_leads',
                    'is_system'    => false,
                    'first_touch'  => $isFirst,
                    'tied'         => $isFirst && $isTied,
                    'is_estimated' => $e['is_estimated'],
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b['date']->timestamp <=> $a['date']->timestamp);

        return $rows;
    }

    /**
     * The tab-badge count — Johan's rule: "whatever filters the list must also filter every
     * count ... tied to history." This is not a separate query; it's count(rows()) for the
     * SAME $includeSystem the list is currently rendering, off the SAME memoized rows() call
     * the paginator uses, so the badge and the list can never disagree.
     */
    public function count(Contact $contact, bool $includeSystem): int
    {
        return count($this->rows($contact, $includeSystem));
    }

    public function paginate(Contact $contact, bool $includeSystem, int $perPage = 50): LengthAwarePaginator
    {
        $rows = $this->rows($contact, $includeSystem);
        $page = Paginator::resolveCurrentPage('history');
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return new LengthAwarePaginator(
            $slice,
            count($rows),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => 'history']
        );
    }

    private function buyerActivitySummary(string $activityType, array $meta): string
    {
        return match ($activityType) {
            'feedback_captured' => 'Feedback captured' . (isset($meta['event_title']) ? ' — ' . $meta['event_title'] : ''),
            'viewing_completed' => 'Viewing completed',
            'presentation'      => 'Presentation feedback captured',
            'manual'            => $meta['notes'] ?? (isset($meta['portal_response']) ? 'Portal response: ' . $meta['portal_response'] : 'Manual activity logged'),
            'retention_action'  => 'Retention action: ' . str_replace('_', ' ', $meta['action_code'] ?? ''),
            'note_added'        => 'Note added',
            'call_logged'       => 'Call logged',
            'email_sent'        => 'Email sent',
            'whatsapp_sent'     => 'WhatsApp sent',
            default             => ucfirst(str_replace('_', ' ', $activityType)),
        };
    }
}
