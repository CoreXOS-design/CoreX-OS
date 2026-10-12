<?php

declare(strict_types=1);

namespace App\Support\QuickLinks;

use Carbon\CarbonInterface;

/**
 * Quick Links W2 (.ai/specs/quick-links.md §5.1) — the buyer pre-approval chip. PURE (no queries): the contact row already
 * carries amount + expiry, so a list page can show it on every row at no extra cost. Mirrors Contact::hasValidPreapproval().
 *
 * Returns: key (valid|expired|none), tone, label, detail.
 */
final class PreapprovalState
{
    public static function resolve(mixed $amount, ?CarbonInterface $expiresAt, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $hasAmount = $amount !== null && (float) $amount > 0;

        if ($hasAmount && $expiresAt && ($expiresAt->isSameDay($now) || $expiresAt->greaterThan($now))) {
            return [
                'key' => 'valid', 'tone' => 'success',
                'label' => 'Pre-approved ' . self::money((float) $amount),
                'detail' => 'Pre-approval of ' . self::money((float) $amount) . ' valid until ' . $expiresAt->format('d M Y') . '.',
            ];
        }

        if ($hasAmount) {
            return [
                'key' => 'expired', 'tone' => 'warning',
                'label' => 'Pre-approval expired',
                'detail' => 'A pre-approval of ' . self::money((float) $amount) . ' is on file but '
                    . ($expiresAt ? 'expired on ' . $expiresAt->format('d M Y') : 'has no expiry date') . ' — update it.',
            ];
        }

        return ['key' => 'none', 'tone' => 'default', 'label' => 'No pre-approval', 'detail' => 'No bond pre-approval recorded for this buyer.'];
    }

    /** R 1.2m / R 850k / R 950 — short enough for a chip. */
    private static function money(float $v): string
    {
        return match (true) {
            $v >= 1_000_000 => 'R ' . rtrim(rtrim(number_format($v / 1_000_000, 2, '.', ''), '0'), '.') . 'm',
            $v >= 1_000     => 'R ' . number_format($v / 1_000, 0, '.', '') . 'k',
            default         => 'R ' . number_format($v, 0, '.', ' '),
        };
    }
}
