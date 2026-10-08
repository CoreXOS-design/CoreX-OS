<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderSetting;

/**
 * Johan, 9 Oct 2026 (J3/J5): the job card screen shows what FITS the card's stage and collapses the rest - the same rule as the fault and work
 * order screens. This is the ONE place that decides which stage a job card is in and which actions it offers, so the screen, its "What happens
 * next" box and the tests read the same answer.
 *
 * Stages, in order:
 *   draft           the job is being put together (tasks, lines, prices); nothing has been confirmed or sent
 *   quoted          the quote is with the owner (above the no-approval limit); waiting for their decision
 *   owner_declined  the owner said no to the quote
 *   approved        approved (the owner, or the price is within the owner's limit); not yet booked
 *   scheduled       booked for a date
 *   in_progress     the work is under way
 *   signed_off      the crew has signed the work off; the office checks it and completes the card
 *   disputed        the tenant said it is not complete; it goes back to the crew
 *   completed / cancelled   closed - read-only
 */
class RentalJobCardStageService
{
    public const STAGES = ['draft', 'quoted', 'owner_declined', 'approved', 'scheduled', 'in_progress', 'signed_off', 'disputed', 'completed', 'cancelled'];

    /**
     * @return array{
     *   key:string, label:string, next:string, open:bool, authorised:bool,
     *   quote:array{intent:string, amount:float, limit:float, within:bool, button:string, confirm:string, title:string, note:string},
     *   can:array<string,bool>, later:array<int,array{label:string,reason:string}>
     * }
     */
    public function forCard(RentalJobCard $card): array
    {
        $workOrder = $card->workOrder;
        $open = ! $card->isClosed();
        $pricesOn = RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id);
        $vat = app(RentalJobCardVatService::class);
        $amount = round($vat->inclusiveTotal($card), 2);
        $limit = $card->property ? RentalWorkOrderSetting::thresholdFor($card->property) : RentalWorkOrderSetting::spendThresholdFor($card->agency_id);
        $hasLines = $card->acceptedLines()->exists();
        $unpriced = $pricesOn ? $card->acceptedLines()->whereNull('line_total')->count() : 0;
        $authorised = $open ? app(RentalApprovalGateService::class)->authoriseCard($card, false)->authorised : false;
        $approval = $workOrder?->owner_approval_status;
        $baseline = (bool) $workOrder?->hasApprovedBaseline();
        $money = fn (float $v) => 'R' . number_format($v, 2);

        $workerDone = (bool) $card->worker_signed_off_at;
        $agentDone = (bool) $card->agent_signed_off_at;
        $key = match (true) {
            $card->status === RentalJobCard::STATUS_COMPLETED => 'completed',
            $card->status === RentalJobCard::STATUS_CANCELLED => 'cancelled',
            $card->status === RentalJobCard::STATUS_DISPUTED => 'disputed',
            $workerDone && in_array($card->status, [RentalJobCard::STATUS_APPROVED, RentalJobCard::STATUS_SCHEDULED, RentalJobCard::STATUS_IN_PROGRESS], true) => 'signed_off',
            $card->status === RentalJobCard::STATUS_IN_PROGRESS => 'in_progress',
            $card->status === RentalJobCard::STATUS_SCHEDULED => 'scheduled',
            $card->status === RentalJobCard::STATUS_APPROVED => 'approved',
            $card->status === RentalJobCard::STATUS_QUOTED && $approval === RentalWorkOrder::APPROVAL_DECLINED => 'owner_declined',
            $card->status === RentalJobCard::STATUS_QUOTED => 'quoted',
            default => 'draft',
        };

        // ── the price action: what pressing it REALLY does ───────────────────────────────────────────────────────────────────────────────
        $within = $amount <= $limit;
        $currentRevision = (int) $card->quoteRevisions()->whereNull('superseded_at')->max('revision');
        $quote = [
            'intent' => $within ? 'confirm' : 'send',
            'amount' => $amount, 'limit' => $limit, 'within' => $within,
            'title' => $within ? 'Confirm the price' : ($currentRevision ? 'Quote to owner' : 'Send quote to owner'),
            'button' => $within
                ? "Confirm price - within the owner's limit, approved automatically"
                : ($currentRevision ? 'Re-send revised quote to owner for approval (Rev ' . ($currentRevision + 1) . ')' : 'Send quote to owner for approval'),
            'confirm' => $within
                ? 'Confirm the price of ' . $money($amount) . '? It is within the owner\'s no-approval limit of ' . $money($limit) . ', so it is approved automatically. Nothing is sent to the owner.'
                : ($currentRevision
                    ? 'Re-send this job card to the owner as Rev ' . ($currentRevision + 1) . '? It replaces Rev ' . $currentRevision . ', and any approval of Rev ' . $currentRevision . ' no longer applies.'
                    : 'Send this job card (' . $money($amount) . ') to the owner for approval? It is above their no-approval limit of ' . $money($limit) . '.'),
            'note' => $within
                ? $money($amount) . ' is within the owner\'s no-approval limit of ' . $money($limit) . ' - confirming the price approves it automatically. Nothing goes to the owner.'
                : $money($amount) . ' is above the owner\'s no-approval limit of ' . $money($limit) . ' - the owner is sent the quote and must approve it before the work is booked.',
        ];

