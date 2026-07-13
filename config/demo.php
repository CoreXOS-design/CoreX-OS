<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Automated demo refresh
    |--------------------------------------------------------------------------
    |
    | `demo:refresh` DROPS the demo database and rebuilds it from scratch. It is
    | scheduled daily but only acts once per `interval_days`, so a missed or
    | failed night self-heals on the next tick instead of waiting a full cycle.
    |
    | `enabled` is a DEDICATED opt-in, deliberately separate from
    | DEMO_SEED_ALLOWED. Allowing an operator to hand-run a seed is a different
    | decision from authorising an unattended 03:00 job to drop the database, and
    | one must never silently imply the other. Absent → false → the command is a
    | no-op, so live and staging are safe by default even though they run the
    | same scheduler off the same shared routes/console.php.
    |
    */

    'refresh' => [
        'enabled'        => env('DEMO_REFRESH_ENABLED', false),
        'interval_days'  => (int) env('DEMO_REFRESH_INTERVAL_DAYS', 3),

        // The EXACT database this box is permitted to wipe, named explicitly.
        // demo:refresh refuses unless the resolved 'demo' connection points at
        // precisely this name. Nothing is inferred — a mistyped or inherited
        // DB_DEMO_DATABASE cannot silently redirect the wipe at another database,
        // because that database's name will not match this one.
        //
        // Absent → the command refuses outright. There is no default: "which
        // database may I destroy" is never a question to answer by convention.
        'database'       => env('DEMO_REFRESH_DATABASE'),

        // Environments where an automated wipe is conceivable at all. Live is
        // 'production' and staging is 'staging'; neither can ever match.
        'environments'   => ['local', 'demo'],

        // Rollback safety net: a dump is taken BEFORE the wipe and restored if
        // the rebuild or its verification fails, so a broken seeder can never
        // leave demo1 serving an empty database until a human notices.
        'backup_path'    => storage_path('app/demo-backups'),
        'keep_backups'   => 3,
    ],

];
