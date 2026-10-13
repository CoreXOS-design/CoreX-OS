<?php

namespace App\Services\Rentals;

use App\Models\RentalApprovalDecision;
use App\Models\RentalWorkOrder;
use App\Support\AppDate;

/**
 * .ai/specs/rentals-faults-work-orders.md section 24 - what the staff work order screen SAYS about where a job stands: the "Where this job is" strip,
 * the "Next" sentence and its one button, and the one-line summary on each collapsible panel. A pure READ of existing model state - it writes nothing,
 * decides nothing and sends nothing; every button it names submits or opens something that already existed on the page.
 *
 * $c (what the screen already holds): proceed (authoriseToProceed), selectedQuote, costActive, costRepair, costMeasure, js (contractorStatus), finishedRound,
 * can (permission map: create, record_approval, complete, manage_completion).
 */
final class RentalWorkOrderScreen
{
    public static function money(float|int|string|null $n): string
    {
        return 'R' . number_format((float) $n, 2, '.', ',');
    }

    /** @return array{steps: array, next: array, panels: array, tz: string, cancelled: bool} */
    public function build(RentalWorkOrder $wo, array $c): array
    {
        $tz = $wo->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $fmt = fn ($d) => $d ? AppDate::dateTime($d, $tz) : null;
        $proceed = $c['proceed'];
        $js = $c['js'] ?? ['provider' => null, 'sent' => null, 'response' => null, 'response_label' => '', 'decline_reason' => null, 'can_send' => false, 'contact' => ['has_contact' => false]];
        $selected = $c['selectedQuote'] ?? null;
        $costActive = $c['costActive'] ?? null;
        $repair = $c['costRepair'] ?? ['agent_can' => false];
        $can = $c['can'] ?? [];
        $internal = $wo->assignment_type === RentalWorkOrder::ASSIGNMENT_INTERNAL;
        $ownerC = $wo->isOwnerContractor();
        $cancelled = $wo->status === RentalWorkOrder::STATUS_CANCELLED;
        $completed = $wo->status === RentalWorkOrder::STATUS_COMPLETED;
        $contractor = $wo->contractorLabel() ?? ($js['provider']?->name ?? 'the contractor');
        $who = app(RentalWhoPaysService::class);
        $fault = $who->faultFor($wo);
        $ruling = $fault ? $who->currentRuling($wo) : null;
        $hold = app(RentalOwnerHoldService::class)->activeFor($wo);
        $variation = $wo->openVariation();
        $finished = $c['finishedRound'] ?? null;
        $sentToOwner = RentalApprovalDecision::query()->where('rental_work_order_id', $wo->id)
            ->where('decision', RentalApprovalDecision::DECISION_NEEDS_OWNER)->orderByDesc('id')->first()?->created_at;
        // the go-ahead = the work order PDF emailed to the contractor (RentalWorkOrderService::sendContractorWorkOrder writes a 'work_order_sent' update; a failed send says so). NOT the job link:
        // sending the JOB (the contractor's own link, to quote) already moves the work order to "ordered".
        $goAheadAt = $wo->updates->where('update_type', 'work_order_sent')->filter(fn ($u) => ! str_contains((string) $u->note, 'could not'))->sortByDesc('created_at')->first()?->created_at;
        $started = in_array($wo->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED], true);
        $goAheadDone = $goAheadAt !== null || $started;
        $goAheadAt ??= $started ? $wo->ordered_at : null;
        $beganAt = $wo->updates->where('to_status', RentalWorkOrder::STATUS_IN_PROGRESS)->sortBy('created_at')->first()?->created_at;
        $costAt = $costActive?->isAuthorised() ? ($costActive->told_at ?? $costActive->created_at) : ($wo->approvals->first()?->decided_at);
        $inProgressNow = in_array($wo->status, [RentalWorkOrder::STATUS_IN_PROGRESS, RentalWorkOrder::STATUS_COMPLETED], true);

