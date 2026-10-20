<?php

namespace App\Services\Rentals;

use App\Models\RentalApproval;
use App\Models\RentalFaultReport;
use App\Support\AppDate;

/**
 * .ai/specs/rentals-faults-work-orders.md section 28 - what the OFFICE fault screen SAYS about where a fault stands: the "Where this fault is" strip, the
 * "Returned | Next" bar (what came back last, and the one next action) and the one-line summary on each folded panel. A pure READ of existing model state - it
 * writes nothing, decides nothing and sends nothing; every button it names submits, opens or presses something that already exists on the page.
 * Same shape as RentalWorkOrderScreen / RentalJobCardScreen.
 *
 * Stage keys: prepare (reported / under review), with_owner, appoint (owner approved), declined, work_order (a work order exists), closed.
 *
 * $c (what the screen already holds): can (send_to_owner, record_approval, raise_work_order), inspectBlock, recordBlock (string|null - why the owner's decision
 * cannot be recorded), canOpenWorkOrder (the agency has the work-order feature), ownerQueries (RentalOwnerHoldService::queriesFor), ownerHold.
 */
final class RentalFaultScreen
{
    /** @return array{stage: string, steps: array, returned: array, next: array, panels: array, tz: string} */
    public function build(RentalFaultReport $f, array $c): array
    {
        $tz = \App\Support\AppDate::agencyTimezone($f->agency);
        $fmt = fn ($d) => $d ? AppDate::dateTime($d, $tz) : null;
        $stage = self::stageOf($f);
        $decision = $f->decision();
        $wo = $f->rental_work_order_id ? $f->workOrder : null;

        return [
            'stage' => $stage,
            'tz' => $tz,
            'steps' => $this->steps($f, $stage, $decision, $wo, $fmt),
            'returned' => $this->returned($f, $stage, $decision, $wo, $c, $fmt),
            'next' => $this->next($f, $stage, $decision, $wo, $c, $fmt),
            'panels' => $this->panels($f, $stage, $decision, $fmt, $c),
        ];
    }

    /** Which stage the fault is in - the one place that decides it (the view used to carry its own copy). */
    public static function stageOf(RentalFaultReport $f): string
    {
        if (in_array($f->status, [RentalFaultReport::STATUS_RESOLVED, RentalFaultReport::STATUS_CANCELLED], true)) {
            return 'closed';
        }

        return match ($f->status) {
            RentalFaultReport::STATUS_REPORTED, RentalFaultReport::STATUS_UNDER_REVIEW => 'prepare',
            RentalFaultReport::STATUS_AWAITING_APPROVAL => 'with_owner',
            RentalFaultReport::STATUS_APPROVED, RentalFaultReport::STATUS_OWNER_HANDLING => 'appoint',
            RentalFaultReport::STATUS_DECLINED => 'declined',
            default => 'work_order',
        };
    }

