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

    /*
    |--------------------------------------------------------------------------
    | Backlog alarm — PER-LANE thresholds (2026-08-28)
    |--------------------------------------------------------------------------
    | QueueHealthcheck::checkStalledQueue() used to scan the WHOLE `jobs` table
    | as one undifferentiated pile and judge it against a single 600s deadline.
    | That was correct while every job shared `default`. It stopped being correct
    | the moment slow work was given its own lane.
    |
    | Concretely: on 2026-08-27 TranscribeVoiceNoteJob was moved to a dedicated
    | `transcription` lane (see the job's docblock) precisely so a batch of voice
    | notes could no longer head-of-line-block the fast scheduled work. It worked —
    | but the alarm still counted those notes. A voice note costs ~30-90s of
    | whisper.cpp and box-wide transcription concurrency is exactly 1 by design
    | (TranscriptionService::WHISPER_LOCK), so a normal nightly batch of ~18 notes
    | takes ~17 minutes to drain and ALWAYS parks the oldest waiting job well past
    | 600s. The alarm fired at 22:15 on 08-26, 08-27 and 08-28 while every worker
    | was healthy and every latency-sensitive lane was running on time.
    |
    | A false alarm every night is worse than no alarm: it trains the owner to
    | ignore the one night it is real. So each lane is now judged against what is
    | normal FOR THAT LANE.
    |
    | Two lane shapes:
    |
    |   LATENCY lanes (the default, and every lane not listed with overrides) —
    |   someone or something is waiting on these, so depth over time is itself the
    |   fault. Unchanged behaviour: oldest waiting job older than `max_age` (the
    |   command's --max-age, 600s) => alarm. The important lanes still alarm just
    |   as fast as they did before this change.
    |
    |   BATCH lanes (`requires_progress` => true) — a deep queue is the NORMAL
    |   steady state, so depth proves nothing. What proves a fault is the queue not
    |   MOVING. A batch lane alarms only when the oldest waiting job is past
    |   `max_age` AND the head of that lane has not advanced for `progress_window`
    |   seconds. Head-advance is used rather than `reserved_at` deliberately: with
    |   `--sleep=3` there is a ~3s window between finishing one job and reserving
    |   the next where NOTHING on the lane is reserved, and a run landing in that
    |   window would read a healthy worker as dead (~1% per run, ~4% a night).
    |   The head of the waiting queue does not flicker like that — it advances the
    |   instant a job is picked up and never moves backwards.
    |
    | `progress_window` must exceed the longest a single job on that lane can
    | legitimately hold the head, i.e. that job's own $timeout. TranscribeVoiceNoteJob
    | has $timeout = 1200, so 1500 leaves margin without letting a genuinely wedged
    | lane hide for long. Worker-process DEATH is not this alarm's job anyway —
    | corex:queue-worker-liveness-alert catches a FATAL/STOPPED process within a
    | minute, on a completely independent mechanism (supervisorctl, not the DB).
    |
    | `supervisor` is the program to restart for that lane. The alert email used to
    | tell the reader to restart `corex-worker-live:*` no matter which lane was
    | actually stalled — wrong, and actively misleading, for all seven other lanes.
    | Verified against /etc/supervisor/conf.d/*.conf on the live host, 2026-08-28.
    */
    'backlog' => [

        'default_supervisor' => 'corex-worker-live:*',

        'lanes' => [
            'default'        => ['supervisor' => 'corex-worker-live:*'],
            'bg_removal'     => ['supervisor' => 'corex-worker-live:*'],
            'matching'       => ['supervisor' => 'corex-worker-live-matching:*'],
            'mail'           => ['supervisor' => 'corex-worker-live-mail:*'],
            'webhooks'       => ['supervisor' => 'corex-worker-live-webhooks:*'],
            'buyer-matching' => ['supervisor' => 'corex-worker-live-buyer-matching:*'],
            'p24import'      => ['supervisor' => 'corex-worker-p24import:*'],
            'p24images'      => ['supervisor' => 'corex-worker-p24images:*'],

            'transcription'  => [
                'max_age'           => 900,
                'requires_progress' => true,
                'progress_window'   => 1500,
                'supervisor'        => 'corex-worker-live-transcription:*',
            ],
        ],
    ],

];
