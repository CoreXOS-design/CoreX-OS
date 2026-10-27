<?php

/**
 * Article Library (AT-456 Part C) — platform-level defaults. One shelf for every agency, so there is
 * no per-agency setting here; the owner can override these on /admin/article-library/settings
 * (stored as DevSettings), and these are what apply until they do.
 */
return [

    /*
    | AT-509 — original-wording guard. A brief must prove it was rewritten, not copied, before it is stored.
    |  max_overlap   share of the brief's 6-word runs that also occur in the feed text; above this the brief fails
    |  max_run_words longest stretch of words copied verbatim; at or above this the brief fails
    |  retries       extra attempts (with the copied wording quoted back to the AI) before the item is skipped
    */
    'originality' => [
        'max_overlap'   => 0.15,
        'max_run_words' => 12,
        'retries'       => 1,
    ],

];
