<?php

namespace App\Console\Commands;

use App\Models\P24ImportRow;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use Illuminate\Console\Command;

/**
 * AT-419 — manual, on-demand backfill for properties that were confirmed by
 * the P24 importer BEFORE the Imported Stock split shipped. Those rows have
 * p24_imported_at = null and stay exactly where they are today (mixed into
 * the Properties page) until this command is run — this build does not touch
 * historical data on its own. See .ai/specs/at419-imported-stock.md §5.
 *
 *   php artisan properties:backfill-p24-imported           # dry run — reports only
 *   php artisan properties:backfill-p24-imported --apply    # actually stamps rows
 *   php artisan properties:backfill-p24-imported --apply --agency=1
 *
 * Idempotent and safe to re-run: only ever touches properties whose
 * p24_imported_at is still null and which have a confirmed P24 import row
 * pointing at them (p24_import_rows.target_id). Stamps each one with that
 * import row's own confirmed_at — not "now" — so the Imported Date reflects
 * when it was really imported, not when this command happened to run.
 */
class BackfillP24ImportedStock extends Command
{
    protected $signature = 'properties:backfill-p24-imported
        {--apply : Actually write the changes. Without this flag, only reports what would happen.}
        {--agency= : Restrict to a single agency id}';

    protected $description = 'Backfill p24_imported_at on properties that were confirmed by the P24 importer before the Imported Stock page existed.';

    public function handle(): int
    {
        $apply     = (bool) $this->option('apply');
        $agencyId  = $this->option('agency');

        $rows = P24ImportRow::query()
            ->where('row_type', 'listing')
            ->where('status', 'confirmed')
            ->whereNotNull('target_id')
            ->whereNotNull('confirmed_at')
            ->when($agencyId, fn ($q) => $q->whereHas('run', fn ($r) => $r->where('agency_id', $agencyId)))
            ->get(['id', 'target_id', 'confirmed_at']);

        if ($rows->isEmpty()) {
            $this->info('No confirmed P24 import rows found.');
            return self::SUCCESS;
        }

        $properties = Property::withoutGlobalScope(AgencyScope::class)
            ->whereIn('id', $rows->pluck('target_id')->unique())
            ->whereNull('p24_imported_at')
            ->get(['id', 'status', 'agency_id'])
            ->keyBy('id');

        if ($properties->isEmpty()) {
            $this->info('Every P24-imported property already has an Imported Date — nothing to backfill.');
            return self::SUCCESS;
        }

        $byStatus = [];
        $plan = [];
        foreach ($rows as $row) {
            $property = $properties->get($row->target_id);
            if (! $property) continue;
            // A property can have more than one confirmed row (re-imports) — the
            // FIRST confirm is what "Imported Date" should mean, so keep the
            // earliest confirmed_at seen per property.
            if (! isset($plan[$property->id]) || $row->confirmed_at->lt($plan[$property->id])) {
                $plan[$property->id] = $row->confirmed_at;
            }
            $statusKey = strtolower((string) ($property->status ?: 'unknown'));
            $byStatus[$statusKey] = ($byStatus[$statusKey] ?? 0) + 1;
        }

        $this->info(($apply ? 'Backfilling' : '[DRY RUN] Would backfill') . ' ' . count($plan) . ' propert' . (count($plan) === 1 ? 'y' : 'ies') . ':');
        foreach ($byStatus as $status => $count) {
            $onOff = in_array($status, Property::OFF_MARKET_STATUSES, true) ? 'Imported Stock' : 'Properties (unchanged, active)';
            $this->line("  {$status}: {$count} → will show on {$onOff}");
        }

        if (! $apply) {
            $this->comment('Dry run — no changes made. Re-run with --apply to write.');
            return self::SUCCESS;
        }

        // withProgressBar's callback is ($value, $bar, $key) — NOT ($value, $key).
        // $plan is keyed by property id => confirmedAt, so $confirmedAt is the
        // value and $propertyId is the third argument, not the second.
        $this->withProgressBar($plan, function ($confirmedAt, $bar, $propertyId) {
            Property::withoutGlobalScope(AgencyScope::class)
                ->whereKey($propertyId)
                ->update(['p24_imported_at' => $confirmedAt]);
        });
        $this->newLine(2);
        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