    private static function name($contact): string
    {
        return $contact ? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) : '';
    }

    // ── the strip ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function steps(RentalFaultReport $f, string $stage, ?RentalApproval $decision, $wo, \Closure $fmt): array
    {
        $cancelled = $f->status === RentalFaultReport::STATUS_CANCELLED;
        $told = $f->arranged_by_agent_at;   // the agent went ahead and the owner was told, not asked
        $steps = [
            ['reported', 'Reported', true, $f->reported_at ?? $f->created_at],
            ['owner_asked', $told ? 'Owner told' : 'Owner asked', $f->sent_to_owner_at !== null || $told !== null || $decision !== null, $f->sent_to_owner_at ?? $told],
            ['owner_decided', 'Owner decided', $decision !== null || $told !== null, $decision ? ($decision->decided_at ?? $decision->created_at) : $told],
            ['work_order', 'Work order', $wo !== null || $f->status === RentalFaultReport::STATUS_WORK_ORDER_RAISED, $wo?->created_at],
            ['closed', 'Closed', $f->status === RentalFaultReport::STATUS_RESOLVED, $f->resolved_at],
        ];
        // a fault the owner declined, or one that never needed a work order, leaves that step out
        if ($stage === 'declined' || ($f->status === RentalFaultReport::STATUS_RESOLVED && $wo === null)) {
            $steps = array_values(array_filter($steps, fn ($s) => $s[0] !== 'work_order'));
        }
        if ($f->status === RentalFaultReport::STATUS_OWNER_HANDLING) {
            $steps = array_values(array_filter($steps, fn ($s) => $s[0] !== 'work_order'));
        }
        $lastDone = -1;
        foreach ($steps as $i => $st) {
            if ($st[2]) {
                $lastDone = $i;
            }
        }
        $out = [];
        foreach ($steps as $i => [$key, $label, $done, $at]) {
            $state = $done ? 'done' : (! $cancelled && $i === $lastDone + 1 ? 'current' : 'todo');
            $out[] = ['key' => $key, 'label' => $label, 'state' => $state, 'at' => $done ? $fmt($at) : null];
        }

        return $out;
    }

    // ── "Returned": what came back last ──────────────────────────────────────────────────────────────────────────────────────────────

    private function returned(RentalFaultReport $f, string $stage, ?RentalApproval $decision, $wo, array $c, \Closure $fmt): array
    {
        $svc = app(RentalAuthorisationService::class);
        $who = $f->reportedByContact ? self::name($f->reportedByContact) : ($f->reportedByUser?->name ?? '');
        $via = ucfirst(str_replace('_', ' ', (string) $f->reported_channel));
        $words = function (?string $t): string {
            $t = trim(preg_replace('/\s+/u', ' ', (string) $t));

            return $t === '' ? '' : ': "' . \Illuminate\Support\Str::limit($t, 140) . '"';
        };
        $base = fn (string $text, string $anchor, string $tone = 'info', bool $waiting = false) => ['label' => 'Returned', 'text' => $text, 'anchor' => $anchor, 'tone' => $tone, 'waiting' => $waiting];

        // an unanswered owner question always comes first: it is waiting on the office
        $open = collect($c['ownerQueries'] ?? [])->first(fn ($q) => ! $q->isAnswered());
        if ($open && $stage !== 'closed') {
            return $base('Owner asked ' . $fmt($open->asked_at) . ': "' . \Illuminate\Support\Str::limit(trim((string) $open->question), 120) . '"', 'owner-questions', 'warn', true);
        }
        if (! empty($c['ownerHold']) && $stage !== 'closed') {
            return $base('The owner has put this on hold - nothing can be sent, approved or started until they say go ahead.', 'owner-questions', 'danger', true);
        }

        switch ($stage) {
            case 'prepare':
                $comment = $f->ownerComment();
                $text = 'Reported ' . $fmt($f->reported_at ?? $f->created_at) . ($who !== '' ? ' by ' . $who : '') . ($via !== '' ? ' via ' . $via : '');

                return $base($text . ($comment ? '. Owner wrote: "' . \Illuminate\Support\Str::limit($comment['text'], 100) . '"' : ''), 'tenant');
            case 'with_owner':
                $comment = $f->ownerComment();
                if ($comment) {
                    return $base('Owner wrote ' . $fmt($comment['at']) . ': "' . \Illuminate\Support\Str::limit($comment['text'], 120) . '"', 'owner-comment');
                }

                return $base('Sent to the owner ' . $fmt($f->sent_to_owner_at) . ' - they have not answered yet.', 'decision', 'warn');
            case 'appoint':
            case 'declined':
                if ($decision) {
                    $row = $svc->activeFor($f, \App\Models\RentalAuthorisation::POINT_INSPECT);
                    $by = $decision->evidence_type === RentalApproval::EVIDENCE_PORTAL ? 'on their link' : 'by ' . strtolower(RentalApproval::evidenceLabel($decision->evidence_type));
                    $said = $words($decision->evidence_text);
                    $approved = $decision->decision === RentalApproval::DECISION_APPROVED;

                    return $base('Owner ' . ($approved ? 'approved' : 'declined') . ' ' . $by . ' ' . $fmt($decision->decided_at ?? $decision->created_at) . $said
                        . ($row && $row->also_cost_up_to !== null && $approved ? ' (cost up to ' . RentalWorkOrderScreen::money($row->also_cost_up_to) . ' on the same call)' : ''),
                        'records', $approved ? 'ok' : 'danger');
                }

                return $base('The owner ' . ($stage === 'declined' ? 'declined' : 'approved') . '.', 'records', $stage === 'declined' ? 'danger' : 'ok');
            case 'work_order':
                if ($wo) {
                    return $base('Work order #' . $wo->id . ' is ' . strtolower($wo->stageLabel('agent')) . '.', 'records', 'info');
                }

                return $base('A work order has been raised.', 'records', 'info');
            default:
                $last = $f->history()->last();
                $verb = $f->status === RentalFaultReport::STATUS_CANCELLED ? 'Cancelled ' . $fmt($f->cancelled_at) . ($f->cancel_reason ? ': ' . $f->cancel_reason : '')
                    : 'Closed ' . $fmt($f->resolved_at) . ($f->outcome ? ' - ' . str_replace('_', ' ', $f->outcome) : '');

                return $base($verb !== '' ? $verb . '.' : (($last['action'] ?? 'Logged') . ' ' . $fmt($last['at'] ?? null) . '.'), 'history', 'muted');
        }
    }

    // ── "Next": one sentence, one primary button, at most one secondary ────────────────────────────────────────────────────────────────

    private function next(RentalFaultReport $f, string $stage, ?RentalApproval $decision, $wo, array $c, \Closure $fmt): array
    {
        $can = $c['can'] ?? [];
        $go = fn (string $label, string $panel, ?string $click = null) => ['kind' => 'go', 'label' => $label, 'panel' => $panel, 'click' => $click];
        $link = fn (string $label, string $href) => ['kind' => 'link', 'label' => $label, 'href' => $href];
        $recordOk = ($can['record_approval'] ?? false) && ($c['recordBlock'] ?? null) === null;
        $record = $recordOk ? $go("Record the owner's decision", 'decision', '[data-owner-decision-open]') : null;
        $openWo = $wo && ($c['canOpenWorkOrder'] ?? false) ? $link('Open work order #' . $wo->id, route('corex.rental-work-orders.show', $wo->id)) : null;
        $hold = ! empty($c['ownerHold']);

        $out = ['text' => '', 'tone' => 'info', 'button' => null, 'secondary' => null];
        switch ($stage) {
            case 'prepare':
                $canSend = ($can['send_to_owner'] ?? false) && $f->sent_to_owner_at === null && empty($c['inspectBlock']);
                $out = [
                    'text' => $hold ? 'Paused by the owner. Use Resume on this page to record their go-ahead.' : 'Check what the owner will see, then send it to the owner - or send a contractor to look first.',
                    'tone' => 'warn',
                    'button' => $canSend ? $go('Send to the owner to authorise', 'decision', '[data-send-to-owner-to-authorise]') : $go('Who goes to look first?', 'decision'),
                    'secondary' => $record,
                ];
                break;
            case 'with_owner':
                $out = [
                    'text' => $hold ? 'Paused by the owner. Use Resume on this page to record their go-ahead.' : 'Waiting for the owner. If they have told you their decision, record it.',
                    'tone' => 'warn',
                    'button' => $record ?? $go('Who goes to look first?', 'decision'),
                ];
                break;
            case 'appoint':
                $ok = ($can['raise_work_order'] ?? false) && $f->workOrderBlockReason() === null;
                $out = [
                    'text' => $f->status === RentalFaultReport::STATUS_OWNER_HANDLING ? 'The owner is handling this themselves.' : 'The owner approved. Choose who does the work and the work order is made from this fault.',
                    'tone' => 'ok',
                    'button' => $ok ? $go('Create work order', 'appoint') : null,
                ];
                if ($f->status === RentalFaultReport::STATUS_OWNER_HANDLING) {
                    $out['button'] = null;
                    $out['tone'] = 'info';
                }
                break;
            case 'declined':
                $out = [
                    'text' => 'The owner declined, so the fault is closed. If they ring back and change their mind, change the decision here.',
                    'tone' => 'danger',
                    'button' => $recordOk ? $go('Owner changed their mind - change the decision', 'decision', '[data-owner-decision-open]') : null,
                ];
                break;
            case 'work_order':
                $out = [
                    'text' => $wo ? 'Work order #' . $wo->id . ' carries this fault now (' . strtolower($wo->stageLabel('agent')) . ').' : 'A work order carries this fault now.',
                    'tone' => 'info',
                    'button' => $openWo,
                ];
                break;
            default:
                $out = [
                    'text' => $f->status === RentalFaultReport::STATUS_CANCELLED ? 'This fault was cancelled. Nothing is left to do.' : 'This fault is closed. Nothing is left to do.',
                    'tone' => 'muted',
                    'button' => $openWo,
                ];
        }

        return ['key' => $stage, 'tone' => $out['tone'], 'text' => $out['text'], 'button' => $out['button'], 'secondary' => $out['secondary'] ?? null];
    }

    // ── one line for each folded panel ──────────────────────────────────────────────────────────────────────────────────────────────

    private function panels(RentalFaultReport $f, string $stage, ?RentalApproval $decision, \Closure $fmt, array $c): array
    {
        $who = $f->reportedByContact ? self::name($f->reportedByContact) : ($f->reportedByUser?->name ?? '');
        $sent = $f->sent_to_owner_at !== null;
        $ar = app(RentalAuthorisationService::class)->rowsFor($f);
        $latest = $ar->whereNull('superseded_at')->last();
        $last = $f->history()->reject(fn ($e) => in_array($e['action'], ['Note added', 'Logged'], true))->last();
        $n = $f->photos->count();

        return [
            'tenant' => trim(($f->typeName() ?: 'Fault') . ' - ' . ucfirst(str_replace('_', ' ', (string) $f->reported_channel)) . ' - ' . $fmt($f->reported_at ?? $f->created_at) . ($who !== '' ? ' - ' . $who : ''), ' -'),
            'ownercopy' => $sent ? 'Sent to the owner ' . $fmt($f->sent_to_owner_at) : ($f->owner_version_saved_at ? 'Saved ' . $fmt($f->owner_version_saved_at) . ' - not sent yet' : 'Not prepared yet - the owner sees your wording, not the tenant\'s'),
            'decision' => $f->hasLiveWorkOrder() ? 'A work order exists' : ($sent ? 'With the owner - no answer yet' : 'Nothing sent yet'),
            'records' => $ar->isEmpty() ? 'None yet' : ($latest ? ($latest->isAuthorised() ? 'Authorised' : 'Declined') . ' ' . $fmt($latest->told_at ?? $latest->created_at) : $ar->count() . ' records'),
            'outcome' => $f->outcome ? ucfirst(str_replace('_', ' ', $f->outcome)) . ($f->outcome_set_automatically ? ' (set automatically)' : '') : 'Not recorded',
            'photos' => $n === 0 ? 'No photos' : $n . ($n === 1 ? ' photo' : ' photos'),
            'history' => $last ? $fmt($last['at']) . ' - ' . $last['action'] : 'No history yet',
        ];
    }
}
