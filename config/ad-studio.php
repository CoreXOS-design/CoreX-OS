<?php

/*
|--------------------------------------------------------------------------
| Ad Studio - one-click video ads (AT-527, spec .ai/specs/training-videos.md section 9.21)
|--------------------------------------------------------------------------
|
| CoreX's own marketing, made by the System Owner. NOT an agency setting (same whichever owner is signed in), so there is deliberately no Setup Wizard
| entry (non-negotiable #10a). Every brand value below is only the DEFAULT: the System Owner edits them in Video ads -> Brand settings
| (table ad_studio_settings).
*/

return [

    // Ad scripts (type "ad"): resources/training/ads/<feature-key>.php, written by Claude and approved by Johan.
    'scripts_dir' => resource_path('training/ads'),

    // Private disk folder the finished ads are copied to (data volume), streamed through authenticated routes.
    'disk' => 'local',
    'folder' => 'video-ads',

    'lengths' => [15, 30, 60],

    // format => who it is for (shown in the editor and on the list)
    'formats' => [
        '9:16' => 'Reels, Shorts, TikTok',
        '1:1' => 'Facebook and LinkedIn feed',
        '16:9' => 'YouTube',
    ],

    // What the editor's live check enforces (spec 9.21.2). Estimated length = words / words_per_second + line_pad per line + end card.
    'limits' => [
        'question_words' => 9,
        'question_chars' => 60,
        'caption_words' => 12,
        'caption_chars' => 70,
        'shots_min' => 1,
        'shots_max' => 8,
        'zoom_min' => 1.0,
        'zoom_max' => 2.5,
        'words_per_second' => 2.5,
        'line_pad_seconds' => 0.6,
        'end_card_seconds' => 3.5,
        'post_caption_chars' => 2200,
        'hashtags_max' => 15,
    ],

    // Words that may never describe CoreX in an ad (Johan's wording rule): the line is "HFC runs on CoreX, and we keep expanding it".
    'banned_build_words' => ['build', 'builds', 'building', 'built', 'develop', 'develops', 'developing', 'developed'],

    // Phrases that promise money (no price or discount promises in an ad).
    'banned_promises' => ['discount', 'cheapest', 'lowest price', 'guarantee', 'guaranteed', 'free for life', '% off'],

    'brand' => [
        'logo' => 'images/corex-os-logo.svg',   // a path under public/ (or a path on the public disk after an upload)
        'colours' => ['primary' => '#0b2a4a', 'secondary' => '#0e5a7a', 'accent' => '#33c4e0', 'text' => '#ffffff'],
        'website' => 'https://corexos.co.za',
        'email' => 'johan@corexos.co.za',
        'phone' => '+27 76 618 5578',
        'social' => ['facebook' => '', 'instagram' => '', 'linkedin' => '', 'youtube' => '', 'tiktok' => ''],
        'join_line' => 'Join CoreX',
        'cta_line' => 'Ask us for a demo',
        'tagline' => 'HFC runs on CoreX, and we keep expanding it.',
        'music' => null,   // no track until Johan approves one (spec 9.21.6)
    ],

    'social_labels' => ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok'],
];
