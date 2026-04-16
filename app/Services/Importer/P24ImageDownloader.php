<?php

namespace App\Services\Importer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class P24ImageDownloader
{
    /**
     * Downloads $url to storage/app/public/$destRelativePath.
     * Returns the relative path on success, null on failure.
     */
    public function download(string $url, string $destRelativePath): ?string
    {
        try {
            $response = Http::timeout(15)->retry(3, 250)->get($url);
            if (!$response->ok()) {
                Log::warning("P24 image download non-200", ['url' => $url, 'status' => $response->status()]);
                return null;
            }
            Storage::disk('public')->put($destRelativePath, $response->body());
            return $destRelativePath;
        } catch (\Throwable $e) {
            Log::warning("P24 image download failed", ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
