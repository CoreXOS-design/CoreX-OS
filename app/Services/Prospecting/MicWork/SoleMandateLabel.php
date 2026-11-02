<?php

namespace App\Services\Prospecting\MicWork;

use Carbon\Carbon;

/**
 * The wording of the sole-mandate flag on the Adverts ribbon, the List badge and the Full card (mic-work-tab.md 13.4).
 * It only formats what the file record already says (`sole_mandate_days`, `sole_mandate_first_seen_at`, `sole_mandate_ending`);
 * nothing is worked out here, so every screen reads the same.
 */
final class SoleMandateLabel
{
    /** @return array{text: string, title: string, ending: bool}|null  null when the record is not a sole mandate */
    public static function for(array $adv): ?array
    {
        if (empty($adv['is_sole_mandate'])) {
            return null;
        }
        $days = $adv['sole_mandate_days'] ?? null;
        $since = $adv['sole_mandate_first_seen_at'] ?? null;
        $ending = $days !== null && ! empty($adv['sole_mandate_ending']);
        $source = ($adv['sole_mandate_source'] ?? '') === 'alert' ? 'from a P24 alert' : 'from the advert wording';

        return [
            'text' => 'Sole mandate' . ($days !== null ? ' · ' . (int) $days . ' d' : '') . ($ending ? ' · may be ending' : ''),
            'title' => $since ? 'first seen as sole mandate ' . Carbon::parse($since)->format('j M Y') : 'Sole mandate (' . $source . ')',
            'ending' => $ending,
        ];
    }
}
