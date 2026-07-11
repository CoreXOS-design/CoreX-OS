<?php

namespace App\Support;

use App\Models\DevSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Which half of the codebase is live on this host.
 *
 * Spec: .ai/specs/demo-access-control.md §3
 *
 * CoreX runs one codebase in two roles:
 *
 *   primary — the real install (live, staging, local). Owns the durable
 *             demo-access records and issues grants. The gate, the watermark
 *             and the reset countdown are all INERT here.
 *   demo    — demo1.corexos.co.za. Gated by EnsureDemoGrant, watermarked per
 *             company, and destroyed every 3 days.
 *
 * WHY THIS EXISTS. There was no usable "am I the demo?" predicate before:
 *
 *   - config('app.env_label') (config/app.php:45) is COSMETIC. It drives the
 *     colour of the environment banner and nothing else. A display string is
 *     not a security boundary.
 *   - DemoLoginController::isEnabled() requires !app()->environment('production'),
 *     but the demo host runs APP_ENV=production — so that flag is FALSE on the
 *     very box it is supposed to describe.
 *
 * Anything security-bearing gates on THIS, not on either of those.
 */
final class Instance
{
    public const PRIMARY = 'primary';
    public const DEMO    = 'demo';

    /**
     * The configured role. Anything that is not exactly 'demo' is treated as
     * 'primary' — the safe default. A typo in COREX_INSTANCE_ROLE must never
     * turn a real install into a demo (which would expose demo:reset and
     * suppress nothing of value), so demo is opt-in by exact match.
     */
    public static function role(): string
    {
        $role = strtolower(trim((string) config('corex.instance.role', self::PRIMARY)));

        return $role === self::DEMO ? self::DEMO : self::PRIMARY;
    }

    public static function isDemo(): bool
    {
        return self::role() === self::DEMO;
    }

    public static function isPrimary(): bool
    {
        return self::role() === self::PRIMARY;
    }

    /**
     * Base URL of the primary instance, as seen from a demo host.
     *
     * DB FIRST, .env as fallback. The connection is configured through the demo's
     * own "Demo Connection" page (Dev Settings, owner-only) — a System Owner can
     * repoint or re-token the demo from a browser, without a deploy and without SSH.
     * The env keys remain honoured so an instance can still be bootstrapped from a
     * .env alone, and so an existing deployment keeps working after this change.
     */
    public static function controlUrl(): ?string
    {
        $url = trim((string) DevSetting::get('demo_control_url', ''));

        if ($url === '') {
            $url = trim((string) config('corex.instance.control_url', ''));
        }

        return $url === '' ? null : rtrim($url, '/');
    }

    /**
     * The DemoConnector bearer token this demo presents to primary.
     *
     * Stored ENCRYPTED in dev_settings (Crypt::encryptString), because unlike a hash
     * it must be replayable — the demo has to send the real thing on every call — so
     * it cannot be one-way. Encrypted-at-rest means a DB dump of the demo box (which
     * is a disposable, frequently-rebuilt machine) does not hand someone a working
     * credential into primary's control API.
     */
    public static function controlToken(): ?string
    {
        $stored = (string) DevSetting::get('demo_control_token_encrypted', '');

        if ($stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                // A key rotation (or a token copied between installs) makes the
                // ciphertext undecryptable. Fall through to .env rather than throw —
                // but this WILL fail the gate closed, and the Demo Connection page
                // reports it as "not configured" so it is fixable in the browser.
                Log::warning('[demo-access] Stored demo control token could not be decrypted — has APP_KEY changed? Re-paste the token on the Demo Connection page.');
            }
        }

        $token = trim((string) config('corex.instance.control_token', ''));

        return $token === '' ? null : $token;
    }

    /** Persist the connection from the demo's Demo Connection page. */
    public static function setControlUrl(?string $url): void
    {
        DevSetting::set('demo_control_url', $url ? rtrim(trim($url), '/') : '');
    }

    public static function setControlToken(?string $token): void
    {
        $token = $token ? trim($token) : '';

        DevSetting::set(
            'demo_control_token_encrypted',
            $token === '' ? '' : Crypt::encryptString($token)
        );
    }

    /**
     * The public half of the stored token (e.g. "cx_demo_a1b2c3d4"), for display.
     * Never render the secret half — there is no reason to, and every reason not to.
     */
    public static function controlTokenPrefix(): ?string
    {
        $token = self::controlToken();

        if (! $token || ! str_contains($token, '.')) {
            return null;
        }

        return explode('.', $token, 2)[0];
    }

    /**
     * True when this demo host is actually able to reach primary. False means
     * the gate will fail CLOSED (spec §6.3) — which is correct, but is also
     * the single most likely cause of "nobody can get into the demo", so it is
     * surfaced explicitly rather than being inferred from a connection error.
     */
    public static function isDemoWired(): bool
    {
        return self::isDemo()
            && self::controlUrl() !== null
            && self::controlToken() !== null;
    }
}
