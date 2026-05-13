<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only viewer for live wishlist_migration_log entries. Defaults to the
 * most recent live run; filterable by action / contact / run_id. -v dumps
 * the field_mapping_snapshot JSON for each row.
 */
class WishlistShowMigrationLog extends Command
{
    protected $signature = 'wishlist:show-migration-log
                            {--run-id= : Specific run_id (defaults to most recent live run)}
                            {--action= : Filter by action (created, appended, merged, skipped, failed)}
                            {--contact= : Filter by contact_id}';

    protected $description = 'Show entries from wishlist_migration_log for the live migration runs.';

    public function handle(): int
    {
        $runId = $this->option('run-id');
        if (!$runId) {
            $runId = DB::table('wishlist_migration_log')
                ->where('mode', 'live')
                ->orderByDesc('created_at')
                ->value('run_id');
            if (!$runId) {
                $this->warn('No live wishlist_migration_log entries found.');
                return self::SUCCESS;
            }
            $this->line("Using most recent live run_id={$runId}");
        }

        $query = DB::table('wishlist_migration_log')
            ->where('run_id', $runId)
            ->where('mode', 'live');
        if ($action = $this->option('action')) {
            $query->where('action', $action);
        }
        if ($contact = $this->option('contact')) {
            $query->where('contact_id', (int) $contact);
        }

        $rows = $query->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->warn('No log entries match the filters.');
            return self::SUCCESS;
        }

        $verbose = $this->getOutput()->isVerbose();
        $headers = ['id', 'contact_id', 'action', 'target_match_id', 'agency_id', 'notes'];
        $tableRows = [];
        foreach ($rows as $r) {
            $tableRows[] = [
                $r->id,
                $r->contact_id,
                $r->action,
                $r->target_contact_match_id ?? '—',
                $r->agency_id,
                $verbose ? ($r->notes ?? '') : $this->truncate((string) ($r->notes ?? ''), 70),
            ];
        }

        $this->table($headers, $tableRows);

        if ($verbose) {
            $this->newLine();
            $this->line('Verbose: field_mapping_snapshot per row:');
            foreach ($rows as $r) {
                $this->newLine();
                $this->line("--- log id={$r->id} contact_id={$r->contact_id} action={$r->action} target_match_id={$r->target_contact_match_id} ---");
                $this->line(json_encode(json_decode($r->field_mapping_snapshot ?? 'null', true), JSON_PRETTY_PRINT));
            }
        }

        $this->line("Total: {$rows->count()} entries (run_id={$runId})");
        return self::SUCCESS;
    }

    private function truncate(string $s, int $len): string
    {
        if (mb_strlen($s) <= $len) return $s;
        return mb_substr($s, 0, $len - 1) . '…';
    }
}
