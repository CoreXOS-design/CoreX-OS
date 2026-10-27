<?php

namespace App\Console\Commands;

use App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;

/**
 * sold-stock:expire-portals - the "Keep showing on portals as Sold for X days" window has closed
 * (properties.portal_sold_until < today): withdraw the Sold advert from Property24 and clear the flags.
 * Spec: .ai/specs/sold-stock.md section 2B. Daily; idempotent.
 */
class SoldStockExpirePortals extends Command
{
    protected $signature = 'sold-stock:expire-portals {--dry-run}';

    protected $description = 'Take Sold properties off the portals once their agency\'s "show as Sold for X days" window has passed.';

    public function handle(): int
    {
        $n = 0;
        Property::withoutGlobalScope(AgencyScope::class)
            ->whereRaw("LOWER(TRIM(status)) = 'sold'")
            ->whereNotNull('portal_sold_until')->whereDate('portal_sold_until', '<', now()->toDateString())
            ->where(fn ($q) => $q->where('p24_syndication_enabled', true)->orWhere('pp_syndication_enabled', true))
            ->orderBy('id')->chunkById(100, function ($chunk) use (&$n) {
                foreach ($chunk as $property) {
                    $n++;
                    if ($this->option('dry-run')) {
                        continue;
                    }
                    DesyndicatePropertyFromPortalsJob::dispatch($property, removeFromWebsite: true, keepPpForSold: false, keepP24ForSold: false);
                    Property::withoutGlobalScopes()->whereKey($property->id)->update(['p24_syndication_enabled' => false, 'pp_syndication_enabled' => false]);
                }
            });

        $this->info(($this->option('dry-run') ? '[dry-run] ' : '') . "sold-stock:expire-portals: {$n} sold listing(s) past their window.");

        return self::SUCCESS;
    }
}
