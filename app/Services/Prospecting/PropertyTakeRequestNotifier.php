<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\PropertyTakeRequest;
use App\Models\User;
use App\Services\CommandCenter\NotificationDispatcher;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). Notify-and-confirm, the smallest shape that works —
 * same NotificationDispatcher every other agency notification already routes
 * through (mirrors StaleClaimController::reassign()'s use of it), not a new channel.
 * Recipients: same permission gate as the review screen (prospecting_setup.manage —
 * reuses the existing admin/BM-scoped permission, no new one invented).
 */
class PropertyTakeRequestNotifier
{
    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    public function notifyApprovers(PropertyTakeRequest $request): void
    {
        $approvers = User::where('agency_id', $request->agency_id)
            ->where('is_active', 1)
            ->get()
            ->filter(fn (User $u) => $u->hasPermission('prospecting_setup.manage'));

        $label = $request->property?->address ?: 'a property';
        $requester = $request->requestedBy?->name ?? 'An agent';

        foreach ($approvers as $approver) {
            $this->dispatcher->fire($approver, 'deeds.duplicate_take_pending', $request, [
                'title'            => 'Duplicate-property take needs approval',
                'body'             => "{$requester} wants to take {$label} (off market {$request->age_days} days) from Deeds Capture.",
                'subject_label'    => $label,
                'action_url'       => route('corex.property-take-requests.index', [], false),
                'severity'         => 'warning',
                'threshold_hit_at' => now(),
            ]);
        }
    }

    public function notifyRequesterOfDecision(PropertyTakeRequest $request): void
    {
        $requester = $request->requestedBy;
        if (!$requester) {
            return;
        }

        $label = $request->property?->address ?: 'a property';
        $approved = $request->status === PropertyTakeRequest::STATUS_APPROVED;

        $this->dispatcher->fire($requester, 'deeds.duplicate_take_decided', $request, [
            'title'            => $approved ? 'Take request approved' : 'Take request rejected',
            'body'             => $approved
                ? "{$label} is yours — it's now Prospecting under your name."
                : "Your request to take {$label} was rejected." . ($request->decision_note ? ' Reason: ' . $request->decision_note : ''),
            'subject_label'    => $label,
            'action_url'       => route('corex.deeds-capture.index', [], false),
            'severity'         => $approved ? 'info' : 'warning',
            'threshold_hit_at' => now(),
        ]);
    }
}
