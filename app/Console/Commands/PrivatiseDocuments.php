<?php

namespace App\Console\Commands;

use App\Support\PrivateScanFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;

/**
 * Move every sensitive document off the world-readable PUBLIC disk onto the private LOCAL disk.
 *
 * nginx serves storage/app/public with no login, so a document there is open to anyone holding the
 * link. After this runs the old public URL is a 404 and the document opens only through the
 * authenticated route (corex.documents.file) or its existing token portals.
 * Spec: .ai/specs/private-document-storage.md §3.5.
 *
 *     php artisan documents:privatise --dry-run     # preview, writes nothing
 *     php artisan documents:privatise               # move
 *
 * What it moves, in order:
 *   1. `documents` rows with disk = 'public' (incl. archived) -> same relative path on `local`, row flipped to
 *      disk = 'local'. One DB transaction per batch.
 *   2. Staff take-on / HR files (`user_documents`) — delegated to user-documents:migrate-to-private-disk.
 *   3. Legacy FICA files (`fica_documents`, public copies) — path unchanged; readers already try `local` first.
 *   4. Legacy public rental signature images — row rewritten to the "private:<path>" form.
 *   5. Sweep: any file still left in a sensitive public folder with no row pointing at it (orphans).
 *
 * Idempotent: a second run finds nothing to move. A file is copied, size-verified, the row flipped, and only
 * then is the public copy deleted — a crash can leave a spare private copy, never a row with no file.
 * Never overwrites a different private file at the same path (reported as a conflict, public copy kept).
 * Every move is logged to storage/logs/privatise-documents.log.
 */
class PrivatiseDocuments extends Command
{
    protected $signature = 'documents:privatise
        {--dry-run : List what would move without changing anything}
        {--batch=200 : Rows per database transaction}';

    protected $description = 'Move sensitive documents from the public disk to the private local disk (security).';

    /** Public folders that must never hold a document (swept for orphans in step 5). */
    private const SENSITIVE_PUBLIC_GLOBS = [
        '#^properties/\d+/files/#',
        '#^contacts/\d+/files/#',
        '#^fica/#',
        '#^take-on-docs/#',
        '#^properties/\d+/rental-(inspection|inventory)-signatures/#',
        '#^properties/\d+/rental-inventory-buyer-acceptances/#',
    ];

    /** @var array{moved:int,already:int,missing:int,conflict:int,failed:int} */
    private array $tally = ['moved' => 0, 'already' => 0, 'missing' => 0, 'conflict' => 0, 'failed' => 0];

    private bool $dry = false;

    /** Dry-run only: paths already counted as "would move", so the orphan sweep does not count them twice. @var array<string,true> */
    private array $planned = [];

    private LoggerInterface $log;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->tally = ['moved' => 0, 'already' => 0, 'missing' => 0, 'conflict' => 0, 'failed' => 0];
        $this->planned = [];
        $batch = max(1, (int) $this->option('batch'));
        $this->log = Log::build(['driver' => 'single', 'path' => storage_path('logs/privatise-documents.log')]);
        $this->log->info('documents:privatise start', ['dry_run' => $this->dry]);

        $this->stepDocuments($batch);
        $this->stepUserDocuments();
        $this->stepFica();
        $this->stepSignatures($batch);
        $this->stepSweepOrphans();

        $t = $this->tally;
        $this->newLine();
        $this->info(($this->dry ? '[dry-run] ' : '')
            . "Done. {$t['moved']} " . ($this->dry ? 'would move' : 'moved')
            . ", {$t['already']} already private, {$t['missing']} source missing, {$t['conflict']} conflicts, {$t['failed']} failed.");
        $this->log->info('documents:privatise end', $t + ['dry_run' => $this->dry]);

