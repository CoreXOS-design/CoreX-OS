<?php

/**
 * Mobile app — an EXTERNAL-LINK entry in the guided-tour catalogue.
 *
 * Same catalogue, different shape: a definition that carries `external_url`
 * instead of `route` + `steps` renders as a card in the Guided Tours directory
 * whose button opens that URL in a new tab. It has no on-page anchors, so it
 * never auto-launches, never binds a "?" launcher to a screen, and is skipped by
 * Ellie's tour knowledge (which requires steps).
 *
 * It belongs here because Guided Tours is where an agent goes to answer "how do
 * I use CoreX" — and "on your phone" is part of that answer. Anything else would
 * mean a second, parallel directory of help cards.
 *
 * Merged by App\Support\Tours\TourRegistry::all(); rendered by
 * App\Http\Controllers\CoreX\GuidedToursController.
 */

return [
    'mobile-app' => [
        'key'          => 'mobile-app',
        'title'        => 'Mobile app',
        'description'  => 'Take CoreX with you. Get the CoreX mobile app on your phone so your contacts, listings and diary are with you out on show.',
        'external_url' => 'https://corexweb.co.za/mobile-app',
        'cta'          => 'Get the app',
    ],
];
