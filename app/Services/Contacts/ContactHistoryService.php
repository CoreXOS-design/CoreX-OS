<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CX-110 (Johan, 2026-08-20) — the contact History tab used to read ONLY contact_audit_log.
 * Real history was sitting, correctly written, in four other tables the whole time
 * (buyer_activity_log, calendar_event_feedback, calendar_events, contact_access_log) — a
 * write-one-place/read-another gap, not data loss. This service merges all five into one
 * chronological list.
 *
 * Performance: at most 6 queries total, regardless of how many rows exist — one SELECT per
 * source table (5, but contact_access_log is skipped entirely when the system trail is off,
 * so the default view is 5 queries) plus one bulk actor-name lookup. No query runs per row.
 * Each source query is capped at PER_SOURCE_CAP rows (newest first) before merging, so an
 * old, heavily page-viewed contact can't turn this into an unbounded fetch. The controller
 * resolves ONE service instance and calls both paginate() and count() on it — per-instance
 * memoization means that pair never re-runs the query set a second time.
 *
 * Scope safety: every source is filtered by contact_id = the resolved $contact's id AND
 * agency_id = $contact->agency_id. The contact itself is already the correct tenant (resolved
 * upstream via the normal agency-scoped route binding) — filtering child rows to ITS id is
 * the actual scope boundary for a single-contact view; the agency_id match is defense in
 * depth, not the primary guard, since buyer_activity_log and contact_access_log carry no
 * branch_id to join through and a single contact belongs to exactly one branch/agency anyway.
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
            ->get(['id', 'user_id', 'actor_type', 'actor_label', 'source', 'event_category', 'event_type', 'human_summary', 'created_at']);

        // 2) buyer_activity_log
        $buyerRows = DB::table('buyer_activity_log')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->orderByDesc('activity_date')->limit(self::PER_SOURCE_CAP)
            ->get(['id', 'activity_type', 'activity_date', 'related_feedback_id', 'metadata', 'logged_by_user_id']);

        // 3) calendar_event_feedback — excluding rows already represented via buyer_activity_log.
        $dedupFeedbackIds = $buyerRows->pluck('related_feedback_id')->filter()->values();
        $feedbackRows = DB::table('calendar_event_feedback')
            ->where('contact_id', $contactId)->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->when($dedupFeedbackIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $dedupFeedbackIds))
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

        // 6) ONE bulk actor-name lookup across every source, instead of a query per row.
        $userIds = collect()
            ->merge($auditRows->pluck('user_id'))
            ->merge($buyerRows->pluck('logged_by_user_id'))
            ->merge($feedbackRows->pluck('captured_by_user_id'))
            ->merge($eventRows->pluck('created_by_id'))
            ->merge($accessRows->pluck('user_id'))
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
            $rows[] = [
                'date'     => Carbon::parse($r->created_at),
                'actor'    => $r->user_id ? ($names[$r->user_id] ?? 'Unknown user') : ($r->actor_label ?: 'System'),
                'summary'  => $r->human_summary ?: ucfirst(str_replace('_', ' ', $r->event_type)),
                'category' => $isSystem ? 'system' : 'contact',
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
