<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue failure alert EMAILS (new alerting built 2026-08-23)
    |--------------------------------------------------------------------------
    | Default OFF. Johan, 2026-08-23, on shipping this batch to live: "as long
    | as we are not activating more alerts Im fine with it. so keep it off,
    | but fix it so we have less errors running." Checked live's
    | dev_settings.queue_backlog_alert_emails before this flag existed and
    | found it POPULATED (a.roets12@gmail.com) — deploying either of the two
    | NEW alert paths below as-is, unguarded, would have started emailing
    | that address the moment a job next fails on live. That is activating a
    | new alert, which he explicitly ruled out.
    |
    | Gates ONLY:
    |   - App\Support\Queue\QueueFailureAlerter::notify() — the per-job-class
    |     failure digest (App\Mail\QueueJobFailureDigestMail).
    |   - App\Console\Commands\QueueHealthcheck::notifyGrowth() — the
    |     failed_jobs-growth alert (App\Mail\QueueFailedJobsGrowthAlertMail).
    | Both are brand new today. Log::critical in both call sites fires
    | UNCONDITIONALLY regardless of this flag — that's the "fewer errors
    | running" visibility Johan still gets: every failure is still logged,
    | nothing about the safety net is weakened, only the email is held back.
    |
    | Deliberately does NOT touch QueueHealthcheck::notifyStalled() /
    | QueueBacklogAlertMail (the pre-existing stalled-worker alert) — that
    | already runs in production today, unrelated to this batch, and
    | changing its behavior was never asked for.
    |
    | See .ai/audits/2026-08-23-queue-failed-jobs-triage.md and
    | .ai/audits/2026-08-23-oversight-nudges-status.md for the full story.
    */
    'failure_digest_emails_enabled' => (bool) env('QUEUE_FAILURE_DIGEST_EMAILS_ENABLED', false),

];
