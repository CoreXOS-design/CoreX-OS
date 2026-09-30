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

    /** @var array<int, string> property_id => start date of the active lease planned/created this run */
    private array $activeThisRun = [];

    /** @var array<int, string> human-readable notes on leases downgraded to expired to keep one active lease per property */
    private array $downgraded = [];

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
        foreach ($this->downgraded as $note) {
            $this->warn("  one-active-lease rule — {$note}");
        }
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

        // Drop the agency/branch scopes (this runs without a user) but keep
        // archived rows OUT: withoutGlobalScopes() alone would also strip the
        // SoftDeletes scope and migrate archived rentals as live leases.
        Rental::withoutGlobalScopes()->whereNull('rentals.deleted_at')->get()->each(function (Rental $rental) use ($dryRun, &$matched, &$unresolved) {
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
            // The address match searches every agency — never let a rental
            // in one agency become a lease on another agency's property.
            if ($rental->agency_id && (int) $rental->agency_id !== (int) $agencyId) {
                $unresolved[] = ['id' => $rental->id, 'lease_address' => (string) $rental->lease_address . ' (matched property belongs to a different agency)'];

                return;
            }

            $latestVersion = $rental->currentAmountVersion;
            $rentalAmount = $latestVersion->rent_incl ?? $latestVersion->rent_excl ?? 0;

            $status = $this->resolveStatus($property->id, (bool) $rental->is_active, $rental->lease_start_date, "rentals.id={$rental->id}", $dryRun);

            if (!$dryRun) {
                DB::transaction(function () use ($rental, $property, $agencyId, $rentalAmount, $status) {
                    Lease::withoutGlobalScopes()->create([
                        'agency_id' => $agencyId,
                        'branch_id' => $rental->branch_id,
                        'property_id' => $property->id,
                        'status' => $status,
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

        LeaseRecord::withoutGlobalScopes()->whereNull('lease_records.deleted_at')->with('document.owner')->get()
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

                // lease_records carries no agency_id of its own; its document's
                // owner is the agency it belongs to — must match the property's.
                $recordAgencyId = $record->document?->owner?->agency_id;
                if ($recordAgencyId && (int) $recordAgencyId !== (int) $agencyId) {
                    $unresolved[] = ['id' => $record->id, 'property_address' => (string) $record->property_address . ' (matched property belongs to a different agency)'];

                    return;
                }

                $status = $this->resolveStatus($property->id, $record->status === LeaseRecord::STATUS_ACTIVE, $record->lease_start_date, "lease_records.id={$record->id}", $dryRun);

                if (!$dryRun) {
                    DB::transaction(function () use ($record, $property, $agencyId, $status) {
                        $lease = Lease::withoutGlobalScopes()->create([
                            'agency_id' => $agencyId,
                            'property_id' => $property->id,
                            'status' => $status,
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

    /**
     * leases.md §3.5 — one ACTIVE lease per property. A legacy row that
     * wants to be active while the property already has an active lease
     * (a real one, an earlier migrated one, or one planned earlier in this
     * run) is resolved by start date: the newer lease stays active and the
     * older becomes expired. A real (non-migrated) active lease always wins.
     * In a dry run nothing is written but the same decisions are reported.
     */
    private function resolveStatus(int $propertyId, bool $wantsActive, $startDate, string $label, bool $dryRun): string
    {
        if (!$wantsActive) {
            return 'expired';
        }

        $start = $startDate ? \Illuminate\Support\Carbon::parse($startDate)->toDateString() : now()->toDateString();

        $existing = Lease::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('property_id', $propertyId)->where('status', 'active')->first();

        $existingStart = $existing?->start_date?->toDateString();
        $existingIsReal = $existing && $existing->source !== 'migrated_legacy';

        if (!$existing && isset($this->activeThisRun[$propertyId])) {
            $existingStart = $this->activeThisRun[$propertyId]; // planned (dry run) — nothing to demote yet
        }

        if ($existingStart === null) {
            $this->activeThisRun[$propertyId] = $start;

            return 'active';
        }

        if ($existingIsReal || $existingStart >= $start) {
            $this->downgraded[] = "{$label} migrated as expired (property {$propertyId} already has an active lease)";

            return 'expired';
        }

        // The migrated row is newer than the active one already there: it
        // takes over, the older migrated lease is expired.
        if ($existing && !$dryRun) {
            $existing->forceFill(['status' => 'expired'])->save();
        }
        $this->downgraded[] = ($existing ? "lease #{$existing->id}" : 'an earlier migrated row') . " expired in favour of newer {$label} (property {$propertyId})";
        $this->activeThisRun[$propertyId] = $start;

        return 'active';
    }

    private function matchOneProperty(?string $freeTextAddress): ?Property
    {
        return app(\App\Services\Rentals\LeasePropertyResolver::class)->matchOneByAddress($freeTextAddress);
    }

}
