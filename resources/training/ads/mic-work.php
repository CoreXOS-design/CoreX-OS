<?php

/**
 * AT-527 Ad Studio - "Ad 1" for Market Intelligence (spec .ai/specs/training-videos.md section 9.21). Formula: Question -> Solution shots -> Call to action.
 * Filmed from the training script `mic-work` in the CoreX Training Academy, whose rows are invented (TrainingMicSeeder: "Academy Lane" exists nowhere), so no real
 * client, agent or address can appear. `step` is the 0-based step of the training script the shot shows. A word between asterisks is drawn in the cyan accent.
 * NOT approved until Johan says so: an unapproved script cannot be generated.
 */
return [
    'type' => 'ad',
    'key' => 'mic-work',
    'title' => 'Market Intelligence',
    'screen' => 'mic-work',
    'approved' => false,
    'subline' => 'Every owner. Every price drop. One screen.',
    'badge' => ['text' => '', 'example' => false, 'source' => ''],   // optional cyan stat circle: a real sourced number, or an example labelled as such
    'questions' => [
        'a' => 'Still *guessing* which owners to phone?',
        'b' => 'Why wait for sellers to *call* you?',
    ],
    'shots' => [
        ['id' => 'all-houses', 'caption' => 'See every house for sale in your area.', 'step' => 0, 'action' => 'highlight', 'zoom' => 1.3, 'proof' => false],
        ['id' => 'price-drops', 'caption' => 'Know when a price drops.', 'step' => 3, 'action' => 'highlight', 'zoom' => 2.0, 'proof' => false],
        ['id' => 'example', 'caption' => 'Example: a house on Academy Lane cut its price twice.', 'selector' => '[data-qa="work-row"]', 'action' => 'highlight', 'zoom' => 1.6, 'proof' => true],
        ['id' => 'neighbours', 'caption' => 'Phone the neighbours the day a house sells.', 'step' => 4, 'action' => 'click', 'zoom' => 1.5, 'proof' => false],
    ],
    'defaults' => ['length' => 30, 'formats' => ['9:16', '1:1'], 'voice' => null],
    'post' => [
        'caption' => "{question}\n\nCoreX shows you every house for sale in your area, tells you when a price drops, and lists the neighbours to phone the day a house sells.\n\n{cta} {website}",
        'hashtags' => ['RealEstate', 'EstateAgent', 'PropertyAgent', 'SouthAfrica', 'PropTech', 'CoreX'],
    ],
];
