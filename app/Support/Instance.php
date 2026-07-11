<?php

namespace App\Support;

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

    /** Base URL of the primary instance, as seen from a demo host. */
    public static function controlUrl(): ?string
    {
        $url = trim((string) config('corex.instance.control_url', ''));

        return $url === '' ? null : rtrim($url, '/');
    }

    /** The AgencyApiKey bearer token a demo host presents to primary. */
    public static function controlToken(): ?string
    {
        $token = trim((string) config('corex.instance.control_token', ''));

        return $token === '' ? null : $token;
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
