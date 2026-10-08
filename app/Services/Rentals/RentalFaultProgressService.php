<?php

namespace App\Services\Rentals;

use App\Models\Agency;
use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalJobCard;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use Illuminate\Support\Carbon;

/**
 * The ONE progress line of a fault (Johan, 8 Oct 2026): fault AND work order in a single sequence, so nobody can say "I did
 * not know where it was".
 *
 *   1 Sent to agent -> 2 Agent reviewing -> 3 Sent to owner for approval -> 4 Owner approved (or Not approved)
 *   -> 5 Sent to contractor for scheduling -> 6 Appointment set (date, time, who is coming) -> 7 Work in progress
 *   -> 8 Work completed (with the tenant's "please check" while a check is open)
 *
 * Every step is DERIVED from the real records (the fault, its decision, the work order, its completion rounds) - there is no
 * separately typed status to drift. Each step that has been reached carries the moment it was reached. The words are data
 * (config/rental-fault-progress.php), per audience. A declined fault ends at "Not approved" - for the tenant a neutral line,
 * never the owner's reason or the agent's notes.
 *
 * @phpstan-type Step array{key:string,label:string,state:string,current:bool,at:?string,detail:?string,work_order_id:?int,action:?string}
 */
class RentalFaultProgressService
{
    public const AUDIENCE_TENANT = 'tenant';
    public const AUDIENCE_OWNER = 'owner';
    public const AUDIENCE_AGENT = 'agent';

