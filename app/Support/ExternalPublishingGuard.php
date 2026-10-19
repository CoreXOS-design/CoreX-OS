<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * One gate in front of every WRITE CoreX makes to an outside publishing channel:
 * Facebook / Instagram (Meta), Property24 and Private Property.
 *
 * WHY. OutboundMailGuard only watches mail. A copy of the live system (e.g.
 * live-testing.corexos.co.za) runs APP_ENV=production with a copy of the real
 * database, and that database carries the agencies' REAL Meta page tokens and
 * portal credentials — so a scheduled post or a status change made on the copy
 * would land on a real public page or a real portal listing (release audit
 * 2026-10-18, C-F1 / C-F4).
 *
 * DEFAULT IS ALLOWED. The real live server must keep publishing with no change to
 * its .env, so this guard never infers "non-production" from APP_ENV (the copies
 * report production too). Publishing is refused only when it is switched off
 * explicitly:
 *   - this install is the demo (Instance::isDemo()), or
 *   - COREX_EXTERNAL_PUBLISHING=false (config corex.external_publishing).
 *
 * Reads (insights, status checks, lookups, lead/stat feeds) are never gated: they
 * change nothing outside CoreX.
 */
final class ExternalPublishingGuard
{
    public const CHANNEL_META             = 'meta';
    public const CHANNEL_PROPERTY24       = 'property24';
    public const CHANNEL_PRIVATE_PROPERTY = 'private_property';

    /** True when this install may write to Meta / Property24 / Private Property. */
    public static function isAllowed(): bool
    {
        return self::refusalReason() === null;
    }

    /** Why publishing is refused on this install, or null when it is allowed. */
    public static function refusalReason(): ?string
    {
        if (Instance::isDemo()) {
            return 'demo instance';
        }

        $setting = filter_var(config('corex.external_publishing', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        // An unparseable value falls back to the default (allowed): the switch is an explicit "off", and a typo
        // must never silently stop the real live server publishing.
        if ($setting === false) {
            return 'COREX_EXTERNAL_PUBLISHING is off';
        }

        return null;
    }

    /**
     * Plain-language wording for the agent / admin when a post or push was refused.
     */
    public static function userMessage(): string
    {
        return 'Publishing to Facebook, Instagram and the property portals is switched off on this copy of CoreX, '
            . 'so nothing was sent. Only the live system publishes.';
    }

    /**
     * Log one WARNING line for a refused portal write. Never throws.
     *
     * @param string          $channel  one of the CHANNEL_* constants
     * @param string          $action   what was attempted, e.g. 'status_update', 'ListingStatusUpdate'
     * @param int|string|null $property the CoreX property id (or the portal's id for it) when known
     */
    public static function logRefusedWrite(string $channel, string $action, int|string|null $property = null): void
    {
        try {
            Log::warning(sprintf(
                'External publishing refused (%s): %s on property %s did nothing - %s.',
                $channel,
                $action,
                $property === null || $property === '' ? '(none)' : (string) $property,
                self::refusalReason() ?? 'allowed',
            ));
        } catch (\Throwable) {
            // Logging must never break the caller.
        }
    }
}
