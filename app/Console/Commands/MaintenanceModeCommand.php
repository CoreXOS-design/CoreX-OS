<?php

namespace App\Console\Commands;

use App\Models\Agency;
use Illuminate\Console\Command;

/**
 * Per-agency maintenance escape hatch (AT-93, re-scoped).
 *
 * Puts a single agency into / out of maintenance from the CLI with no UI
 * dependency — so a System Owner can always lift an agency's maintenance
 * even if the toggle page is unreachable.
 *
 * Usage:
 *   php artisan corex:maintenance                       (list every agency's state)
 *   php artisan corex:maintenance <agency> status
 *   php artisan corex:maintenance <agency> on  [--message="..."]
 *   php artisan corex:maintenance <agency> off
 *
 * <agency> is an id, slug, or exact name.
 * Spec: .ai/specs/maintenance-mode.md
 */
class MaintenanceModeCommand extends Command
{
    protected $signature = 'corex:maintenance
        {agency? : Agency id, slug, or exact name}
        {action=status : on | off | status}
        {--message= : Optional message shown to that agency\'s users when turning on}';

    protected $description = 'Per-agency maintenance mode (tenant-level). CLI escape hatch independent of the UI.';

    public function handle(): int
    {
        $agencyArg = $this->argument('agency');

        // No agency → list every agency's current state.
        if ($agencyArg === null) {
            $rows = Agency::orderBy('name')->get()->map(fn ($a) => [
                $a->id,
                $a->name,
                $a->isInMaintenance() ? 'MAINTENANCE' : 'live',
                optional($a->maintenance_started_at)?->toDateTimeString() ?? '',
            ])->all();

            $this->table(['ID', 'Agency', 'State', 'Since'], $rows);
            return self::SUCCESS;
        }

        $agency = $this->resolveAgency((string) $agencyArg);
        if (!$agency) {
            $this->error("No agency matched '{$agencyArg}' (try an id, slug, or exact name).");
            return self::INVALID;
        }

        $action = strtolower(trim((string) $this->argument('action')));

        switch ($action) {
            case 'on':
            case 'enable':
                $agency->enterMaintenance($this->option('message') ?: null);
                $this->warn("Agency \"{$agency->name}\" is now in MAINTENANCE — only System Owners can access it.");
                return self::SUCCESS;

            case 'off':
            case 'disable':
                $agency->exitMaintenance();
                $this->info("Agency \"{$agency->name}\" is now LIVE — users can sign in normally.");
                return self::SUCCESS;

            case 'status':
                if ($agency->isInMaintenance()) {
                    $this->warn("Agency \"{$agency->name}\": MAINTENANCE"
                        . ($agency->maintenance_started_at ? " (since {$agency->maintenance_started_at->toDateTimeString()})" : ''));
                } else {
                    $this->info("Agency \"{$agency->name}\": live.");
                }
                return self::SUCCESS;

            default:
                $this->error("Unknown action '{$action}'. Use: on | off | status.");
                return self::INVALID;
        }
    }

    private function resolveAgency(string $needle): ?Agency
    {
        if (ctype_digit($needle)) {
            $byId = Agency::find((int) $needle);
            if ($byId) {
                return $byId;
            }
        }

        return Agency::where('slug', $needle)->first()
            ?? Agency::where('name', $needle)->first();
    }
}
