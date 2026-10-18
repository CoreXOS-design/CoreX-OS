<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections\Concerns;

use App\Models\RentalInspection;
use App\Models\RentalInspectionAttendance;
use App\Services\Rentals\RentalInspectionAttendanceService;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — an in/out inspection now needs an attendance outcome
 * for every expected party before it can complete. Fixtures that are about something ELSE (a deadline, a
 * signature rule, a wet-ink scan) call this to put that record in place, so they keep testing what they
 * were written to test. Model-level on purpose — the attendance rules themselves are tested in
 * RentalInspectionI3AttendanceTest.
 */
trait RecordsAttendance
{
    protected function recordAttendanceForEveryParty(RentalInspection $inspection): void
    {
        foreach (app(RentalInspectionAttendanceService::class)->expectedParties($inspection) as $party) {
            RentalInspectionAttendance::create([
                'agency_id' => $inspection->agency_id,
                'rental_inspection_id' => $inspection->id,
                'party_role' => $party['party_role'],
                'party_contact_id' => $party['contact_id'],
                'party_user_id' => $party['user_id'],
                'attended_as' => RentalInspectionAttendance::AS_SELF,
                'outcome' => RentalInspectionAttendance::OUTCOME_ATTENDED,
                'recorded_by_user_id' => $inspection->created_by_user_id,
                'recorded_at' => now(),
                'created_at' => now(),
            ]);
        }
    }
}