        // ── B. the strip: only the steps that apply to THIS job ──────────────────────────────────────────────────────────────────
        $steps = [['reported', 'Reported', true, $fmt($wo->reported_at)]];
        if (! $internal && ! $ownerC) {
            $steps[] = ['contractor_sent', 'Contractor sent', (bool) $js['sent'], $fmt($js['sent']?->sent_at)];
            $steps[] = ['quote_in', 'Quote in', $wo->quotes->isNotEmpty(), $fmt($wo->quotes->sortBy('created_at')->first()?->created_at)];
        }
        if (! $ownerC) {
            $steps[] = ['cost', 'Cost approved', (bool) $proceed->authorised && ($started || $costActive?->isAuthorised() || $wo->hasApprovedBaseline() || $wo->approved_amount !== null), $fmt($costAt)];
        }
        if (! $internal && ! $ownerC) {
            $steps[] = ['go_ahead', 'Go-ahead sent', $goAheadDone, $fmt($goAheadAt)];
        }
        $steps[] = ['in_progress', 'Work in progress', $inProgressNow, $fmt($beganAt)];
        $steps[] = ['completed', 'Completed', $completed, $fmt($wo->completed_at)];
        if ($fault) {
            $steps[] = ['who_pays', 'Who pays ruled', (bool) $ruling, $fmt($ruling?->ruled_at)];
        }
        $lastDone = -1;
        foreach ($steps as $i => $st) {
            if ($st[2]) {
                $lastDone = $i;
            }
        }
        $out = [];
        foreach ($steps as $i => [$key, $label, $done, $at]) {
            $out[] = ['key' => $key, 'label' => $label, 'state' => $done ? 'done' : ($i === $lastDone + 1 && ! $cancelled ? 'current' : 'todo'), 'at' => $done ? $at : null];
        }

        // ── C. the Next bar: one sentence, at most one button ────────────────────────────────────────────────────────────────────
        $btnGo = fn (string $label, string $panel, ?string $click = null) => ['kind' => 'go', 'label' => $label, 'panel' => $panel, 'click' => $click];
        $btnForm = fn (string $label, string $form) => ['kind' => 'form', 'label' => $label, 'form' => $form];
        $amount = $selected ? self::money($selected->ownerFacingAmount()) : null;
        $next = ['key' => 'none', 'tone' => 'info', 'text' => '', 'button' => null, 'panel' => 'history'];

