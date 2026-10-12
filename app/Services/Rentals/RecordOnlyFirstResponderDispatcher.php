<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\User;

/**
 * The default {@see FirstResponderDispatcher} until the fault flow is wired: sends NOTHING. It writes one line on the fault's history - who chose whom, and
 * that it was not sent - so the choice is on the record and nobody is told anything on the strength of it.
 */
class RecordOnlyFirstResponderDispatcher implements FirstResponderDispatcher
{
    public function send(RentalFaultReport $fault, array $responder, User $by, string $choice): array
    {
        $toOwner = $choice === self::CHOICE_OWNER_AUTHORISE;
        $note = $toOwner
            ? $by->name . ' chose to send this to the owner to authorise (first to look: ' . $responder['name'] . ', ' . $responder['kind_label'] . ') - not sent yet.'
            : $by->name . ' chose to send ' . $responder['name'] . ' (' . $responder['kind_label'] . ') to inspect - not sent yet.';
        RentalFaultReportUpdate::create([
            'agency_id' => $fault->agency_id,
            'rental_fault_report_id' => $fault->id,
            'update_type' => RentalFaultReportUpdate::TYPE_FIRST_RESPONDER_CHOSEN,
            'note' => $note,
            'created_by_user_id' => $by->id,
        ]);

        return ['sent' => false, 'message' => $toOwner
            ? 'Noted: this is for the owner to authorise. Nothing has been sent to the owner yet.'
            : 'Noted: ' . $responder['name'] . ' is to inspect. Nothing has been sent to them yet.'];
    }
}
