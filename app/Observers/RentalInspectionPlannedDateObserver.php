<?php

namespace App\Observers;

use App\Models\RentalInspection;
use App\Models\RentalInspectionPlannedDate;

/**
 * .ai/specs/rental-inspections.md §45.7 (Build I-5) — keeps a loaded interim date in step with the inspection booked from
 * it: that inspection completes -> the date is DONE; it is cancelled or archived -> the date returns to PLANNED (and is
 * reminded again). A model observer, like RentalInspectionCompletionObserver, so it reacts to the state change itself
 * regardless of which controller or service made it — no edit to RentalInspection's own lifecycle methods.
 */
class RentalInspectionPlannedDateObserver
{
    public function updated(RentalInspection $inspection): void
    {
        if (! $inspection->wasChanged('status')) {
            return;
        }

        if ($inspection->status === RentalInspection::STATUS_COMPLETED) {
            $this->linked($inspection)->update(['status' => RentalInspectionPlannedDate::STATUS_DONE]);
        } elseif ($inspection->status === RentalInspection::STATUS_CANCELLED) {
            $this->release($inspection);
        }
    }

    /** Archived (soft-deleted) inspection: the booking no longer stands. A date already DONE stays done. */
    public function deleted(RentalInspection $inspection): void
    {
        $this->release($inspection);
    }

    private function release(RentalInspection $inspection): void
    {
        $this->linked($inspection)->where('status', RentalInspectionPlannedDate::STATUS_BOOKED)
            ->update(['status' => RentalInspectionPlannedDate::STATUS_PLANNED, 'rental_inspection_id' => null]);
    }

    private function linked(RentalInspection $inspection)
    {
        return RentalInspectionPlannedDate::withoutGlobalScopes()
            ->where('rental_inspection_id', $inspection->id)
            ->whereIn('status', RentalInspectionPlannedDate::OPEN_STATUSES);
    }
}
