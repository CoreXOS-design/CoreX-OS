<?php

namespace App\Console\Commands\Demo;

use App\Models\DevSetting;
use App\Support\DemoResetSchedule;
use App\Support\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

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

        // ── Second, independent guard. ──
        //
        // 2026-09-02 incident: the scheduler entry that gates this command lives in
        // routes/console.php, in a shared, actively-edited checkout — a working-tree
        // edit to disable it is NOT durable (a `git reset`/checkout by any other
        // process silently restores whatever was last committed). That is exactly
        // what re-armed the 3am reset on webinar-eve after it had been disabled the
        // evening before, wiping the just-verified demo dataset with 24 hours to go.
        //
        // This check lives in the COMMAND itself, not the schedule, and reads a
        // DB row (dev_settings), not a file — so a code-level revert of the
        // schedule entry cannot silently re-arm actual execution on its own. Both
        // the schedule AND this flag have to agree before a reset can run, whether
        // triggered by cron or by hand. Cleared via:
        //   php artisan tinker --execute="DevSetting::set('demo_reset_frozen','0');"
        if (DevSetting::bool('demo_reset_frozen')) {
            $this->error('REFUSED: demo:reset is frozen (dev_settings.demo_reset_frozen=true).');
            $this->line('  This is a deliberate freeze — someone decided the demo dataset must not be touched right now.');
            $this->line('  To lift it: DevSetting::set(\'demo_reset_frozen\', \'0\')');

            Log::warning('[demo-access] demo:reset was invoked while frozen (demo_reset_frozen) and refused.', [
                'scheduled' => (bool) $this->option('scheduled'),
            ]);

            return self::FAILURE;
        }

        // Scheduler calls this daily; only every 3rd day is a reset day.
        if ($this->option('scheduled') && ! DemoResetSchedule::isResetDay()) {
            $this->info('Not a reset day. Next reset: ' . DemoResetSchedule::next()->toDayDateTimeString());

            return self::SUCCESS;
        }

        // ── Back up BEFORE the wipe, and abort if it fails. ──
        //
        // Converged from demo:refresh (AT-230 / Staging merge): the two branches
        // each built a 3-day demo rebuild, and demo:refresh's one clear advantage
        // was that it never destroyed anything it had not first dumped. A wipe with
        // no backup is unrecoverable, so a failed backup PREVENTS the reset rather
        // than being absorbed and logged — an unbacked demo rebuild that silently
        // proceeds is precisely the failure this guard exists to make impossible.
        if (! $this->backup()) {
            $this->error('REFUSED: backup failed — the demo was NOT wiped.');

            return self::FAILURE;
        }

        $this->warn('Resetting the demo database — dropping all tables.');

        // migrate:fresh drops every table and re-runs migrations from scratch.
        $this->call('migrate:fresh', ['--force' => true]);

        // Reference data that migrations do NOT carry (seeders never run on a
        // git-pull deploy — AT-162). Includes the demo T&C v1, without which the
        // clickwrap has nothing to show and every prospect is hard-blocked.
        $this->call('deploy:sync-reference-data');

        // --force: required by DemoSeed's own double-lock on any non-local
        // environment (DEMO_SEED_ALLOWED=true in .env is the other half).
        // Omitting it here meant demo:seed silently refused on every call —
        // migrate:fresh had already dropped every table by this point, so the
        // demo was left freshly-migrated but with zero application data every
        // single time this command ran (AT-265's role_permissions-empty halt
        // was the visible symptom of this, not the cause). Confirmed live
        // 2026-08-20: this exact gap left corex_demo empty for ~2 days across
        // two silent nightly failures before being caught.
        $this->call('demo:seed', ['--force' => true]);

        // migrate:fresh drops every table the worker's connection is mid-poll
        // against, which crashes the corex-worker-demo supervisor process to
        // FATAL (confirmed live 2026-09-01 — nothing in this command brought
        // it back afterward, and a dead worker means any REAL async job
        // dispatched by a live demo visitor after this point — e-sign
        // completion, etc. — sits stuck in the queue forever). Restarting it
        // is best-effort: the reset itself (backup, wipe, seed) has already
        // fully succeeded by this point, so a restart failure is logged and
        // surfaced, never allowed to flip the command's own result to
        // FAILURE over a problem the reset itself didn't cause.
        $this->restartQueueWorker();

        Log::info('[demo-access] Demo database reset.', [
            'next_reset' => DemoResetSchedule::next()->toIso8601String(),
        ]);

        $this->info('Demo reset complete. Next reset: ' . DemoResetSchedule::next()->toDayDateTimeString());

        return self::SUCCESS;
    }

    /**
     * Restart the corex-worker-demo supervisor program so queued jobs
     * dispatched by real (non-seeder) activity after a reset have a worker
     * to run on. Non-fatal by design — see call site.
     */
    private function restartQueueWorker(): void
    {
        try {
            $process = new Process(['supervisorctl', 'restart', 'corex-worker-demo'], null, null, null, 30);
            $process->run();

            if ($process->isSuccessful()) {
                $this->info('Queue worker (corex-worker-demo) restarted.');
                Log::info('[demo-access] corex-worker-demo restarted after reset.', [
                    'output' => trim($process->getOutput()),
                ]);
            } else {
                $this->warn('Queue worker restart failed — see log. Demo reset itself still succeeded.');
                Log::error('[demo-access] corex-worker-demo restart FAILED after reset — worker may be dead.', [
                    'exit_code' => $process->getExitCode(),
                    'output'    => trim($process->getOutput()),
                    'error'     => trim($process->getErrorOutput()),
                ]);
            }
        } catch (\Throwable $e) {
            $this->warn('Queue worker restart threw — see log. Demo reset itself still succeeded.');
            Log::error('[demo-access] corex-worker-demo restart THREW after reset — worker may be dead.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Dump the demo database to disk before it is destroyed.
     *
     * Targets the DEFAULT connection, not demo:refresh's dedicated `demo` one: on
     * a demo INSTANCE (Instance::isDemo()) the default database IS the demo
     * database, and the caller has already proven that guard.
     *
     * Returns false on any failure — the caller must then refuse to wipe.
     */
    private function backup(): bool
    {
        $cfg = config('database.connections.' . config('database.default'));
        $db  = $cfg['database'];

        $dir = config('demo.refresh.backup_path', storage_path('app/demo-backups'));

        $path = rtrim($dir, '/') . '/' . $db . '-' . now()->format('Ymd-His') . '.sql';

        // Everything from here to the dump is a reason to REFUSE, never to continue:
        // an undirectory, an unwritable path, a missing mysqldump. Each one means we
        // are about to drop every table with nowhere to put the copy.
        try {
            File::ensureDirectoryExists($dir);
            $out = @fopen($path, 'w');
        } catch (\Throwable $e) {
            $out = false;
        }

        if ($out === false) {
            Log::error('[demo-access] Pre-reset backup could not be opened — demo not wiped.', [
                'database' => $db,
                'path'     => $path,
            ]);

            return false;
        }

        $process = new Process([
            'mysqldump',
            '--host=' . $cfg['host'],
            '--port=' . $cfg['port'],
            '--user=' . $cfg['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--add-drop-table',
            $db,
        ], null, ['MYSQL_PWD' => $cfg['password']], null, 600);

        try {
            $process->run(function ($type, $buffer) use ($out) {
                if ($type === Process::OUT) {
                    fwrite($out, $buffer);
                }
            });
        } catch (\Throwable $e) {
            Log::error('[demo-access] Pre-reset backup threw — demo not wiped.', [
                'database' => $db,
                'error'    => $e->getMessage(),
            ]);

            return false;
        } finally {
            fclose($out);
        }

        // A zero-byte or missing dump is a failed dump, whatever the exit code says.
        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
            Log::error('[demo-access] Pre-reset backup FAILED — demo not wiped.', [
                'database' => $db,
                'path'     => $path,
                'stderr'   => $process->getErrorOutput(),
            ]);

            return false;
        }

        $this->info('Backed up to ' . $path . ' (' . number_format(filesize($path) / 1048576, 1) . ' MB).');

        $this->rotateBackups($dir, $db);

        return true;
    }

    /** Keep only the newest N dumps — a demo box is not an archive. */
    private function rotateBackups(string $dir, string $db): void
    {
        $keep = max(1, (int) config('demo.refresh.keep_backups', 3));

        $dumps = collect(File::glob(rtrim($dir, '/') . '/' . $db . '-*.sql'))
            ->sortByDesc(fn ($f) => filemtime($f))
            ->values();

        $dumps->slice($keep)->each(fn ($f) => File::delete($f));
    }
}
