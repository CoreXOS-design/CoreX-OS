<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\PortalLead;
use App\Models\Property;
use App\Services\Buyers\BuyerLeadCascadeService;
use Illuminate\Console\Command;

/**
 * Part 5 — backfill the RECENT (default last 30 days) portal leads through the SAME
 * shared cascade new leads now use: seed a criteria-bearing wishlist from the enquired
 * property → buyer auto-lands 'New' on the pipeline + feeds MIC demand, owned by the
 * listing agent and source-tagged by portal.
 *
 * Deliberately scoped to the recent window: older buyers come via the agent-confirmed
 * spreadsheet import separately, so backfilling them here would double-load them.
 *
 * Idempotent: seedFromListing() skips when an equivalent wishlist already exists, so
 * re-running is safe.
 */
class BackfillPortalLeadBuyers extends Command
{
    protected $signature = 'buyers:backfill-portal-leads
        {--agency= : Limit to one agency id}
        {--days=30 : How many days back to process (default 30; older leads are NOT touched)}
        {--dry-run : Report what would happen without writing}';

    protected $description = 'Seed criteria-bearing buyers + pipeline landing from the last N days of portal leads.';

    public function handle(BuyerLeadCascadeService $cascade): int
    {
        $days  = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $dry   = (bool) $this->option('dry-run');

        $query = PortalLead::withoutGlobalScopes()
            ->whereNotNull('contact_id')
            ->whereNotNull('listing_id')
            ->whereNull('deleted_at')
            ->where('received_at', '>=', $since)
            ->orderBy('received_at');

        if ($agency = $this->option('agency')) {
            $query->where('agency_id', (int) $agency);
        }

        $leads = $query->get();
        $this->info(($dry ? '[dry-run] ' : '')
            . "Processing {$leads->count()} portal lead(s) from the last {$days} days (with a contact + listing).");

        $seeded = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($leads as $lead) {
            $contact  = Contact::withoutGlobalScopes()->find($lead->contact_id);
            $property = Property::withoutGlobalScopes()->find($lead->listing_id);
            if (! $contact || ! $property) {
                $missing++;
                continue;
            }

            $owner = $property->agent_id ?? $contact->created_by_user_id ?? $lead->existing_contact_agent_id;
            if (! $owner) {
                $skipped++;
                continue;
            }

            $source = $lead->portal === PortalLead::PORTAL_P24
                ? BuyerLeadCascadeService::SOURCE_PORTAL_P24
                : BuyerLeadCascadeService::SOURCE_PORTAL_PP;

            if ($dry) {
                $this->line("  would seed lead #{$lead->id} ({$lead->portal}) → contact #{$contact->id} via listing #{$property->id}");
                $seeded++;
                continue;
            }

            try {
                $match = $cascade->seedFromListing($contact, $property, (int) $owner, $source, $lead->message);
                $match ? $seeded++ : $skipped++;
            } catch (\Throwable $e) {
                $this->warn("  lead #{$lead->id} failed: {$e->getMessage()}");
                $skipped++;
            }
        }

        $this->info(($dry ? '[dry-run] ' : '')
            . "Done. Seeded: {$seeded} · skipped (already-seeded/auto-seed-off/no-owner): {$skipped} · missing contact/listing: {$missing}.");

        return self::SUCCESS;
    }
}
