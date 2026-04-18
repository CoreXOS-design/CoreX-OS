<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeSoftDeleted extends Command
{
    protected $signature = 'db:purge-soft-deleted
                            {--dry-run : Only report counts, do not delete}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Permanently delete every row with a non-null deleted_at across all tables.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $driver = DB::connection()->getDriverName();
        $tables = $this->listTables($driver);

        $targets = [];
        foreach ($tables as $table) {
            if (!Schema::hasColumn($table, 'deleted_at')) continue;
            $count = DB::table($table)->whereNotNull('deleted_at')->count();
            if ($count > 0) $targets[$table] = $count;
        }

        if (empty($targets)) {
            $this->info('Nothing to purge — no rows with deleted_at set.');
            return self::SUCCESS;
        }

        $this->table(['Table', 'Rows to purge'], collect($targets)->map(fn($n,$t) => [$t, $n])->values()->all());
        $total = array_sum($targets);
        $this->line("Total rows: {$total}");

        if ($dryRun) {
            $this->warn('Dry run — no rows deleted.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm('Permanently delete all of the above? This cannot be undone.', false)) {
            $this->warn('Aborted.');
            return self::FAILURE;
        }

        $deleted = [];
        foreach ($targets as $table => $_) {
            try {
                $deleted[$table] = DB::table($table)->whereNotNull('deleted_at')->delete();
            } catch (\Throwable $e) {
                $this->error("Failed to purge {$table}: " . $e->getMessage());
                $deleted[$table] = 'ERROR';
            }
        }

        $this->table(['Table', 'Rows deleted'], collect($deleted)->map(fn($n,$t) => [$t, $n])->values()->all());
        $this->info('Purge complete.');
        return self::SUCCESS;
    }

    private function listTables(string $driver): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => array_map(fn($r) => array_values((array)$r)[0], DB::select('SHOW TABLES')),
            'sqlite' => array_column(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"), 'name'),
            'pgsql'  => array_column(DB::select("SELECT tablename AS name FROM pg_tables WHERE schemaname = current_schema()"), 'name'),
            default  => [],
        };
    }
}
