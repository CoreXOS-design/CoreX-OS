<?php

namespace App\Listeners\Rentals;

use App\Events\Rentals\LeaseNoticeReviewed;
use App\Events\Rentals\RentalAppointmentSet;
use App\Events\Rentals\RentalInspectionReportReady;
use App\Events\Rentals\RentalOwnerDecisionNeeded;
use App\Events\Rentals\RentalTenantFaultStatusChanged;
use App\Events\Rentals\RentalWorkReportedDone;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalAuthorisationService;
use App\Services\Rentals\RentalClientPushService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * AT-508 (P1) — the phones of tenants and owners, told through the domain events (NN #9). Every handler is best-effort: a push that cannot be
 * sent must never undo or fail the thing that happened. Tenants never receive anything that names or describes the lessor.
 * Which categories are mandatory / switchable: config/rental-push.php. Staff phones are already served by NotificationDispatcher (their own alert settings).
 */
class SendRentalClientPush
{
    public function __construct(private RentalClientPushService $push, private RentalAuthorisationService $authorisation) {}

    public function decisionNeeded(RentalOwnerDecisionNeeded $e): void
    {
        $this->safely(function () use ($e) {
            $s = $e->subject;
            $key = $s instanceof RentalFaultReport ? 'fault' : 'work_order';
            $this->push->sendToContacts(
                $this->authorisation->ownersFor($s), 'decision_needed',
                $e->reminderNumber > 0 ? 'Reminder: a decision is waiting for you' : 'A decision is waiting for you',
                $this->line($s),
                "{$key}:{$s->id}|r{$e->reminderNumber}",
                ['subject_type' => $key, 'subject_id' => (string) $s->id, 'audience' => 'owner'],
                $s->agency_id,
            );
        });
    }

    public function faultStatusChanged(RentalTenantFaultStatusChanged $e): void
    {
        $this->safely(function () use ($e) {
            $f = $e->fault;
            $text = match ($e->stepKey) {
                'sent_to_owner' => 'Your fault has been sent to the owner for approval',
                'owner_approved' => 'Your fault has been approved',
                'not_approved' => 'Your fault was not approved',
                'completed' => 'Your fault has been marked as done',
                default => null,
            };
            if ($text === null) {
                return;
            }
            $this->push->sendToContacts(
                $f->lease?->tenantContacts() ?? collect(), 'fault_status', $text, $this->line($f),
                "fault:{$f->id}|step:{$e->stepKey}",
                ['subject_type' => 'fault', 'subject_id' => (string) $f->id, 'audience' => 'tenant'],
                $f->agency_id,
            );
        });
    }

    public function appointmentSet(RentalAppointmentSet $e): void
    {
        $this->safely(function () use ($e) {
            $wo = $e->workOrder;
            $when = $wo->appointment_at ? \App\Support\AppDate::dateTime($wo->appointment_at, \App\Support\AppDate::agencyTimezone($wo->agency)) : '';
            $title = $e->changed ? 'Your repair appointment has changed' : 'A repair appointment has been booked';
            $body = trim($this->line($wo) . ($when !== '' ? " - {$when}" : ''));
            $fact = "work_order:{$wo->id}|appt:" . ($wo->appointment_at?->getTimestamp() ?? 0);
            $data = ['subject_type' => 'work_order', 'subject_id' => (string) $wo->id];

            // The tenant is told unless the tenant is the one who set it; the owner unless the owner set it on the portal themselves.
            $this->push->sendToContacts($wo->lease?->tenantContacts() ?? collect(), 'appointment', $title, $body, "{$fact}|tenant", $data + ['audience' => 'tenant'], $wo->agency_id);
            if ($e->setBy !== 'owner') {
                $this->push->sendToContacts($this->authorisation->ownersFor($wo), 'appointment', $title, $body, "{$fact}|owner", $data + ['audience' => 'owner'], $wo->agency_id);
            }
        });
    }

    /** Work reported done: the tenant is asked to check it; the owner hears it is done. */
    public function workReportedDone(RentalWorkReportedDone $e): void
    {
        $this->safely(function () use ($e) {
            $round = $e->round;
            $wo = RentalWorkOrder::withoutGlobalScopes()->find($round->rental_work_order_id);
            if (! $wo) {
                return;
            }
            $data = ['subject_type' => 'work_order', 'subject_id' => (string) $wo->id];
            $fact = "work_order:{$wo->id}|round:{$round->round_no}";

            if ($round->outcome === \App\Models\RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT) {
                $this->push->sendToContacts($wo->lease?->tenantContacts() ?? collect(), 'work_check_requested', 'Please check the finished work', $this->line($wo), "{$fact}|tenant", $data + ['audience' => 'tenant'], $wo->agency_id);
            }
            $this->push->sendToContacts($this->authorisation->ownersFor($wo), 'work_finished', 'Work has been reported finished', $this->line($wo), "{$fact}|owner", $data + ['audience' => 'owner'], $wo->agency_id);
        });
    }

    /** A notice was accepted (the person who gave it AND the other party are told) or rejected (only the person who gave it). */
    public function noticeReviewed(LeaseNoticeReviewed $e): void
    {
        $this->safely(function () use ($e) {
            $n = $e->notice;
            $lease = Lease::withoutGlobalScopes()->find($n->lease_id);
            if (! $lease) {
                return;
            }
            $tenants = $lease->tenantContacts();
            $owners = $lease->landlordContacts();
            $byTenant = $n->given_by === Lease::NOTICE_BY_TENANT;
            $giver = $byTenant ? $tenants : $owners;
            $other = $byTenant ? $owners : $tenants;
            $address = $lease->property?->buildDisplayAddress() ?: '';
            $data = ['subject_type' => 'lease_notice', 'subject_id' => (string) $n->id];
            $fact = "notice:{$n->id}|{$e->outcome}";

            if ($e->outcome === 'accepted') {
                $this->push->sendToContacts($giver, 'notice_outcome', 'Your notice has been accepted', $address, "{$fact}|giver", $data + ['audience' => $byTenant ? 'tenant' : 'owner'], $lease->agency_id);
                $this->push->sendToContacts($other, 'notice_outcome', 'A notice on your lease has been accepted', $address, "{$fact}|other", $data + ['audience' => $byTenant ? 'owner' : 'tenant'], $lease->agency_id);
            } else {
                $this->push->sendToContacts($giver, 'notice_outcome', 'Your notice was not accepted', $address, "{$fact}|giver", $data + ['audience' => $byTenant ? 'tenant' : 'owner'], $lease->agency_id);
            }
        });
    }

    /** A completed inspection's report is open to its parties: the tenant and the owner of the lease are told once per inspection (switchable). */
    public function inspectionReportReady(RentalInspectionReportReady $e): void
    {
        $this->safely(function () use ($e) {
            $i = $e->inspection;
            $lease = $i->lease_id ? Lease::withoutGlobalScopes()->find($i->lease_id) : null;
            if (! $lease) {
                return;
            }
            $title = ucfirst(RentalInspection::typeName($i->type)) . ' report is ready';
            $body = $lease->property?->buildDisplayAddress() ?: '';
            $data = ['subject_type' => 'inspection', 'subject_id' => (string) $i->id];
            $fact = "inspection:{$i->id}";

            $this->push->sendToContacts($lease->tenantContacts(), 'inspection_ready', $title, $body, "{$fact}|tenant", $data + ['audience' => 'tenant'], $lease->agency_id);
            $this->push->sendToContacts($lease->landlordContacts(), 'inspection_ready', $title, $body, "{$fact}|owner", $data + ['audience' => 'owner'], $lease->agency_id);
        });
    }

    /** "12 Sea Rd, Margate: Leaking tap" — neutral, no agency name. */
    private function line(RentalFaultReport|RentalWorkOrder $s): string
    {
        $address = $s->property?->buildDisplayAddress() ?: '';

        return trim($address . ($s->title ? ($address !== '' ? ': ' : '') . $s->title : ''));
    }

    private function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning('Rental client push failed (the event itself is unaffected)', ['error' => $e->getMessage()]);
        }
    }
}
