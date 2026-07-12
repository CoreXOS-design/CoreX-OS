<?php

namespace App\Console\Commands;

use Database\Seeders\DemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Full demo rebuild: DROP every table in the demo database, re-migrate, reseed,
 * and verify — on a fixed interval (default every 3 days), unattended.
 *
 * WHY THIS COMMAND EXISTS
 * -----------------------
 * The previous arrangement in routes/console.php was:
 *
 *     Schedule::command('demo:cleanup --force')->dailyAt('03:00');
 *     Schedule::command('demo:seed')->dailyAt('03:05');       // <-- no --force
 *
 * DemoDataSeeder::environmentGateRefusal() refuses ANY non-local environment
 * unless --force is passed, and the demo box runs APP_ENV=demo. So the cleanup
 * ran every night and the reseed refused every night: the demo silently emptied
 * itself and never refilled. Two commands, one missing flag, no verification,
 * and nothing to notice the failure.
 *
 * This command replaces both with a single atomic operation:
 *
 *   gate → back up → wipe → migrate → seed → VERIFY → (rollback on any failure)
 *
 * SAFETY. The destructive step is reached only after ALL of these hold:
 *
 *   1. config('demo.refresh.enabled') — a dedicated opt-in, separate from
 *      DEMO_SEED_ALLOWED. Live/staging never set it, and they run the same
 *      shared scheduler, so the default of false is what keeps them safe.
 *   2. DemoDataSeeder::environmentGateRefusal(force: true) — DEMO_SEED_ALLOWED.
 *   3. DemoDataSeeder::protectedDatabaseRefusal() — never a real working DB.
 *   4. The target database must actually LOOK like a demo: it must carry an
 *      agency flagged is_demo=1 (or be empty/unmigrated). This is the last line
 *      of defence — it holds even if every env var above is misconfigured, so a
 *      pointed-wrong connection cannot drop a live dataset.
 *
 * ROLLBACK. mysqldump runs before the wipe. If migrate, seed or the seeder's own
 * stageV_verifyDemoIntegrity() throws, the dump is restored and the site is
 * brought back up on the OLD data. A failed refresh degrades to "yesterday's
 * demo", never to "no demo".
 */
class DemoRefresh extends Command
{
    protected $signature = 'demo:refresh
        {--force : Run now, ignoring the interval (the safety gates still apply)}
        {--dry-run : Report what would happen and exit without touching anything}';

    protected $description = 'Wipe and rebuild the demo database on an interval (default: every 3 days). Gated, verified, and rolled back on failure.';

    private const MARKER = 'demo-last-refresh';

