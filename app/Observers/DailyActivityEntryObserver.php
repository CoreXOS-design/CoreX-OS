<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DailyActivityEntry;
use App\Services\RoadToSold\LegacyEntryBridge;

/**
 * AT-454 v2 phase 3 - mirrors every change of a daily-activity credit into the task completions of an agency that has been cut over
 * (LegacyEntryBridge). A no-op for every other agency.
 */
final class DailyActivityEntryObserver
{
    public function __construct(private readonly LegacyEntryBridge $bridge)
    {
    }

    public function saved(DailyActivityEntry $entry): void
    {
        $this->bridge->sync($entry);
    }

    public function deleted(DailyActivityEntry $entry): void
    {
        $this->bridge->remove($entry);
    }
}
