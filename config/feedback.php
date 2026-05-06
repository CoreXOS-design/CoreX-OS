<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feedback Delivery Channel
    |--------------------------------------------------------------------------
    |
    | Controls how feedback reports are delivered after submission.
    | 'log'   — writes to storage/logs/feedback.log (local dev)
    | 'email' — sends email to agency recipients (staging / production)
    |
    | When set to 'auto', the channel is derived from APP_ENV:
    |   local → log,  everything else → email
    |
    */
    'channel' => env('FEEDBACK_CHANNEL', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Recipients
    |--------------------------------------------------------------------------
    |
    | If the agency has no feedback_recipients configured, these addresses
    | receive the notification email. Typically the product owner / dev team.
    |
    */
    'fallback_recipients' => array_filter(explode(',', env('FEEDBACK_FALLBACK_RECIPIENTS', ''))),

];
