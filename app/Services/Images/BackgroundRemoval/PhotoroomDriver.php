<?php

namespace App\Services\Images\BackgroundRemoval;

use App\Contracts\Images\BackgroundRemovalDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Photoroom "Remove Background" API. Multipart POST, `x-api-key` header,
 * returns raw PNG bytes (Content-Type: image/png) directly on success — no
 * JSON envelope. `size` selects the output resolution tier: preview
 * (0.25MP) | medium (1.5MP) | hd (4MP) | full (36MP) — read from
 * services.bg_removal.resolution (default medium; our 1200×1200/1.44MP
 * source needs at least medium, preview would visibly soften every ad).
 *
 * Endpoint/response shape taken from Photoroom's public API docs at build
 * time (2026-08) — no live key was available to verify against a real call
 * (see ad-manager.md §15.2's verification note). If the endpoint or a
 * header name has moved, only api_url/photoroom config changes — never the
 * calling code.
 */
class PhotoroomDriver implements BackgroundRemovalDriver
{
    public function name(): string
    {
        return 'photoroom';
    }

    public function removeBackground(string $absolutePath): BackgroundRemovalResult
    {
        $cfg = (array) config('services.bg_removal.photoroom', []);
        $apiKey = $cfg['api_key'] ?? null;

        if (empty($apiKey)) {
            throw new BackgroundRemovalException(
                'Photoroom API key is not configured (PHOTOROOM_API_KEY).',
                driver: $this->name(),
            );
        }

        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false) {
            throw new BackgroundRemovalException(
                "Source photo could not be read at {$absolutePath}.",
                driver: $this->name(),
            );
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $apiKey])
                ->timeout((int) ($cfg['timeout'] ?? 30))
                ->attach('image_file', $bytes, basename($absolutePath))
                ->post($cfg['api_url'] ?? 'https://sdk.photoroom.com/v1/segment', [
                    'format' => 'png',
                    'size'   => config('services.bg_removal.resolution', 'medium'),
                ]);
        } catch (\Throwable $e) {
            throw new BackgroundRemovalException(
                'Photoroom API request failed: ' . $e->getMessage(),
                driver: $this->name(),
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new BackgroundRemovalException(
                'Photoroom API request failed: ' . $this->errorMessage($response),
                driver: $this->name(),
                httpStatus: $response->status(),
            );
        }

        $body = $response->body();
        if ($body === '' || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new BackgroundRemovalException(
                'Photoroom API returned an unexpected response (not an image).',
                driver: $this->name(),
                httpStatus: $response->status(),
            );
        }

        return new BackgroundRemovalResult(
            pngContents: $body,
            driver: $this->name(),
            costCredits: $response->header('X-Credits-Charged') ?: $response->header('x-credits-charged'),
        );
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();

        return is_array($json) ? (string) ($json['detail'] ?? $json['message'] ?? $response->status()) : (string) $response->status();
    }
}
