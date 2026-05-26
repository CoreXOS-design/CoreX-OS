<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only viewer for wishlist_migration_log entries. Defaults to the most
 * recent dry-run; filterable by action / contact / run_id. Prints a table.
 * `-v` (verbose) appends the full field_mapping_snapshot JSON for each row.
 */
class WishlistShowDryRunLog extends Command
{
    protected $signature = 'wishlist:show-dry-run-log
                            {--run-id= : Specific run_id (defaults to most recent dry-run)}
                            {--action= : Filter by action (would_create, would_append, would_merge, would_skip, would_fail)}
                            {--contact= : Filter by contact_id}
                            {--mode=dry_run : dry_run or live}';

    protected $description = 'Show entries from wishlist_migration_log for review before running the live migration.';

    public function handle(): int
    {
        $mode = $this->option('mode');
        $runId = $this->option('run-id');

        if (!$runId) {
            $runId = DB::table('wishlist_migration_log')
                ->where('mode', $mode)
                ->orderByDesc('created_at')
                ->value('run_id');
            if (!$runId) {
                $this->warn("No wishlist_migration_log entries found for mode={$mode}.");
                return self::SUCCESS;
            }
            $this->line("Using most recent {$mode} run_id={$runId}");
        }

        $query = DB::table('wishlist_migration_log')->where('run_id', $runId);
        if ($action = $this->option('action')) {
            $query->where('action', $action);
        }
        if ($contact = $this->option('contact')) {
            $query->where('contact_id', (int) $contact);
        }

        $rows = $query->orderBy('contact_id')->get();
        if ($rows->isEmpty()) {
            $this->warn('No log entries match the filters.');
            return self::SUCCESS;
        }

        $verbose = $this->getOutput()->isVerbose();
        $headers = ['id', 'contact_id', 'action', 'agency_id', 'notes (truncated)'];
        $tableRows = [];

        foreach ($rows as $r) {
            $tableRows[] = [
                $r->id,
                $r->contact_id,
                $r->action,
                $r->agency_id,
                $verbose ? ($r->notes ?? '') : $this->truncate((string) ($r->notes ?? ''), 80),
            ];
        }

        $this->table($headers, $tableRows);

        if ($verbose) {
            $this->newLine();
            $this->line('Verbose: field_mapping_snapshot per row:');
            foreach ($rows as $r) {
                $this->newLine();
                $this->line("--- log id={$r->id} contact_id={$r->contact_id} action={$r->action} ---");
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
