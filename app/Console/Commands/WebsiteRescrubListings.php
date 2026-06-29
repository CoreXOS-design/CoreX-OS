<?php

namespace App\Console\Commands;

use App\Models\AgencyApiKey;
use App\Models\Property;
use App\Models\PropertyWebsiteSyndication;
use App\Models\Scopes\AgencyScope;
use App\Services\Syndication\Website\WebsiteSyndicationService;
use Illuminate\Console\Command;

/**
 * Re-push every listing currently live on an agency website so the site re-pulls
 * the (now suburb-level only) public payload. Use this once after the
 * ListingResource location cut so that any website which cached the OLD payload
 * — one that still carried street address / GPS — receives a fresh
 * listing.updated webhook and refreshes to the suburb-only shape.
 *
 * Mechanism: for each enabled property_website_syndication row, fire
 * WebsiteSyndicationService::resend() — the existing "Refresh" action — which
 * stamps last_synced_at and emits ListingSyndicationChanged('updated'). The
 * webhook payload is built by ListingResource, so it is already scrubbed.
 *
 * Sites that PULL (GET /api/v1/website/listings) need no action at all — that
 * endpoint returns the scrubbed shape the moment the code is deployed. This
 * command exists for webhook/cache consumers and as a belt-and-braces refresh.
 *
 * Idempotent and safe to re-run. --dry-run lists the target set without firing.
 */
class WebsiteRescrubListings extends Command
{
    protected $signature = 'website:rescrub-listings
        {--agency=0 : Limit to one agency ID (0 = all agencies)}
        {--key=0 : Limit to one agency_api_key (website) ID (0 = all websites)}
        {--limit=0 : Cap the number re-pushed (0 = no cap)}
        {--dry-run : List the target listings and exit without firing webhooks}';

    protected $description = 'Re-push all live website listings so external sites refresh to the suburb-only payload';

    public function handle(WebsiteSyndicationService $svc): int
    {
        $agencyId = (int) $this->option('agency');
        $keyId    = (int) $this->option('key');
        $limit    = (int) $this->option('limit');
        $dryRun   = (bool) $this->option('dry-run');

        $query = PropertyWebsiteSyndication::withoutGlobalScope(AgencyScope::class)
            ->where('enabled', true);

        if ($agencyId > 0) {
            $query->where('agency_id', $agencyId);
        }
        if ($keyId > 0) {
            $query->where('agency_api_key_id', $keyId);
        }

        $total = (clone $query)->count();
        $this->info("Live (enabled) website listing rows matched: {$total}"
            . ($agencyId ? "  agency={$agencyId}" : '')
            . ($keyId ? "  key={$keyId}" : '')
            . ($limit ? "  limit={$limit}" : ''));

        if ($total === 0) {
            $this->warn('Nothing to re-push.');
            return self::SUCCESS;
        }

        // Cache keys so we resolve each website credential once, not per row.
        $keys = [];

        $pushed = 0; $skipped = 0; $scanned = 0; $errors = 0;

        $query->orderBy('id')->chunkById(200, function ($rows) use (
            $svc, $dryRun, $limit, &$keys, &$pushed, &$skipped, &$scanned, &$errors
        ) {
            foreach ($rows as $row) {
                if ($limit > 0 && $scanned >= $limit) {
                    return false; // stop chunking once the cap is hit
                }
                $scanned++;

                $property = Property::withoutGlobalScope(AgencyScope::class)
                    ->with('agent')
                    ->find($row->property_id);
                if (!$property) {
                    $skipped++;
                    continue;
                }

                if (!array_key_exists($row->agency_api_key_id, $keys)) {
                    $keys[$row->agency_api_key_id] = AgencyApiKey::withoutGlobalScope(AgencyScope::class)
                        ->find($row->agency_api_key_id);
                }
                $key = $keys[$row->agency_api_key_id];
                if (!$key) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $this->line("  would re-push #{$property->id} \"{$property->title}\" → website key {$key->id} ({$key->name})");
                    $pushed++;
                    continue;
                }

                try {
                    $svc->resend($property, $key);
                    $pushed++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->warn("  FAILED #{$property->id} → key {$key->id}: {$e->getMessage()}");
                }

                if ($pushed % 50 === 0) {
                    $this->info("  progress — re-pushed {$pushed}, skipped {$skipped}, errors {$errors}");
                }
            }

            return true;
        });

        if ($dryRun) {
            $this->info("DRY RUN — {$pushed} listing(s) would be re-pushed. No webhooks fired.");
            return self::SUCCESS;
        }

        $this->info("DONE — re-pushed {$pushed}, skipped {$skipped} (missing property/key), errors {$errors}.");
        $this->line('Each re-push fired a listing.updated webhook carrying the suburb-only payload; PULL-based sites already serve the scrubbed shape.');

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
