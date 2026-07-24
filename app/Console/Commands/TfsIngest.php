<?php

namespace App\Console\Commands;

use App\Services\Compliance\Tfs\SanctionsListIngestService;
use Illuminate\Console\Command;

/**
 * Ingest a TFS sanctions feed into CoreX. FAIL LOUD — a geo-block / non-XML / empty
 * fetch exits non-zero and records a `failed` import; it never leaves a stale list
 * looking fresh. --file lets an operator supply the XML if the live fetch is blocked.
 */
class TfsIngest extends Command
{
    protected $signature = 'tfs:ingest
        {--source=fic_un_consolidated : feed key from config/tfs.php}
        {--file= : ingest from a local XML file instead of the live fetch (geo-block fallback)}
        {--force : re-parse and replace even if the content SHA is unchanged}';

    protected $description = 'Fetch + ingest a Targeted Financial Sanctions list feed (multi-source, SHA-versioned, fail-loud).';

    public function handle(SanctionsListIngestService $svc): int
    {
        $feed = (string) $this->option('source');
        $file = $this->option('file') ?: null;
        $force = (bool) $this->option('force');

        $this->info("TFS ingest: feed=$feed" . ($file ? " file=$file" : ' (live fetch)') . ($force ? ' [force]' : ''));

        try {
            $import = $svc->ingest($feed, $file, $force);
        } catch (\Throwable $e) {
            $this->error('INGEST FAILED (fail-loud): ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line("  status:      {$import->status}");
        $this->line("  http:        " . ($import->http_status ?? '—'));
        $this->line("  sha256:      " . substr((string) $import->content_sha256, 0, 16) . '…');
        $this->line("  bytes:       {$import->file_bytes}");
        $this->line("  records:     {$import->record_count} ({$import->individual_count} individuals, {$import->entity_count} entities)");
        $this->line("  version:     import #{$import->id} @ " . optional($import->finished_at)->toDateTimeString());

        if ($import->status === 'failed') {
            $this->error('  ' . $import->error);
            return self::FAILURE;
        }
        $this->info('  OK');
        return self::SUCCESS;
    }
}
