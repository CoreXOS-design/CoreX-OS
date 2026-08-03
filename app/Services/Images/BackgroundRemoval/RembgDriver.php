<?php

namespace App\Services\Images\BackgroundRemoval;

use App\Contracts\Images\BackgroundRemovalDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Self-hosted rembg (u2net_human_seg) background-removal driver — the
 * default implementation as of §15.3, superseding the paid-API build.
 *
 * Talks to a persistent local FastAPI service (services/bgremoval/app.py,
 * deployed to /mnt/HC_Volume_103099143/corex-bgremoval-svc, systemd unit
 * corex-bgremoval.service) that keeps the model loaded in memory across
 * requests. The volume spike that justified this build measured ~0.7s of a
 * ~1.1s cold call as model load alone; a fresh process per call would
 * re-pay that on every photo. A persistent service pays it once per
 * process lifetime, then serves each request in ~0.3-0.5s.
 *
 * No cost/credit concept — self-hosted, so BackgroundRemovalResult::$costCredits
 * is always null here (the paid-API drivers are the only ones that populate it).
 */
class RembgDriver implements BackgroundRemovalDriver
{
    public function name(): string
    {
        return 'rembg';
    }

    public function removeBackground(string $absolutePath): BackgroundRemovalResult
    {
        $cfg = (array) config('services.bg_removal.rembg', []);
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'http://127.0.0.1:3106'), '/');

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false) {
            throw new BackgroundRemovalException(
                "Source photo could not be read at {$absolutePath}.",
                driver: $this->name(),
            );
        }

        try {
            $response = Http::timeout((int) ($cfg['timeout'] ?? 30))
                ->attach('image', $bytes, basename($absolutePath))
                ->post("{$baseUrl}/remove-background");
        } catch (\Throwable $e) {
            // Covers "service down" (connection refused — systemctl stop,
            // a crash, or the box rebooting) exactly like a timeout or any
            // other transport failure — all funnel through the same
            // BackgroundRemovalException the job already knows how to
            // retry/fail cleanly on.
            throw new BackgroundRemovalException(
                'rembg service request failed: ' . $e->getMessage(),
                driver: $this->name(),
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new BackgroundRemovalException(
                'rembg service request failed: ' . $this->errorMessage($response),
                driver: $this->name(),
                httpStatus: $response->status(),
            );
        }

        $body = $response->body();
        if ($body === '' || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new BackgroundRemovalException(
                'rembg service returned an unexpected response (not an image).',
                driver: $this->name(),
                httpStatus: $response->status(),
            );
        }

        return new BackgroundRemovalResult(
            pngContents: $body,
            driver: $this->name(),
            costCredits: null,
        );
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();

        return is_array($json) ? (string) ($json['error'] ?? $response->status()) : (string) $response->status();
    }
}
