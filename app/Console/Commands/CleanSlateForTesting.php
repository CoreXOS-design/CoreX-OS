<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanSlateForTesting extends Command
{
    protected $signature = 'tracker:clean-slate';

    protected $description = 'Clear all transactional data for audit testing (keeps setup/config intact)';

    /**
     * Tables to clear, ordered for FK safety (children before parents).
     */
    private function tablesToClear(): array
    {
        return [
            // --- Deals & Finance ---
            'finance_audit_items',
            'finance_audit_runs',
            'finance_computed_values',
            'deal_settlements',
            'deal_money_lines',
            'deal_logs',
            'deal_user',
            'deals',
            'worksheets',

            // --- Daily Activity ---
            'daily_activity_entries',
            'daily_activities',

            // --- Signatures ---
            'wet_ink_inspections',
            'signatures',
            'signature_audit_log',
            'signature_markers',
            'signature_requests',
            'signature_templates',

            // --- Documents (filled copies, not templates) ---
            'docuperfect_pack_instance_values',
            'docuperfect_documents',

            // --- Presentations ---
            'presentation_active_listings',
            'presentation_articles',
            'presentation_document_library_items',
            'presentation_fields',
            'presentation_links',
            'presentation_listing_price_history',
            'presentation_sections',
            'presentation_snapshots',
            'presentation_sold_comps',
            'presentation_uploads',
            'presentation_url_snapshots',
            'presentation_versions',
            'presentations',
        ];
    }

    public function handle(): int
    {
        // Step 1 — Warning + confirmation
        $this->newLine();
        $this->error('  ⚠  WARNING: This will DELETE all transactional data!  ');
        $this->newLine();
        $this->warn('The following data groups will be permanently deleted:');
        $this->line('  • Deals & Finance (deals, settlements, money lines, audit runs, worksheets)');
        $this->line('  • Daily Activity (activities + entries)');
        $this->line('  • Signatures (templates, requests, markers, inspections, audit log)');
        $this->line('  • Documents (filled copies + pack instance values)');
        $this->line('  • Presentations (all presentation_* tables)');
        $this->newLine();
        $this->info('Setup/config tables (users, branches, definitions, templates, etc.) are NOT affected.');
        $this->newLine();
        $this->warn('TIP: Copy database/database.sqlite to a backup before proceeding.');
        $this->newLine();

        $answer = $this->ask('Type YES to proceed');

        if ($answer !== 'YES') {
            $this->info('Aborted. No data was deleted.');
            return self::SUCCESS;
        }

        // Step 2 — Delete from tables in FK order
        $tables = $this->tablesToClear();
        $summary = [];

        // Disable FK checks during bulk delete
        DB::statement('PRAGMA foreign_keys = OFF');

        foreach ($tables as $table) {
            // Check table exists
            $exists = DB::select(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                [$table]
            );

            if (empty($exists)) {
                $summary[$table] = 'SKIPPED (not found)';
                continue;
            }

            $count = DB::table($table)->count();
            DB::table($table)->delete();
            $summary[$table] = $count;
        }

        // Re-enable FK checks
        DB::statement('PRAGMA foreign_keys = ON');

        // Step 3 — Reset SQLite auto-increment
        $existingTables = array_filter($tables, function ($t) use ($summary) {
            return is_int($summary[$t] ?? null);
        });

        if (!empty($existingTables)) {
            $placeholders = implode(',', array_fill(0, count($existingTables), '?'));
            DB::delete(
                "DELETE FROM sqlite_sequence WHERE name IN ({$placeholders})",
                array_values($existingTables)
            );
        }

        // Step 4 — Print summary
        $this->newLine();
        $this->info('Clean slate complete. Summary:');
        $this->newLine();

        $totalRows = 0;
        $rows = [];
        foreach ($summary as $table => $result) {
            if ($result === 'SKIPPED (not found)') {
                $rows[] = [$table, $result];
            } else {
                $rows[] = [$table, number_format($result) . ' rows deleted'];
                $totalRows += $result;
            }
        }

        $this->table(['Table', 'Result'], $rows);
        $this->newLine();
        $this->info("Total: " . number_format($totalRows) . " rows deleted across " . count($existingTables) . " tables.");
        $this->info('Auto-increment sequences reset.');
        $this->newLine();

        return self::SUCCESS;
    }
}
