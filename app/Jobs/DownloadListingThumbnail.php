<?php

namespace App\Jobs;

use App\Models\ProspectingListing;
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

    public function __construct(
        public ProspectingListing $listing,
        public string $thumbnailUrl,
    ) {}

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

            $this->listing->update(['thumbnail_path' => $path]);

            Log::info("DownloadListingThumbnail: saved thumbnail for listing {$this->listing->id} at {$path}");
        } catch (\Throwable $e) {
            Log::warning("DownloadListingThumbnail: error for listing {$this->listing->id} — {$e->getMessage()}");
        }
    }
}
