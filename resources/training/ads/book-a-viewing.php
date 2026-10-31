<?php

/**
 * AT-527 Ad Studio - "Ad 1" for Book a viewing (spec .ai/specs/training-videos.md section 9.21). Filmed from the training script `book-a-viewing` in the CoreX
 * Training Academy (invented data only). NOT approved until Johan says so.
 */
return [
    'type' => 'ad',
    'key' => 'book-a-viewing',
    'title' => 'Book a viewing',
    'screen' => 'book-a-viewing',
    'approved' => false,
    'questions' => [
        'a' => 'Still *losing* track of your viewings?',
        'b' => 'Does your seller know what buyers *said*?',
    ],
    'shots' => [
        ['id' => 'one-diary', 'caption' => 'Every viewing goes in one diary.', 'step' => 0, 'action' => 'highlight', 'zoom' => 1.2, 'proof' => false],
        ['id' => 'few-clicks', 'caption' => 'Book it in a few clicks.', 'step' => 1, 'action' => 'click', 'zoom' => 1.8, 'proof' => false],
        ['id' => 'house-and-buyer', 'caption' => 'Pick the house and the buyer.', 'step' => 4, 'action' => 'highlight', 'zoom' => 1.6, 'proof' => false],
        ['id' => 'feedback', 'caption' => 'Your seller sees the feedback on their own link.', 'step' => 7, 'action' => 'highlight', 'zoom' => 1.6, 'proof' => false],
    ],
    'defaults' => ['length' => 30, 'formats' => ['9:16', '1:1'], 'voice' => null],
    'post' => [
        'caption' => "{question}\n\nBook a viewing in a few clicks, link it to the buyer and the house, and your seller sees the feedback on their own link.\n\n{cta} {website}",
        'hashtags' => ['RealEstate', 'EstateAgent', 'PropertyAgent', 'SouthAfrica', 'PropTech', 'CoreX'],
    ],
];
