<?php

namespace App\Console\Commands\Deploy;

use App\Contracts\SyncableReferenceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * AT-162 — kills the "seeded reference data doesn't deploy" bug-class.
 *
 * CoreX deploys are `git pull` + `migrate --force` + clears — they do NOT run
 * seeders. So GLOBAL reference data provisioned by a seeder (calendar event
 * classes/types, permission definitions, …) silently fails to travel to a
 * target environment on promotion (this is how "Private" was missing on live).
 *
 * This command is the SINGLE, idempotent, environment-agnostic step every
 * deploy runs after `migrate` to (re)provision must-travel GLOBAL reference
 * data. Registered provisioners must be idempotent and global-scope
 * (`agency_id IS NULL`) so re-running never disturbs per-agency customisations.
 *
 * 2026-08-24 (cc2, Johan) — which seeders run is DISCOVERED, not listed. The
 * MDF and Addendum B gap happened because two real, verified document-template
 * seeders were never hand-added here. The fix for "someone forgot to list it"
 * cannot be "list everything found nearby" — two OTHER seeders live in the
 * exact same directory, share large parts of the same name, and are dead
 * (superseded first attempts, never actually run anywhere); Staging proved
 * running one of them after the real one silently swaps the row's content
 * back to an untested template, same id, no error. So discovery is opt-in by
 * marker interface (App\Contracts\SyncableReferenceSeeder), not by directory
 * membership or naming convention — see that interface's own docblock for the
 * full reasoning. A seeder is run because it implements the interface, never
 * because of where it lives or what it's called.
 *
 * RULE (see BUILD_STANDARD): any new seeder that is the source of truth for
 * must-travel GLOBAL reference rows implements SyncableReferenceSeeder.
 * Prefer a migration backfill where the value is fixed; mark a seeder when it
 * owns the value instead.
 */
class SyncReferenceData extends Command
{
    protected $signature = 'deploy:sync-reference-data {--dry-run : List what would run without executing}';

    protected $description = 'Provision/refresh idempotent GLOBAL reference data that deploys must carry (seeders do not run on git-pull deploys). Run after migrate on every deploy.';

    /**
     * Discover every seeder under database/seeders/ that implements
     * SyncableReferenceSeeder. The directory walk here is enumeration ONLY —
     * it finds candidate class names to check, exactly the way Composer's own
     * autoloader would resolve a PSR-4 path to a class name. It is never the
     * selection criterion: a seeder in this same directory that does NOT
     * implement the interface is skipped regardless of its name or location.
     * Only the `instanceof` check below decides inclusion.
     *
     * Sorted alphabetically by FQCN for a stable, reproducible order — nothing
     * here depends on declaration order.
     */
    private function discoverSeeders(): array
    {
        $base = database_path('seeders');
        $found = [];

        foreach (File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = $file->getRelativePathname();
            $class = 'Database\\Seeders\\' . str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $relative);

            if (! class_exists($class)) {
                continue; // not autoloadable at this FQCN — not a candidate, not an error
            }

            // The ONLY inclusion test. Nothing about the filename or directory above
            // this line ever decides membership — only this line does.
            if (! is_subclass_of($class, SyncableReferenceSeeder::class)) {
                continue;
            }

            $found[] = $class;
        }

        sort($found);

        return $found;
    }

    /** Idempotent reference-provisioning commands [name, args]. */
    private array $commands = [
        ['corex:sync-permissions', ['--merge-defaults' => true]], // permission definitions from config
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach ($this->discoverSeeders() as $seeder) {
            $this->line(($dry ? '[dry-run] ' : '') . "seed: {$seeder}");
            if (! $dry) {
                $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
            }
        }

        foreach ($this->commands as [$command, $args]) {
            $this->line(($dry ? '[dry-run] ' : '') . "cmd:  {$command} " . json_encode($args));
            if (! $dry) {
                $this->call($command, $args);
            }
        }

        if (! $dry && ! $this->assertPermissionGrantsExist()) {
            return self::FAILURE;
        }

        $this->info('deploy:sync-reference-data — ' . ($dry ? 'dry-run complete.' : 'reference data provisioned.'));

        return self::SUCCESS;
    }

    /**
     * AT-265 — the POST-CONDITION that makes fail-closed survivable.
     *
     * PermissionService now DENIES every non-owner when `role_permissions` is empty (it used to
     * grant everyone everything, which is why this check did not need to exist before). The two
     * changes are load-bearing for each other: the deny is only safe because the deploy guarantees
     * the table is populated, and the deploy guarantee is only necessary because of the deny.
     *
     * `corex:sync-permissions --merge-defaults` (run just above) reprovisions grants from
     * config/corex-permissions.php for every role that has config defaults. If the table is STILL
     * empty afterwards, this environment is about to deny every non-owner user — an outage. The
     * deploy must not report success and walk away from that; it fails, loudly, while a human is
     * still watching the terminal.
     *
     * The likeliest cause is an empty `roles` table (merge-defaults fans out across roles, so no
     * roles means no grants), which is why that is called out by name.
     */
    private function assertPermissionGrantsExist(): bool
    {
        if (\App\Models\RolePermission::exists()) {
            return true;
        }

        $this->newLine();
        $this->error('AT-265 — DEPLOY HALTED: `role_permissions` is EMPTY after provisioning.');
        $this->error('PermissionService fails CLOSED, so every non-owner user on this environment');
        $this->error('would be denied all access. Owners can still sign in (audited break-glass).');
        $this->newLine();
        $this->warn('Most likely cause: the `roles` table is empty, so `corex:sync-permissions');
        $this->warn('--merge-defaults` had no roles to fan out across. Check `roles`, then re-run');
        $this->warn('`php artisan deploy:sync-reference-data`.');

        return false;
    }
}
