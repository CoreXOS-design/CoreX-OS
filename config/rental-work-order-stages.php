<?php

/**
 * Rentals work order - the PLAIN stages everyone reads (Johan, 8 Oct 2026, W2): few, plain, and DATA - not labels
 * scattered through PHP and Blade. `RentalWorkOrderClientViewService::stageKey()` decides which stage a work order is
 * in; the words below are what each audience sees. Change a word here and every screen, the portal API and the emails
 * follow. An audience that has no entry for a stage falls back to 'agent'.
 *
 * Stage keys:
 *   created         the work order exists (arranging the contractor / waiting for the go-ahead)
 *   appointment_set a date for the repair is booked
 *   in_progress     the work has started
 *   check_requested the work is reported done and the tenant is asked to check
 *   reopened        the tenant said it is not complete - it is being put right
 *   completed       finished
 *   cancelled       called off
 *   needs_decision  (owner only) a quote or extra work is waiting for the owner
 *
 * THE OFFICE'S finer stages (the agent's badge only - the tenant and the owner keep the plain ones above): the real position before the work
 * starts, so the badge never reads "Created" once the work order has been approved or sent:
 *   approved_to_send     the owner's go-ahead is in (or the quote is within the no-approval limit) - ready to send to the contractor
 *   sent_to_contractor   the work order has gone to the contractor, who has not booked a date yet
 *   with_owner_contractor the owner's own contractor has it, no date yet
 *
 * `status_words` are the SAME names for the raw status wherever a status is printed (the history's "from -> to", the badge's tooltip), so a
 * work order never reads "Ordered" in one place and "Sent to contractor" in another.
 */
return [
    'created' => [
        'agent' => 'Created', 'owner' => 'Created', 'tenant' => 'Created',
    ],
    'appointment_set' => [
        'agent' => 'Appointment set', 'owner' => 'Appointment set', 'tenant' => 'Appointment set',
    ],
    'in_progress' => [
        'agent' => 'In progress', 'owner' => 'In progress', 'tenant' => 'In progress',
    ],
    'check_requested' => [
        'agent' => 'Reported complete — tenant check', 'owner' => 'Reported complete — the tenant is checking', 'tenant' => 'Reported complete — please check',
    ],
    'reopened' => [
        'agent' => 'Not complete — reopened', 'owner' => 'Not complete — reopened', 'tenant' => 'Not complete — reopened',
    ],
    'completed' => [
        'agent' => 'Completed', 'owner' => 'Completed', 'tenant' => 'Completed',
    ],
    'cancelled' => [
        'agent' => 'Cancelled', 'owner' => 'Cancelled', 'tenant' => 'Cancelled',
    ],
    'needs_decision' => [
        'agent' => 'Waiting for the owner', 'owner' => 'Needs your decision', 'tenant' => 'Created',
    ],
    'approved_to_send' => [
        'agent' => 'Approved - ready to send', 'owner' => 'Created', 'tenant' => 'Created',
    ],
    'sent_to_contractor' => [
        'agent' => 'Sent to contractor', 'owner' => 'Created', 'tenant' => 'Created',
    ],
    'with_owner_contractor' => [
        'agent' => "With the owner's contractor", 'owner' => 'Created', 'tenant' => 'Created',
    ],

    'status_words' => [
        'reported' => 'Created',
        'ordered' => 'Sent to contractor',
        'in_progress' => 'In progress',
        'disputed' => 'Not complete - reopened',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],
];
