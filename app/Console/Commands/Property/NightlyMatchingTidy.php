<?php

declare(strict_types=1);

namespace App\Console\Commands\Property;

use App\Jobs\Prospecting\RunSameAddressSweepJob;
use App\Models\AddressMatchSetting;
use App\Models\Agency;
use Illuminate\Console\Command;

/**
 * AT-520 4.10: the nightly safety net for the automatic matching rules. Queues the same job that runs after every import (same-address sweep, P24 groups,
 * twins, complexes, map points, unit splits, advert-vs-file link audit, backlog collapse) for every agency that has the sweep switched on - so an agency
 * whose imports never fired the trigger still ends up tidy. Each agency's own settings decide what the job does; nothing runs for an agency that switched it off.
 */
final class NightlyMatchingTidy extends Command
{
    protected $signature = 'property-identity:nightly {--agency= : One agency id}';

    protected $description = 'Queue the automatic matching rules for every agency (nightly safety net)';

    public function handle(): int
    {
        $ids = $this->option('agency') ? [(int) $this->option('agency')] : Agency::query()->pluck('id')->map(fn ($i) => (int) $i)->all();
        $queued = 0;
        foreach ($ids as $id) {
            if (AddressMatchSetting::forAgency($id)['same_address_sweep']) {
                RunSameAddressSweepJob::dispatch($id);
                $queued++;
            }
        }
        $this->info("Queued the matching rules for {$queued} of " . count($ids) . ' agencies.');

        return self::SUCCESS;
    }
}
