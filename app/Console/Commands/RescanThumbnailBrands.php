<?php

namespace App\Console\Commands;

use App\Models\ProspectingListing;
use App\Services\Prospecting\ListingImageValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * AT-22 item 2 — competitor-branding backfill.
 *
 * The content-detection gate (ListingImageValidator::inspectImageBytes) runs
 * at ingress for every NEW thumbnail, but rows captured BEFORE the gate existed
 * still carry their original bytes on disk with thumbnail_blocked_reason null —
 * including the PRES 87 / v175 leak (PP-T5391969, a RE/MAX "Coast and Country"
 * card stored as pp_PP-T5391969.jpg with a null source URL). This command
 * re-inspects every stored thumbnail file's PIXELS and persists a block reason
 * where OCR brand-text or the flat-graphic signal proves it is not a genuine
 * property photo.
 *
 * Idempotent: re-running re-inspects and converges to the same verdicts. The
 * file is never deleted (audit / no hard delete) — only thumbnail_blocked_reason
 * is written, which the seller-surface render gate and the thumbnail route both
 * honour. --dry-run reports without writing. --only=<id> targets a single row
 * (used to re-verify the exact leaked card). --rescan re-inspects rows that
 * already carry a reason (default skips them).
 */
class RescanThumbnailBrands extends Command
{
    protected $signature = 'prospecting:rescan-thumbnail-brands
        {--dry-run : Report verdicts without writing thumbnail_blocked_reason}
        {--only= : Inspect only this prospecting_listings id}
        {--rescan : Re-inspect rows that already have a blocked reason}
        {--chunk=200 : Rows per chunk}';

    protected $description = 'Re-inspect stored prospecting thumbnails for competitor branding (OCR + flat-graphic) and block the non-photo ones (AT-22 item 2).';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $only      = $this->option('only');
        $rescan    = (bool) $this->option('rescan');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $validator = new ListingImageValidator();
        if (! $validator->ocrAvailable()) {
            $this->warn('tesseract OCR is NOT available on this host — brand-TEXT detection is inert; only the flat-graphic signal will fire. Install tesseract-ocr for full coverage.');
        }

        $scanned     = 0;   // files inspected
        $missingFile = 0;   // path set but no file on disk
        $blocked     = 0;   // newly flagged this run
        $cleared     = 0;   // previously flagged, re-inspect now genuine (only with --rescan)
        $genuine     = 0;   // passed

        $query = ProspectingListing::query()
            ->whereNotNull('thumbnail_path')
            ->where('thumbnail_path', '!=', '')
            ->orderBy('id');

        if ($only !== null && $only !== '') {
            $query->where('id', (int) $only);
        } elseif (! $rescan) {
            // Default pass only touches rows not yet inspected.
            $query->whereNull('thumbnail_blocked_reason');
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . 'Inspecting stored thumbnails for competitor branding...');

        $query->chunkById($chunkSize, function ($listings) use (
            $validator, $dryRun, &$scanned, &$missingFile, &$blocked, &$cleared, &$genuine
        ) {
            foreach ($listings as $listing) {
                if (! Storage::disk('local')->exists($listing->thumbnail_path)) {
                    $missingFile++;
                    continue;
                }

                $scanned++;
                $verdict = $validator->inspectImageFile(
                    Storage::disk('local')->path($listing->thumbnail_path)
                );
                $newReason = $verdict['reason']; // null | 'brand:x' | 'graphic'
                $oldReason = $listing->thumbnail_blocked_reason;

                if ($newReason !== null) {
                    $blocked++;
                    $this->line(sprintf(
                        '  #%d %s — BLOCK (%s) [colours=%d entropy=%.2f]',
                        $listing->id,
                        $listing->portal_ref ?? '?',
                        $newReason,
                        $verdict['signals']['unique_colors'],
                        $verdict['signals']['entropy'],
                    ));
                } else {
                    $genuine++;
                    if ($oldReason !== null) {
                        $cleared++;
                    }
                }

                if (! $dryRun && $newReason !== $oldReason) {
                    $listing->thumbnail_blocked_reason = $newReason;
                    $listing->save();
                }
            }
        });

        $this->table(
            ['Metric', 'Count'],
            [
                ['Files inspected',          $scanned],
                ['Missing file on disk',     $missingFile],
                [($dryRun ? 'Would block' : 'Blocked'), $blocked],
                ['Genuine (passed)',         $genuine],
                ['Cleared (re-inspect genuine)', $cleared],
            ]
        );

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Done. {$blocked} thumbnail(s) flagged as branded/non-photo.");

        return self::SUCCESS;
    }
}
