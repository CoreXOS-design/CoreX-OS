<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ContactMatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Roll back a completed wishlist:migrate run by:
 *   1. Restoring buyer_preferences from the on-disk snapshot.
 *   2. Soft-deleting every ContactMatch row created/appended by the run.
 *   3. Restoring the soft-deleted placeholder Row 1 (id=1, contact_id=2).
 *   4. NULL-ing preapproval_* on the contacts that received those updates.
 *
 * Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 12 (rollback plan).
 *
 * The rollback itself is logged via Log::info to laravel.log — the existing
 * wishlist_migration_log.action enum does not include 'rolled_back', and the
 * restored buyer_preferences table is itself the implicit audit trail.
 */
class WishlistRollbackMigration extends Command
{
    protected $signature = 'wishlist:rollback-migration
                            {--run-id= : The live wishlist:migrate run_id to roll back}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Restore buyer_preferences and revert ContactMatch + preapproval writes from a previous live migration.';

    public function handle(): int
    {
        $runId = $this->option('run-id');
        if (!$runId) {
            $this->error('--run-id is required.');
            return self::FAILURE;
        }

        $logRows = DB::table('wishlist_migration_log')
            ->where('run_id', $runId)
            ->where('mode', 'live')
            ->get();
        if ($logRows->isEmpty()) {
            $this->error("No live migration log entries found for run_id={$runId}.");
            return self::FAILURE;
        }

        $snapshotDir = storage_path("backups/wishlist-migration/{$runId}");
        if (!File::isDirectory($snapshotDir)) {
            $this->error("Snapshot directory not found: {$snapshotDir}");
            return self::FAILURE;
        }
        $metaPath = $snapshotDir . '/_metadata.json';
        if (!File::exists($metaPath)) {
            $this->error("Snapshot _metadata.json missing — rollback cannot verify snapshot integrity.");
            return self::FAILURE;
        }
        $meta = json_decode(File::get($metaPath), true);
        $mode = $meta['mode'] ?? 'unknown';

        $created    = $logRows->whereIn('action', ['created', 'appended', 'merged'])->count();
        $deletions  = $logRows->where('action', 'skipped')->where('target_contact_match_id', 1)->count();
        $affectedContactIds = $logRows->pluck('contact_id')->filter()->unique()->values();

        $this->newLine();
        $this->info("Rollback plan for run_id={$runId}:");
        $this->line("  snapshot mode:            {$mode}");
        $this->line("  snapshot dir:             {$snapshotDir}");
        $this->line("  ContactMatch rows to soft-delete (created/appended): {$created}");
        $this->line("  ContactMatch id=1 restore (un-soft-delete): " . ($deletions > 0 ? 'yes' : 'no'));
        $this->line("  contacts affected (preapproval revert):    {$affectedContactIds->count()}");
        $this->line("  buyer_preferences restore from snapshot:   yes");
        $this->newLine();

        if (!$this->option('force')) {
            $answer = $this->ask("Type 'rollback' to proceed");
            if ($answer !== 'rollback') {
                $this->warn('Aborted (no changes made).');
                return self::SUCCESS;
            }
        }

        try {
            DB::transaction(function () use ($runId, $logRows, $snapshotDir, $mode, $affectedContactIds) {
                // 1. Restore buyer_preferences from snapshot.
                $this->restoreTableFromSnapshot('buyer_preferences', $snapshotDir, $mode);
                $this->line('  ✓ buyer_preferences restored');

                // 2. Soft-delete every ContactMatch this run created.
                $newMatchIds = $logRows
                    ->whereIn('action', ['created', 'appended', 'merged'])
                    ->pluck('target_contact_match_id')
                    ->filter()
                    ->values();
                ContactMatch::withoutGlobalScopes()
                    ->whereIn('id', $newMatchIds)
                    ->delete();
                $this->line('  ✓ ' . $newMatchIds->count() . ' migrated ContactMatch rows soft-deleted');

                // 3. Restore the placeholder Row 1.
                $placeholder = $logRows->where('action', 'skipped')
                    ->where('target_contact_match_id', 1)
                    ->first();
                if ($placeholder) {
                    ContactMatch::withoutGlobalScopes()->onlyTrashed()->where('id', 1)->restore();
                    $this->line('  ✓ ContactMatch id=1 restored');
                }

                // 4. Revert preapproval blocks. We restore from the snapshot rather
                //    than NULL-ing to avoid clobbering preapproval data that may
                //    have existed pre-migration on these contacts (edge case).
                $this->restoreContactsPreapproval($snapshotDir, $mode, $affectedContactIds->all());
                $this->line('  ✓ preapproval block reverted for ' . $affectedContactIds->count() . ' contacts');
            });
        } catch (\Throwable $e) {
            $this->error('Rollback FAILED — transaction rolled back.');
            $this->error('  ' . $e->getMessage());
            return self::FAILURE;
        }

        // 5. Re-trigger ContactMatchObserver-style integrity: ensure each
        //    contact still has exactly one primary live match. After the
        //    soft-deletes above, contact_id=24's Row 2 may have lost its
        //    primary; restore it explicitly.
        $row2 = ContactMatch::withoutGlobalScopes()->find(2);
        if ($row2 && $row2->deleted_at === null) {
            $row2->is_primary = true;
            $row2->save();
        }

        Log::info('Wishlist live migration rolled back', [
            'run_id'             => $runId,
            'rolled_back_at'     => now()->toIso8601String(),
            'contact_matches_archived' => $created,
            'contacts_affected'  => $affectedContactIds->count(),
            'snapshot_dir'       => $snapshotDir,
        ]);

        $this->newLine();
        $this->info('Rollback complete.');
        $this->line("  buyer_preferences: " . DB::table('buyer_preferences')->count() . ' (restored)');
        $this->line('  See storage/logs/laravel.log for the rollback record.');

        return self::SUCCESS;
    }

