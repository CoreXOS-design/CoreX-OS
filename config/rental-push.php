<?php

/**
 * AT-508 (P1) — the phone notifications a tenant or owner can receive about rentals.
 * .ai/specs/at-508-mobile-rentals.md §13 (Johan, 25 Oct 2026):
 *   MANDATORY  — a decision is needed, anything urgent, appointments, work finished, notice outcomes. Cannot be switched off.
 *   SWITCHABLE — purely informational updates only. Agency default (Rental portal settings / Setup Wizard) + a per-person override.
 * The mobile app shows ONLY the switchable ones (GET /api/v1/client/push-preferences).
 * Wording is neutral on purpose: no agency name, no branding (multi-agency, NN #9).
 */
return [
    'categories' => [
        'decision_needed' => [
            'audience' => 'owner', 'mandatory' => true,
            'label' => 'A decision is waiting for you',
            'description' => 'A repair, quote or extra cost needs your approval.',
        ],
        'work_check_requested' => [
            'audience' => 'tenant', 'mandatory' => true,
            'label' => 'Please check the finished work',
            'description' => 'Work at your home has been reported finished and we need you to confirm it.',
        ],
        'work_finished' => [
            'audience' => 'owner', 'mandatory' => true,
            'label' => 'Work has been reported finished',
            'description' => 'The work at your property has been reported as done.',
        ],
        'appointment' => [
            'audience' => 'both', 'mandatory' => true,
            'label' => 'Repair appointment',
            'description' => 'A repair appointment has been booked or changed.',
        ],
        'notice_outcome' => [
            'audience' => 'both', 'mandatory' => true,
            'label' => 'Notice update',
            'description' => 'A notice about the lease has been accepted or declined.',
        ],
        'fault_status' => [
            'audience' => 'tenant', 'mandatory' => false,
            'label' => 'Progress on a fault you reported',
            'description' => 'Your fault was sent to the owner, approved, or declined.',
        ],
        'work_order_update' => [
            'audience' => 'owner', 'mandatory' => false,
            'label' => 'Repair updates',
            'description' => 'General updates on repairs at your property.',
        ],
        'inspection_ready' => [
            'audience' => 'both', 'mandatory' => false,
            'label' => 'An inspection report is ready',
            'description' => 'A move-in or move-out inspection report is ready for you to view.',
        ],
    ],
];
