<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\RentalApplication;
use Illuminate\Support\Collection;

/**
 * .ai/specs/leases.md §12.2/§12.3 — the Lease Hub's "tenancy log": one
 * chronological, searchable, type-filterable record of everything that has
 * happened on ONE tenancy. This is the evidence pack an out-inspection
 * leans on, so every source below is scoped by `lease_id`, never
 * `property_id` — a property can have many leases over its life, and a
 * previous tenant's faults/work-orders/inspections must never surface on
 * the current (or any other) tenant's log. The one exception is the
 * application source, which is reached via `leases.rental_application_id`
 * (a lease has at most one originating application, not a property-wide
 * list of them).
 *
 * Designed to be extended with more sources later (notices — Stage 6/7, filed
 * documents; job cards — AT-442 — landed 2026-10-06, rental-work-orders.md §14.29) without reworking callers: each
 * source is a private builder returning plain arrays of the same shape,
 * merged and sorted once at the end. A new source is one more private
 * method and one more line in buildAllEntries().
 */
class LeaseTimelineService
{
    // BUILD 3 — the three maintenance-flow types (§17.16) sit at the end of the list. A later lease e-sign build adds its own
    // types AFTER this block; this line is the only one both touch, so keep any change here to appending.
    public const TYPES = ['application', 'lease', 'inspection', 'fault', 'work_order', 'job_card', 'notice', 'rental_notice', 'emergency_approval', 'variation', 'completion_check'];

    /**
     * @return array{entries: Collection, total: int}
     */
    public function paginatedFor(Lease $lease, ?string $search = null, array $types = [], ?string $dateFrom = null, ?string $dateTo = null, int $perPage = 50, int $page = 1): array
    {
        $entries = $this->allEntriesFor($lease);

        $search = trim((string) $search);
        if ($search !== '') {
            $needle = mb_strtolower($search);
            $entries = $entries->filter(function (array $entry) use ($needle) {
                return str_contains(mb_strtolower($entry['description']), $needle)
                    || str_contains(mb_strtolower($entry['actor'] ?? ''), $needle);
            });
        }

        if (!empty($types)) {
            $entries = $entries->whereIn('type', $types);
        }

        if ($dateFrom) {
            $entries = $entries->filter(fn (array $e) => $e['occurred_at'] >= $dateFrom);
        }
        if ($dateTo) {
            $entries = $entries->filter(fn (array $e) => $e['occurred_at'] <= $dateTo . ' 23:59:59');
        }

        $entries = $entries->values();
        $total = $entries->count();

        // .ai/specs/leases.md §12.3 — "No pagination cap below 500 rows... if
        // this assumption breaks on real data, paginate." A real tenancy's
        // log is small; the slice below is cheap at this scale and keeps the
        // API/PDF/screen all consuming the exact same ordered collection.
        $offset = max(0, ($page - 1) * $perPage);
        $page = $entries->slice($offset, $perPage)->values();

        return ['entries' => $page, 'total' => $total];
    }

    public function allEntriesFor(Lease $lease): Collection
    {
        $entries = collect()
            ->merge($this->applicationEntries($lease))
            ->merge($this->leaseEntries($lease))
            ->merge($this->inspectionEntries($lease))
            ->merge($this->faultEntries($lease))
            ->merge($this->workOrderEntries($lease))
            ->merge($this->jobCardEntries($lease))
            ->merge($this->noticeEntries($lease))
            ->merge($this->renewalEventEntries($lease))
            ->merge($this->maintenanceFlowEntries($lease)); // BUILD 3 — §17.16

        return $entries->sortByDesc('occurred_at')->values();
    }

    private function entry(string $type, string $occurredAt, string $description, ?string $actor, ?string $status, ?string $routeName, $routeParam): array
    {
        return [
            'type' => $type,
            'occurred_at' => (string) $occurredAt,
            'description' => $description,
            'actor' => $actor,
            'status' => $status,
            'route_name' => $routeName,
            'route_param' => $routeParam,
        ];
    }