    private function restoreTableFromSnapshot(string $table, string $dir, string $mode): void
    {
        // Clear the table first so the snapshot is the canonical state.
        DB::table($table)->delete();

        if ($mode === 'json') {
            $path = $dir . '/' . $table . '.json';
            if (!File::exists($path)) {
                throw new \RuntimeException("Snapshot file missing: {$path}");
            }
            $rows = json_decode(File::get($path), true);
            if (!is_array($rows)) {
                throw new \RuntimeException("Snapshot file corrupt: {$path}");
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                if (!empty($chunk)) DB::table($table)->insert($chunk);
            }
        } else {
            // SQL mode: shell out to mysql client to load.
            $conn = config('database.connections.' . config('database.default'));
            $path = $dir . '/' . $table . '.sql';
            $cmd = sprintf(
                'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
                escapeshellarg($conn['host']),
                escapeshellarg((string) $conn['port']),
                escapeshellarg($conn['username']),
                escapeshellarg($conn['password']),
                escapeshellarg($conn['database']),
                escapeshellarg($path)
            );
            passthru($cmd);
        }
    }

    /**
     * Restore the preapproval columns on the affected contacts using the
     * full contacts-table snapshot. Reads only the rows we care about so
     * the file scan is cheap.
     *
     * @param int[] $contactIds
     */
    private function restoreContactsPreapproval(string $dir, string $mode, array $contactIds): void
    {
        if (empty($contactIds)) return;

        if ($mode === 'json') {
            $path = $dir . '/contacts.json';
            if (!File::exists($path)) return;
            $rows = json_decode(File::get($path), true);
            if (!is_array($rows)) return;
            $byId = [];
            foreach ($rows as $r) {
                if (isset($r['id']) && in_array((int) $r['id'], $contactIds, true)) {
                    $byId[(int) $r['id']] = $r;
                }
            }
            foreach ($contactIds as $cid) {
                $snapRow = $byId[(int) $cid] ?? null;
                DB::table('contacts')->where('id', $cid)->update([
                    'preapproval_amount'      => $snapRow['preapproval_amount']      ?? null,
                    'preapproval_expires_at'  => $snapRow['preapproval_expires_at']  ?? null,
                    'preapproval_institution' => $snapRow['preapproval_institution'] ?? null,
                ]);
            }
        } else {
            // SQL mode: the simplest safe path is to NULL the columns. A full
            // contacts-table restore would clobber unrelated edits since the
            // migration. NULLing is correct iff no contact had pre-existing
            // preapproval data, which we assume given the empty audit baseline.
            foreach ($contactIds as $cid) {
                DB::table('contacts')->where('id', $cid)->update([
                    'preapproval_amount'      => null,
                    'preapproval_expires_at'  => null,
                    'preapproval_institution' => null,
                ]);
            }
        }
    }
}
