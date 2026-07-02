<?php

namespace App\Services\Communications;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AT-156 — WhatsApp Capture Linking: talks to the WAHA server for session
 * lifecycle + QR pairing (the AT-148 WahaMediaClient only downloads media).
 *
 * All calls authenticate with the WAHA API key server-side (X-Api-Key) — the
 * key NEVER leaves the server. The QR PNG is proxied to the browser by the
 * controller, so the client never sees WAHA or the key.
 *
 * A connection-level failure (WAHA down) throws {@see WahaUnavailableException}
 * so the caller can render a graceful "service unavailable, retry" state rather
 * than a 500. Logical outcomes (no session, wrong state) are returned as data.
 */
class WahaSessionClient
{
    private function base(): string
    {
        return rtrim((string) config('communications.waha.base_url', 'http://127.0.0.1:3111'), '/');
    }

    private function http(int $timeout = 15)
    {
        $req = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson();
        if ($apiKey = config('communications.waha.api_key')) {
            $req = $req->withHeaders(['X-Api-Key' => $apiKey]);
        }

        return $req;
    }

    /**
     * @return array{exists:bool,status:string,me:?array}
     * @throws WahaUnavailableException when WAHA cannot be reached
     */
    public function status(string $session): array
    {
        try {
            $res = $this->http()->get($this->base() . '/api/sessions/' . rawurlencode($session));
        } catch (\Throwable $e) {
            throw new WahaUnavailableException('WAHA unreachable: ' . $e->getMessage(), 0, $e);
        }

        if ($res->status() === 404) {
            return ['exists' => false, 'status' => 'NO_SESSION', 'me' => null];
        }
        if (! $res->successful()) {
            throw new WahaUnavailableException('WAHA status HTTP ' . $res->status());
        }

        $body = $res->json() ?: [];

        return [
            'exists' => true,
            'status' => (string) ($body['status'] ?? 'UNKNOWN'),
            'me'     => $body['me'] ?? null,
        ];
    }

    /**
     * Create-and-start the session if it does not exist, or (re)start it if it
     * is stopped. Idempotent — calling it when the session is already
     * SCAN_QR_CODE / WORKING is a no-op that just returns current status.
     *
     * @throws WahaUnavailableException
     */
    public function ensureStarted(string $session, string $webhookUrl, string $secret): array
    {
        $current = $this->status($session);

        if (! $current['exists']) {
            $payload = [
                'name'   => $session,
                'start'  => true,
                'config' => ['webhooks' => [[
                    'url'    => $webhookUrl,
                    'events' => ['message', 'message.any', 'session.status'],
                    'hmac'   => ['key' => $secret],
                ]]],
            ];
            try {
                $this->http(20)->post($this->base() . '/api/sessions', $payload);
            } catch (\Throwable $e) {
                throw new WahaUnavailableException('WAHA create-session failed: ' . $e->getMessage(), 0, $e);
            }

            return $this->status($session);
        }

        // Exists but idle/failed — nudge it back to a pairing/working state.
        if (in_array($current['status'], ['STOPPED', 'FAILED'], true)) {
            try {
                $this->http(20)->post($this->base() . '/api/sessions/' . rawurlencode($session) . '/start');
            } catch (\Throwable $e) {
                throw new WahaUnavailableException('WAHA start-session failed: ' . $e->getMessage(), 0, $e);
            }

            return $this->status($session);
        }

        return $current;
    }

    /**
     * Fetch the pairing QR as PNG bytes (null if not currently pairable, e.g.
     * already WORKING or no session). Never throws for a logical miss.
     *
     * @throws WahaUnavailableException on connection failure
     */
    public function qrPng(string $session): ?string
    {
        try {
            $res = $this->http()->get($this->base() . '/api/' . rawurlencode($session) . '/auth/qr', ['format' => 'image']);
        } catch (\Throwable $e) {
            throw new WahaUnavailableException('WAHA qr fetch failed: ' . $e->getMessage(), 0, $e);
        }

        if (! $res->successful()) {
            return null;
        }
        $bytes = $res->body();

        return $bytes !== '' ? $bytes : null;
    }

    /** Restart a session (stop then start) — the FAILED-state recovery path. */
    public function restart(string $session, string $webhookUrl, string $secret): array
    {
        try {
            $this->http(20)->post($this->base() . '/api/sessions/' . rawurlencode($session) . '/stop');
        } catch (\Throwable $e) {
            // best-effort stop; ensureStarted below will surface a real outage
        }

        return $this->ensureStarted($session, $webhookUrl, $secret);
    }

    /**
     * Unpair + remove the session (unlink). Best-effort: an unreachable WAHA
     * must not block the user from soft-deleting their device row.
     */
    public function remove(string $session): void
    {
        foreach (['/logout', ''] as $suffix) {
            try {
                if ($suffix === '') {
                    $this->http()->delete($this->base() . '/api/sessions/' . rawurlencode($session));
                } else {
                    $this->http()->post($this->base() . '/api/sessions/' . rawurlencode($session) . $suffix);
                }
            } catch (\Throwable $e) {
                // swallow — unlink proceeds regardless of WAHA availability
            }
        }
    }
}
