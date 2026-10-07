<?php

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\AddressMatchSetting;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Finds the rows that could be "the same property" as a set of AddressFacts and scores each one with
 * AddressMatchScorer (.ai/specs/structured-address-matching.md §6.5).
 *
 * Retrieval is by three INDEXED sets — erf/LPI, scheme/complex, street within the suburb (plus its
 * Property24 neighbours) — unioned; there is no `limit(50)` and no unordered `first()`, so a busy
 * suburb can no longer hide the right row, and the answer no longer depends on row order. The scorer,
 * not the query, decides: every candidate is scored and only exact / possible / street_only results come
 * back, best first (tier, then score, then oldest id — deterministic).
 *
 * Read-only and agency-scoped by an explicit agency id (safe from queue workers); soft-deleted rows
 * never match. Works the same on any model that has the address columns: Property, TrackedProperty.
 */
final class AddressMatcher
{
    private const TIER_ORDER = [
        AddressMatchScorer::TIER_EXACT       => 0,
        AddressMatchScorer::TIER_POSSIBLE    => 1,
        AddressMatchScorer::TIER_STREET_ONLY => 2,
    ];

    /** exact-rule priority, strongest first — also the order the old strategies ran in */
    public const RULE_ORDER = ['lpi', 'erf', 'scheme', 'street'];

    /** @var array<string, array<string, bool>> */
    private static array $columns = [];

    public function __construct(private readonly AddressMatchScorer $scorer = new AddressMatchScorer())
    {
    }

    /**
     * @param  array<int, int>  $excludeIds
     * @return array<int, array{model: Property, result: array<string,mixed>, facts: AddressFacts}>
     */
    public function properties(int $agencyId, AddressFacts $a, ?array $settings = null, array $excludeIds = []): array
    {
        return $this->run(Property::class, $agencyId, $a, $settings, $excludeIds);
    }

    /**
     * @param  array<int, int>  $excludeIds
     * @return array<int, array{model: TrackedProperty, result: array<string,mixed>, facts: AddressFacts}>
     */
    public function trackedProperties(int $agencyId, AddressFacts $a, ?array $settings = null, array $excludeIds = []): array
    {
        return $this->run(TrackedProperty::class, $agencyId, $a, $settings, $excludeIds);
    }

    /**
     * Exact hits grouped by rule and ordered strongest rule first: [rule => hits…]. Callers that
     * must pick ONE row (promote link, tracked ingest) take the first group that has exactly one hit,
     * like the sequential strategies this replaces.
     *
     * @param  array<int, array{model: Model, result: array<string,mixed>, facts: AddressFacts}>  $hits
     * @return array<string, array<int, array{model: Model, result: array<string,mixed>, facts: AddressFacts}>>
     */
    public static function exactByRule(array $hits): array
    {
        $groups = [];
        foreach (self::RULE_ORDER as $rule) {
            $groups[$rule] = [];
        }
        foreach ($hits as $h) {
            if ($h['result']['tier'] === AddressMatchScorer::TIER_EXACT) {
                $groups[(string) $h['result']['rule']][] = $h;
            }
        }

        return array_filter($groups, fn ($g) => $g !== []);
    }

    // ───────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  class-string<Model>  $class
     * @param  array<int, int>  $excludeIds
     * @return array<int, array{model: Model, result: array<string,mixed>, facts: AddressFacts}>
     */
    private function run(string $class, int $agencyId, AddressFacts $a, ?array $settings, array $excludeIds): array
    {
        $settings ??= AddressMatchSetting::forAgency($agencyId);
        if ($a->neighbourKeys === [] && $a->suburbText !== null && ($settings['neighbour_suburb_credit'] ?? 'possible') === 'possible') {
            $a->neighbourKeys = TrackedPropertyAddress::neighbouringSuburbKeys($a->suburbText, $a->lat, $a->lng);
        }

        $out = [];
        foreach ($this->candidates($class, $agencyId, $a, $settings, $excludeIds) as $model) {
            $facts = AddressFacts::fromModel($model);
            $result = $this->scorer->score($a, $facts, $settings);
            if (! isset(self::TIER_ORDER[$result['tier']])) {
                continue;
            }
            $out[] = ['model' => $model, 'result' => $result, 'facts' => $facts];
        }

        usort($out, function ($x, $y) {
            return [self::TIER_ORDER[$x['result']['tier']], -$x['result']['score'], (int) $x['model']->getKey()]
                <=> [self::TIER_ORDER[$y['result']['tier']], -$y['result']['score'], (int) $y['model']->getKey()];
        });

        return $out;
    }

