<?php

namespace App\Console\Commands\Deploy;

use Illuminate\Console\Command;

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
 * RULE (see BUILD_STANDARD): any new seeder that is the source of truth for
 * must-travel GLOBAL reference rows is registered HERE. Prefer a migration
 * backfill where the value is fixed; register here when a seeder owns it.
 */
class SyncReferenceData extends Command
{
    protected $signature = 'deploy:sync-reference-data {--dry-run : List what would run without executing}';

    protected $description = 'Provision/refresh idempotent GLOBAL reference data that deploys must carry (seeders do not run on git-pull deploys). Run after migrate on every deploy.';

    /** Idempotent, global-scope reference seeders. */
    private array $seeders = [
        \Database\Seeders\NotificationEventTypeSeeder::class, // notification catalogue — had NO seeder; a fresh env got an EMPTY settings page (AT-235 R1 / AT-162)
        \Database\Seeders\CalendarEventClassSeeder::class, // calendar event classes/types + natures/occupies_time/autofill (AT-162)
        \Database\Seeders\DataDictionarySeeder::class,     // CoreX-standard SA e-sign data dictionary (AT-177 / WS0)
        \Database\Seeders\ReferencePackDictionarySeeder::class, // 6 entries the 116 reference-proof surfaced (AT-177 / WS5)
        \Database\Seeders\DemoTncVersionSeeder::class,      // demo T&C v1 — without it EVERY demo prospect is blocked at the clickwrap (AT-230)
        \Database\Seeders\PayrollTaxTableSeeder::class,     // AT-237 C1 — SARS PAYE brackets (GLOBAL, seed-only) — without it PAYE silently R0
        \Database\Seeders\PayrollTaxRebateSeeder::class,    // AT-237 C1 — SARS rebates/thresholds/UIF ceiling/SDL rate (GLOBAL, seed-only)
    ];

    /** Idempotent reference-provisioning commands [name, args]. */
    private array $commands = [
        ['corex:sync-permissions', ['--merge-defaults' => true]], // permission definitions from config
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        foreach ($this->seeders as $seeder) {
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
