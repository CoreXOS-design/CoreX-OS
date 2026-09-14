<?php

namespace App\Console\Commands\Syndication;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use App\Services\Syndication\PortalInventoryGuard;
use Illuminate\Console\Command;

/**
 * Reports what an agency's Private Property branch actually holds versus what
 * CoreX believes it holds.
 *
 * Run this the moment a new agency's PP credentials are saved — before any
 * syndication. An agency arriving with an existing portal account brings
 * listings CoreX did not create and cannot address, and every one of them is a
 * future duplicate or a future advert that outlives the sale.
 *
 * Reports only. Deactivating a live advert is a business decision, never a
 * command's. See .ai/specs/portal-inventory-guard.md.
 */
class AuditPortalInventory extends Command
{
    protected $signature = 'pp:audit-inventory
        {--agency= : Agency id; defaults to every PP-enabled agency}
        {--refresh : Re-read the portal instead of using the cached snapshot}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Report Private Property listings CoreX does not control, duplicates, and adverts outliving their property';

    public function handle(PortalInventoryGuard $guard): int
    {
        $agencies = $this->resolveAgencies();

        if ($agencies->isEmpty()) {
            $this->error('No PP-enabled agency found.');
            return 1;
        }

        $payload = [];

        foreach ($agencies as $agency) {
            $report = $guard->classify($agency, (bool) $this->option('refresh'));
            $payload[$agency->id] = $report;

            if ($this->option('json')) {
                continue;
            }

            $this->renderReport($agency, $report);
        }

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        // Non-zero when something needs a human, so a scheduled run can alert.
        $needsAttention = collect($payload)->contains(
            fn ($r) => $r['orphan_advertised'] !== [] || $r['stale_advertised'] !== []
        );

        return $needsAttention ? 2 : 0;
    }

    private function resolveAgencies()
    {
        $query = Agency::withoutGlobalScope(AgencyScope::class)
            ->where('pp_enabled', true)
            ->whereNotNull('pp_branch_guid');

        if ($id = $this->option('agency')) {
            $query->where('id', $id);
        }

        return $query->orderBy('id')->get();
    }

    private function renderReport(Agency $agency, array $report): void
    {
        $this->newLine();
        $this->info("Private Property inventory — {$agency->name} (agency #{$agency->id})");
        $this->line("  listings on branch: {$report['total']}   publicly advertised: {$report['advertised']}");

        $this->section(
            'Advertised but NOT controlled by CoreX',
            'CoreX cannot reprice, flag or withdraw these. When the property sells, the advert stays up.',
            $report['orphan_advertised']
        );

        $this->section(
            'Advertised twice (same property, two live listings)',
            'One of each pair is a leftover from the agency\'s previous feed.',
            $report['duplicates']
        );

        $this->section(
            'Advertised while the CoreX property is off market',
            'CoreX owns these, so a re-sync should clear them.',
            $report['stale_advertised']
        );

        if ($report['orphan_advertised'] === [] && $report['stale_advertised'] === []) {
            $this->info('  Clean — every advertised listing is controlled by CoreX.');
        }
    }

    private function section(string $title, string $why, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn("  {$title}: " . count($rows));
        $this->line("  {$why}");

        $this->table(
            ['Portal id', 'Status', 'Type', 'Price', 'Suburb', 'Headline'],
            array_map(fn ($r) => [
                $r['portal_id'],
                $r['status'],
                $r['listing_type'],
                number_format((int) $r['price']),
                mb_substr($r['suburb'], 0, 18),
                mb_substr($r['headline'], 0, 44),
            ], $rows)
        );
    }
}
