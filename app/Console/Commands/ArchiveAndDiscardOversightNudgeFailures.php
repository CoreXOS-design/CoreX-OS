<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CX — 2026-08-23. Johan's decision on the 10,356-row OversightNudgeMail
 * failed_jobs backlog: DISCARD, fix forward — do not retry. Reasoning (also
 * recorded in .ai/audits/2026-08-23-queue-failed-jobs-triage.md, §7): the
 * mail-namespace bug (fixed 2026-08-23) is resolved, and OversightDigestJob
 * runs hourly and will re-nudge anything still genuinely outstanding — so
 * nothing real is lost by discarding. Retrying instead would deliver up to
 * 10,356 "action required" emails to real managers, weeks late, about things
 * many of them will have already dealt with by other means in the meantime.
 * A stale automated nudge arriving weeks late reads as CoreX being broken or
 * ignored, which is worse for a client relationship than the nudge never
 * having arrived — confusion and a broken-trust signal, not a caught-up one.
 *
 * SCOPE: this command touches ONLY failed_jobs rows whose displayName is
 * EXACTLY App\Mail\OversightNudgeMail (matched via the JSON path operator,
 * not a LIKE substring match — no accidental collision with any other
 * class). It does not touch SyncProperty24Activations, RegenerateBuyerMatchesJob,
 * OversightDigestJob, or DesyndicatePropertyFromPortalsJob rows — those are
 * evidence of real unresolved problems and must survive this untouched. See
 * that same audit file, §3, for why DesyndicatePropertyFromPortalsJob
 * specifically must never be bulk-retried OR bulk-deleted without per-row
 * review — this command will never touch it, by construction.
 *
 * SAFETY CONTRACT:
 * - Defaults to a dry run: prints the exact SQL, the row count, and the
 *   archive path it WOULD write to. Changes nothing unless --execute is
 *   passed explicitly.
 * - Archives every matching row (id, uuid, connection, queue, payload,
 *   exception, failed_at) to a JSON file on the data volume BEFORE deleting
 *   anything, and verifies the written file's row count matches the query
 *   before proceeding.
 * - Deletes by the EXACT row ids that were just archived — not by re-running
 *   the same WHERE clause a second time — so there is no window where a
 *   newly-inserted row (a fresh, still-broken send, impossible after
 *   2026-08-23's fix, but never assume) could be deleted without having been
 *   archived first.
 * - Live requires Johan's explicit go-ahead for this exact action (per his
 *   2026-08-23 instruction) — this command does not know or care which
 *   environment it runs in; that authorization lives outside this file, in
 *   the decision to invoke --execute against a given database at all.
 */
class ArchiveAndDiscardOversightNudgeFailures extends Command
{
    protected $signature = 'corex:archive-discard-oversight-nudge-failures
        {--execute : Actually archive and delete. Without this flag, nothing is written or deleted.}
        {--path=/mnt/HC_Volume_103099143/corex-backups/queue-job-archives : Base directory for the archive file (must be on the data volume, never /, and writable by the www-data FPM/CLI user).}';

    protected $description = 'Archive then discard failed_jobs rows for App\\Mail\\OversightNudgeMail specifically — see class docblock for the full reasoning and safety contract.';

    private const TARGET_CLASS = 'App\\Mail\\OversightNudgeMail';

    public function handle(): int
    {
        $sql = "select `id`, `uuid`, `connection`, `queue`, `payload`, `exception`, `failed_at` "
            . "from `failed_jobs` where `payload`->>'$.displayName' = '" . self::TARGET_CLASS . "'";

        $rows = DB::table('failed_jobs')
            ->where('payload->displayName', self::TARGET_CLASS)
            ->orderBy('id')
            ->get();

        $count = $rows->count();

        $this->line('Exact SQL (archive query — the delete afterwards targets these same row ids, not a re-run of this WHERE clause):');
        $this->line($sql . ';');
        $this->newLine();
        $this->info("Rows matching App\\Mail\\OversightNudgeMail in failed_jobs right now: {$count}");

        if ($count === 0) {
            $this->info('Nothing to do.');
            return self::SUCCESS;
        }

        $archivePath = rtrim((string) $this->option('path'), '/')
            . '/failed-oversight-nudges-' . config('app.env') . '-' . now()->format('Ymd-His') . '.json';

        if (!$this->option('execute')) {
            $this->warn('DRY RUN — nothing archived, nothing deleted. Would write to:');
            $this->line($archivePath);
            $this->warn('Re-run with --execute to actually archive and delete.');
            return self::SUCCESS;
        }

        $dir = dirname($archivePath);
        if (!is_dir($dir)) {
            $this->error("Archive directory does not exist: {$dir}. Refusing to proceed — create it first (on the data volume, never under /).");
            return self::FAILURE;
        }

        $payload = $rows->map(fn ($r) => [
            'id'         => $r->id,
            'uuid'       => $r->uuid,
            'connection' => $r->connection,
            'queue'      => $r->queue,
            'payload'    => $r->payload,
            'exception'  => $r->exception,
            'failed_at'  => $r->failed_at,
        ])->all();

        file_put_contents($archivePath, json_encode([
            'archived_at'       => now()->toDateTimeString(),
            'environment'       => config('app.env'),
            'target_class'      => self::TARGET_CLASS,
            'reason'            => 'Mail-namespace bug fixed 2026-08-23 (Content(view:) should have been Content(markdown:)). '
                . 'Johan\'s decision: discard rather than retry — a stale nudge arriving weeks late confuses a '
                . 'manager about something already handled and reads as CoreX being broken, which is worse for the '
                . 'client relationship than the nudge never arriving. OversightDigestJob runs hourly and will '
                . 're-nudge anything still genuinely outstanding, so nothing real is lost.',
            'row_count'         => $count,
            'rows'              => $payload,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        clearstatcache(true, $archivePath);
        if (!is_file($archivePath)) {
            $this->error('Archive file was not created — aborting before any delete.');
            return self::FAILURE;
        }

        $written = json_decode(file_get_contents($archivePath), true);
        $writtenCount = count($written['rows'] ?? []);
        if ($writtenCount !== $count) {
            $this->error("Archive verification failed: queried {$count} rows but the written file has {$writtenCount}. Aborting before any delete.");
            return self::FAILURE;
        }

        $this->info("Archived {$writtenCount} rows to: {$archivePath}");

        $ids = $rows->pluck('id')->all();
        $deleted = DB::table('failed_jobs')->whereIn('id', $ids)->delete();

        $this->info("Deleted {$deleted} rows from failed_jobs (matched by the exact ids just archived).");

        if ($deleted !== $count) {
            $this->error("MISMATCH: archived {$count} but deleted {$deleted} — investigate before trusting the archive as complete. The archive file itself is still valid and was written first.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
