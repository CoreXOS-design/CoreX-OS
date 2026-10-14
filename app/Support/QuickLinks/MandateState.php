<?php

declare(strict_types=1);

namespace App\Support\QuickLinks;

use Carbon\CarbonInterface;

/**
 * Quick Links W2 (.ai/specs/quick-links.md §5.2) — the mandate chip on a property: type + days left, amber inside the agency's
 * own warning window (MandateExpiryPolicy::warnDaysFor — an agency setting), red once expired. PURE: the property row already
 * carries mandate_type + expiry_date, so list pages show it for free.
 *
 * Returns null when there is nothing to show (no mandate type and no expiry). Otherwise key (ok|expiring|expired|open_ended),
 * tone, label, detail.
 */
final class MandateState
{
    public static function resolve(?string $type, ?CarbonInterface $expiry, int $warnDays, ?CarbonInterface $now = null): ?array
    {
        $now ??= now();
        $type = $type !== null && trim($type) !== '' ? ucfirst(trim($type)) : null;   // keep the agency's own wording ("Sole Mandate")

        if ($type === null && $expiry === null) {
            return null;
        }
        $name = $type === null ? 'Mandate' : (stripos($type, 'mandate') !== false ? $type : $type . ' mandate');

        if ($expiry === null) {
            return ['key' => 'open_ended', 'tone' => 'default', 'label' => $type ?? 'Mandate', 'detail' => $name . ' with no expiry date recorded.'];
        }

        $days = (int) $now->copy()->startOfDay()->diffInDays($expiry->copy()->startOfDay(), false);

        if ($days < 0) {
            return ['key' => 'expired', 'tone' => 'danger', 'label' => ($type ? $type . ' · ' : '') . 'expired', 'detail' => $name . ' expired on ' . $expiry->format('d M Y') . '.'];
        }
        $left = $days === 0 ? 'expires today' : ($days === 1 ? '1 day left' : $days . ' days left');
        $label = ($type ? $type . ' · ' : '') . $left;

        return $days <= $warnDays
            ? ['key' => 'expiring', 'tone' => 'warning', 'label' => $label, 'detail' => $name . ' ends ' . $expiry->format('d M Y') . ' — renew it soon.']
            : ['key' => 'ok', 'tone' => 'success', 'label' => $label, 'detail' => $name . ' runs to ' . $expiry->format('d M Y') . '.'];
    }
}
