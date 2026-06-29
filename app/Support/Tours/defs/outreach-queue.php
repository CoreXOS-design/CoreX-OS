<?php

/**
 * AT-121 — Outreach Queue guided tour.
 *
 * Teaches the SIMPLIFIED model (AT-117): you prepare messages anytime, they are
 * READY immediately (no scheduling / no due-time), and the ONLY limit on sending
 * is the agency send-window — outside permitted hours the send buttons disable.
 *
 * Pure data merged by App\Support\Tours\TourRegistry::all(); every `element`
 * points at a real data-tour anchor in corex/outreach-queue/index.blade.php.
 * Permission `outreach_queue.view` is the route middleware key (AT-120), so the
 * catalogue only lists it to a user who can reach the queue.
 *
 * @return array<string,array<string,mixed>>
 */

return [
    'outreach-queue' => [
        'key'         => 'outreach-queue',
        'title'       => 'Working your Outreach Queue',
        'description' => 'Send the WhatsApp messages you prepared earlier — by hand, within your agency\'s permitted hours.',
        'route'       => 'corex.outreach-queue.index',
        'permission'  => 'outreach_queue.view',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="oq-intro"]',
                'title'   => 'Your outreach queue',
                'body'    => 'This is where the WhatsApp messages you prepared earlier — from a contact or the Core-Matches share — wait for you to send them. There is no scheduling: a message you add is ready straight away.',
            ],
            [
                'element' => '[data-tour="oq-ready"]',
                'title'   => 'Ready to send',
                'body'    => 'Everything you have queued sits here, ready now. Work down the list one by one — each row shows the contact, the source, and a preview of the message you prepared.',
            ],
            [
                'element' => '[data-tour="oq-open"]',
                'title'   => 'Open WhatsApp, then you tap Send',
                'body'    => 'This opens the pre-filled WhatsApp chat — you tap Send inside WhatsApp to deliver it (CoreX opens the chat; you send it). The one limit: you can only send during your agency\'s permitted outreach hours. Outside those hours these buttons are disabled and the message simply waits here until the window opens.',
            ],
        ],
    ],
];
