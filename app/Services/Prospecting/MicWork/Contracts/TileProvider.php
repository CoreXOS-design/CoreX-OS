<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork\Contracts;

use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * One MIC Work tile's data (mic-work-tab.md section 1 / 11). A lane's service implements this and tags itself in the container:
 * `$this->app->tag(MyTile::class, 'mic.work.tiles')`. No provider tagged for a tile number = the tile shows "coming", never a number.
 * Every read is bound to $agencyId; counts come from stored figures, never computed on page load.
 */
interface TileProvider
{
    /** Tile number 1-11 from the spec. */
    public function tile(): int;

    /**
     * @return array{count: ?int, reason: string, note?: ?string}  count null = nothing to count yet; note e.g. "3 sales - too few to rank"
     */
    public function summary(int $agencyId, User $user): array;

    /** Subquery of tracked_properties.id the tile points at (tile click = filter the list to these files). Null = the tile is a panel/strip, not a filter. */
    public function propertyIds(int $agencyId, User $user): ?Builder;
}