    /**
     * The union of the three candidate sets, de-duplicated by id.
     *
     * @param  class-string<Model>  $class
     * @param  array<int, int>  $excludeIds
     * @return array<int, Model>
     */
    private function candidates(string $class, int $agencyId, AddressFacts $a, array $settings, array $excludeIds): array
    {
        /** @var Model $proto */
        $proto = new $class();
        $table = $proto->getTable();
        $base = fn (): Builder => $class::queryWithoutAgencyScope()
            ->where($table . '.agency_id', $agencyId)
            ->whereNull($table . '.deleted_at')
            ->when($excludeIds !== [], fn ($q) => $q->whereNotIn($table . '.id', $excludeIds));

        $found = [];
        $add = function ($rows) use (&$found) {
            foreach ($rows as $r) {
                $found[(int) $r->getKey()] ??= $r;
            }
        };

        // (1) LPI / erf — leading zeros ignored ("01329" = "1329").
        if ($a->lpi !== null || $a->erf !== null) {
            $q = $base()->where(function ($w) use ($a, $table) {
                if ($a->lpi !== null && $this->has($table, 'lpi_code')) {
                    $w->orWhereRaw('LOWER(' . $table . '.lpi_code) = ?', [$a->lpi]);
                }
                if ($a->erf !== null && $this->has($table, 'erf_number')) {
                    $w->orWhereRaw("TRIM(LEADING '0' FROM " . $table . '.erf_number) = ?', [ltrim($a->erf, '0') === '' ? '0' : ltrim($a->erf, '0')]);
                }
            });
            $add($q->get());
        }

        // (2) scheme / complex — by registered scheme number, or a complex name sharing its longest word.
        if (($a->schemeNumber !== null || $a->complex !== null) && $this->has($table, 'complex_name')) {
            $q = $base()->where(function ($w) use ($a, $table) {
                if ($a->schemeNumber !== null && $this->has($table, 'scheme_number')) {
                    $w->orWhereRaw('LOWER(TRIM(' . $table . '.scheme_number)) = ?', [$a->schemeNumber]);
                }
                if ($a->complex !== null) {
                    $word = $this->longestWord($a->complex);
                    if ($word !== null) {
                        $like = '%' . $this->escapeLike($word) . '%';
                        // apostrophes are dropped from the words we search with ("Shaka's" -> "shakas"), so drop them from the column too
                        $w->orWhereRaw("REPLACE(" . $table . ".complex_name, '''', '') LIKE ?", [$like]);
                        if ($this->has($table, 'scheme_name')) {
                            $w->orWhereRaw("REPLACE(" . $table . ".scheme_name, '''', '') LIKE ?", [$like]);
                        }
                    }
                }
            });
            $add($q->get());
        }

        // (3) street within the suburb (+ neighbours) — the street name column still holds the type and,
        // on old rows, the number, so it is matched on its core word(s), not equality.
        if ($a->streetCore !== null && $this->has($table, 'street_name')) {
            $suburbKeys = $a->suburbKeys;
            if (($settings['neighbour_suburb_credit'] ?? 'possible') === 'possible') {
                $suburbKeys = array_values(array_unique(array_merge($suburbKeys, $a->neighbourKeys)));
            }
            $q = $base()->where(function ($w) use ($a, $table) {
                $like = '%' . $this->escapeLike($a->streetCore) . '%';
                $w->whereRaw("REPLACE(" . $table . ".street_name, '''', '') LIKE ?", [$like]);
                if ($this->has($table, 'street_core')) {
                    $w->orWhere($table . '.street_core', $a->streetCore);
                }
            });
            if ($suburbKeys !== []) {
                $q->whereIn($table . '.suburb_normalised', $suburbKeys);
            } else {
                // no suburb to narrow by: the street word alone is too loose to scan — fall back to the same
                // P24 suburb id when there is one, else nothing from this set.
                if ($a->p24SuburbId !== null && $this->has($table, 'p24_suburb_id')) {
                    $q->where($table . '.p24_suburb_id', $a->p24SuburbId);
                } else {
                    $q = null;
                }
            }
            if ($q !== null) {
                $add($q->get());
            }
        }

        return array_values($found);
    }

    private function longestWord(string $s): ?string
    {
        $words = array_filter(explode(' ', $s), fn ($w) => mb_strlen($w) >= 3 && ! ctype_digit($w));
        usort($words, fn ($x, $y) => mb_strlen($y) <=> mb_strlen($x));

        return $words[0] ?? null;
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    private function has(string $table, string $col): bool
    {
        return self::$columns[$table][$col] ??= Schema::hasColumn($table, $col);
    }
}
