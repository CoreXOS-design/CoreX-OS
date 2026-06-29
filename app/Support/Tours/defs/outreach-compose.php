<?php

/**
 * AT-121 — Contact Outreach Compose guided tour.
 *
 * The full seller-pitch composer (route seller-outreach.composer.show). Distinct
 * from the core `outreach-composer` tour, which targets the contact record's
 * Outreach TAB (corex.contacts.show) — this one walks the dedicated compose
 * screen: channel, message, sourced facts, send now vs. add-to-queue.
 *
 * Pure data merged by App\Support\Tours\TourRegistry::all(); every `element`
 * points at a real data-tour anchor in seller-outreach/compose.blade.php and
 * _compose-form.blade.php. Permission `outreach.compose` is the route middleware
 * key, so the catalogue only lists it to a user who can reach the composer.
 *
 * @return array<string,array<string,mixed>>
 */

return [
    'outreach-compose' => [
        'key'         => 'outreach-compose',
        'title'       => 'Composing a seller pitch',
        'description' => 'Write a compliant, data-backed outreach message and either send it now or add it to your queue.',
        'route'       => 'seller-outreach.composer.show',
        'permission'  => 'outreach.compose',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            [
                'element' => '[data-tour="oc-channel"]',
                'title'   => 'Choose the channel',
                'body'    => 'Reach the seller by WhatsApp or Email. The message below pre-fills from your agency\'s template for the channel you pick.',
            ],
            [
                'element' => '[data-tour="oc-body"]',
                'title'   => 'The message',
                'body'    => 'Edit freely. The tokens (opt-out and tracking links) are filled in with real per-send links at the moment you send — leave them in place. Every figure you quote is sourced live, so the pitch stays defensible.',
            ],
            [
                'element' => '[data-tour="oc-facts"]',
                'title'   => 'Sourced facts',
                'body'    => 'These are the live, sourced claims behind your pitch — the data the message draws on, so what you tell the seller is always backed by what CoreX can show.',
            ],
            [
                'element' => '[data-tour="oc-send"]',
                'title'   => 'Send now',
                'body'    => 'Opens WhatsApp (or sends the branded email) and records the send for PPRA compliance — you tap Send inside WhatsApp. For WhatsApp this is enabled only during your agency\'s permitted outreach hours.',
            ],
            [
                'element' => '[data-tour="oc-queue"]',
                'title'   => 'Or add to your queue',
                'body'    => 'Prepared this outside sending hours, or lining up a batch? Add it to your Outreach Queue instead — it is ready immediately and you send it by hand from the queue once the send-window is open.',
            ],
        ],
    ],
];
