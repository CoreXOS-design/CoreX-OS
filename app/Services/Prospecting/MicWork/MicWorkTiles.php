<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\User;
use App\Services\Prospecting\MicWork\Contracts\PanelProvider;
use App\Services\Prospecting\MicWork\Contracts\TileProvider;
use Illuminate\Database\Query\Builder;

/**
 * The eleven Work tab tiles (mic-work-tab.md section 1) and whatever services currently feed them. A tile without a tagged provider is "coming":
 * no number, no invented value. Providers are tagged `mic.work.tiles` / `mic.work.panels` by the lane that owns the data.
 */
final class MicWorkTiles
{
    /** number => [key, title, what a click does: filter | strip | panel] */
    public const TILES = [
        1 => ['stuck', 'Stuck on the market', 'filter'],
        2 => ['owner', 'Owner selling privately', 'filter'],
        3 => ['gone', 'Under offer, sold or gone', 'filter'],
        4 => ['stock', 'Where our stock sits', 'strip'],
        5 => ['fastest', 'What sells fastest for us', 'strip'],
        6 => ['leads', 'Where our leads come from', 'strip'],
        7 => ['buyers', 'Buyers waiting, stock short', 'filter'],
        8 => ['alerts', 'New from P24 alerts', 'filter'],
        9 => ['prices', 'Market prices here', 'strip'],
        10 => ['neighbours', 'Neighbours of a sale', 'panel'],
        11 => ['refresh', 'Market data last refreshed', 'panel'],
    ];

    /** @var array<int, TileProvider> */
    private array $tiles = [];
    /** @var array<int, PanelProvider> */
    private array $panels = [];

    /**
     * @param  iterable<TileProvider>  $tileProviders
     * @param  iterable<PanelProvider>  $panelProviders
     */
    public function __construct(iterable $tileProviders = [], iterable $panelProviders = [])
    {
        foreach ($tileProviders as $p) {
            $this->tiles[$p->tile()] = $p;
        }
        foreach ($panelProviders as $p) {
            $this->panels[$p->tile()] = $p;
        }
    }

    public static function fromContainer(): self
    {
        return new self(app()->tagged('mic.work.tiles'), app()->tagged('mic.work.panels'));
    }

    public static function numberForKey(?string $key): ?int
    {
        foreach (self::TILES as $n => [$k]) {
            if ($k === $key) {
                return $n;
            }
        }

        return null;
    }

    /**
     * The tile row, in order, only the numbers $shown (agency Settings; default all eleven).
     *
     * @param  list<int>|null  $shown
     * @return list<array{number:int, key:string, title:string, kind:string, ready:bool, count:?int, reason:string, note:?string}>
     */
    public function row(int $agencyId, User $user, ?array $shown = null): array
    {
        $out = [];
        foreach (self::TILES as $n => [$key, $title, $kind]) {
            if ($shown !== null && ! in_array($n, $shown, true)) {
                continue;
            }
            $provider = $this->tiles[$n] ?? null;
            $panelReady = $kind === 'panel' && isset($this->panels[$n]);
            if ($provider) {
                $s = $provider->summary($agencyId, $user);
                $out[] = ['number' => $n, 'key' => $key, 'title' => $title, 'kind' => $kind, 'ready' => true,
                    'count' => $s['count'], 'reason' => $s['reason'], 'note' => $s['note'] ?? null];
            } else {
                $out[] = ['number' => $n, 'key' => $key, 'title' => $title, 'kind' => $kind, 'ready' => $panelReady,
                    'count' => null, 'reason' => $panelReady ? 'Open' : 'Coming', 'note' => null];
            }
        }

        return $out;
    }

    /** Subquery of tracked property ids for a filter tile, or null (unknown tile, coming, or not a filter). */
    public function propertyIds(?string $key, int $agencyId, User $user): ?Builder
    {
        $n = self::numberForKey($key);

        return $n !== null && isset($this->tiles[$n]) ? $this->tiles[$n]->propertyIds($agencyId, $user) : null;
    }

    public function panel(int $number): ?PanelProvider
    {
        return $this->panels[$number] ?? null;
    }
}
