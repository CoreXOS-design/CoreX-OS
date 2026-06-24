<?php

namespace App\Console\Commands;

use App\Services\MaintenanceMode;
use Illuminate\Console\Command;

/**
 * Escape hatch for CoreX maintenance mode (AT-93).
 *
 * Turns the system-wide maintenance gate on/off from the CLI with NO
 * dependency on the web UI — so a System Owner can always lift
 * maintenance even if the toggle page itself is unreachable. As a last
 * resort the flag file can also be removed by hand:
 *   rm storage/framework/corex-maintenance.flag
 *
 * Usage:
 *   php artisan corex:maintenance         (status)
 *   php artisan corex:maintenance on
 *   php artisan corex:maintenance off
 *
 * Spec: .ai/specs/maintenance-mode.md
 */
class MaintenanceModeCommand extends Command
{
    protected $signature = 'corex:maintenance {action=status : on | off | status}';

    protected $description = 'Toggle CoreX system-wide maintenance mode (owner-only gate). CLI escape hatch independent of the UI.';

    public function handle(MaintenanceMode $maintenance): int
    {
        $action = strtolower(trim((string) $this->argument('action')));

        switch ($action) {
            case 'on':
            case 'enable':
                $maintenance->enable(by: 'artisan CLI');
                $this->warn('Maintenance mode is now ON — only System Owners can access CoreX.');
                $this->line('Flag: '.$maintenance->flagPath());
                return self::SUCCESS;

            case 'off':
            case 'disable':
                $maintenance->disable();
                $this->info('Maintenance mode is now OFF — CoreX is live for all users.');
                return self::SUCCESS;

            case 'status':
                if ($maintenance->isActive()) {
                    $meta = $maintenance->meta();
                    $this->warn('Maintenance mode: ON');
                    $this->line('  Enabled at: '.($meta['enabled_at'] ?? 'unknown'));
                    $this->line('  Enabled by: '.($meta['enabled_by'] ?? 'unknown'));
                } else {
                    $this->info('Maintenance mode: OFF (site is live).');
                }
                return self::SUCCESS;

            default:
                $this->error("Unknown action '{$action}'. Use: on | off | status.");
                return self::INVALID;
        }
    }
}
