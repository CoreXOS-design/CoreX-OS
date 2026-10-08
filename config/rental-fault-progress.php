<?php

/**
 * Rentals - the ONE progress line a fault follows from "reported" to "work completed" (Johan, 8 Oct 2026). Fault AND work
 * order in a single sequence. `App\Services\Rentals\RentalFaultProgressService` decides which step each fault/work order
 * has really reached (from the real records - never a typed status); the WORDS below are data, per audience:
 * 'tenant', 'owner', 'agent'. A missing audience falls back to 'agent'. Change a word here and the portal, the API and
 * the emails follow.
 *
 * Steps, in order: sent_to_agent, agent_reviewing, sent_to_owner, owner_decided (owner_approved | not_approved),
 * sent_to_contractor (+ _internal / _owner variants), appointment_set, in_progress, completed (completed_check while the
 * tenant is asked to check), plus the plain side states reopened and cancelled.
 */
return [
    'sent_to_agent' => ['tenant' => 'Sent to your agent', 'owner' => 'Reported', 'agent' => 'Reported'],
    'agent_reviewing' => ['tenant' => 'Agent reviewing', 'owner' => 'Your agent is reviewing it', 'agent' => 'Under agent review'],
    'sent_to_owner' => ['tenant' => 'Sent to owner for approval', 'owner' => 'Sent to you for approval', 'agent' => 'Sent to owner for approval'],
    'owner_approved' => ['tenant' => 'Owner approved', 'owner' => 'You approved', 'agent' => 'Owner approved'],
    // The tenant NEVER sees the owner's reason or the agent's notes - only this neutral line.
    'not_approved' => ['tenant' => 'Not approved — your agent will contact you', 'owner' => 'You declined', 'agent' => 'Owner declined'],
    'sent_to_contractor' => ['tenant' => 'Sent to contractor for scheduling', 'owner' => 'Sent to contractor for scheduling', 'agent' => 'Sent to contractor for scheduling'],
    'sent_to_contractor_internal' => ['tenant' => 'Sent to our maintenance team for scheduling', 'owner' => 'Sent to our maintenance team for scheduling', 'agent' => 'Sent to our maintenance team for scheduling'],
    'sent_to_contractor_owner' => ['tenant' => "Sent to the owner's contractor for scheduling", 'owner' => 'Sent to your contractor for scheduling', 'agent' => "Sent to the owner's contractor for scheduling"],
    'appointment_set' => ['tenant' => 'Appointment set', 'owner' => 'Appointment set', 'agent' => 'Appointment set'],
    'in_progress' => ['tenant' => 'Work in progress', 'owner' => 'Work in progress', 'agent' => 'Work in progress'],
    'completed' => ['tenant' => 'Work completed', 'owner' => 'Work completed', 'agent' => 'Work completed'],
    'completed_check' => ['tenant' => 'Work completed — please check', 'owner' => 'Work completed — the tenant is checking', 'agent' => 'Work completed — tenant check'],
    'reopened' => ['tenant' => 'Not complete — being put right', 'owner' => 'Not complete — being put right', 'agent' => 'Not complete — reopened'],
    'cancelled' => ['tenant' => 'Cancelled', 'owner' => 'Cancelled', 'agent' => 'Cancelled'],
    // A fault closed with no repair work (e.g. resolved with the tenant's first-aid steps, or the owner handled it).
    'closed' => ['tenant' => 'Closed', 'owner' => 'Closed', 'agent' => 'Closed'],
];