    /**
     * @return array{steps: array<int, array<string,mixed>>, current: string, current_label: string, ended: ?string}
     */
    public function forFault(RentalFaultReport $fault, string $audience = self::AUDIENCE_TENANT): array
    {
        $aud = in_array($audience, [self::AUDIENCE_TENANT, self::AUDIENCE_OWNER, self::AUDIENCE_AGENT], true) ? $audience : self::AUDIENCE_AGENT;
        $tz = $this->timezone($fault);

        $decision = $fault->decision();
        $declined = $decision && $decision->decision === RentalApproval::DECISION_DECLINED;
        $wo = $fault->rental_work_order_id
            ? RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->whereNull('deleted_at')->find($fault->rental_work_order_id)
            : null;
        $cancelled = $fault->status === RentalFaultReport::STATUS_CANCELLED || ($wo && $wo->status === RentalWorkOrder::STATUS_CANCELLED);

        $steps = [];
        $push = function (string $key, string $labelKey, ?Carbon $at, ?string $detail = null, ?string $action = null, ?int $woId = null) use (&$steps, $aud) {
            $steps[] = [
                'key' => $key, 'label' => $this->label($labelKey, $aud), 'state' => $at ? 'done' : 'todo',
                'current' => false, 'at' => $at?->toIso8601String(), 'detail' => $detail, 'work_order_id' => $woId, 'action' => $action,
            ];
        };

        // 1 - always reached: the moment it was reported.
        $push('sent_to_agent', 'sent_to_agent', $fault->reported_at ?? $fault->created_at);

        // 2 - the agent has picked it up: a prepared owner version, a send, a decision or a work order.
        $reviewAt = $fault->owner_version_saved_at
            ?? $this->firstUpdateAt($fault)
            ?? $fault->sent_to_owner_at
            ?? ($decision ? ($decision->decided_at ?? $decision->created_at) : null)
            ?? $wo?->created_at;
        $reviewing = $fault->status !== RentalFaultReport::STATUS_REPORTED || $reviewAt !== null;
        $push('agent_reviewing', 'agent_reviewing', $reviewing ? ($reviewAt ?? $fault->updated_at) : null);

        // 3 - sent to the owner (or the owner's answer was taken without a send, e.g. by phone).
        $sentAt = $fault->sent_to_owner_at ?? ($decision ? ($decision->decided_at ?? $decision->created_at) : null) ?? $wo?->created_at;
        $push('sent_to_owner', 'sent_to_owner', $sentAt);

        // 4 - the owner's decision; a work order raised without one means the agent proceeded (approval not required).
        $decidedAt = $decision ? ($decision->decided_at ?? $decision->created_at) : $wo?->created_at;
        if ($declined) {
            $push('owner_decided', 'not_approved', $decidedAt);
        } else {
            $push('owner_decided', 'owner_approved', $decidedAt);
        }

        $ended = null;
        if ($declined) {
            $ended = 'not_approved';
        } else {
            // 5 - handed to whoever does the work.
            $variant = $wo ? match ($wo->assignment_type) {
                RentalWorkOrder::ASSIGNMENT_INTERNAL => 'sent_to_contractor_internal',
                RentalWorkOrder::ASSIGNMENT_OWNER_CONTRACTOR => 'sent_to_contractor_owner',
                default => 'sent_to_contractor',
            } : 'sent_to_contractor';
            // The agency's own contractor is only "sent" when the office actually sends them the work order (after the quote and the
            // owner's authorisation) - a work order that still waits on a quote or the owner's answer has not been sent to anyone.
            // The internal crew and the owner's own contractor are engaged from the moment the work order exists. If a later step has
            // already happened (e.g. an appointment booked early) the hand-over obviously has too.
            $appt = $wo ? app(RentalWorkOrderClientViewService::class)->appointmentAt($wo) : null;
            $handedOverAt = null;
            if ($wo) {
                $handedOverAt = $wo->assignment_type === RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER
                    ? ($wo->ordered_at ?? (in_array($wo->status, [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_DISPUTED], true) ? $wo->updated_at : null)
                        ?? ($appt ? ($wo->appointment_set_at ?? $appt) : null))
                    : $wo->created_at;
            }
            $push('sent_to_contractor', $variant, $handedOverAt, null, null, $wo?->id);

            // 6 - the appointment: date, time and who is coming.
            $apptDetail = null;
            if ($wo && $appt) {
                $apptDetail = $appt->copy()->setTimezone($tz)->format('D j M Y, H:i') . ' — ' . $this->whoIsComing($wo, $aud);
            }
            $push('appointment_set', 'appointment_set', $appt ? ($wo->appointment_set_at ?? $appt) : null, $apptDetail, null, $wo?->id);

            // 7 - work under way.
            $card = $wo ? RentalJobCard::withoutGlobalScopes()->where('agency_id', $wo->agency_id)->whereNull('deleted_at')->where('rental_work_order_id', $wo->id)->orderByDesc('id')->first() : null;
            $started = $wo && (in_array($wo->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_DISPUTED], true)
                || ($card && $card->status === RentalJobCard::STATUS_IN_PROGRESS));
            $push('in_progress', 'in_progress', $started ? ($this->startedAt($wo) ?? $wo->updated_at) : null, null, null, $wo?->id);

            // 8 - finished; while the tenant is asked to check, that is said and offered.
            $round = $wo ? RentalWorkCompletionRound::withoutGlobalScopes()->where('agency_id', $wo->agency_id)->where('rental_work_order_id', $wo->id)->orderByDesc('round_no')->first() : null;
            $awaiting = $round && $round->outcome === RentalWorkCompletionRound::OUTCOME_AWAITING_TENANT;
            $closedByFault = ! $wo && $fault->status === RentalFaultReport::STATUS_RESOLVED;
            if ($wo && $wo->status === RentalWorkOrder::STATUS_DISPUTED) {
                // Not finished after all: the plain "being put right" line replaces the completed step for now.
                $push('reopened', 'reopened', $round?->responded_at ?? $wo->updated_at, null, null, $wo->id);
            } elseif ($awaiting && $wo->status !== RentalWorkOrder::STATUS_COMPLETED) {
                $push('completed', 'completed_check', $round->opened_at, null, $aud === self::AUDIENCE_TENANT ? 'check' : null, $wo->id);
            } elseif ($wo && $wo->status === RentalWorkOrder::STATUS_COMPLETED) {
                $push('completed', 'completed', $wo->completed_at ?? $wo->updated_at, null, null, $wo->id);
            } elseif ($closedByFault) {
                $push('completed', 'closed', $fault->resolved_at ?? $fault->updated_at);
            } else {
                $push('completed', 'completed', null, null, null, $wo?->id);
            }
        }

