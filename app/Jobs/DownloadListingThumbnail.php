<?php

namespace App\Jobs;

use App\Models\ProspectingListing;
use App\Services\Prospecting\ListingImageValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DownloadListingThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries = 2;

    /**
     * Its own lane; a worker must drain `thumbnails` or these strand. Set via
     * onQueue() (not a redeclared $queue property, which conflicts with the
     * Queueable trait).
     *
     * WHY NOT `default` (2026-09-14): this job is network-bound (~1-2s of CDN
     * fetch + GD downscale each) and arrives in BULK — a single
     * `prospecting:rehydrate-thumbnails` run dispatches one per listing. On
     * 2026-09-14 such a run put 5,226 of them on `default`, whose worker is
     * numprocs=1, and head-of-line-blocked every fast scheduled job behind
     * ~2h15m of image downloads. The casualty that mattered: PullP24LeadsJob /
     * PullPpLeadsJob run every 5 minutes, so each new portal lead check joined
     * the back of the queue — inbound buyer enquiries stopped reaching CoreX
     * for the duration.
     *
     * This is exactly the TranscribeVoiceNoteJob story of 2026-08-27 (see
     * config/queue_alerting.php) and it gets the same answer: slow bulk work
     * does not share a lane with latency-sensitive scheduled work.
     *
     * NOT `p24images` either — that lane is deliberately narrow to stay under
     * P24's per-IP limiter (see DownloadP24RowImagesJob); borrowing it would
     * starve the P24 import gallery pipeline instead. Different upstream,
     * different lane.
     */
    public function __construct(
        public ProspectingListing $listing,
        public string $thumbnailUrl,
    ) {
        $this->onQueue('thumbnails');
    }

    public function handle(): void
    {
        try {
            $imageData = file_get_contents($this->thumbnailUrl);

            if ($imageData === false) {
                Log::warning("DownloadListingThumbnail: failed to download {$this->thumbnailUrl} for listing {$this->listing->id}");
                return;
            }

            $source = @imagecreatefromstring($imageData);

            if ($source === false) {
                Log::warning("DownloadListingThumbnail: invalid image from {$this->thumbnailUrl} for listing {$this->listing->id}");
                return;
            }

            // AT-22 item 2 — competitor-branding gate, run on the FULL-RES bytes
            // (best OCR fidelity) BEFORE the 300px downscale. A captured "photo"
            // that is in fact a RE/MAX / Pam Golding / Seeff card — uploaded by a
            // competitor as the listing's primary image and served from a neutral
            // portal CDN URL — carries no brand token in its URL or our generated
            // filename; only its PIXELS betray it. Inspect them here so the verdict
            // is cached once and the seller-surface render gate is a single column
            // read. The file is still stored (audit / no hard delete); the reason
            // column is what blanks it to the "No photo" placeholder.
            $brandVerdict = (new ListingImageValidator())->inspectImageBytes($imageData);

            $origWidth = imagesx($source);
            $origHeight = imagesy($source);
            $newWidth = 300;
            $newHeight = (int) round($origHeight * ($newWidth / $origWidth));

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

            ob_start();
            imagejpeg($resized, null, 85);
            $jpegData = ob_get_clean();

            imagedestroy($source);
            imagedestroy($resized);

            $filename = $this->listing->portal_source . '_' . str_replace(['/', '\\', ' '], '_', $this->listing->portal_ref) . '.jpg';
            $path = 'prospecting/thumbnails/' . $filename;

            Storage::disk('local')->put($path, $jpegData);

            // Persist BOTH the stored path and the source URL it came from.
            // thumbnail_source_url lets prospecting:rehydrate-thumbnails
            // re-fetch this image later (e.g. after the Laravel 11 disk-root
            // move orphaned the old files) without needing a fresh capture
            // (AT-22 item 7). Set the attributes directly + save() so the
            // write does not depend on $fillable containing the new column.
            $this->listing->thumbnail_path = $path;
            $this->listing->thumbnail_source_url = $this->thumbnailUrl;
            $this->listing->thumbnail_blocked_reason = $brandVerdict['reason']; // null = genuine
            $this->listing->save();

            if ($brandVerdict['reason'] !== null) {
                Log::warning("DownloadListingThumbnail: BLOCKED branded/non-photo thumbnail for listing {$this->listing->id} ({$brandVerdict['reason']}) — stored for audit at {$path} but suppressed from seller surfaces.");
            } else {
                Log::info("DownloadListingThumbnail: saved thumbnail for listing {$this->listing->id} at {$path}");
            }
        } catch (\Throwable $e) {
            Log::warning("DownloadListingThumbnail: error for listing {$this->listing->id} — {$e->getMessage()}");
        }
    }
}
