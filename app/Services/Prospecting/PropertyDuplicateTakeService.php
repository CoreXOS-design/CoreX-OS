<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\User;
use App\Services\Audit\PropertyAuditService;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21).
 *
 * "NO SILENT REASSIGNMENT, ever. The agent's click is the deliberate act, and it
 * must be recorded — who took it, from whom, when, and which band/date justified
 * it." Named after the incident that made this non-negotiable: agent offboarding
 * silently reassigning contacts left 13 real cases unattributed on live.
 *
 * Called ONLY when a deeds-capture match landed in the auto-take band — the
 * calling controller has already refused active/no-go and filed a pending
 * approval request for the middle band before this ever runs.
 */
class PropertyDuplicateTakeService
{
    public function __construct(private readonly PropertyAuditService $auditService) {}

    public function reassign(Property $property, User $capturingAgent, PropertyDuplicateAgeResult $age): Property
    {
        $oldAgentId = $property->agent_id;
        $oldStatus = $property->status;

        if ((int) $oldAgentId === (int) $capturingAgent->id && $oldStatus === Property::STATUS_PROSPECTING) {
            return $property; // already exactly right — nothing to record
        }

        $property->status = Property::STATUS_PROSPECTING;
        $property->agent_id = $capturingAgent->id;
        $property->save();

        $this->auditService->log(
            property: $property,
            eventCategory: 'property',
            eventType: 'deeds_duplicate_reassigned',
            user: $capturingAgent,
            oldValues: ['agent_id' => $oldAgentId, 'status' => $oldStatus],
            newValues: ['agent_id' => $capturingAgent->id, 'status' => Property::STATUS_PROSPECTING],
            metadata: [
                'band' => $age->band,
                'age_days' => $age->days,
                'date_field_used' => $age->dateField,
                'date_is_fallback' => $age->isFallback,
                'via' => 'deeds_capture',
            ],
            humanSummary: 'Taken via deeds capture by ' . $capturingAgent->name
                . ' — off market ' . $age->days . ' days (' . $age->dateFieldLabel() . '), auto-take band.',
        );

        return $property;
    }
}
