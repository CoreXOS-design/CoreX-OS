<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\Mandate\MandateExpired;
use App\Models\Property;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Scan agency-stock properties whose mandate has reached its expiry date
 * and aren't already marked expired. For each match, set status='expired'
 * and fire the MandateExpired domain event (spec corex-domain-events-spec.md).
 *
 * Scheduled daily — see routes/console.php.
 */
class ExpireMandates extends Command
{
    protected $signature = 'mandates:expire {--dry-run : List affected properties without changing or firing events}';

    protected $description = 'Mark properties whose mandate expiry has passed as expired and fire MandateExpired events.';

    public function handle(): int
    {
        $today = Carbon::today();

        $query = Property::query()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', $today)
            ->where(function ($q) {
                $q->whereNull('status')->orWhereNotIn('status', ['expired', 'sold', 'withdrawn']);
            });

        $count = 0;

        $query->chunkById(100, function ($props) use (&$count) {
            foreach ($props as $property) {
                $count++;
                if ($this->option('dry-run')) {
                    $this->line("DRY: would expire property #{$property->id} (expiry={$property->expiry_date?->toDateString()})");
                    continue;
                }

                // Mutate status inside a transaction; fire event AFTER commit.
                DB::transaction(function () use ($property) {
                    $property->status = 'expired';
                    $property->save();
                });

                event(new MandateExpired(
                    mandate: $property,
                    agencyIdHint: $property->agency_id,
                ));
            }
        });

        $this->info("Scanned. {$count} mandate(s) " . ($this->option('dry-run') ? 'would be expired.' : 'expired.'));

        return self::SUCCESS;
    }
}
