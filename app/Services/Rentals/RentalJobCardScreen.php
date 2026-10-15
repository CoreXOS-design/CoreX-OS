<?php

namespace App\Services\Rentals;

use App\Models\RentalAuthorisation;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobDispatch;
use App\Models\RentalWorkOrder;
use App\Support\AppDate;

/**
 * AT-460 - what the OFFICE job card screen SAYS about where the card stands: the stepper, the "Returned" side of the bar (the latest thing that
 * came back into the office), the "Next" side (one sentence, one primary button, at most one secondary) and the "Authorised" fact of the money
 * card. A pure READ of existing state - it writes nothing and decides nothing; every button it names submits or opens something that already
 * exists on the page. Which stage the card is in and what can be pressed comes from RentalJobCardStageService (passed in as $stage).
 *
 * $c (what the screen already holds): can (permission map: send_quote, share, create, sign_off, record_approval), awaitingLines, quoteChanged,
 * quoteRevisions, crewSent, crewLinksEnabled, quoteFormOn (the send-quote form is on the page: permission + a landlord + no approved baseline).
 */
final class RentalJobCardScreen
{
    /** @return array{steps: array, returned: array, next: array, authorised: array, tz: string} */
    public function build(RentalJobCard $card, array $stage, array $c): array
    {
        $tz = $card->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $fmt = fn ($d) => $d ? AppDate::dateTime($d, $tz) : null;
        $wo = $card->workOrder;
        $costActive = $wo ? app(RentalAuthorisationService::class)->activeFor($wo, RentalAuthorisation::POINT_COST) : null;

        return [
            'tz' => $tz,
            'steps' => $this->steps($card, $costActive, $c, $fmt),
            'returned' => $this->returned($card, $stage, $costActive, $c, $fmt),
            'next' => $this->next($card, $stage, $c, $fmt),
            'authorised' => $this->authorised($card, $costActive, $fmt),
        ];
    }

    private static function money(float|int|string|null $n): string
    {
        return 'R' . number_format((float) $n, 2, '.', ',');
    }

    /** First time the card moved INTO $status (from its history), else null. */
    private function reached(RentalJobCard $card, string $status)
    {
        return $card->updates->where('update_type', 'status_change')->where('to_status', $status)->sortBy('created_at')->first()?->created_at;
    }

    // ── the stepper ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function steps(RentalJobCard $card, ?RentalAuthorisation $costActive, array $c, \Closure $fmt): array
    {
        $status = $card->status;
        $cancelled = $status === RentalJobCard::STATUS_CANCELLED;
        $disputed = $status === RentalJobCard::STATUS_DISPUTED;
        $rank = ['draft' => 0, 'quoted' => 1, 'approved' => 2, 'scheduled' => 3, 'in_progress' => 4, 'disputed' => 4, 'completed' => 5];
        $at = $rank[$status] ?? -1;
        $wo = $card->workOrder;

        $quotedAt = $this->reached($card, RentalJobCard::STATUS_QUOTED) ?? ($c['quoteRevisions'] ?? collect())->sortBy('created_at')->first()?->created_at;
        $approvedAt = ($costActive?->isAuthorised() ? ($costActive->told_at ?? $costActive->created_at) : null)
            ?? $this->reached($card, RentalJobCard::STATUS_APPROVED) ?? $wo?->approvals->first()?->decided_at;
        $beganAt = $this->reached($card, RentalJobCard::STATUS_IN_PROGRESS);
        $disputeAt = $card->updates->where('update_type', 'dispute_opened')->sortByDesc('created_at')->first()?->created_at;

        // A cancelled card keeps whatever it had reached (its history says so); an open one goes by where its status is.
        $steps = [
            ['quoted', 'Quoted', $cancelled ? $quotedAt !== null : $at >= 1, $quotedAt],
            ['approved', 'Owner approved', $cancelled ? $approvedAt !== null : $at >= 2, $approvedAt],
            ['booked', 'Booked', $cancelled ? $card->scheduled_at !== null : $at >= 3, $card->scheduled_at],
            ['in_progress', 'In progress', $cancelled ? $beganAt !== null : $at >= 4, $beganAt],
            ['completed', 'Completed', $status === RentalJobCard::STATUS_COMPLETED, $card->completed_at],
        ];
        if ($disputed || $disputeAt !== null) {
            $steps[] = ['disputed', 'Disputed', $disputed, $disputeAt];
        }

        $lastDone = -1;
        foreach ($steps as $i => $st) {
            if ($st[2] && $st[0] !== 'disputed') {
                $lastDone = $i;
            }
        }
        $out = [];
        foreach ($steps as $i => [$key, $label, $done, $when]) {
            $state = $done ? 'done' : 'todo';
            if (! $done && ! $cancelled && ! $disputed && $key !== 'disputed' && $i === $lastDone + 1) {
                $state = 'current';
            }
            if ($disputed && $key === 'disputed') {
                $state = 'current';
            }
            $out[] = ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $done ? $fmt($when) : null];
        }

