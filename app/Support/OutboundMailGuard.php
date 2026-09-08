<?php

namespace App\Support;

/**
 * AT-URGENT-2026-09-08 — Johan: "if I hit a test send from any of the test
 * sites it will mail actual people... the per-mailbox outgoing SMTP work
 * means the application now connects directly to a real mail server...
 * Laravel's MAIL_MAILER setting in .env does NOT intercept that path."
 *
 * This is the single, hardcoded gate every outbound-mail interception point
 * calls before allowing a real send. It is deliberately NOT a setting: no
 * database row, no agency config, no per-user toggle — only two signals a
 * person using the app can never reach: APP_ENV and the configured APP_URL
 * host, checked against a hardcoded allow-list baked into this file.
 *
 * BOTH signals must agree before a real send is allowed. One signal alone
 * is not enough: live-testing.corexos.co.za was documented (2026-08-27,
 * .ai/releases/2026-08-27-staging-live-testing-and-esign-plan.md) as "the
 * old live box, repurposed" — carrying the same /corex codebase path
 * convention as real production. A box like that could plausibly end up
 * with APP_ENV=production set (inherited, copied, or mistaken) while its
 * real hostname is not corexos.co.za. Checking APP_ENV alone would treat
 * it as production and leave it unguarded — exactly the failure mode this
 * exists to prevent. Checking the hostname alone would be defeated by a
 * stale/wrong APP_URL on the real production box. Requiring both closes
 * that gap without depending on any single value being right forever.
 *
 * Fail-safe direction: if either signal is missing, blank, malformed, or
 * does not exactly match, this returns false (NOT production) — mail is
 * guarded. The only way to open the gate is an exact, positive match on
 * both. A missing or broken config value can only ever make this MORE
 * restrictive, never less.
 */
class OutboundMailGuard
{
    /**
     * The only hostnames this application will ever treat as real
     * production. Deliberately hardcoded here, not read from .env — this
     * must survive a wrong or copied environment file. Source: CLAUDE.md,
     * "Production server = 62.238.31.82 ... corexos.co.za / www.corexos.co.za".
     */
    private const PRODUCTION_HOSTS = [
        'corexos.co.za',
        'www.corexos.co.za',
    ];

    /**
     * Header stamped on a redirected copy so this guard never intercepts
     * its own redirect and loops. Never set by anything else — a genuine
     * outbound message from application code has no reason to carry it.
     */
    public const REDIRECTED_HEADER = 'X-CoreX-Mail-Guard-Redirected';

    public static function isProductionConfirmed(): bool
    {
        $env = (string) config('app.env', '');
        if ($env !== 'production') {
            return false;
        }

        $host = self::configuredAppHost();
        if ($host === null) {
            return false;
        }

        return in_array($host, self::PRODUCTION_HOSTS, true);
    }

    public static function isActive(): bool
    {
        return ! self::isProductionConfirmed();
    }

    /**
     * The single permitted destination for every redirected message.
     * Points at the environment's local Mailpit-style catcher by default —
     * never a real external address, never agency-configurable.
     */
    public static function sinkAddress(): string
    {
        return (string) env('MAIL_GUARD_SINK_ADDRESS', 'outbound-guard@localhost.test');
    }

    public static function sinkHost(): string
    {
        return (string) env('MAIL_GUARD_SINK_HOST', env('MAIL_HOST', '127.0.0.1'));
    }

    public static function sinkPort(): int
    {
        return (int) env('MAIL_GUARD_SINK_PORT', env('MAIL_PORT', 1025));
    }

    private static function configuredAppHost(): ?string
    {
        $url = (string) config('app.url', '');
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