        if ($cancelled) {
            $next = ['key' => 'cancelled', 'tone' => 'muted', 'text' => 'Cancelled ' . $fmt($wo->cancelled_at) . ($wo->cancelledByUser ? ' by ' . $wo->cancelledByUser->name : '') . ($wo->cancel_reason ? ': ' . $wo->cancel_reason : '.'), 'button' => null, 'panel' => ''];
        } elseif ($completed) {
            if ($fault && ! $ruling) {
                $next = ['key' => 'rule_who_pays', 'tone' => 'warn', 'text' => 'Completed ' . $fmt($wo->completed_at) . '. Rule who pays.', 'panel' => 'whopays',
                    'button' => ($can['manage_completion'] ?? false) ? $btnGo('Rule who pays', 'whopays', '[data-wp-rule-open]') : null];
            } else {
                $next = ['key' => 'done', 'tone' => 'ok', 'text' => 'Completed ' . $fmt($wo->completed_at) . '.' . ($ruling ? ' Who pays: ' . $ruling->summary() . '.' : ''), 'button' => null, 'panel' => ''];
            }
        } elseif ($hold) {
            $pauseFault = app(RentalOwnerHoldService::class)->jobOf($wo)[0] ?? null;
            $next = ['key' => 'on_hold', 'tone' => 'danger', 'text' => 'The owner has put this job on hold. Nothing can be sent, approved or started until the owner says go ahead.', 'panel' => 'history',
                'button' => $pauseFault ? ['kind' => 'link', 'label' => 'Open the fault to resume it', 'href' => route('corex.rental-fault-reports.show', $pauseFault->id)] : null];
        } elseif ($variation) {
            $next = ['key' => 'extra_waiting', 'tone' => 'warn', 'text' => 'Extra work of ' . self::money($variation->extra_amount) . ' is waiting for the owner (new total ' . self::money($variation->new_total) . '). The job cannot close until the owner decides.', 'panel' => 'cost',
                'button' => ($can['record_approval'] ?? false) ? $btnGo("Record the owner's reply", 'cost', '#variation-panel summary') : null];
        } elseif ($finished && in_array($wo->status, [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS], true)) {
            $by = $finished->reported_via === \App\Models\RentalWorkCompletionRound::VIA_OWNER_PORTAL ? 'The owner' : ($finished->reported_by_label ?: 'The contractor');
            $next = ['key' => 'reported_finished', 'tone' => 'warn', 'text' => $by . ' reported the work finished on ' . $fmt($finished->opened_at) . '. Check it and complete the job.', 'panel' => 'completion',
                'button' => ($can['complete'] ?? false) ? $btnGo('Complete', 'completion', '[data-complete-work-order]') : null];
        } elseif ($wo->status === RentalWorkOrder::STATUS_DISPUTED) {
            $next = ['key' => 'disputed', 'tone' => 'danger', 'text' => 'The tenant says the work is not fixed. It has been sent back - waiting for ' . $contractor . ' to report it fixed.', 'panel' => 'completion',
                'button' => $btnGo('Open the completion check', 'completion')];
        } elseif ($wo->status === RentalWorkOrder::STATUS_IN_PROGRESS) {
            $next = ['key' => 'in_progress', 'tone' => 'info', 'text' => 'Work is in progress.' . ($internal ? ' The crew works from the job card.' : ''), 'panel' => 'completion',
                'button' => $internal ? ($wo->jobCard ? ['kind' => 'link', 'label' => 'Open the job card', 'href' => route('corex.rental-job-cards.show', $wo->jobCard)] : null)
                    : (($can['complete'] ?? false) ? $btnGo('Complete', 'completion', '[data-complete-work-order]') : null)];
        } elseif ($ownerC && $wo->status === RentalWorkOrder::STATUS_ORDERED) {
            $next = ['key' => 'owner_arranges', 'tone' => 'info', 'text' => 'The owner arranges and pays their own contractor - nothing is sent from here. Mark it in progress when the work starts.', 'panel' => 'completion',
                'button' => ($can['create'] ?? false) ? $btnForm('Mark in progress', 'wo-start-progress-form') : null];
        } elseif ($internal) {
            $m = $c['costMeasure'] ?? ['amount' => null];
            if ($proceed->authorised && ($costActive?->isAuthorised() || $wo->approved_amount !== null || $wo->hasApprovedBaseline())) {
                $next = ['key' => 'crew_approved', 'tone' => 'ok', 'text' => 'Cost approved. Our crew does the job from the job card.', 'panel' => 'jobcard',
                    'button' => $wo->jobCard ? ['kind' => 'link', 'label' => 'Open the job card', 'href' => route('corex.rental-job-cards.show', $wo->jobCard)] : null];
            } elseif ($wo->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING || ($m['amount'] !== null && ! ($repair['agent_can'] ?? false))) {
                $next = ['key' => 'cost_owner', 'tone' => 'warn', 'text' => 'Our crew price of ' . self::money($m['amount']) . ' must be approved by the owner.' . ($sentToOwner ? ' Sent to the owner ' . $fmt($sentToOwner) . '.' : ''), 'panel' => 'cost',
                    'button' => ($can['record_approval'] ?? false) ? $btnGo("Record the owner's decision", 'cost', '[data-cost-owner-decision-open]') : null];
            } elseif ($m['amount'] !== null) {
                $next = ['key' => 'cost_inside_limit', 'tone' => 'info', 'text' => 'Our crew price of ' . self::money($m['amount']) . " is inside the owner's repair limit.", 'panel' => 'cost',
                    'button' => ($can['record_approval'] ?? false) ? $btnGo('Authorise ' . self::money($m['amount']), 'cost', '[data-cost-authorise]') : null];
            } else {
                $next = ['key' => 'cost_none', 'tone' => 'info', 'text' => 'No price yet. Price the job card, or approve a small amount by typing it.', 'panel' => 'cost',
                    'button' => $btnGo('Approve the cost', 'cost')];
            }
        } elseif ($ownerC) {
            $next = ['key' => 'owner_arranges', 'tone' => 'info', 'text' => 'The owner arranges and pays their own contractor - nothing is sent from here.', 'panel' => 'contractor', 'button' => null];
        } else {
            // outside contractor, still at "Reported"
            $declined = $wo->owner_approval_status === RentalWorkOrder::APPROVAL_DECLINED;
            $authorised = $proceed->authorised && ($costActive?->isAuthorised() || $wo->approved_amount !== null || $wo->hasApprovedBaseline());
            $declinedQuote = $declined ? ($wo->quotes->whereNotNull('declined_at')->sortByDesc('declined_at')->first() ?? $selected) : null;
            if ($declined) {
                $next = ['key' => 'owner_declined', 'tone' => 'danger', 'text' => 'The owner declined the quote' . ($declinedQuote ? ' (' . ($declinedQuote->supplier?->name ?? 'supplier') . ', ' . self::money($declinedQuote->ownerFacingAmount()) . ')' : '')
                    . ($declinedQuote?->decline_reason ? ': ' . $declinedQuote->decline_reason : '.') . ' Capture another quote, or cancel the work order - that closes the fault as "owner declined".', 'panel' => 'quotes',
                    'button' => $btnGo('Capture a quote', 'quotes', '[data-capture-quote-toggle]')];
            } elseif ($authorised && $goAheadAt && $wo->status === RentalWorkOrder::STATUS_ORDERED) {
                $next = ['key' => 'go_ahead_sent', 'tone' => 'ok', 'text' => 'Approved. Go-ahead sent to ' . $contractor . ' ' . $fmt($goAheadAt) . '.', 'panel' => 'completion',
                    'button' => ($can['create'] ?? false) ? $btnForm('Mark in progress', 'wo-start-progress-form') : null];
            } elseif ($authorised) {
                $next = ['key' => 'authorised', 'tone' => 'ok', 'text' => 'Approved' . ($selected ? ' (' . $amount . ')' : '') . '. The go-ahead has not been sent to ' . $contractor . ' yet.', 'panel' => 'contractor',
                    'button' => ($can['create'] ?? false) && $selected ? $btnForm('Send the go-ahead', 'wo-go-ahead-form') : null];
            } elseif ($selected && $wo->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING) {
                $next = ['key' => 'waiting_owner', 'tone' => 'warn', 'text' => 'Quote ' . $amount . ' is in. The owner must approve it' . ($sentToOwner ? ' - sent to the owner ' . $fmt($sentToOwner) : '') . '.', 'panel' => 'cost',
                    'button' => ($can['record_approval'] ?? false) ? $btnGo("Record the owner's decision", 'cost', '[data-cost-owner-decision-open]') : null];
            } elseif ($selected && ($repair['agent_can'] ?? false)) {
                $next = ['key' => 'quote_inside_limit', 'tone' => 'info', 'text' => 'Quote ' . $amount . " is inside the owner's repair limit.", 'panel' => 'cost',
                    'button' => ($can['record_approval'] ?? false) ? $btnGo('Authorise ' . $amount, 'cost', '[data-cost-authorise]') : null];
            } elseif ($selected) {
                $next = ['key' => 'quote_needs_owner', 'tone' => 'warn', 'text' => 'Quote ' . $amount . " is above the owner's repair limit (or none is set), so the owner decides.", 'panel' => 'cost',
                    'button' => ($can['record_approval'] ?? false) ? $btnGo("Record the owner's decision", 'cost', '[data-cost-owner-decision-open]') : null];
            } elseif ($wo->quotes->isNotEmpty()) {
                $next = ['key' => 'quote_not_selected', 'tone' => 'warn', 'text' => 'No quote chosen yet - the owner has not been asked. Choose one of the quotes.', 'panel' => 'quotes',
                    'button' => $btnGo('Choose a quote', 'quotes')];
            } elseif (! $js['provider']) {
                $next = ['key' => 'no_contractor', 'tone' => 'warn', 'text' => 'No contractor has been chosen for this job yet.', 'panel' => 'contractor',
                    'button' => ($can['create'] ?? false) ? $btnGo('Choose the contractor', 'contractor') : null];
            } elseif ($js['response'] === 'declined') {
                $next = ['key' => 'contractor_declined', 'tone' => 'danger', 'text' => $js['provider']->name . ' declined the job' . ($js['decline_reason'] ? ': ' . $js['decline_reason'] : '.'), 'panel' => 'contractor',
                    'button' => ($can['create'] ?? false) ? $btnGo('Choose a different contractor', 'contractor', '[data-choose-contractor]') : null];
            } elseif (! $js['sent'] && ! $js['can_send']) {
                $next = ['key' => 'cannot_send', 'tone' => 'warn', 'text' => $js['provider']->name . ' has no email or mobile number on file, so the job cannot be sent.', 'panel' => 'contractor',
                    'button' => $btnGo('Add their details', 'contractor')];
            } elseif (! $js['sent']) {
                $next = ['key' => 'not_sent', 'tone' => 'warn', 'text' => 'The job has not been sent to ' . $js['provider']->name . ' yet.', 'panel' => 'contractor',
                    'button' => ($can['create'] ?? false) ? $btnForm('Send the job to ' . $js['provider']->name, 'wo-send-job-form') : null];
            } else {
                $answer = $js['response'] === 'accepted' ? $js['provider']->name . ' accepted the job and is preparing a quote.' : 'Waiting for ' . $js['provider']->name . ' to accept or quote.';
                $next = ['key' => 'waiting_contractor', 'tone' => 'info', 'text' => $answer, 'panel' => 'contractor',
                    'button' => ($can['create'] ?? false) && $js['can_send'] ? $btnForm('Send the job again', 'wo-send-job-form') : null];
            }
        }