        return $out;
    }

    // ── "Returned": the latest thing that came back into the office ────────────────────────────────────────────────────────────────────

    private function returned(RentalJobCard $card, array $stage, ?RentalAuthorisation $costActive, array $c, \Closure $fmt): array
    {
        $events = collect();
        $open = ! $card->isClosed();
        $s = fn (int $n, string $one, string $many) => $n . ' ' . ($n === 1 ? $one : $many);

        // 1. the crew sent lines (or prices): how many, and what became of them
        $crewLines = $card->lines->filter(fn ($l) => $l->crew_added_at !== null && $l->office_status !== RentalJobCardLine::OFFICE_CREW_DRAFT);
        if ($crewLines->isNotEmpty()) {
            $sentAt = $card->updates->whereIn('update_type', ['crew_lines_sent', 'pricing_submitted'])->sortByDesc('created_at')->first()?->created_at
                ?? $crewLines->max('crew_added_at');
            $waiting = $crewLines->where('office_status', RentalJobCardLine::OFFICE_AWAITING)->count();
            $accepted = $crewLines->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED)->count();
            $rejected = $crewLines->where('office_status', RentalJobCardLine::OFFICE_REJECTED)->count();
            $parts = array_filter([
                $accepted ? $accepted . ' accepted' : null,
                $waiting ? $waiting . ' waiting' : null,
                $rejected ? $rejected . ' not accepted' : null,
            ]);
            $events->push([
                'at' => $sentAt, 'anchor' => 'jc-lines', 'waiting' => $waiting > 0, 'tone' => $waiting > 0 ? 'warn' : 'info',
                'text' => 'Crew sent ' . $s($crewLines->count(), 'line', 'lines') . ' ' . $fmt($sentAt) . ($parts ? ' - ' . implode(', ', $parts) : ''),
            ]);
        }

        // 2. the owner answered (on their link, or the agent recorded what they said)
        if ($costActive && in_array($costActive->basis, [RentalAuthorisation::BASIS_OWNER_LINK, RentalAuthorisation::BASIS_OWNER_INSTRUCTION], true)) {
            $when = $costActive->told_at ?? $costActive->created_at;
            $amount = $costActive->amount_up_to ?? $card->workOrder?->approved_amount ?? $stage['quote']['amount'];
            $how = $costActive->basis === RentalAuthorisation::BASIS_OWNER_LINK ? 'on the link' : 'by ' . strtolower(RentalAuthorisation::viaLabel($costActive->told_via));
            $events->push([
                'at' => $when, 'anchor' => 'jc-money', 'waiting' => false, 'tone' => $costActive->isAuthorised() ? 'ok' : 'danger',
                'text' => 'Owner ' . ($costActive->isAuthorised() ? 'approved ' . self::money($amount) : 'declined') . ' ' . $how . ' ' . $fmt($when),
            ]);
        }

        // 3. the crew marked the work done (their own link / page / a signed copy - not the office typing it in)
        if ($card->worker_signed_off_at && $card->worker_sign_off_via && $card->worker_sign_off_via !== RentalJobCard::SIGN_OFF_VIA_OFFICE) {
            $photos = $card->photos->where('photo_type', 'completed')->count();
            $events->push([
                'at' => $card->worker_signed_off_at, 'anchor' => 'jc-signoff', 'waiting' => $open && ! $card->agent_signed_off_at, 'tone' => 'warn',
                'text' => 'Crew marked complete ' . $fmt($card->worker_signed_off_at) . ($photos ? ', ' . $s($photos, 'photo', 'photos') : ''),
            ]);
        }

        // 4. the tenant said it is not complete
        $dispute = $card->updates->where('update_type', 'dispute_opened')->sortByDesc('created_at')->first();
        if ($dispute) {
            $events->push([
                'at' => $dispute->created_at, 'anchor' => 'jc-completion', 'waiting' => $card->status === RentalJobCard::STATUS_DISPUTED, 'tone' => 'danger',
                'text' => 'Tenant disputed ' . $fmt($dispute->created_at) . ($dispute->note ? ' - ' . \Illuminate\Support\Str::limit(preg_replace('/^Tenant reported the work as not complete:\s*/', '', (string) $dispute->note), 90) : ''),
            ]);
        } elseif ($card->tenant_confirmed_at && $card->tenant_confirmed_fixed !== null) {
            $events->push([
                'at' => $card->tenant_confirmed_at, 'anchor' => 'jc-completion', 'waiting' => false, 'tone' => $card->tenant_confirmed_fixed ? 'ok' : 'danger',
                'text' => ($card->tenant_confirmed_fixed ? 'Tenant confirmed the work is fixed ' : 'Tenant says the work is NOT complete ') . $fmt($card->tenant_confirmed_at),
            ]);
        }

        // the first thing still waiting on the office wins; otherwise the latest thing that came back
        $waitingNow = $events->where('waiting', true)->sortByDesc('at')->first();
        $pick = $waitingNow ?? $events->sortByDesc('at')->first();
        if ($pick) {
            return ['label' => 'Returned'] + $pick;
        }

        // nothing has come back: the last thing that happened
        $last = $card->history()->last();

        return [
            'label' => 'Last event', 'anchor' => 'jc-history', 'waiting' => false, 'tone' => 'muted', 'at' => $last['at'] ?? null,
            'text' => ($last['action'] ?? 'Logged') . ' ' . $fmt($last['at'] ?? null) . (! empty($last['actor']) ? ' - ' . $last['actor'] : ''),
        ];
    }

    // ── "Next": one sentence, one primary button, at most one secondary ────────────────────────────────────────────────────────────────

    private function next(RentalJobCard $card, array $stage, array $c, \Closure $fmt): array
    {
        $can = $c['can'] ?? [];
        $key = $stage['key'];
        $amount = $stage['quote']['amount'];
        $wo = $card->workOrder;
        $awaiting = ($c['awaitingLines'] ?? collect())->count();
        $form = fn (string $label, string $id) => ['kind' => 'form', 'label' => $label, 'form' => $id];
        $go = fn (string $label, string $target, ?string $click = null, ?string $focus = null) => ['kind' => 'go', 'label' => $label, 'target' => $target, 'click' => $click, 'focus' => $focus];
        $quoteButton = ($c['quoteFormOn'] ?? false) && $stage['can']['price'] ? $form($stage['quote']['button'], 'jc-send-quote-form') : null;
        $resendQuote = ($c['quoteFormOn'] ?? false) && $stage['can']['price'] && ($c['quoteChanged'] ?? false) ? $form('Re-send quote to owner', 'jc-send-quote-form') : null;
        $crewTold = (bool) ($c['crewSent'] ?? false);
        $crewSend = ($c['crewLinksEnabled'] ?? false) && ($can['share'] ?? false) && $stage['can']['crew_link']
            ? $form($crewTold ? 'Resend crew link' : 'Send the crew their link', 'jc-crew-send-form') : null;
        $when = $card->scheduled_at ? $fmt($card->scheduled_at) : null;
        $out = ['key' => $key, 'tone' => 'info', 'text' => '', 'button' => null, 'secondary' => null];

        switch ($key) {
            case 'draft':
                if (! $stage['has_lines']) {
                    $out = ['text' => 'Add what needs doing: a task with its parts and labour lines.', 'button' => $go('Add a line', '#jc-lines', null, '#jc-lines input[aria-label="Item"]'), 'tone' => 'warn'];
                } elseif ($stage['unpriced'] > 0) {
                    $out = ['text' => 'Price every line first (' . $stage['unpriced'] . ' ' . ($stage['unpriced'] === 1 ? 'line has' : 'lines have') . ' no price yet).', 'button' => $go('Price the lines', '#jc-lines'), 'tone' => 'warn'];
                } elseif (! $stage['prices_on']) {
                    $out = ['text' => 'Assign the crew and book a date.', 'button' => $go('Assign the crew', '#jc-crew', null, '[data-assign-crew-select]')];
                } else {
                    $out = ['text' => $stage['quote']['intent'] === 'confirm' ? 'Confirm the price: ' . self::money($amount) . '.' : 'Send the ' . self::money($amount) . ' quote to the owner for approval.', 'button' => $quoteButton, 'tone' => 'warn'];
                }
                break;
            case 'quoted':
                $pending = $wo?->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING;
                if ($stage['quote']['within'] && ! $pending) {
                    $out = ['text' => 'Price confirmed at ' . self::money($amount) . '. Authorise the cost to open booking.', 'tone' => 'warn',
                        'button' => ($can['record_approval'] ?? false) ? $go('Authorise ' . self::money($amount), '#jc-money', '[data-cost-authorise]') : null];
                } else {
                    $out = ['text' => 'Waiting for the owner to approve ' . self::money($amount) . '.', 'tone' => 'warn',
                        'button' => ($can['record_approval'] ?? false) ? $go("Record the owner's decision", '#jc-money', '[data-cost-owner-decision-open]') : null,
                        'secondary' => $resendQuote];
                }
                break;
            case 'owner_declined':
                $out = ['text' => 'The owner declined ' . self::money($amount) . '. Change the job and re-send it, or cancel the job card.', 'tone' => 'danger', 'button' => $quoteButton];
                break;
            case 'approved':
                if (! $card->rental_crew_id) {
                    $out = ['text' => 'Approved. Assign the crew, then book the date.', 'tone' => 'ok', 'button' => ($can['create'] ?? false) ? $go('Assign the crew', '#jc-crew', null, '[data-assign-crew-select]') : null];
                } else {
                    $out = ['text' => 'Approved. Book the date for the work.', 'tone' => 'ok',
                        'button' => ($can['create'] ?? false) ? $go('Book the date', '#jc-crew', null, '[name="scheduled_at"]') : null, 'secondary' => $crewSend];
                }
                break;
            case 'scheduled':
                $start = ($can['create'] ?? false) && $stage['can']['start'] ? $form('Mark in progress', 'jc-start-form') : null;
                if (! $crewTold && $crewSend) {
                    $out = ['text' => 'Booked' . ($when ? ' for ' . $when : '') . '. The crew has not been sent their link.', 'tone' => 'warn', 'button' => $crewSend, 'secondary' => $start];
                } else {
                    $out = ['text' => 'Booked' . ($when ? ' for ' . $when : '') . '. Mark it in progress when the crew starts.', 'tone' => 'ok', 'button' => $start, 'secondary' => $crewSend];
                }
                break;
            case 'in_progress':
                $sign = ($can['sign_off'] ?? false) && $stage['can']['worker_sign_off'] ? $go('Record the sign-off', '#jc-signoff') : null;
                $out = ['text' => 'The work is under way. Waiting for the crew to finish.', 'tone' => 'info', 'button' => $crewSend ?? $sign, 'secondary' => $crewSend ? $sign : null];
                break;
            case 'signed_off':
                if (! $card->agent_signed_off_at) {
                    $out = ['text' => 'The crew says it is done. Check the work, then sign it off.', 'tone' => 'warn',
                        'button' => ($can['sign_off'] ?? false) && $stage['can']['agent_sign_off'] ? $form('Agent sign-off', 'jc-agent-signoff-form') : null];
                } else {
                    $out = ['text' => 'Both sign-offs are in. Choose who pays and complete the job card.', 'tone' => 'ok',
                        'button' => ($can['sign_off'] ?? false) && $stage['can']['complete'] ? $go('Mark complete', '#jc-signoff', null, '#complete-paid-by') : null];
                }
                break;
            case 'disputed':
                $out = ['text' => 'The tenant says the work is not complete. It is back with the crew.', 'tone' => 'danger', 'button' => $go('Resolve dispute', '#jc-completion')];
                break;
            case 'completed':
                $out = ['text' => 'Completed' . ($card->completed_at ? ' ' . $fmt($card->completed_at) : '') . '.', 'tone' => 'ok'];
                break;
            default:
                $out = ['text' => 'Cancelled' . ($card->cancelled_at ? ' ' . $fmt($card->cancelled_at) : '') . ($card->cancel_reason ? ': ' . $card->cancel_reason : '') . '.', 'tone' => 'muted'];
        }

        // crew lines waiting for the office are always worth one click, whatever the stage
        if (($out['secondary'] ?? null) === null && $awaiting > 0 && $stage['open']) {
            $out['secondary'] = $go('Review ' . $awaiting . ' crew ' . ($awaiting === 1 ? 'line' : 'lines'), '#jc-lines');
        }

        return ['key' => $key, 'tone' => $out['tone'] ?? 'info', 'text' => $out['text'], 'button' => $out['button'] ?? null, 'secondary' => $out['secondary'] ?? null];
    }

    // ── the money card's "Authorised" fact ───────────────────────────────────────────────────────────────────────────────────────────────

    private function authorised(RentalJobCard $card, ?RentalAuthorisation $costActive, \Closure $fmt): array
    {
        $wo = $card->workOrder;
        if ($costActive) {
            $svc = app(RentalAuthorisationService::class);

            return ['key' => $costActive->isAuthorised() ? 'authorised' : 'declined', 'text' => $svc->recordTextFor($costActive)];
        }
        if ($wo && $wo->hasApprovedBaseline()) {
            return ['key' => 'authorised', 'text' => ($wo->approvalBasisLabel() ?: 'Approved') . ' - ' . self::money($wo->approved_amount) . ' (before authorisation records were kept).'];
        }
        if ($wo?->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING) {
            $sent = \App\Models\RentalApprovalDecision::query()->where('rental_work_order_id', $wo->id)->where('decision', \App\Models\RentalApprovalDecision::DECISION_NEEDS_OWNER)->orderByDesc('id')->first()?->created_at;

            return ['key' => 'waiting', 'text' => 'Waiting for the owner' . ($sent ? ' - sent ' . $fmt($sent) : '') . '.'];
        }
        if ($wo?->owner_approval_status === RentalWorkOrder::APPROVAL_DECLINED) {
            return ['key' => 'declined', 'text' => 'The owner declined.'];
        }

        return ['key' => 'none', 'text' => 'Not authorised yet.'];
    }
}
