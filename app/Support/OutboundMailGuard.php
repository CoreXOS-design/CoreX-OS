<?php

namespace App\Support;

use App\Models\DevSetting;
use App\Models\OutboundMailGuardCapture;
use App\Models\OutboundMailGuardToggleAudit;
use Illuminate\Support\Carbon;

/**
 * AT-URGENT-2026-09-08/09 — Johan: "if I hit a test send from any of the
 * test sites it will mail actual people... the per-mailbox outgoing SMTP
 * work means the application now connects directly to a real mail server...
 * Laravel's MAIL_MAILER setting in .env does NOT intercept that path."
 *
 * This is the single gate every outbound-mail interception point calls
 * before allowing a real send.
 *
 * 2026-09-09 revision (Johan, via conductor) — this used to be pure
 * hardcode with no override at all. It is now a genuine kill switch: a
 * super admin (isOwnerRole()) can force interception ON or OFF on ANY
 * environment, including production — "mail is escaping or a host is
 * blocking us, a super admin flips it, the bleeding stops, we fix, we flip
 * back." What stays hardcoded is everything that keeps this safe: the list
 * of environments that send BY DEFAULT (SENDING_ENVIRONMENTS, below), the
 * fail-safe direction (a missing/corrupt override always falls back to that
 * default, in EITHER direction — it can never silently start sending on a
 * test site, and can never silently go silent on production), and the fact
 * that only a super admin can ever move the switch (enforced server-side by
 * MailInterceptToggleController's owner_only middleware, never by this
 * class, which has no notion of the current user at all).
 */
class OutboundMailGuard
{
    /**
     * Every environment this application sends real mail from BY DEFAULT —
     * i.e. with the override (below) not set. Explicit, readable list: one
     * row per (APP_ENV, APP_URL host) pair, not an inference. Anything not
     * listed here defaults to intercepting.
     *
     *   - production / corexos.co.za, www.corexos.co.za — the live site.
     *   - staging / staging.corexos.co.za — Johan: "if we move email to
     *     staging... we can test emails on my account there... once we have
     *     it working and happy... we can make a plan to get it promoted to
     *     live." The only way to ever prove the poller and sending path
     *     work against a real mailbox.
     *
     * QA1 (qatesting1.corexos.co.za) is deliberately absent — it keeps
     * intercepting by default, exactly as before this revision.
     */
    private const SENDING_ENVIRONMENTS = [
        ['env' => 'production', 'host' => 'corexos.co.za'],
        ['env' => 'production', 'host' => 'www.corexos.co.za'],
        ['env' => 'staging', 'host' => 'staging.corexos.co.za'],
    ];

    /**
     * DevSetting key for the kill switch. Three states, not a plain
     * boolean: '1' (force intercept), '0' (force send), or absent/null (no
     * override — use the environment default above). A missing or corrupt
     * value collapses to "absent", which is what makes the fail-safe
     * direction symmetric: it can only ever produce the environment's OWN
     * natural behaviour, never the opposite of it.
     *
     * Deliberately a DevSetting, not an agency setting or a .env value —
     * DevSetting has no agency_id column at all, so it is structurally
     * incapable of being agency-configurable. Visibility is enforced by
     * MailInterceptToggleController's owner_only middleware + the settings
     * screen's isOwnerRole() check, not by anything in this class.
     */
    public const TOGGLE_KEY = 'mail_intercept_forced';

    /**
     * Header stamped on a redirected/forwarded copy so this guard never
     * intercepts its own copy and loops. Never set by anything else — a
     * genuine outbound message from application code has no reason to
     * carry it.
     */
    public const REDIRECTED_HEADER = 'X-CoreX-Mail-Guard-Redirected';

    /** True if THIS environment sends real mail by default (override not set). */
    public static function isSendingConfirmed(): bool
    {
        $env = (string) config('app.env', '');
        $host = self::configuredAppHost();

        if ($host === null) {
            return false;
        }

        foreach (self::SENDING_ENVIRONMENTS as $candidate) {
            if ($env === $candidate['env'] && $host === $candidate['host']) {
                return true;
            }
        }

        return false;
    }

    /** True = outbound mail is being intercepted RIGHT NOW, accounting for any override. */
    public static function isActive(): bool
    {
        $forced = self::forcedDirection();
        if ($forced !== null) {
            return $forced;
        }

        return ! self::isSendingConfirmed();
    }

    /**
     * True when the current effective state (isActive()) differs from what
     * this environment would do with no override — i.e. a super admin has
     * deliberately moved it away from its default. Drives the banner: the
     * loud "someone changed this" warning only ever fires here, never on
     * an environment quietly doing what it always does.
     */
    public static function isOverridden(): bool
    {
        return self::forcedDirection() !== null
            && self::isActive() !== ! self::isSendingConfirmed();
    }

    /**
     * true = forced to intercept, false = forced to send, null = no
     * override set — caller falls back to the environment default.
     */
    public static function forcedDirection(): ?bool
    {
        $raw = DevSetting::get(self::TOGGLE_KEY, null);
        if ($raw === null || $raw === '') {
            return null;
        }

        return in_array((string) $raw, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * When the CURRENT intercept-on streak started, if it was caused by an
     * override (isOverridden() && isActive()). Null otherwise. Only ever
     * meaningful in that one state — an override into intercepting always
     * has a corresponding audit row, by construction (nothing can set
     * TOGGLE_KEY without going through MailInterceptToggleService, which
     * always writes one).
     */
    public static function forcedSince(): ?Carbon
    {
        $row = OutboundMailGuardToggleAudit::where('direction', OutboundMailGuardToggleAudit::DIRECTION_INTERCEPT_ON)
            ->latest('id')
            ->first();

        return $row?->created_at;
    }

    /** How many messages have been held since the current forced-on streak began. */
    public static function heldCount(): int
    {
        $since = self::forcedSince();
        if ($since === null) {
            return 0;
        }

        return OutboundMailGuardCapture::where('captured_at', '>=', $since)->count();
    }

    /** The single most recent toggle change, whichever direction — for the banner's "who/when/why". */
    public static function lastToggleChange(): ?OutboundMailGuardToggleAudit
    {
        return OutboundMailGuardToggleAudit::with('user')->latest('id')->first();
    }

    /**
     * Does a local Mailpit-style catcher actually exist here? Checked
     * against the REAL configured mail hosts, not an environment-name
     * guess — same signal partials._env-banner already uses for its own
     * "Open Mailpit" link, for the same reason: self-corrects the moment
     * an environment's mail config genuinely changes, with no code change
     * needed. True on QA1 and (today) Staging; false on live, which is
     * exactly why OutboundMailGuardCapture exists — that table is the
     * source of truth everywhere, this is a convenience on top of it.
     */
    public static function hasLocalSink(): bool
    {
        return collect(['smtp', 'corex', 'otp'])->every(
            fn (string $mailer) => config("mail.mailers.{$mailer}.host") === self::sinkHost()
                && (int) config("mail.mailers.{$mailer}.port") === self::sinkPort()
        );
    }

    /**
     * The single permitted destination for a copy forwarded to a local
     * sink (QA1/Staging convenience only — see OutboundMailGuardCapture for
     * the actual source of truth, which exists on every environment
     * including live, where no sink exists at all). Never a real external
     * address, never agency-configurable.
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
