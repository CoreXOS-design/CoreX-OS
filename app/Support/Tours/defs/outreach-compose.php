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
        'pick_from'   => 'corex.contacts.index',
        'pick_note'   => 'Open the seller you want to pitch, go to their Outreach tab and click Compose pitch — I\'ll pick up from there.',
        'setup'       => [
            ['action' => 'scrollTop'],
        ],
        'steps' => [
            // The property picker sits above the channel toggle; it is absent in
            // address-only mode, where the engine simply skips this step.
            [
                'element'       => '[data-tour="oc-property"]',
                'section'       => 'Choosing the property',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Pick the property this pitch is about.'],
                'title'         => 'Which property?',
                'body'          => 'The pitch is built around one of this seller\'s properties. Picking a different one reloads the message with that property\'s facts.',
            ],
            [
                'element' => '[data-tour="oc-channel"]',
                'section' => 'Choosing the channel',
                'do'      => ['action' => 'choose', 'say' => 'Click WhatsApp or Email — the page reloads with that channel\'s message.'],
                'title'   => 'Choose the channel',
                'body'    => 'Reach the seller by WhatsApp or Email. The message below pre-fills from your agency\'s template for the channel you pick.',
            ],
            [
                'element'       => '[data-tour="oc-template"]',
                'advanced_only' => true,
                'do'            => ['action' => 'fill', 'say' => 'Pick the template you want to start from.'],
                'title'         => 'Pick a template',
                'body'          => 'Your agency\'s approved wording for this channel. Choosing another one reloads the message with that wording.',
            ],
            [
                'element' => '[data-tour="oc-body"]',
                'section' => 'Writing the message',
                'do'      => ['action' => 'fill', 'say' => 'Make the message your own, leaving the link tokens in place — or press Skip this step if it is ready.'],
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
                'section' => 'Sending it now',
                'do'      => ['action' => 'click', 'say' => 'Click to message the seller now — WhatsApp opens for you to tap Send, and Email goes out straight away (or press Skip this step to queue it).'],
                'title'   => 'Send now',
                'body'    => 'Opens WhatsApp (or sends the branded email) and records the send for PPRA compliance — you tap Send inside WhatsApp. For WhatsApp this is enabled only during your agency\'s permitted outreach hours.',
            ],
            [
                'element' => '[data-tour="oc-queue"]',
                'section' => 'Queueing it for later',
                'do'      => ['action' => 'click', 'say' => 'Click Add to queue — nothing goes to the seller yet; it waits in your Outreach Queue.'],
                'title'   => 'Or add to your queue',
                'body'    => 'Prepared this outside sending hours, or lining up a batch? Add it to your Outreach Queue instead — it is ready immediately and you send it by hand from the queue once the send-window is open.',
            ],
        ],
    ],
];
