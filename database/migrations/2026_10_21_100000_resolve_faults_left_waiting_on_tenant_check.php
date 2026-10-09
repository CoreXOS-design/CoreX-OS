<?php

use App\Models\RentalFaultReport;
use App\Models\RentalWorkOrder;
use Illuminate\Database\Migrations\Migration;

/**
 * Johan, 9 Oct 2026 (T1) - the tenant's check never blocks closing. Before this, a work order the agent had COMPLETED could leave its fault
 * parked at "Work order raised" because the tenant had not answered. Those faults resolve now, exactly as completing the work order would have
 * done today: outcome Repaired (automatic, so the agent can still change it), dated the day the work order was completed.
 *
 * Deliberately quiet - no tenant/owner notification goes out for a repair that is being caught up, not newly done. The tenant's open
 * check stays on the record as an optional answer. Work orders the tenant already sent back (status "disputed") are NOT touched: the agent
 * has a live reopened job there. Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        RentalWorkOrder::withoutGlobalScopes()
            ->where('status', RentalWorkOrder::STATUS_COMPLETED)
            ->whereNotNull('reported_fault_report_id')
            ->orderBy('id')
            ->chunkById(100, function ($orders) {
                foreach ($orders as $wo) {
                    $fault = RentalFaultReport::withoutGlobalScopes()->find($wo->reported_fault_report_id);
                    if (! $fault || (int) $fault->rental_work_order_id !== (int) $wo->id
                        || in_array($fault->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED], true)) {
                        continue;
                    }

                    $when = $wo->completed_at ?? now();
                    $note = 'Resolved automatically when the work order was completed. Change the outcome if the repair was not complete.';
                    $fault->forceFill([
                        'status' => RentalFaultReport::STATUS_RESOLVED,
                        'outcome' => RentalFaultReport::OUTCOME_REPAIRED,
                        'outcome_note' => $note,
                        'repaired_at' => $when->toDateString(),
                        'resolved_at' => $when,
                        'outcome_set_automatically' => true,
                    ])->save();
                    $fault->updates()->create([
                        'agency_id' => $fault->agency_id, 'update_type' => \App\Models\RentalFaultReportUpdate::TYPE_OUTCOME_SET,
                        'note' => $note, 'created_by_user_id' => null,
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Not reversible: it only moves faults to the state completing their work order already implies.
    }
};
