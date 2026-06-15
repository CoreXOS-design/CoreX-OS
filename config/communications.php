<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Communication Archive (AT-32 / AT-33)
    |--------------------------------------------------------------------------
    | Storage destination for raw .eml / .json payloads and attachments. The
    | content-addressed writer abstracts this — swap to a Storage Box / S3
    | bucket by changing this disk, no code change. Default 'local' resolves to
    | storage/app/private/communications/.
    */
    'disk' => env('COMMUNICATIONS_DISK', 'local'),

    /*
    | Inbound grace window (calendar days) before an unmatched inbound item
    | prunes. Clamped to a maximum of 5 by CommunicationPending::graceDays().
    */
    'pending_grace_days' => (int) env('COMMUNICATIONS_PENDING_GRACE_DAYS', 4),

];
