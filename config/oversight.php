<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Oversight nudge emails (OversightDigestJob -> OversightNudgeMail)
    |--------------------------------------------------------------------------
    | Default OFF (Johan, 2026-08-23). The mail-namespace bug fixed the same
    | day meant this send path failed 100% of the time since the feature
    | shipped — nobody has ever seen what real volume looks like in
    | production. Turning the fix on without volume-testing first risks
    | repeating the "staff got flooded with email" incident from CoreX's
    | original live cutover, at a real manager's inbox this time.
    |
    | This gates ONLY the outbound Mail::queue() call in OversightDigestJob —
    | not dispatching, so nothing ever enters the mail queue while off (a
    | queued Mailable is processed by Laravel's own internal
    | SendQueuedMailable job; there is no clean hook to abort mid-flight once
    | queued without touching framework internals, so not-queuing in the
    | first place is both simpler and trivially safe against failed_jobs
    | pile-up). The OversightNudge row (idempotency tracking) and the in-app
    | DatabaseNotification are UNAFFECTED by this flag — they keep recording
    | normally either way. This matters: if idempotency tracking were also
    | suppressed while this flag is off, every nudge that "should" have fired
    | during the off period would look brand new the moment this is switched
    | on, and all fire in one burst — recreating the exact flood this flag
    | exists to prevent. Suppressing only the send, while still recording
    | that the nudge occurred, means turning this on later starts clean.
    |
    | See .ai/audits/2026-08-23-queue-failed-jobs-triage.md for the full
    | investigation and the volume analysis needed before this is turned on.
    */
    'nudges_enabled' => (bool) env('OVERSIGHT_NUDGES_ENABLED', false),

];
