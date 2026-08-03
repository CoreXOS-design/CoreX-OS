<?php

namespace App\Services\Images\BackgroundRemoval;

use App\Contracts\Images\BackgroundRemovalDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * remove.bg API. Multipart POST, `X-Api-Key` header, returns raw PNG bytes
 * on success plus an `X-Credits-Charged` response header (the figure this
 * feature logs per call — see RemoveAgentPhotoBackgroundJob). On error, a
 * JSON body `{errors:[{title:...}]}` with a 4xx/5xx status.
 *
 * `size` selects the output resolution tier: preview (0.25MP) | medium
 * (1.5MP) | hd (4MP) | full/4k (original) — read from
 * services.bg_removal.resolution (default medium).
 */
class RemoveBgDriver implements BackgroundRemovalDriver
{
    public function name(): string
    {
        return 'remove_bg';
    }

    public function removeBackground(string $absolutePath): BackgroundRemovalResult
    {
        $cfg = (array) config('services.bg_removal.remove_bg', []);
        $apiKey = $cfg['api_key'] ?? null;

        if (empty($apiKey)) {
            throw new BackgroundRemovalException(
                'remove.bg API key is not configured (REMOVE_BG_API_KEY).',
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
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->timeout((int) ($cfg['timeout'] ?? 30))
                ->attach('image_file', $bytes, basename($absolutePath))
                ->post($cfg['api_url'] ?? 'https://api.remove.bg/v1.0/removebg', [
                    'size' => config('services.bg_removal.resolution', 'medium'),
                ]);
        } catch (\Throwable $e) {
            throw new BackgroundRemovalException(
                'remove.bg API request failed: ' . $e->getMessage(),
                driver: $this->name(),
                previous: $e,
            );
        }

        if ($response->failed()) {
            throw new BackgroundRemovalException(
                'remove.bg API request failed: ' . $this->errorMessage($response),
                driver: $this->name(),
                httpStatus: $response->status(),
            );
        }

        $body = $response->body();
        if ($body === '' || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            throw new BackgroundRemovalException(
                'remove.bg API returned an unexpected response (not an image).',
                driver: $this->name(),
                httpStatus: $response->status(),
            );
        }

        return new BackgroundRemovalResult(
            pngContents: $body,
            driver: $this->name(),
            costCredits: $response->header('X-Credits-Charged'),
        );
    }

    private function errorMessage(Response $response): string
    {
        $json = $response->json();
        $first = is_array($json['errors'] ?? null) ? ($json['errors'][0]['title'] ?? null) : null;

        return $first ?? (string) $response->status();
    }
}