        // ── what can be pressed at this stage ────────────────────────────────────────────────────────────────────────────────────────────
        $crewVisible = in_array($card->status, RentalJobCard::CREW_VISIBLE_STATUSES, true);
        $can = [
            'edit_lines' => $open,
            'assign_crew' => $open,
            'price' => $open && $hasLines && $unpriced === 0 && ! $baseline && in_array($key, ['draft', 'quoted', 'owner_declined', 'approved', 'scheduled', 'in_progress'], true),
            'schedule' => $open && $authorised && in_array($key, ['draft', 'quoted', 'approved', 'scheduled', 'in_progress'], true),
            'start' => $open && $card->status === RentalJobCard::STATUS_SCHEDULED && ! $workerDone,
            'crew_link' => $open && $authorised && $crewVisible,
            'worker_sign_off' => $open && ! $workerDone && in_array($card->status, [RentalJobCard::STATUS_SCHEDULED, RentalJobCard::STATUS_IN_PROGRESS, RentalJobCard::STATUS_DISPUTED], true),
            'signed_copy' => $open && ! $workerDone && in_array($card->status, [RentalJobCard::STATUS_SCHEDULED, RentalJobCard::STATUS_IN_PROGRESS, RentalJobCard::STATUS_DISPUTED], true),
            'agent_sign_off' => $open && $workerDone && ! $agentDone,
            'tenant_confirm' => $open && $workerDone && ! $card->tenant_confirmed_at,
            'complete' => $open && $workerDone && $agentDone,
            'cancel' => $open,
        ];

        // ── the steps that are not available yet, with the plain reason (shown collapsed, never as a live button) ────────────────────────
        $later = [];
        if ($open) {
            $reasonSign = 'Available once the job is booked and the work is under way.';
            if (! $workerDone && ! $can['worker_sign_off']) {
                $later[] = ['label' => 'Worker sign-off', 'reason' => $reasonSign];
            }
            if (! $workerDone && ! $can['signed_copy']) {
                $later[] = ['label' => 'Upload the signed copy', 'reason' => 'Available once the crew has done the work.'];
            }
            if (! $agentDone && ! $can['agent_sign_off']) {
                $later[] = ['label' => 'Agent sign-off', 'reason' => 'Available once the crew has signed the work off.'];
            }
            if (! $card->tenant_confirmed_at && ! $can['tenant_confirm']) {
                $later[] = ['label' => 'Record the tenant\'s confirmation', 'reason' => 'Available once the crew has signed the work off.'];
            }
            if (! $can['complete']) {
                $later[] = ['label' => 'Complete the job card', 'reason' => 'Needs the worker sign-off and the agent sign-off first.'];
            }
            if (! $can['crew_link']) {
                $later[] = ['label' => 'Share a crew link / print with a link', 'reason' => 'Available once the job is approved.'];
            }
            if (! $can['schedule']) {
                $later[] = ['label' => 'Book a date', 'reason' => 'Available once the job is approved.'];
            }
        }

        $date = $card->scheduled_at?->format('D j M Y, H:i');
        $next = match ($key) {
            'draft' => match (true) {
                ! $hasLines => 'Add what needs doing: tasks, each with its parts and labour lines.',
                $unpriced > 0 => 'Price every line first (' . $unpriced . ' ' . ($unpriced === 1 ? 'line has' : 'lines have') . ' no price yet).',
                ! $pricesOn => 'Assign the crew and book a date.',
                default => $quote['note'] . ' Then assign the crew and book a date.',
            },
            'quoted' => 'Waiting for the owner to approve ' . $money($amount) . '. Booking and the crew link stay locked until they decide. Changed the card since? Re-send it.',
            'owner_declined' => 'The owner declined the quote. Change the job and re-send it, or cancel the job card.',
            'approved' => 'Approved. Assign the crew if you have not, book a date, then share the crew link or print the job card.',
            'scheduled' => 'Booked' . ($date ? ' for ' . $date : '') . '; the tenant has been told. When the crew starts, press Mark in progress.',
            'in_progress' => 'The work is under way. When the crew has finished: record the worker sign-off (or upload the signed copy, or the crew signs on their link).',
            'signed_off' => $agentDone
                ? 'Both sign-offs are in. Choose who pays and complete the job card.'
                : 'The crew says it is done. Check the work: Agent sign-off, then Complete the job card (you choose who pays).',
            'disputed' => 'The tenant says the work is not complete. Send it back to the crew (see the tenant check panel).',
            'completed' => 'This job card is complete.',
            default => 'This job card was cancelled' . ($card->cancel_reason ? ': ' . $card->cancel_reason : '') . '.',
        };

        $labels = [
            'draft' => 'Draft', 'quoted' => 'Waiting for the owner', 'owner_declined' => 'Owner declined', 'approved' => 'Approved - ready to book',
            'scheduled' => 'Booked', 'in_progress' => 'In progress', 'signed_off' => 'Crew done - office to check', 'disputed' => 'Not complete - reopened',
            'completed' => 'Completed', 'cancelled' => 'Cancelled',
        ];

        return ['key' => $key, 'label' => $labels[$key], 'next' => $next, 'open' => $open, 'authorised' => $authorised, 'quote' => $quote, 'can' => $can, 'later' => $later];
    }
}
