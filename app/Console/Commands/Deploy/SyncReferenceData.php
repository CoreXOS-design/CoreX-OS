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
        \Database\Seeders\CalendarEventClassSeeder::class, // calendar event classes/types + natures/occupies_time/autofill (AT-162)
        \Database\Seeders\DataDictionarySeeder::class,     // CoreX-standard SA e-sign data dictionary (AT-177 / WS0)
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

        $this->info('deploy:sync-reference-data — ' . ($dry ? 'dry-run complete.' : 'reference data provisioned.'));

        return self::SUCCESS;
    }
}
