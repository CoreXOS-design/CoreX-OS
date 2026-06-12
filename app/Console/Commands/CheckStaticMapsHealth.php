<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * AT-22 item 3/5 — Google Static Maps health-check.
 *
 * The presentation spatial view (item 3) and the subject-card map (item 5) both
 * render a Google Static Maps PNG when `services.google.static_maps_api_key` is
 * a non-empty string, and silently fall back to a blank-grey SVG when the fetch
 * returns null. The live symptom — pins on a grey background — was NOT a missing
 * key: the key is set (the GOOGLE_GEOCODING_API_KEY, reused), but the Maps
 * Static API is NOT ENABLED on the Google Cloud project, so every request 403s
 * with "This API is not activated on your API project."
 *
 * The gating code only checks the key STRING is non-empty, so the 403 is
 * invisible to an operator. This command makes it visible: it fires one real
 * Static Maps request and reports OK / not-activated / key-missing / other,
 * returning a non-zero exit on any problem so it can gate a deploy. Run it on
 * Staging (and live) AFTER enabling the API to confirm both maps will render.
 */
class CheckStaticMapsHealth extends Command
{
    protected $signature = 'presentations:check-static-maps';

    protected $description = 'Probe the Google Static Maps API and report whether presentation maps (spatial view + subject map) will render (AT-22 item 3/5).';

    public function handle(): int
    {
        $key = (string) config('services.google.static_maps_api_key', '');

        if ($key === '') {
            $this->error('Static Maps API key is EMPTY (services.google.static_maps_api_key / GOOGLE_STATIC_MAPS_API_KEY / GOOGLE_GEOCODING_API_KEY).');
            $this->line('  → Presentation maps will use the blank-grey SVG fallback. Set a key with the Maps Static API enabled.');
            return self::FAILURE;
        }

        $this->line('Key configured (…' . substr($key, -6) . '). Probing Maps Static API...');

        // A tiny, cheap request over the KZN South Coast.
        $url = 'https://maps.googleapis.com/maps/api/staticmap?center=-30.85,30.38&zoom=13&size=120x80&key=' . urlencode($key);

        try {
            $resp = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $body = (string) $resp->body();

        if ($resp->ok() && str_starts_with($body, "\x89PNG")) {
            $this->info('✓ HEALTHY — Maps Static API returned a PNG. Spatial view + subject map will render the real basemap.');
            return self::SUCCESS;
        }

        // Surface the actionable failure mode explicitly. Google returns a 403
        // for BOTH "API not activated on the project" AND "key not authorized /
        // API restrictions" — both are fixed in the Cloud Console for this key.
        if ($resp->status() === 403 && $this->isApiAccessError($body)) {
            $this->error('✗ Maps Static API is BLOCKED for this key (HTTP 403): the API is either not enabled on the project, or the key’s API restrictions exclude it.');
            $this->line('  → Enable "Maps Static API": https://console.cloud.google.com/apis/library/static-maps-backend.googleapis.com');
            $this->line('  → AND check the key’s "API restrictions" include Maps Static API: https://console.cloud.google.com/apis/credentials');
            $this->line('  → Until fixed, the spatial view + subject map fall back to the blank-grey SVG.');
            $this->line('  Body: ' . mb_substr($body, 0, 200));
            return self::FAILURE;
        }

        $this->error('✗ Maps Static API returned HTTP ' . $resp->status() . ' (not a PNG).');
        $this->line('  Body: ' . mb_substr($body, 0, 300));
        return self::FAILURE;
    }

    /** Google's 403 phrasings for "this key can't use this API". */
    private function isApiAccessError(string $body): bool
    {
        foreach (['not activated', 'not authorized', 'API restrictions', 'IP, referer or API'] as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
