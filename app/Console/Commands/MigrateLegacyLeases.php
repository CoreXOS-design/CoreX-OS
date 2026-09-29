<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\Rental;
use App\Models\Docuperfect\LeaseRecord;
use App\Services\Rentals\TenantContactResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/leases.md §6 — "replace, not extend," nothing deleted. Johan's
 * ruling: 58 legacy `rentals` rows (and lease_records' 2 rows) migrate
 * across into the new `leases` spine. Deliberately an Artisan command, not
 * a raw migration's up() — this needs real address-match/contact-match
 * logic and produces a human-readable outcome report, not a silent
 * one-shot schema change.
 *
 * SAFE TO RE-RUN: every write checks migrated_from_table/migrated_from_id
 * first and skips if a Lease already exists for that legacy row.
 *
 * NOTHING IS DELETED. The source `rentals`/`lease_records` rows are read
 * only, never touched, never soft-deleted, never modified — they remain
 * exactly as they were, satisfying non-negotiable #1 twice over (once by
 * never being touched, once because the new Lease copy is itself
 * soft-deletable, never hard-deletable).
 *
 * `rental_properties` (the fourth schema found during investigation) is
 * DELIBERATELY EXCLUDED — leases.md §6 flags it as having no tenant and no
 * lease dates, a landlord-contact prefill helper, not a tenancy record in
 * substance. Left untouched, still serving DocuPerfect prefill.
 *
 * Confident match = Property::searchAddress() returns EXACTLY ONE row.
 * Anything else (zero or multiple matches) is left unresolved and reported
 * for manual reconciliation — never guessed.
 */
class MigrateLegacyLeases extends Command
{
    protected $signature = 'leases:migrate-legacy {--dry-run : Report matches without writing anything}';

    protected $description = 'Migrate legacy rentals/lease_records rows into the new leases table (address-matched, nothing deleted)';

    public function __construct(private readonly TenantContactResolver $tenantContactResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun ? 'DRY RUN — no writes will be made.' : 'Live run — matched rows will be written.');

        [$rentalsMatched, $rentalsUnresolved] = $this->migrateRentals($dryRun);
        [$leaseRecordsMatched, $leaseRecordsUnresolved] = $this->migrateLeaseRecords($dryRun);

        $this->newLine();
        $this->info("rentals: {$rentalsMatched} matched/migrated, " . count($rentalsUnresolved) . ' unresolved');
        foreach ($rentalsUnresolved as $row) {
            $this->warn("  needs manual review — rentals.id={$row['id']}: \"{$row['lease_address']}\"");
        }

        $this->info("lease_records: {$leaseRecordsMatched} matched/migrated, " . count($leaseRecordsUnresolved) . ' unresolved');
        foreach ($leaseRecordsUnresolved as $row) {
            $this->warn("  needs manual review — lease_records.id={$row['id']}: \"{$row['property_address']}\"");
        }

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: array<int, array{id: int, lease_address: string}>} */
    private function migrateRentals(bool $dryRun): array
    {
        $matched = 0;
        $unresolved = [];

        Rental::withoutGlobalScopes()->get()->each(function (Rental $rental) use ($dryRun, &$matched, &$unresolved) {
            if (Lease::withoutGlobalScopes()->where('migrated_from_table', 'rentals')->where('migrated_from_id', $rental->id)->exists()) {
                $matched++; // already migrated on a prior run

                return;
            }

            $property = $this->matchOneProperty($rental->lease_address);

            if (!$property) {
                $unresolved[] = ['id' => $rental->id, 'lease_address' => (string) $rental->lease_address];

                return;
            }

            $agencyId = $property->agency_id;
            if (!$agencyId) {
                $unresolved[] = ['id' => $rental->id, 'lease_address' => (string) $rental->lease_address . ' (matched property has no agency_id)'];

                return;
            }

            $latestVersion = $rental->currentAmountVersion;
            $rentalAmount = $latestVersion->rent_incl ?? $latestVersion->rent_excl ?? 0;

            if (!$dryRun) {
                DB::transaction(function () use ($rental, $property, $agencyId, $rentalAmount) {
                    Lease::withoutGlobalScopes()->create([
                        'agency_id' => $agencyId,
                        'branch_id' => $rental->branch_id,
                        'property_id' => $property->id,
                        'status' => $rental->is_active ? 'active' : 'expired',
                        'rental_amount' => $rentalAmount,
                        'start_date' => $rental->lease_start_date ?? now()->toDateString(),
                        'end_date' => $rental->lease_end_date,
                        'is_month_to_month' => (bool) $rental->is_month_to_month,
                        'source' => 'migrated_legacy',
                        'created_by_user_id' => $rental->created_by_user_id,
                        'migrated_from_table' => 'rentals',
                        'migrated_from_id' => $rental->id,
                    ]);
                    // No tenant contact migrates here — the legacy `rentals` table never
                    // captured WHO the tenant was (confirmed: no contact/tenant field
                    // anywhere on the model). This is a real gap in the source data, not
                    // something this command can fabricate. The migrated Lease exists with
                    // property + terms but zero lease_tenants rows until an agent adds one.
                });
            }

            $matched++;
        });

        return [$matched, $unresolved];
    }

    /** @return array{0: int, 1: array<int, array{id: int, property_address: string}>} */
    private function migrateLeaseRecords(bool $dryRun): array
    {
        $matched = 0;
        $unresolved = [];

        LeaseRecord::withoutGlobalScopes()->with('document.owner')->get()
            ->each(function (LeaseRecord $record) use ($dryRun, &$matched, &$unresolved) {
                if (Lease::withoutGlobalScopes()->where('migrated_from_table', 'lease_records')->where('migrated_from_id', $record->id)->exists()) {
                    $matched++;

                    return;
                }

                $property = $record->property_id
                    ? Property::withoutGlobalScopes()->find($record->property_id)
                    : $this->matchOneProperty($record->property_address);

                if (!$property) {
                    $unresolved[] = ['id' => $record->id, 'property_address' => (string) $record->property_address];

                    return;
                }

                $agencyId = $property->agency_id;
                if (!$agencyId) {
                    $unresolved[] = ['id' => $record->id, 'property_address' => (string) $record->property_address . ' (matched property has no agency_id)'];

                    return;
                }

                if (!$dryRun) {
                    DB::transaction(function () use ($record, $property, $agencyId) {
                        $lease = Lease::withoutGlobalScopes()->create([
                            'agency_id' => $agencyId,
                            'property_id' => $property->id,
                            'status' => $record->status === LeaseRecord::STATUS_ACTIVE ? 'active' : 'expired',
                            'rental_amount' => $record->rental_amount ?? 0,
                            'start_date' => $record->lease_start_date ?? now()->toDateString(),
                            'end_date' => $record->lease_end_date,
                            'source' => 'migrated_legacy',
                            'source_document_id' => $record->document_id,
                            'migrated_from_table' => 'lease_records',
                            'migrated_from_id' => $record->id,
                        ]);

                        $tenantContact = $this->tenantContactResolver->matchOrCreate($agencyId, $property->branch_id, $record->tenant_name, $record->tenant_email);
                        if ($tenantContact) {
                            LeaseTenant::create([
                                'lease_id' => $lease->id,
                                'contact_id' => $tenantContact->id,
                                'is_primary' => true,
                            ]);
                        }
                    });
                }

                $matched++;
            });

        return [$matched, $unresolved];
    }

    private function matchOneProperty(?string $freeTextAddress): ?Property
    {
        return app(\App\Services\Rentals\LeasePropertyResolver::class)->matchOneByAddress($freeTextAddress);
    }

}
