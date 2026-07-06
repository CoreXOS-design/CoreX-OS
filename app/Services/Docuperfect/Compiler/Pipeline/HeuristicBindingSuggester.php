<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Pipeline;

use App\Models\Docuperfect\DataDictionaryEntry;
use App\Services\Docuperfect\Compiler\Contracts\BindingSuggester;
use App\Support\Docuperfect\Cds\Pipeline\BindingSuggestion;
use Illuminate\Database\Eloquent\Builder;

/**
 * AT-177 / WS4-E — a heuristic {@see BindingSuggester} (spec §3.3). Ranks Data Dictionary
 * entries against a fill-point's label + surrounding context by token overlap and domain
 * synonyms. AI enhances, never replaces: this SUGGESTS; the Studio operator CONFIRMS. An
 * AI-backed suggester can swap in behind the same interface later.
 */
final class HeuristicBindingSuggester implements BindingSuggester
{
    /** Strong domain signals: a word in the field text → dictionary keys it hints at. */
    private const SYNONYMS = [
        'price' => ['purchase_price', 'monthly_rental'],
        'purchase' => ['purchase_price'],
        'rental' => ['monthly_rental'],
        'rent' => ['monthly_rental'],
        'deposit' => ['deposit'],
        'commission' => ['commission_incl_vat', 'commission_excl_vat'],
        'id' => ['seller_id_number', 'buyer_id_number'],
        'identity' => ['seller_id_number', 'buyer_id_number'],
        'erf' => ['erf_number'],
        'title' => ['title_deed_no'],
        'deed' => ['title_deed_no'],
        'scheme' => ['scheme_name'],
        'unit' => ['unit_no'],
        'gps' => ['gps'],
        'ppra' => ['agent_ppra_no'],
        'ffc' => ['agent_ffc'],
        'occupation' => ['occupation_date'],
        'transfer' => ['transfer_date'],
        'offer' => ['offer_date'],
        'expiry' => ['expiry_date'],
        'name' => ['seller_full_name', 'buyer_full_name'],
        'marital' => ['seller_marital_status', 'buyer_marital_status'],
        'address' => ['property_address'],
    ];

    public function suggest(string $fieldLabel, string $contextText = '', ?int $agencyId = null, int $dictionaryVersion = 1): array
    {
        $haystack = self::normalize($fieldLabel . ' ' . $contextText);
        if ($haystack === '') {
            return [];
        }

        $keys = DataDictionaryEntry::query()
            ->where('is_active', true)
            ->where('version', '<=', $dictionaryVersion)
            ->where(function (Builder $q) use ($agencyId): void {
                $q->whereNull('agency_id');
                if ($agencyId !== null) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->pluck('key')
            ->unique();

        $scored = [];
        foreach ($keys as $key) {
            $entry = DataDictionaryEntry::resolve((string) $key, $agencyId, $dictionaryVersion);
            if ($entry === null) {
                continue;
            }
            $confidence = self::score($fieldLabel, $contextText, (string) $key, (string) $entry->label);
            if ($confidence > 0.0) {
                $scored[] = new BindingSuggestion((string) $key, $confidence, self::reasonFor($confidence));
            }
        }

        usort($scored, static fn (BindingSuggestion $a, BindingSuggestion $b): int => $b->confidence <=> $a->confidence);

        return array_slice($scored, 0, 5);
    }

    /**
     * Pure scoring (0..1) of one dictionary key against a fill-point's label + context.
     * Exposed static for isolated testing.
     */
    public static function score(string $fieldLabel, string $contextText, string $key, string $entryLabel): float
    {
        $haystack = self::normalize($fieldLabel . ' ' . $contextText);
        if ($haystack === '') {
            return 0.0;
        }
        $words = array_filter(explode(' ', $haystack), static fn (string $w): bool => strlen($w) >= 3);

        $keyTokens = array_filter(explode('_', strtolower($key)), static fn (string $t): bool => strlen($t) >= 3);
        $labelTokens = array_filter(explode(' ', self::normalize($entryLabel)), static fn (string $t): bool => strlen($t) >= 3);
        $target = array_unique(array_merge($keyTokens, $labelTokens));

        $hits = 0;
        foreach ($target as $token) {
            if (in_array($token, $words, true)) {
                $hits++;
            }
        }
        $overlap = $target === [] ? 0.0 : $hits / count($target);

        // Domain synonym boost.
        $synonymBoost = 0.0;
        foreach (self::SYNONYMS as $word => $keys) {
            if (in_array($word, $words, true) && in_array($key, $keys, true)) {
                $synonymBoost = 0.5;
                break;
            }
        }

        // Exact label containment.
        $exact = str_contains($haystack, self::normalize($entryLabel)) && self::normalize($entryLabel) !== '' ? 0.3 : 0.0;

        return min(1.0, round($overlap * 0.6 + $synonymBoost + $exact, 3));
    }

    private static function reasonFor(float $confidence): string
    {
        return $confidence >= 0.7 ? 'strong label/synonym match' : ($confidence >= 0.4 ? 'partial match' : 'weak token overlap');
    }

    private static function normalize(string $text): string
    {
        $text = strtolower((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
