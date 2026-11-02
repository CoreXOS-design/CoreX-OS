<?php

namespace App\Services\Prospecting\MicWork;

/**
 * TEMPORARY look-and-feel stand-in for the match engine's `buyer_fit` (mic-work-tab.md 13.18) until cc1's `MicWorkList` supplies it.
 * Only used when a buyer is selected AND the URL carries `fit_stub=1` AND the app is not production; deleted when the real data lands.
 */
final class BuyerFitStub
{
    public static function for(string $firstName, int $i): array
    {
        $variants = [
            ['ok', 'ok', 'ok', 'ok', 'ok', 'na', 'na', 'ok'],
            ['ok', 'miss', 'ok', 'ok', 'unknown', 'unknown', 'na', 'miss'],
            ['miss', 'ok', 'ok', 'miss', 'ok', 'ok', 'unknown', 'unknown'],
        ][$i % 3];
        $def = [
            ['price', 'Price', '≤ R1.0m', 'R 950,000'], ['type', 'Type', 'House', 'Apartment'], ['suburb', 'Suburb', 'Margate', 'Margate'], ['beds', 'Bedrooms', '3+', '3'],
            ['baths', 'Bathrooms', '2+', '2'], ['parking', 'Parking', '2+', null], ['erf', 'Erf', null, null], ['floor', 'Floor', '150+', '120 m²'],
        ];
        $fields = [];
        foreach ($def as $k => [$key, $label, $wants, $has]) {
            $fields[] = ['key' => $key, 'label' => $label, 'wants' => $wants, 'has' => $has, 'state' => $variants[$k]];
        }

        return [
            'first_name' => $firstName, 'score' => 88 - ($i % 3) * 7, 'tier' => ['strong', 'mid', 'mid'][$i % 3], 'fields' => $fields,
            'must_haves' => $i % 4 === 3 ? [] : [['label' => 'Pool', 'state' => 'ok'], ['label' => 'Pet friendly', 'state' => 'unknown'], ['label' => 'Sea view', 'state' => $i % 3 === 0 ? 'ok' : 'miss']],
            'deal_breakers' => $i % 4 === 3 ? [] : ($i % 2 ? [['label' => 'Complex', 'state' => 'ok']] : []),
        ];
    }
}
