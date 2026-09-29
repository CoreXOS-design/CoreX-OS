<?php

declare(strict_types=1);

namespace App\Services\Syndication;

use Carbon\Carbon;

/**
 * The single readonly view of a property's layer-3 approval state.
 * Spec: .ai/specs/syndication-approval-gate.md §6.1.
 *
 * Every surface renders from THIS — the syndication panel banner, the badge
 * on the property page, the badge on each Properties-list row, and the JSON
 * the syndication panel endpoint returns. One computation, one truth; a
 * surface can never disagree with the gate.
 *
 * Deliberately mirrors App\Services\Compliance\ReadinessReport so layer 2 and
 * layer 3 read the same way at every call site.
 */
final class SyndicationApprovalState
{
    /** The feature is off for this agency — every surface renders nothing. */
    public const BADGE_NONE = 'none';
    /** Compliance complete, nobody has asked yet — the Send-for-approval state. */
    public const BADGE_NEEDS = 'needs_approval';
    /** A request is pending a decision. */
    public const BADGE_AWAITING = 'awaiting_approval';
    /** Approved — portals unlocked, permanently (D2). */
    public const BADGE_APPROVED = 'approved';
    /** The last request was rejected. */
    public const BADGE_REJECTED = 'not_approved';

    public function __construct(
        public readonly bool $required,
        public readonly bool $approved,
        public readonly string $badge,
        public readonly ?Carbon $approvedAt = null,
        public readonly ?string $approvedByName = null,
        public readonly ?int $pendingApprovalId = null,
        public readonly ?Carbon $requestedAt = null,
        public readonly ?string $requestedByName = null,
        public readonly ?string $lastDecisionNote = null,
        public readonly bool $canRequest = false,
        public readonly bool $complianceComplete = false,
        public readonly array $approverNames = [],
    ) {
    }

    /** Feature off, or nothing to show — every surface renders nothing at all. */
    public function isSilent(): bool
    {
        return ! $this->required;
    }

    public function badgeLabel(): string
    {
        return match ($this->badge) {
            self::BADGE_NEEDS     => 'Needs approval',
            self::BADGE_AWAITING  => 'Awaiting approval',
            self::BADGE_APPROVED  => 'Approved for syndication',
            self::BADGE_REJECTED  => 'Not approved',
            default               => '',
        };
    }

    public function toArray(): array
    {
        return [
            'required'            => $this->required,
            'approved'            => $this->approved,
            'badge'               => $this->badge,
            'badge_label'         => $this->badgeLabel(),
            'approved_at'         => $this->approvedAt?->toIso8601String(),
            'approved_by'         => $this->approvedByName,
            'pending_approval_id' => $this->pendingApprovalId,
            'requested_at'        => $this->requestedAt?->toIso8601String(),
            'requested_by'        => $this->requestedByName,
            'last_decision_note'  => $this->lastDecisionNote,
            'can_request'         => $this->canRequest,
            'compliance_complete' => $this->complianceComplete,
            'approvers'           => $this->approverNames,
        ];
    }
}