    private function applicationEntries(Lease $lease): array
    {
        if (!$lease->rental_application_id) {
            return [];
        }

        /** @var RentalApplication|null $application */
        $application = RentalApplication::withoutGlobalScopes()->find($lease->rental_application_id);
        if (!$application) {
            return [];
        }

        $out = [];
        if ($application->submitted_at) {
            $out[] = $this->entry(
                'application',
                (string) $application->submitted_at,
                'Rental application submitted',
                null,
                $application->status,
                'corex.rental-applications.show',
                $application->id,
            );
        }
        if ($application->status === 'approved') {
            // RentalApplication has no dedicated approved_at column (confirmed
            // by direct inspection) — updated_at is the best available
            // approximation for "when this reached approved", same limitation
            // noted in this feature's own report.
            $out[] = $this->entry(
                'application',
                (string) $application->updated_at,
                'Rental application approved',
                null,
                'approved',
                'corex.rental-applications.show',
                $application->id,
            );
        }

        return $out;
    }

    /**
     * leases.md §12.3 — "a brand-new lease has a genuinely empty log." Plain
     * creation is not itself a tenancy EVENT (nothing has happened on the
     * tenancy yet) — only activation-worth-noting transitions (cancellation)
     * and escalations are logged here. A lease's existence/creation date is
     * already visible in the header, not duplicated into the log.
     */
    private function leaseEntries(Lease $lease): array
    {
        $out = [];

        if ($lease->status === Lease::STATUS_CANCELLED && $lease->cancelled_at) {
            $out[] = $this->entry('lease', (string) $lease->cancelled_at, 'Lease cancelled — ' . $lease->cancel_reason, $lease->cancelledByUser?->name, 'cancelled', 'corex.leases.show', $lease->id);
        }

        foreach ($lease->escalations as $escalation) {
            $out[] = $this->entry(
                'lease',
                (string) $escalation->created_at,
                sprintf('Rent escalated: R%s → R%s (%s%%)', number_format((float) $escalation->previous_rental_amount, 2), number_format((float) $escalation->new_rental_amount, 2), number_format((float) $escalation->escalation_rate_percent, 2)),
                $escalation->createdByUser?->name,
                'escalation',
                'corex.leases.show',
                $lease->id,
            );
        }

        return $out;
    }

    private function inspectionEntries(Lease $lease): array
    {
        return $lease->inspections()->get()->map(function ($inspection) {
            $label = \App\Models\RentalInspection::typeName($inspection->type);
            $occurredAt = $inspection->completed_at ?? $inspection->scheduled_for ?? $inspection->created_at;

            return $this->entry(
                'inspection',
                (string) $occurredAt,
                $label . ' — ' . ucfirst(str_replace('_', ' ', $inspection->status)),
                null,
                $inspection->status,
                'corex.rental-inspections.show',
                $inspection->id,
            );
        })->all();
    }

    private function faultEntries(Lease $lease): array
    {
        return $lease->faultReports()->get()->map(function ($report) {
            return $this->entry(
                'fault',
                (string) ($report->reported_at ?? $report->created_at),
                'Fault reported: ' . $report->title,
                null,
                $report->status,
                'corex.rental-fault-reports.show',
                $report->id,
            );
        })->all();
    }

    private function workOrderEntries(Lease $lease): array
    {
        return $lease->workOrders()->get()->map(function ($workOrder) {
            return $this->entry(
                'work_order',
                (string) ($workOrder->reported_at ?? $workOrder->created_at),
                'Work order: ' . $workOrder->title,
                null,
                $workOrder->status,
                'corex.rental-work-orders.show',
                $workOrder->id,
            );
        })->all();
    }

