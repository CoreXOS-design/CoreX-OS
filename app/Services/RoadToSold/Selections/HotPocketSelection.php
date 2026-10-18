<?php

namespace App\Services\RoadToSold\Selections;

use App\Services\RoadToSold\HotPocketService;
use App\Services\RoadToSold\Support\PocketResolver;
use Illuminate\Database\Query\Builder;

/**
 * Selection 3 — hottest sales pockets. Canvass listings in the suburbs where buyer demand plus the
 * agency's own leads and sales concentrate, hottest suburb first (see HotPocketService).
 */
final class HotPocketSelection extends MicPoolSelection
{
    /** @var array<string,array>|null */
    private ?array $pockets = null;
    private ?string $pocketsFor = null;
    private ?PocketResolver $resolver = null;

    public function __construct(private readonly HotPocketService $service)
    {
    }

    public function key(): string
    {
        return 'hot_pockets';
    }

    public function label(): string
    {
        return 'Hottest sales pockets';
    }

    /** @return array<string,array> */
    private function pockets(SelectionContext $ctx): array
    {
        $st = $ctx->settings;
        $memo = implode('|', [
            $ctx->agencyId, $ctx->track, $ctx->date->toDateString(), $st->hotPocketLookbackDays,
            $st->hotPocketWeightBuyers, $st->hotPocketWeightLeads, $st->hotPocketWeightSales,
            implode(',', $st->buyerMatchTiers), implode(',', $st->buyerActiveStates),
        ]);
        if ($this->pocketsFor !== $memo) {
            $this->pockets = $this->service->rank($ctx->agencyId, $ctx->track, $ctx->date, $st->hotPocketLookbackDays, $st);
            $this->resolver = $this->service->resolver($ctx->agencyId);
            $this->pocketsFor = $memo;
        }

        return $this->pockets;
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        return $this->pockets($ctx) === [] ? [] : parent::candidates($ctx, $limit);
    }

    /**
     * A listing is in a pocket by suburb ID where its tracked property has one; only a listing with no suburb id
     * is matched by NAME, and only when that name belongs to one pocket (a name two towns share needs the town
     * to agree) - the same rule as areas (spec 5.7).
     */
    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $pockets = $this->pockets($ctx);
        $ids = [];
        $uniqueNames = [];
        $townNames = [];     // [lower name, city id|null, lower town|null] for names more than one pocket carries
        $perName = [];
        foreach ($pockets as $p) {
            $perName[mb_strtolower($p['name'])] = ($perName[mb_strtolower($p['name'])] ?? 0) + 1;
        }
        foreach ($pockets as $p) {
            $lower = mb_strtolower($p['name']);
            if ($p['suburb_id']) {
                $ids[] = (int) $p['suburb_id'];
            }
            if ($perName[$lower] === 1 && $this->resolver->nameIsUnique($lower)) {
                $uniqueNames[] = $lower;
            } else {
                $townNames[] = [$lower, $p['city_id'], $p['town'] !== null ? mb_strtolower($p['town']) : null];
            }
        }

        $terms = [];
        $bind = [];
        if ($ids !== []) {
            $terms[] = 'tp.p24_suburb_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($bind, ...$ids);
        }
        if ($uniqueNames !== []) {
            $terms[] = '(tp.p24_suburb_id IS NULL AND LOWER(TRIM(pl.suburb)) IN (' . implode(',', array_fill(0, count($uniqueNames), '?')) . '))';
            array_push($bind, ...$uniqueNames);
        }
        foreach ($townNames as [$name, $city, $town]) {
            $agree = [];
            $b = [$name];
            if ($city !== null) {
                $agree[] = 'tp.p24_city_id = ?';
                $b[] = (int) $city;
            }
            if ($town !== null) {
                $agree[] = 'LOWER(TRIM(tp.town)) = ?';
                $b[] = $town;
            }
            if ($agree === []) {
                continue;   // an ambiguous name with no town to tell it apart is never guessed
            }
            $terms[] = '(tp.p24_suburb_id IS NULL AND LOWER(TRIM(pl.suburb)) = ? AND (' . implode(' OR ', $agree) . '))';
            array_push($bind, ...$b);
        }
        $q->whereRaw($terms === [] ? '1 = 0' : '(' . implode(' OR ', $terms) . ')', $bind);
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $pockets = $this->pockets($ctx);
        $ids = array_values(array_unique(array_filter(array_map(fn ($p) => $p['suburb_id'] ? (int) $p['suburb_id'] : null, $pockets))));
        $names = array_values(array_unique(array_map(fn ($p) => mb_strtolower($p['name']), $pockets)));

        // hottest first: by suburb id where the row has one, else by name (FIELD() is 0 for a miss, 1 for the sentinel)
        $q->orderByRaw(
            'CASE WHEN tp.p24_suburb_id IS NOT NULL THEN FIELD(tp.p24_suburb_id, -1' . str_repeat(',?', count($ids)) . ')'
            . ' ELSE FIELD(LOWER(TRIM(pl.suburb)), \'\'' . str_repeat(',?', count($names)) . ') END',
            [...$ids, ...$names]
        );
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        $pockets = $this->pockets($ctx);
        $p = $this->resolver->resolve(
            $row->tp_suburb_id ? (int) $row->tp_suburb_id : null,
            $row->tp_city_id ? (int) $row->tp_city_id : null,
            $row->tp_town,
            $row->suburb
        );
        $pocket = $p ? ($pockets[$p['key']] ?? null) : null;

        return $pocket ? 'Hot pocket: ' . $this->service->describe($pocket) : 'Hot pocket';
    }
}