        return $t['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ── 1. documents table ────────────────────────────────────────────────────────

    private function stepDocuments(int $batch): void
    {
        $this->info('1/5 documents rows on the public disk');

        $query = DB::table('documents')->where('disk', 'public');
        $this->line('  rows: ' . (clone $query)->count());

        (clone $query)->orderBy('id')->chunkById($batch, function ($rows) {
            $flip = [];
            $deleteAfter = [];

            foreach ($rows as $row) {
                $outcome = $this->copyAcross((string) $row->storage_path, "documents#{$row->id}");
                if (in_array($outcome, ['copied', 'already'], true)) {
                    $flip[] = (int) $row->id;
                    $deleteAfter[] = (string) $row->storage_path;
                }
            }

            if ($this->dry || $flip === []) {
                return;
            }

            DB::transaction(fn () => DB::table('documents')->whereIn('id', $flip)->update(['disk' => 'local']));
            foreach ($deleteAfter as $path) {
                Storage::disk('public')->delete($path);
            }
        });
    }

    // ── 2. staff take-on / HR files ───────────────────────────────────────────────

    private function stepUserDocuments(): void
    {
        $this->info('2/5 staff take-on / HR files (user_documents)');
        $this->call('user-documents:migrate-to-private-disk', $this->dry ? ['--dry-run' => true] : []);
    }

    // ── 3. legacy FICA files ──────────────────────────────────────────────────────

    private function stepFica(): void
    {
        $this->info('3/5 legacy FICA files on the public disk');

        DB::table('fica_documents')->select('id', 'file_path')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                if (! $row->file_path || ! Storage::disk('public')->exists($row->file_path)) {
                    continue;
                }
                $outcome = $this->copyAcross((string) $row->file_path, "fica_documents#{$row->id}");
                if (! $this->dry && in_array($outcome, ['copied', 'already'], true)) {
                    Storage::disk('public')->delete($row->file_path);
                }
            }
        });
    }

    // ── 4. rental signature images referenced by a public "/storage/…" URL ────────

    private function stepSignatures(int $batch): void
    {
        $this->info('4/5 legacy public rental signature images');

        foreach (['rental_inspection_signatures', 'rental_inventory_signatures', 'rental_inventory_buyer_acceptances'] as $table) {
            foreach (['party_signature_path', 'wet_ink_upload_path'] as $column) {
                DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, 'not like', PrivateScanFile::PRIVATE_PREFIX . '%')
                    ->where($column, 'like', '%/storage/%')
                    ->orderBy('id')
                    ->chunkById($batch, function ($rows) use ($table, $column) {
                        $updates = [];
                        $deleteAfter = [];

                        foreach ($rows as $row) {
                            $rel = preg_replace('#^.*/storage/#', '', (string) $row->{$column});
                            if (! $rel || str_contains($rel, '..')) {
                                continue;
                            }
                            $outcome = $this->copyAcross($rel, "{$table}.{$column}#{$row->id}");
                            if (in_array($outcome, ['copied', 'already'], true)) {
                                $updates[(int) $row->id] = PrivateScanFile::PRIVATE_PREFIX . $rel;
                                $deleteAfter[] = $rel;
                            }
                        }

                        if ($this->dry || $updates === []) {
                            return;
                        }

                        DB::transaction(function () use ($table, $column, $updates) {
                            foreach ($updates as $id => $value) {
                                DB::table($table)->where('id', $id)->update([$column => $value]);
                            }
                        });
                        foreach ($deleteAfter as $path) {
                            Storage::disk('public')->delete($path);
                        }
                    });
            }
        }
    }

    // ── 5. orphans still in a sensitive public folder ─────────────────────────────

    private function stepSweepOrphans(): void
    {
        $this->info('5/5 sweep: files left in sensitive public folders with no row');

        $public = Storage::disk('public');
        foreach (['properties', 'contacts', 'fica', 'take-on-docs'] as $root) {
            if (! $public->directoryExists($root)) {
                continue;
            }
            foreach ($public->allFiles($root) as $path) {
                if (! $this->isSensitivePublicPath($path) || isset($this->planned[$path])) {
                    continue;
                }
                $outcome = $this->copyAcross($path, "orphan:{$path}");
                if (! $this->dry && in_array($outcome, ['copied', 'already'], true)) {
                    $public->delete($path);
                }
            }
        }
    }

    private function isSensitivePublicPath(string $path): bool
    {
        foreach (self::SENSITIVE_PUBLIC_GLOBS as $pattern) {
            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    // ── shared ────────────────────────────────────────────────────────────────────

    /**
     * Copy one file public -> local, size-verified. Never deletes anything and never overwrites a different file.
     *
     * @return 'copied'|'already'|'missing'|'conflict'|'failed'
     */
    private function copyAcross(string $path, string $label): string
    {
        $public = Storage::disk('public');
        $local = Storage::disk('local');

        if (! $public->exists($path)) {
            // Source gone. If the private copy is there, the move is already done.
            if ($local->exists($path)) {
                $this->tally['already']++;

                return 'already';
            }
            $this->tally['missing']++;
            $this->warn("  missing on both disks: {$label} {$path}");
            $this->log->warning('missing', ['ref' => $label, 'path' => $path]);

            return 'missing';
        }

        $size = $public->size($path);

        if ($local->exists($path)) {
            if ($local->size($path) === $size) {
                $this->tally['already']++;

                return 'already'; // a previous run (or a crash) already copied it
            }
            $this->tally['conflict']++;
            $this->error("  conflict (different private file at same path, public copy kept): {$label} {$path}");
            $this->log->error('conflict', ['ref' => $label, 'path' => $path]);

            return 'conflict';
        }

        if ($this->dry) {
            if (isset($this->planned[$path])) {
                return 'copied';
            }
            $this->planned[$path] = true;
            $this->line("  would move: {$label} {$path} ({$size} bytes)");
            $this->tally['moved']++;

            return 'copied';
        }

        try {
            $stream = $public->readStream($path);
            if (! is_resource($stream)) {
                throw new \RuntimeException('could not open read stream');
            }
            $local->put($path, $stream);
            fclose($stream);

            if (! $local->exists($path) || $local->size($path) !== $size) {
                $local->delete($path);
                throw new \RuntimeException('size verification failed');
            }
        } catch (\Throwable $e) {
            $this->tally['failed']++;
            $this->error("  FAILED {$label} {$path}: {$e->getMessage()}");
            $this->log->error('failed', ['ref' => $label, 'path' => $path, 'error' => $e->getMessage()]);

            return 'failed';
        }

        $this->tally['moved']++;
        $this->line("  moved: {$label} {$path} ({$size} bytes)");
        $this->log->info('moved', ['ref' => $label, 'path' => $path, 'bytes' => $size]);

        return 'copied';
    }
}
