<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Snapshot trait for the wishlist migration commands.
 *
 * Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 8.1.
 *
 * Tries mysqldump first (faster + native SQL restore). If mysqldump is not on
 * PATH (common on Windows dev hosts), falls back to JSON dumps via Eloquent
 * query builder. Either format restores cleanly via WishlistRollbackMigration.
 *
 * The snapshot directory:
 *     storage/backups/wishlist-migration/{run_id}/
 *         buyer_preferences.{sql|json}
 *         contact_matches.{sql|json}
 *         contacts.{sql|json}
 *         prospecting_buyer_matches.{sql|json}
 *         property_buyer_matches.{sql|json}
 *         _metadata.json   (mode marker — "sql" or "json" so rollback knows which to read)
 */
trait WishlistMigrationSnapshot
{
    /**
     * Snapshot every table the wishlist migration touches.
     *
     * @return string the absolute snapshot directory path.
     */
    protected function snapshotTables(string $runId): string
    {
        $dir = storage_path("backups/wishlist-migration/{$runId}");
        File::ensureDirectoryExists($dir);

        $tables = [
            'buyer_preferences',
            'contact_matches',
            'contacts',
            'prospecting_buyer_matches',
            'property_buyer_matches',
        ];

        $mode = $this->resolveSnapshotMode();
        File::put($dir . '/_metadata.json', json_encode([
            'run_id'     => $runId,
            'mode'       => $mode,
            'created_at' => now()->toIso8601String(),
            'tables'     => $tables,
        ], JSON_PRETTY_PRINT));

        foreach ($tables as $table) {
            if ($mode === 'sql') {
                $this->dumpSql($dir, $table);
            } else {
                $this->dumpJson($dir, $table);
            }
        }

        return $dir;
    }

    /** Returns 'sql' if mysqldump is on PATH; 'json' otherwise. */
    protected function resolveSnapshotMode(): string
    {
        $output = [];
        $exitCode = 0;
        @exec((PHP_OS_FAMILY === 'Windows' ? 'where' : 'which') . ' mysqldump 2>&1', $output, $exitCode);
        return $exitCode === 0 ? 'sql' : 'json';
    }

    protected function dumpSql(string $dir, string $table): void
    {
        $conn = config('database.connections.' . config('database.default'));
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s %s %s > %s',
            escapeshellarg($conn['host']),
            escapeshellarg((string) $conn['port']),
            escapeshellarg($conn['username']),
            escapeshellarg($conn['password']),
            escapeshellarg($conn['database']),
            escapeshellarg($table),
            escapeshellarg($dir . '/' . $table . '.sql')
        );
        passthru($cmd);
    }

    protected function dumpJson(string $dir, string $table): void
    {
        // Stream by chunks so very large tables (e.g. prospecting_buyer_matches
        // with 30k rows) don't blow the memory budget.
        $path = $dir . '/' . $table . '.json';
        $handle = fopen($path, 'wb');
        fwrite($handle, "[\n");
        $first = true;
        DB::table($table)->orderBy('id')->chunk(500, function ($rows) use ($handle, &$first) {
            foreach ($rows as $row) {
                $line = json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                fwrite($handle, ($first ? '' : ",\n") . '  ' . $line);
                $first = false;
            }
        });
        fwrite($handle, "\n]\n");
        fclose($handle);
    }
}
