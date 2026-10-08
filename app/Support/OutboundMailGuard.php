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
     *   - production / corexos.co.za, www.corexos.co.za — the live site. NOTHING ELSE.
     *
     * 2026-10-08 (Johan, via conductor) — Staging was on this list so the
     * mailbox poller and sending path could be proved against a real mailbox.
     * That made it the one non-production environment where a database
     * restored from live (carrying `mail_intercept_forced` = 0, or no row)
     * would have mailed real tenants and landlords — and 18 of Staging's 20
     * mailboxes hold real SMTP credentials. Staging is now removed: EVERY
     * environment except real production intercepts, and that is decided by
     * environment configuration here, never by a database row (see isActive()).
     */
    private const SENDING_ENVIRONMENTS = [
        ['env' => 'production', 'host' => 'corexos.co.za'],
        ['env' => 'production', 'host' => 'www.corexos.co.za'],
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
        // Environment-forced: anything that is not real production intercepts, full stop. The
        // database override below is NEVER read for such an environment — a dev_settings row
        // restored from live (`mail_intercept_forced` = 0, or absent) cannot switch the guard off.
        if (! self::isSendingConfirmed() || self::isTripped()) {
            return true;
        }

        // Real production only: the super-admin kill switch (force intercept during an incident).
        $forced = self::forcedDirection();
        if ($forced !== null) {
            return $forced;
        }

        return false;
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
        if (self::isTripped()) {
            return false;
        }

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
        return (string) config('mail.guard.sink_address', 'outbound-guard@localhost.test');
    }

    public static function sinkHost(): string
    {
        return (string) config('mail.guard.sink_host', '127.0.0.1');
    }

    public static function sinkPort(): int
    {
        return (int) config('mail.guard.sink_port', 1025);
    }

    /**
     * True = a non-production environment is pointed at a real mail host, so this process refuses to
     * send ANYTHING, including the guard's own sink copy. Derived from config on every call (cheap, no
     * database), so it can never go stale or stick after a config change.
     */
    public static function isTripped(): bool
    {
        return ! self::isSendingConfirmed() && self::bootProblems() !== [];
    }

    /**
     * Is $host a place mail can only be caught, never delivered? Loopback, private (RFC 1918)
     * ranges, localhost / mailpit / mailhog names, and reserved .test / .localhost names. A blank
     * host cannot connect anywhere. Anything else is treated as a REAL mail host.
     */
    public static function isLocalMailHost(?string $host): bool
    {
        $host = strtolower(trim((string) $host));
        if ($host === '' || $host === 'null') {
            return true;
        }
        if (in_array($host, ['localhost', '::1', '[::1]', 'mailpit', 'mailhog'], true)
            || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return false;
    }

    /**
     * Boot-time check, config only (no database — it must work before one is reachable). On any
     * environment that is not real production, every mail host the application can connect to
     * must be a local catcher. If one is a real host, this process is TRIPPED: it logs loudly and
     * refuses to send anything (guard vetoes everything, no sink copy, per-mailbox sends refuse).
     *
     * @return list<string> the problems found (empty = fine, or real production where it does not apply)
     */
    public static function auditBootConfiguration(): array
    {
        $problems = self::isSendingConfirmed() ? [] : self::bootProblems();

        if ($problems !== []) {
            \Illuminate\Support\Facades\Log::critical(
                'OUTBOUND MAIL GUARD BOOT CHECK FAILED — this is not production but is pointed at a real mail host. '
                . 'Sending is REFUSED in this process until the mail configuration is fixed.',
                [
                    'app_env' => config('app.env'),
                    'app_url' => config('app.url'),
                    'problems' => $problems,
                ],
            );
        }

        return $problems;
    }

    /** @return list<string> */
    private static function bootProblems(): array
    {
        $problems = [];

        $default = (string) config('mail.default', '');
        $defaultTransport = (string) config("mail.mailers.{$default}.transport", '');
        if (! in_array($defaultTransport, ['', 'log', 'array', 'smtp', 'failover', 'roundrobin'], true)) {
            $problems[] = "default mailer '{$default}' uses the real transport '{$defaultTransport}'";
        }

        foreach (array_unique(array_filter([$default, 'smtp', 'corex', 'otp'])) as $mailer) {
            $host = config("mail.mailers.{$mailer}.host");
            if ($host !== null && ! self::isLocalMailHost((string) $host)) {
                $problems[] = "mailer '{$mailer}' points at the real mail host '{$host}'";
            }
        }

        if (! self::isLocalMailHost(self::sinkHost())) {
            $problems[] = "the guard sink host '" . self::sinkHost() . "' is a real mail host";
        }

        return $problems;
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
