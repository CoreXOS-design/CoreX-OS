<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * AT-479 / rental-takeon-import.md R1-R3 — turns an uploaded Tenancy Schedule (and optional Rent Roll) into a run with
 * one resolved row per lease, ready for the review screen. Nothing is written to properties / contacts / leases.
 *
 * The uploaded files are read straight from PHP's temp upload and never stored: the money columns the reports carry
 * (commission, invoices, the Rent Roll's amounts) stay in reOS (Johan's money-side ruling), and everything CoreX keeps is
 * already in the rows' payloads.
 */
class ReosTenancyRunService
{
    public function __construct(
        private readonly ReosTenancyScheduleParser $schedule,
        private readonly ReosRentRollParser $rentRoll,
        private readonly ReosRentRollMerger $merger,
        private readonly ReosRowResolver $resolver,
    ) {
    }

    /**
     * @throws ReosLayoutException  the file is not the report it should be (message is for the uploader)
     */
    public function createRun(User $user, int $agencyId, ?int $branchId, UploadedFile $scheduleFile, ?UploadedFile $rentRollFile = null): RentalTakeOnImportRun
    {
        $schedulePath = (string) $scheduleFile->getRealPath();
        $this->schedule->assertLayout($schedulePath);

        $rollRows = [];
        if ($rentRollFile !== null) {
            $rollPath = (string) $rentRollFile->getRealPath();
            $this->rentRoll->assertLayout($rollPath);
            $rollRows = $this->rentRoll->rows($rollPath);
        }

        $parsed = iterator_to_array($this->schedule->rows($schedulePath), false);
        if ($parsed === []) {
            throw new ReosLayoutException('The Tenancy Schedule has no leases in it (nothing below its header row).');
        }

        $rollSummary = $rentRollFile !== null ? $this->merger->merge($parsed, $rollRows) : null;

        return DB::transaction(function () use ($user, $agencyId, $branchId, $scheduleFile, $rentRollFile, $parsed, $rollSummary, $rollRows) {
            $run = RentalTakeOnImportRun::create([
                'agency_id' => $agencyId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'status' => RentalTakeOnImportRun::STATUS_PARSING,
                'source' => RentalTakeOnImportRun::SOURCE_REOS_TENANCY_SCHEDULE,
                'source_filename' => $scheduleFile->getClientOriginalName(),
                'rent_roll_filename' => $rentRollFile?->getClientOriginalName(),
            ]);

            $counts = ['total' => 0, 'property_matched' => 0, 'property_to_choose' => 0, 'property_not_in_corex' => 0, 'owner_matched' => 0, 'tenant_matched' => 0,
                'already_in_corex' => 0, 'errors' => 0,
                'rent_roll' => $rollSummary === null ? null : ['rows' => count($rollRows)] + $rollSummary,
                'imported_created' => 0, 'imported_updated' => 0, 'imported_failed' => 0];

            foreach ($parsed as $p) {
                $row = RentalTakeOnImportRow::create([
                    'agency_id' => $agencyId,
                    'run_id' => $run->id,
                    'row_number' => $p['row_number'],
                    'external_id' => $p['payload']['external_id'],
                    'external_url' => $p['payload']['external_url'],
                    'payload_json' => $p['payload'],
                    'errors_json' => $p['errors'] ?: null,
                    'warnings_json' => $p['warnings'] ?: null,
                    'status' => RentalTakeOnImportRow::STATUS_PENDING,
                ]);
                $this->resolver->resolve($row, $run);
                $row->refresh();

                $counts['total']++;
                $counts[match ($row->property_match_action) {
                    RentalTakeOnImportRow::ACTION_MATCH => 'property_matched',
                    RentalTakeOnImportRow::ACTION_CHOOSE => 'property_to_choose',
                    default => 'property_not_in_corex',
                }]++;
                if (($row->landlord_match_json[1]['action'] ?? null) === 'match') {
                    $counts['owner_matched']++;
                }
                if (($row->tenant_match_json[1]['action'] ?? null) === 'match') {
                    $counts['tenant_matched']++;
                }
                if ($row->existing_lease_id) {
                    $counts['already_in_corex']++;
                }
                if ($row->status === RentalTakeOnImportRow::STATUS_ERROR) {
                    $counts['errors']++;
                }
            }

            $run->update(['status' => RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, 'counts_json' => $counts]);

            return $run;
        });
    }
}
