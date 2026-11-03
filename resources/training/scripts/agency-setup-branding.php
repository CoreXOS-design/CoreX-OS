<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-branding',
    'title' => 'Add your logo and colours',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/branding',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now make CoreX look like your agency.',
    'steps' => [
        [
            'selector' => 'input[name="logo"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose your logo file here.',
            'why' => 'It prints on your documents and emails.',
        ],
        [
            'selector' => 'input[name="default_color"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Check the header colour. Type a new one if it is not your brand colour.',
            'why' => 'Page headers and letterheads use this colour.',
        ],
        [
            'selector' => 'input[name="button_color"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose the button colour.',
            'why' => 'Every main button in CoreX uses it.',
        ],
        [
            'selector' => 'input[name="sidebar_color"]',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose the highlight colour for the side menu.',
            'why' => 'Your team sees the menu in your colours all day.',
        ],
        [
            'selector' => 'div:has(> #f_viewing_pack_cover_slogan)',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Home is where we start',
            'narration' => 'Type a short slogan for your buyer viewing packs.',
            'why' => 'It prints on the cover of every pack a buyer receives.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your colours apply as soon as you save.',
        ],
    ],
    'outro' => 'Next: your offices.',
];
