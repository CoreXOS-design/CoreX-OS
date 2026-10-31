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
    /** number => [key, full title (tooltip), what a click does: filter | strip | panel, short label shown on the tile] */
    public const TILES = [
        1 => ['stuck', 'Stuck on the market', 'filter', 'Stuck'],
        2 => ['owner', 'Owner selling privately', 'filter', 'Owner selling'],
        3 => ['gone', 'Under offer, sold or gone', 'filter', 'Under offer / sold'],
        4 => ['our_stock', 'Where our stock sits', 'strip', 'Our stock'],
        5 => ['fast_sellers', 'What sells fastest for us', 'strip', 'Fast sellers'],
        6 => ['lead_sources', 'Where our leads come from', 'strip', 'Lead pockets'],
        7 => ['buyers_waiting', 'Buyers waiting, stock short', 'filter', 'Buyers waiting'],
        8 => ['p24_alerts', 'New from P24 alerts', 'filter', 'P24 alerts'],
        9 => ['market_prices', 'Market prices here', 'strip', 'Market prices'],
        10 => ['neighbours', 'Neighbours of a sale', 'panel', 'Neighbours'],
        11 => ['refresh', 'Market data last refreshed', 'panel', 'Data refreshed'],
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

    /** cc5's numbers (tiles 1, 4-9; spec 11.1). Absent until their service lands - then those tiles say Coming. */
    private const CC5_SERVICE = 'App\\Services\\MarketIntelligence\\Pockets\\TileService';
    private const CC5_SETTINGS = 'App\\Models\\MicAgencySetting';

    /** @return array<int, object>  TileResult by tile number */
    private function cc5Results(User $user): array
    {
        if (! class_exists(self::CC5_SERVICE)) {
            return [];
        }
        try {
            return app(self::CC5_SERVICE)->forViewer($user);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('MIC Work tiles: cc5 TileService failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Tiles the agency shows (Settings; default all eleven). */
    public function shownFor(int $agencyId): array
    {
        return class_exists(self::CC5_SETTINGS) ? array_map('intval', app(self::CC5_SETTINGS)::for($agencyId)->tilesShown()) : range(1, 11);
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
     * @return list<array{number:int, key:string, title:string, short:string, kind:string, ready:bool, count:?int, reason:string, note:?string}>
     */
    public function row(int $agencyId, User $user, ?array $shown = null): array
    {
        $shown ??= $this->shownFor($agencyId);
        $cc5 = $this->cc5Results($user);
        $out = [];
        foreach (self::TILES as $n => [$key, $title, $kind, $short]) {
            if ($shown !== null && ! in_array($n, $shown, true)) {
                continue;
            }
            $provider = $this->tiles[$n] ?? null;
            if (! $provider && isset($cc5[$n])) {
                $r = $cc5[$n];
                $out[] = ['number' => $n, 'key' => $key, 'title' => $title, 'short' => $short, 'kind' => $kind, 'ready' => true,
                    'count' => $r->tooLittleData ? null : $r->count, 'reason' => $r->reason, 'note' => $r->tooLittleData ? $r->tooLittleMessage : null];
                continue;
            }
            $panelReady = $kind === 'panel' && isset($this->panels[$n]);
            if ($provider) {
                $s = $provider->summary($agencyId, $user);
                $out[] = ['number' => $n, 'key' => $key, 'title' => $title, 'short' => $short, 'kind' => $kind, 'ready' => true,
                    'count' => $s['count'], 'reason' => $s['reason'], 'note' => $s['note'] ?? null];
            } else {
                $out[] = ['number' => $n, 'key' => $key, 'title' => $title, 'short' => $short, 'kind' => $kind, 'ready' => $panelReady,
                    'count' => null, 'reason' => $panelReady ? 'Open' : 'Coming', 'note' => null];
            }
        }

        return $out;
    }

    /** Subquery of tracked property ids for a filter tile, or null (unknown tile, coming, or not a filter). */
    public function propertyIds(?string $key, int $agencyId, User $user): ?Builder
    {
        $n = self::numberForKey($key);
        if ($n === null) {
            return null;
        }
        if (isset($this->tiles[$n])) {
            return $this->tiles[$n]->propertyIds($agencyId, $user);
        }

        return class_exists(self::CC5_SERVICE) && in_array($n, [1, 4, 5, 6, 7, 8, 9], true) ? app(self::CC5_SERVICE)->fileIds($key, $user) : null;
    }

    public function panel(int $number): ?PanelProvider
    {
        return $this->panels[$number] ?? null;
    }
}