    public function handle(): int
    {
        // ── Gate 1: dedicated opt-in for the UNATTENDED wipe ───────────────
        if (!config('demo.refresh.enabled')) {
            // Silent no-op: this runs daily on live and staging too (shared
            // routes/console.php). Not an error — just not their job.
            return self::SUCCESS;
        }

        // ── Gate 2: environment. Live is 'production', staging is 'staging'.
        // Neither can ever match, whatever the env flags say.
        $env = app()->environment();
        $allowedEnvs = (array) config('demo.refresh.environments', ['local', 'demo']);

        if (!in_array($env, $allowedEnvs, true)) {
            $this->error("Refusing: demo:refresh may only run in [" . implode(', ', $allowedEnvs)
                . "]; this environment is '{$env}'.");
            return self::FAILURE;
        }

        // ── Gate 3: reuse the seeder's own double-lock (DEMO_SEED_ALLOWED) ──
        if ($refusal = DemoDataSeeder::environmentGateRefusal(true)) {
            $this->error($refusal);
            return self::FAILURE;
        }

        $demoDb = config('database.connections.demo.database');

        if ($refusal = DemoDataSeeder::protectedDatabaseRefusal($demoDb)) {
            $this->error($refusal);
            return self::FAILURE;
        }

        // ── Gate 4: the target database must be named EXPLICITLY, and match ──
        // Nothing is inferred. DB_DEMO_DATABASE says where the demo connection
        // points; DEMO_REFRESH_DATABASE says which database this box is allowed
        // to DESTROY. They must agree, and both must be set by a human.
        $permitted = config('demo.refresh.database');

        if (blank($permitted)) {
            $this->error('Refusing: DEMO_REFRESH_DATABASE is not set. The database to be '
                . 'wiped must be named explicitly — it is never inferred.');
            return self::FAILURE;
        }

        if ($permitted !== $demoDb) {
            $this->error("Refusing: the 'demo' connection resolves to '{$demoDb}', but this box "
                . "is only permitted to wipe '{$permitted}'. Refusing to touch a database that "
                . 'was not explicitly nominated.');
            Log::critical('demo:refresh REFUSED — target/permitted database mismatch', [
                'resolved'  => $demoDb,
                'permitted' => $permitted,
            ]);
            return self::FAILURE;
        }

        // ── Gate 5: the target must genuinely BE a demo database ───────────
        if ($refusal = $this->notADemoDatabaseRefusal($demoDb)) {
            $this->error($refusal);
            Log::critical('demo:refresh REFUSED — target is not a demo database', [
                'database' => $demoDb,
                'reason'   => $refusal,
            ]);
            return self::FAILURE;
        }

        // ── Interval ───────────────────────────────────────────────────────
        $intervalDays = max(1, (int) config('demo.refresh.interval_days', 3));

        if (!$this->option('force') && !$this->isDue($intervalDays)) {
            $next = $this->lastRefreshedAt()?->addDays($intervalDays);
            $this->line("Not due — last refresh {$this->lastRefreshedAt()?->diffForHumans()}"
                . ', next ' . ($next?->diffForHumans() ?? 'unknown') . '.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("DRY RUN — would wipe and rebuild '{$demoDb}' now (interval {$intervalDays}d).");
            return self::SUCCESS;
        }

        return $this->rebuild($demoDb);
    }

    /**
     * The rebuild proper. Every destructive step sits behind a backup, and any
     * throw restores it.
     */
    private function rebuild(string $demoDb): int
    {
        $this->info("Refreshing demo database '{$demoDb}'…");

        $backup = $this->backup($demoDb);
        if ($backup === null) {
            $this->error('Refusing to wipe: the pre-wipe backup failed. Nothing was changed.');
            Log::critical('demo:refresh aborted — backup failed', ['database' => $demoDb]);
            return self::FAILURE;
        }
        $this->line("  backup: {$backup}");

        // Take the site down so nobody browses a half-built database.
        $this->callSilently('down', ['--render' => 'errors::503']);

        try {
            $this->line('  migrating…');
            $this->callOrThrow('migrate:fresh', ['--database' => 'demo', '--force' => true]);

            $this->line('  seeding…');
            // DemoDataSeeder ends in stageV_verifyDemoIntegrity(), which THROWS
            // if the dataset is empty, an agent is short of properties, or any
            // Home Finders Coastal data leaked in. That throw lands here.
            $this->callOrThrow('db:seed', [
                '--class'    => DemoDataSeeder::class,
                '--database' => 'demo',
                '--force'    => true,
            ]);
        } catch (\Throwable $e) {
            $this->error('  REBUILD FAILED: ' . $e->getMessage());
            $this->warn('  restoring the pre-wipe backup…');

            $restored = $this->restore($demoDb, $backup);

            $this->callSilently('up');

            Log::critical('demo:refresh FAILED — demo database rebuild aborted', [
                'database' => $demoDb,
                'error'    => $e->getMessage(),
                'restored' => $restored,
            ]);

            $this->error($restored
                ? '  restored — the demo is serving the PREVIOUS dataset.'
                : '  RESTORE ALSO FAILED — the demo database needs manual attention. Backup: ' . $backup);

            return self::FAILURE;
        }

        $this->callSilently('up');

        $this->touchMarker();
        $this->pruneBackups();

        $props = DB::connection('demo')->table('properties')->count();
        $this->info("Demo refreshed — {$props} properties. Next refresh in "
            . config('demo.refresh.interval_days', 3) . ' days.');

        Log::info('demo:refresh completed', ['database' => $demoDb, 'properties' => $props]);

        return self::SUCCESS;
    }

    /**
     * Last line of defence — content-based, so it holds even if every env var
     * above is wrong.
     *
     * EVERY agency in the target must be flagged is_demo=1. "At least one demo
     * agency" is NOT sufficient and was a real bug: both hfc_staging and nexus_os
     * carry a demo agency alongside their REAL ones, so an any() check passes on
     * staging and would have authorised wiping it. A database holding even one
     * non-demo agency is somebody's real data. Refuse.
     *
     * An empty or unmigrated database is fine — that is a first run, and there is
     * nothing there to lose.
     */
    private function notADemoDatabaseRefusal(string $demoDb): ?string
    {
        $conn = DB::connection('demo');

        try {
            if (!$conn->getSchemaBuilder()->hasTable('agencies')) {
                return null; // unmigrated — first run
            }

            $total = $conn->table('agencies')->count();

            if ($total === 0) {
                return null; // empty — nothing to lose
            }

            $real = $conn->table('agencies')
                ->where(fn ($q) => $q->where('is_demo', '!=', 1)->orWhereNull('is_demo'))
                ->count();

            if ($real === 0) {
                return null; // every agency is a demo agency — genuinely a demo DB
            }
        } catch (\Throwable $e) {
            return "Cannot inspect '{$demoDb}' to confirm it is a demo database ("
                . $e->getMessage() . '). Refusing to wipe.';
        }

        return "Refusing to wipe '{$demoDb}': {$real} of its {$total} agencies are NOT flagged "
            . 'is_demo=1. A database holding real agencies is not a demo database, whatever '
            . 'the environment says. Check DB_DEMO_DATABASE / DEMO_REFRESH_DATABASE.';
    }

    private function backup(string $demoDb): ?string
    {
        $dir = config('demo.refresh.backup_path');
        File::ensureDirectoryExists($dir);

        // No Date::now() concerns here — this is runtime, not a workflow script.
        $path = rtrim($dir, '/') . '/' . $demoDb . '-' . now()->format('Ymd-His') . '.sql';

        $cfg = config('database.connections.demo');

        $process = new Process([
            'mysqldump',
            '--host=' . $cfg['host'],
            '--port=' . $cfg['port'],
            '--user=' . $cfg['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--add-drop-table',
            $demoDb,
        ], null, ['MYSQL_PWD' => $cfg['password']], null, 600);

        $out = fopen($path, 'w');

        try {
            $process->run(function ($type, $buffer) use ($out) {
                if ($type === Process::OUT) {
                    fwrite($out, $buffer);
                }
            });
        } finally {
            fclose($out);
        }

        if (!$process->isSuccessful() || filesize($path) === 0) {
            $this->error('  mysqldump failed: ' . trim($process->getErrorOutput()));
            @unlink($path);
            return null;
        }

        return $path;
    }

    /**
     * Restore the pre-wipe dump.
     *
     * The dump is fed to mysql's STDIN via a file handle rather than a shell
     * redirect. An earlier version used Process::fromShellCommandline() with
     * '< ${:FILE}', whose placeholder never substituted — so the restore failed
     * at the exact moment it was needed, which is the only moment it runs. No
     * shell, no quoting, no placeholders: nothing left to get wrong.
     */
    private function restore(string $demoDb, string $backup): bool
    {
        $cfg = config('database.connections.demo');

        $handle = @fopen($backup, 'r');
        if ($handle === false) {
            $this->error("  cannot read backup: {$backup}");
            return false;
        }

        $process = new Process([
            'mysql',
            '--host=' . $cfg['host'],
            '--port=' . $cfg['port'],
            '--user=' . $cfg['username'],
            $demoDb,
        ], null, ['MYSQL_PWD' => $cfg['password']], null, 900);

        $process->setInput($handle);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('  mysql restore failed: ' . trim($process->getErrorOutput()));
            return false;
        }

        return true;
    }

    private function pruneBackups(): void
    {
        $dir = config('demo.refresh.backup_path');
        $keep = max(1, (int) config('demo.refresh.keep_backups', 3));

        $files = collect(File::glob(rtrim($dir, '/') . '/*.sql'))
            ->sortByDesc(fn ($f) => filemtime($f))
            ->values();

        $files->slice($keep)->each(fn ($f) => @unlink($f));
    }

    /**
     * The marker lives on DISK, not in the database — the database is the thing
     * being dropped, so a row in it could never survive to record its own wipe.
     */
    private function markerPath(): string
    {
        return storage_path('app/' . self::MARKER);
    }

    private function lastRefreshedAt(): ?\Illuminate\Support\Carbon
    {
        $path = $this->markerPath();

        if (!File::exists($path)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse(trim(File::get($path)));
        } catch (\Throwable) {
            return null;
        }
    }

    private function isDue(int $intervalDays): bool
    {
        $last = $this->lastRefreshedAt();

        return $last === null || $last->lte(now()->subDays($intervalDays));
    }

    private function touchMarker(): void
    {
        File::put($this->markerPath(), now()->toIso8601String());
    }

    /**
     * call() but surfacing a non-zero exit as an exception, so a failed migrate
     * or seed reaches the rollback instead of being reported as success.
     */
    private function callOrThrow(string $command, array $args = []): void
    {
        $code = $this->call($command, $args);

        if ($code !== 0) {
            throw new \RuntimeException("`{$command}` exited with code {$code}");
        }
    }
}