        if ($cancelled) {
            $ended = 'cancelled';
            $at = $wo && $wo->status === RentalWorkOrder::STATUS_CANCELLED ? $wo->cancelled_at : $fault->cancelled_at;
            $push('cancelled', 'cancelled', $at ?? now());
        }

        // The current step = the last one reached; everything after it stays visible but not done.
        $last = null;
        foreach ($steps as $i => $s) {
            if ($s['state'] === 'done') {
                $last = $i;
            }
        }
        if ($last !== null) {
            $steps[$last]['current'] = true;
        }
        // A declined fault ends at its decision: no later steps are shown at all.
        if ($ended === 'not_approved') {
            $steps = array_values(array_filter($steps, fn ($s) => in_array($s['key'], ['sent_to_agent', 'agent_reviewing', 'sent_to_owner', 'owner_decided'], true)));
        }
        // A reopened step replaces "completed"; mark the cancelled line current.
        if ($ended === 'cancelled') {
            foreach ($steps as $i => $s) {
                $steps[$i]['current'] = $s['key'] === 'cancelled';
            }
        }

        $currentStep = collect($steps)->firstWhere('current', true) ?? end($steps);

        return [
            'steps' => $steps,
            'current' => $currentStep['key'],
            'current_label' => $currentStep['label'],
            'ended' => $ended,
        ];
    }

    /** The key a notification de-duplicates on: the current step AND which variant of it (approved vs not). */
    public function currentKey(RentalFaultReport $fault): string
    {
        $p = $this->forFault($fault, self::AUDIENCE_AGENT);
        $cur = collect($p['steps'])->firstWhere('current', true);
        $key = $cur['key'] ?? 'sent_to_agent';

        return $key === 'owner_decided' ? ($p['ended'] === 'not_approved' ? 'not_approved' : 'owner_approved') : $key;
    }

    public function label(string $labelKey, string $audience): string
    {
        return (string) (config("rental-fault-progress.{$labelKey}.{$audience}")
            ?? config("rental-fault-progress.{$labelKey}.agent")
            ?? ucfirst(str_replace('_', ' ', $labelKey)));
    }

    private function whoIsComing(RentalWorkOrder $wo, string $audience): string
    {
        return match (true) {
            $wo->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL => 'our maintenance team',
            $wo->isOwnerContractor() => ($audience === self::AUDIENCE_OWNER ? 'your contractor' : "the owner's contractor") . ($wo->contractor_name ? ' ' . $wo->contractor_name : ''),
            default => ($wo->supplier()->withoutGlobalScopes()->withTrashed()->first()?->name ?: 'the contractor arranged by the agency'),
        };
    }

    private function startedAt(RentalWorkOrder $wo): ?Carbon
    {
        $at = $wo->updates()->withoutGlobalScopes()->where('to_status', RentalWorkOrder::STATUS_IN_PROGRESS)->orderBy('id')->value('created_at');

        return $at ? Carbon::parse($at) : null;
    }

    private function firstUpdateAt(RentalFaultReport $fault): ?Carbon
    {
        $at = RentalFaultReportUpdate::withoutGlobalScopes()->where('rental_fault_report_id', $fault->id)->orderBy('id')->value('created_at');

        return $at ? Carbon::parse($at) : null;
    }

    private function timezone(RentalFaultReport $fault): string
    {
        $agency = Agency::withoutGlobalScopes()->find($fault->agency_id);

        return $agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    }
}