        // ── E. one line for each panel header, so a closed panel still reads ──────────────────────────────────────────────────────
        $panels = [
            'contractor' => $internal ? 'Our maintenance team'
                : ($ownerC ? "Owner's own contractor" . ($wo->contractor_name ? ': ' . $wo->contractor_name : '')
                : ($js['provider'] ? $js['provider']->name . ' - ' . ($goAheadDone ? 'go-ahead sent' . ($goAheadAt ? ' ' . $fmt($goAheadAt) : '')
                    : ($js['sent'] ? 'job sent ' . $fmt($js['sent']->sent_at) . ' - ' . ($js['response_label'] ?: 'no answer yet') : 'job not sent')) : 'No contractor chosen yet')),
            'jobcard' => $wo->jobCard ? ucfirst(str_replace('_', ' ', $wo->jobCard->status)) . ($wo->jobCard->crew?->name ? ' - ' . $wo->jobCard->crew->name : '') : 'No job card',
            'quotes' => $wo->quotes->isEmpty() ? 'No quotes yet' : $wo->quotes->count() . ($wo->quotes->count() === 1 ? ' quote' : ' quotes') . ($selected ? ' - selected ' . $amount . ' from ' . ($selected->supplier?->name ?? 'the contractor') : ' - none selected'),
            'cost' => $variation ? 'Extra work waiting for the owner'
                : ($costActive && $costActive->isAuthorised() ? 'Authorised ' . ($costActive->amount_up_to !== null ? self::money($costActive->amount_up_to) : '') : ($proceed->authorised && $wo->approved_amount !== null ? 'Approved ' . self::money($wo->approved_amount)
                : ($costActive ? 'Declined by the owner' : ($wo->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING ? 'Waiting for the owner' : ($wo->owner_approval_status === RentalWorkOrder::APPROVAL_DECLINED ? 'Declined by the owner' : 'Not authorised yet'))))),
            'appointment' => $wo->appointment_at ? 'Booked ' . $fmt($wo->appointment_at) : 'Not booked',
            'completion' => $completed ? 'Completed ' . $fmt($wo->completed_at) : ($finished ? 'Reported finished ' . $fmt($finished->opened_at) : ($wo->status === RentalWorkOrder::STATUS_IN_PROGRESS ? 'In progress' : ($wo->status === RentalWorkOrder::STATUS_DISPUTED ? 'Sent back - not fixed' : 'Not started'))),
            'whopays' => $ruling ? $ruling->summary() . ' - ruled ' . $fmt($ruling->ruled_at) : ($who->hasFindings($wo) ? 'Findings recorded - not ruled yet' : 'Not ruled yet'),
            'invoices' => ($n = $wo->invoices()->count()) === 0 ? 'None filed' : $n . ($n === 1 ? ' invoice' : ' invoices') . ' - ' . self::money(app(RentalWorkOrderInvoiceService::class)->total($wo)),
            'photos' => ($n = $wo->photos->count()) === 0 ? 'No photos' : $n . ($n === 1 ? ' photo' : ' photos'),
            'history' => ($h = $wo->history()->last()) ? $fmt($h['at']) . ' - ' . $h['action'] : 'No history yet',
        ];

        return ['steps' => $out, 'next' => $next, 'panels' => $panels, 'tz' => $tz, 'cancelled' => $cancelled];
    }
}
