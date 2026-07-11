<?php

namespace App\Console\Commands\Demo;

use App\Support\DemoResetSchedule;
use App\Support\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Destroys and rebuilds the demo database.
 *
 * Spec: .ai/specs/demo-access-control.md §6.7
 *
 * ══ THIS COMMAND DROPS EVERY TABLE ══
 *
 * It refuses to run unless Instance::isDemo(). A migrate:fresh that fires on
 * primary is an extinction event — every property, deal, contact and signed
 * document, gone. The guard is the first thing in handle(), it is not
 * conditional on any flag, and --force does NOT bypass it.
 *
 * Scheduled daily at 03:00 SAST; no-ops unless DemoResetSchedule::isResetDay().
 * The schedule and the countdown banner both read that same function, so the
 * number on screen cannot disagree with what actually happens.
 *
 * The demo-access GRANTS are untouched by this — they live on primary. That is
 * the entire reason they live on primary.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset
                            {--scheduled : Called by the scheduler — no-op unless today is a reset day}';

    protected $description = 'Wipe and reseed the demo database (DEMO INSTANCES ONLY — refuses to run on primary)';

    public function handle(): int
    {
        // ── The guard. Not negotiable, not bypassable. ──
        if (! Instance::isDemo()) {
            $this->error('REFUSED: demo:reset drops every table, and this instance is not a demo.');
            $this->line('  COREX_INSTANCE_ROLE is currently: ' . Instance::role());
            $this->line('  If you genuinely meant to wipe a demo, set COREX_INSTANCE_ROLE=demo on that host.');

            Log::warning('[demo-access] demo:reset was invoked on a NON-DEMO instance and refused.', [
                'role' => Instance::role(),
            ]);

            return self::FAILURE;
        }

        // Scheduler calls this daily; only every 3rd day is a reset day.
        if ($this->option('scheduled') && ! DemoResetSchedule::isResetDay()) {
            $this->info('Not a reset day. Next reset: ' . DemoResetSchedule::next()->toDayDateTimeString());

            return self::SUCCESS;
        }

        $this->warn('Resetting the demo database — dropping all tables.');

        // migrate:fresh drops every table and re-runs migrations from scratch.
        $this->call('migrate:fresh', ['--force' => true]);

        // Reference data that migrations do NOT carry (seeders never run on a
        // git-pull deploy — AT-162). Includes the demo T&C v1, without which the
        // clickwrap has nothing to show and every prospect is hard-blocked.
        $this->call('deploy:sync-reference-data');

        $this->call('demo:seed');

        Log::info('[demo-access] Demo database reset.', [
            'next_reset' => DemoResetSchedule::next()->toIso8601String(),
        ]);

        $this->info('Demo reset complete. Next reset: ' . DemoResetSchedule::next()->toDayDateTimeString());

        return self::SUCCESS;
    }
}
