<?php

namespace App\Services\RoadToSold\Selections;

use App\Services\RoadToSold\HotPocketService;
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
        $memo = $ctx->agencyId . '|' . $ctx->track . '|' . $ctx->date->toDateString() . '|' . $ctx->settings->hotPocketLookbackDays;
        if ($this->pocketsFor !== $memo) {
            $this->pockets = $this->service->rank($ctx->agencyId, $ctx->track, $ctx->date, $ctx->settings->hotPocketLookbackDays);
            $this->pocketsFor = $memo;
        }

        return $this->pockets;
    }

    public function candidates(SelectionContext $ctx, int $limit): array
    {
        return $this->pockets($ctx) === [] ? [] : parent::candidates($ctx, $limit);
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $keys = array_keys($this->pockets($ctx));
        $q->whereRaw('LOWER(TRIM(pl.suburb)) IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $keys = array_keys($this->pockets($ctx));
        $q->orderByRaw('FIELD(LOWER(TRIM(pl.suburb)), ' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        $pocket = $this->pockets($ctx)[trim(mb_strtolower((string) $row->suburb))] ?? null;

        return $pocket ? 'Hot pocket: ' . $this->service->describe($pocket) : 'Hot pocket';
    }
}