    /**
     * rental-work-orders.md §14.29 — job cards on this tenancy. Scoped by
     * `lease_id` like every other source (a previous tenant's cards never
     * surface here). Four kinds of entry per card: opened, photos added (one
     * line per card per day per uploader kind — crew vs office — with a count),
     * work completed (the crew's sign-off, who and how), card completed (the
     * agent's close). Archived cards drop out, same as archived faults/orders.
     */
    private function jobCardEntries(Lease $lease): array
    {
        $cards = $lease->jobCards()->with(['createdByUser', 'workerSignedOffByUser'])->get();
        if ($cards->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($cards as $card) {
            $out[] = $this->entry(
                'job_card',
                (string) $card->created_at,
                'Job card opened: ' . $card->title,
                $card->createdByUser?->name,
                $card->status,
                'corex.rental-job-cards.show',
                $card->id,
            );

            if ($card->worker_signed_off_at) {
                $via = match ($card->getAttribute('worker_sign_off_via')) {
                    'crew_link' => ' via link',
                    'crew_page' => ' via crew page',
                    'signed_copy' => ' via signed copy',
                    default => '',
                };
                $signer = $card->worker_sign_off_name ?: ($card->workerSignedOffByUser?->name ?: 'the crew');
                $out[] = $this->entry(
                    'job_card',
                    (string) $card->worker_signed_off_at,
                    'Work completed — signed by ' . $signer . $via . ': ' . $card->title,
                    null,
                    $card->status,
                    'corex.rental-job-cards.show',
                    $card->id,
                );
            }

            if ($card->completed_at) {
                $out[] = $this->entry(
                    'job_card',
                    (string) $card->completed_at,
                    'Job card completed: ' . $card->title,
                    null,
                    $card->status,
                    'corex.rental-job-cards.show',
                    $card->id,
                );
            }
        }

        // Photos, grouped: card × day × (crew | office). Crew = no CoreX user behind the upload.
        $titles = $cards->pluck('title', 'id');
        $groups = [];
        \App\Models\RentalWorkOrderPhoto::query()
            ->whereIn('rental_job_card_id', $cards->pluck('id')->all())
            ->orderBy('created_at')->orderBy('id')
            ->get(['id', 'rental_job_card_id', 'uploaded_by_user_id', 'created_at'])
            ->each(function ($photo) use (&$groups) {
                $isCrew = $photo->uploaded_by_user_id === null;
                $key = $photo->rental_job_card_id . '|' . $photo->created_at->format('Y-m-d') . '|' . ($isCrew ? 'crew' : 'office');
                $groups[$key] ??= ['card' => $photo->rental_job_card_id, 'crew' => $isCrew, 'count' => 0, 'at' => $photo->created_at];
                $groups[$key]['count']++;
                if ($photo->created_at->gt($groups[$key]['at'])) {
                    $groups[$key]['at'] = $photo->created_at;
                }
            });
        foreach ($groups as $g) {
            $out[] = $this->entry(
                'job_card',
                (string) $g['at'],
                ($g['crew'] ? 'Crew photos added' : 'Photos added') . ' (' . $g['count'] . '): ' . ($titles[$g['card']] ?? 'Job card'),
                null,
                null,
                'corex.rental-job-cards.show',
                $g['card'],
            );
        }

        return $out;
    }

    /**
     * AT-445 — .ai/specs/rental-portal-access.md §8/§10. Entry type is
     * 'rental_notice', NOT 'notice' — origin/QA1's own renewalEventEntries()
     * below already uses 'notice' for a lease-renewal INTENT event (tenant/
     * landlord notice to vacate, recorded with no document at all). Those
     * are a different kind of tenancy-log entry from this one (an actual
     * breach/vacate DOCUMENT sent by email) and must not collide on the
     * same filter/type key.
     */
    private function noticeEntries(Lease $lease): array
    {
        return $lease->notices()->get()->map(function ($notice) {
            $recipients = array_filter([
                $notice->sent_to_tenant ? 'tenant' : null,
                $notice->sent_to_landlord ? 'landlord' : null,
            ]);

            return $this->entry(
                'rental_notice',
                (string) ($notice->sent_at ?? $notice->created_at),
                ucfirst(str_replace('_', ' ', $notice->notice_type)) . ' notice sent to ' . (implode(' and ', $recipients) ?: 'nobody'),
                $notice->sentByUser?->name,
                'sent',
                'corex.rental-notices.show',
                $notice->id,
            );
        })->all();
    }

    /**
     * .ai/specs/rental-renewals.md §8 — renewal draft/activation and the
     * one-click outcomes (month-to-month, notice given/reversed). Reads
     * the append-only LeaseEvent log, NOT the lease's own live columns —
     * a reversal clears notice_date/is_month_to_month but must not erase
     * the fact that the event happened (unlike escalations/cancellation
     * above, which are safe to derive live because nothing ever un-sets
     * them).
     */
    private function renewalEventEntries(Lease $lease): array
    {
        $noticeTypes = [\App\Models\LeaseEvent::TYPE_NOTICE_RECORDED, \App\Models\LeaseEvent::TYPE_NOTICE_REVERSED, \App\Models\LeaseEvent::TYPE_NOTICE_OUTCOME_CHANGED];

        return $lease->events->map(function (\App\Models\LeaseEvent $event) use ($noticeTypes, $lease) {
            return $this->entry(
                in_array($event->event_type, $noticeTypes, true) ? 'notice' : 'lease',
                (string) $event->occurred_at,
                $event->description,
                $event->actorUser?->name,
                $event->event_type,
                'corex.leases.show',
                $lease->id,
            );
        })->all();
    }

    // ───────────────────────────────────────────────────────────────────────────────────────────────────────────────
    // BUILD 3 BEGIN — maintenance-flow tenancy-log entries (.ai/specs/rental-work-orders.md §17.16). One builder, merged
    // in allEntriesFor() above. Everything is scoped by `lease_id` through the lease's own work orders (a vacancy work
    // order with no lease does not appear, as today) and every entry links to the work order. Reads only the append-only
    // tables, so a later change to a term or a setting never rewrites what the log says.
    // ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Approval decisions (as `work_order` entries), emergency approvals (recorded / voided), variations (raised /
     * auto-approved / approved / declined / withdrawn) and every completion round: "Work reported done by {name} (round n)",
     * "Tenant confirmed" / "Tenant reported not complete: {note}" / "Accepted — no response in {n} days", "Dispute sent back
     * to {crew|contractor}", and "Reported done again (round n+1)".
     */
    private function maintenanceFlowEntries(Lease $lease): array
    {
        $workOrders = $lease->workOrders()->get(['id', 'title', 'assignment_type', 'status']);
        if ($workOrders->isEmpty()) {
            return [];
        }
        $ids = $workOrders->pluck('id')->all();
        $titles = $workOrders->pluck('title', 'id');
        $route = 'corex.rental-work-orders.show';
        $out = [];

        // Approval decisions — "Approved — within the owner's no-approval limit". The system acts, so no actor.
        foreach (\App\Models\RentalApprovalDecision::query()->whereIn('rental_work_order_id', $ids)->orderBy('id')->get() as $decision) {
            $out[] = $this->entry(
                'work_order',
                (string) $decision->created_at,
                ($decision->note ?: ucfirst(str_replace('_', ' ', (string) $decision->decision))) . ': ' . ($titles[$decision->rental_work_order_id] ?? 'Work order'),
                null,
                (string) $decision->decision,
                $route,
                $decision->rental_work_order_id,
            );
        }

        foreach (\App\Models\RentalEmergencyApproval::query()->whereIn('rental_work_order_id', $ids)->with('recordedByUser')->orderBy('id')->get() as $approval) {
            $title = $titles[$approval->rental_work_order_id] ?? 'Work order';
            $out[] = $this->entry(
                'emergency_approval',
                (string) ($approval->approved_at ?? $approval->created_at),
                'Emergency work agreed by the owner (' . ($approval->approved_by_name ?: 'the owner') . ', by ' . str_replace('_', ' ', (string) $approval->approved_via) . '): ' . $title,
                $approval->recordedByUser?->name,
                'recorded',
                $route,
                $approval->rental_work_order_id,
            );
            if ($approval->voided_at) {
                $out[] = $this->entry(
                    'emergency_approval',
                    (string) $approval->voided_at,
                    'Emergency approval voided' . ($approval->void_reason ? ' — ' . $approval->void_reason : '') . ': ' . $title,
                    null,
                    'voided',
                    $route,
                    $approval->rental_work_order_id,
                );
            }
        }

        $variations = \App\Models\RentalWorkOrderVariation::query()->whereIn('rental_work_order_id', $ids)->orderBy('id')->get();
        $raisers = \App\Models\User::query()->withoutGlobalScopes()->whereIn('id', $variations->pluck('raised_by_user_id')->filter()->unique()->all())->pluck('name', 'id');
        foreach ($variations as $variation) {
            $title = $titles[$variation->rental_work_order_id] ?? 'Work order';
            $out[] = $this->entry(
                'variation',
                (string) $variation->raised_at,
                'Variation raised: extra R' . number_format((float) $variation->extra_amount, 2) . ' (new total R' . number_format((float) $variation->new_total, 2) . ') — ' . $title,
                $raisers[$variation->raised_by_user_id] ?? null,
                \App\Models\RentalWorkOrderVariation::STATUS_AWAITING_OWNER,
                $route,
                $variation->rental_work_order_id,
            );
            $decidedWords = match ($variation->status) {
                \App\Models\RentalWorkOrderVariation::STATUS_AUTO_APPROVED => 'Variation auto-approved — within the owner\'s agreed limit',
                \App\Models\RentalWorkOrderVariation::STATUS_APPROVED => 'Variation approved by the owner',
                \App\Models\RentalWorkOrderVariation::STATUS_DECLINED => 'Variation declined by the owner',
                \App\Models\RentalWorkOrderVariation::STATUS_WITHDRAWN => 'Variation withdrawn',
                default => null,
            };
            if ($decidedWords && ($variation->decided_at ?? $variation->raised_at)) {
                $out[] = $this->entry(
                    'variation',
                    (string) ($variation->decided_at ?? $variation->raised_at),
                    $decidedWords . ': ' . $title,
                    null,
                    (string) $variation->status,
                    $route,
                    $variation->rental_work_order_id,
                );
            }
        }

        // Completion rounds — the tenant check (§17.10).
        $rounds = \App\Models\RentalWorkCompletionRound::query()->whereIn('rental_work_order_id', $ids)->orderBy('rental_work_order_id')->orderBy('round_no')->get();
        foreach ($rounds as $round) {
            $title = $titles[$round->rental_work_order_id] ?? 'Work order';
            $out[] = $this->entry(
                'completion_check',
                (string) $round->opened_at,
                ($round->round_no > 1 ? 'Reported done again' : 'Work reported done') . ' by ' . ($round->reported_by_label ?: 'the crew') . ' (round ' . $round->round_no . '): ' . $title,
                null,
                $round->outcome,
                $route,
                $round->rental_work_order_id,
            );
            $answer = match ($round->outcome) {
                \App\Models\RentalWorkCompletionRound::OUTCOME_CONFIRMED => 'Tenant confirmed the work is done',
                \App\Models\RentalWorkCompletionRound::OUTCOME_DISPUTED => 'Tenant reported not complete: ' . ($round->response_note ?: '—'),
                \App\Models\RentalWorkCompletionRound::OUTCOME_ACCEPTED_BY_SILENCE => 'Accepted — no response in ' . max(1, (int) round($round->opened_at->diffInDays($round->window_ends_at ?? $round->opened_at, true))) . ' days',
                default => null,
            };
            if ($answer) {
                $out[] = $this->entry(
                    'completion_check',
                    (string) ($round->responded_at ?? $round->window_ends_at ?? $round->updated_at),
                    $answer . ' (round ' . $round->round_no . ')',
                    null,
                    $round->outcome,
                    $route,
                    $round->rental_work_order_id,
                );
            }
        }

        // "Dispute sent back to the crew / contractor" — read from the work order's own append-only log.
        foreach (\App\Models\RentalWorkOrderUpdate::query()->whereIn('rental_work_order_id', $ids)->where('update_type', 'dispute_sent_back')->with('createdByUser')->orderBy('id')->get() as $update) {
            $kind = ($workOrders->firstWhere('id', $update->rental_work_order_id)?->assignment_type === \App\Models\RentalWorkOrder::ASSIGNMENT_INTERNAL) ? 'crew' : 'contractor';
            $out[] = $this->entry(
                'completion_check',
                (string) $update->created_at,
                "Dispute sent back to the {$kind}: " . ($titles[$update->rental_work_order_id] ?? 'Work order'),
                $update->createdByUser?->name,
                'sent_back',
                $route,
                $update->rental_work_order_id,
            );
        }

        return $out;
    }
    // BUILD 3 END
}
