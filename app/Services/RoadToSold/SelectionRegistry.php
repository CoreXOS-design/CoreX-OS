<?php

namespace App\Services\RoadToSold;

use App\Services\RoadToSold\Selections\BuyerSourcingSelection;
use App\Services\RoadToSold\Selections\ExpiredCompetitorSelection;
use App\Services\RoadToSold\Selections\HotPocketSelection;
use App\Services\RoadToSold\Selections\LongOnMarketSelection;
use App\Services\RoadToSold\Selections\MyClaimsSelection;
use App\Services\RoadToSold\Selections\MyExpiredStockSelection;
use App\Services\RoadToSold\Selections\MyStockNotSellingSelection;
use App\Services\RoadToSold\Selections\NewOnMarketSelection;
use App\Services\RoadToSold\Selections\OwnerKnownSelection;
use App\Services\RoadToSold\Selections\PriceDropSelection;
use App\Services\RoadToSold\Selections\Selection;

/**
 * The ten selections, by their stored key. Which are on, and in what order, is the agency's choice
 * (lane 1's settings); this only knows how to build each one.
 */
final class SelectionRegistry
{
    /** @var array<string,Selection> */
    private array $selections = [];

    public function __construct(?HotPocketService $pockets = null)
    {
        $pockets ??= new HotPocketService();
        foreach ([
            BuyerSourcingSelection::mine(),
            BuyerSourcingSelection::agency(),
            new HotPocketSelection($pockets),
            new PriceDropSelection(),
            new LongOnMarketSelection(),
            new MyExpiredStockSelection(),
            new ExpiredCompetitorSelection(),
            new NewOnMarketSelection(),
            new OwnerKnownSelection(),
            new MyStockNotSellingSelection(),
        ] as $s) {
            $this->selections[$s->key()] = $s;
        }
    }

    /** The day feed's first source (23.24): the agent's own claims. Not one of the ten reorderable sources, so it is not in all(). */
    public function claims(): Selection
    {
        return new MyClaimsSelection();
    }

    public function get(string $key): ?Selection
    {
        return $this->selections[$key] ?? null;
    }

    /** @return array<string,Selection> in the default order */
    public function all(): array
    {
        return $this->selections;
    }
}
