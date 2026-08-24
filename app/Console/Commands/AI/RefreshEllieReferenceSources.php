<?php

namespace App\Console\Commands\AI;

use App\Models\AI\EllieReferenceSource;
use App\Services\AI\EllieReferenceSourceFetchService;
use Illuminate\Console\Command;

/**
 * Daily re-fetch of every active Ellie reference source. The fetch service
 * itself is what enforces the SSRF guards and skips re-embedding when content
 * hasn't changed (content_hash comparison) — this command is just the sweep.
 *
 * Spec: .ai/specs/ellie-reference-sources.md §6.
 */
class RefreshEllieReferenceSources extends Command
{
    protected $signature = 'ellie:refresh-reference-sources';

    protected $description = 'Re-fetch every active Ellie external reference source (SSRF-guarded).';

    public function handle(EllieReferenceSourceFetchService $fetcher): int
    {
        $sources = EllieReferenceSource::where('is_active', true)->get();

        $this->info("Refreshing {$sources->count()} active reference source(s)...");

        $ok = 0;
        $failed = 0;

        foreach ($sources as $source) {
            $fetcher->refresh($source);
            $source->refresh();

            if ($source->last_fetch_status === EllieReferenceSource::STATUS_OK) {
                $ok++;
            } else {
                $failed++;
                $this->warn("  [{$source->id}] {$source->url} — {$source->fetch_error}");
            }
        }

        $this->info("Done: {$ok} ok, {$failed} failed.");

        return self::SUCCESS;
    }
}
