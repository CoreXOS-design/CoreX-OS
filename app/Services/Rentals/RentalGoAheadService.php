<?php

namespace App\Services\Rentals;

use App\Models\RentalAuthorisation;
use App\Models\RentalJobDispatch;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.15 - THE GO-AHEAD GOES OUT BY ITSELF.
 *
 * The moment a cost authorisation that covers the work order's selected quote (or, for a small job with no quote, the amount the agent typed) is
 * recorded - by the owner on their link, by an agent recording the owner's instruction, or by the agent inside the owner's repair / emergency limit -
 * the contractor is emailed the go-ahead: once per authorisation record, with the explicit wording below and the private job link. Nothing is sent
 * when the owner has stopped the job, when the contractor has no email, or when the authorisation does not cover what the contractor quoted. The agent's
 * "Send the go-ahead again" button stays (it uses the same mail and wording). Every send is one {@see RentalJobDispatch} row (reason `go_ahead`, the
 * idempotency key names the authorisation record) and one line in the work order's history saying who authorised.
 */
class RentalGoAheadService
{
    /** @var string the dispatch row's reason */
    public const REASON = RentalJobDispatch::REASON_GO_AHEAD;

    private function auth(): RentalAuthorisationService
    {
        return app(RentalAuthorisationService::class);
    }

    private static function rand(float $amount): string
    {
        return 'R' . number_format($amount, 2, '.', ' ');
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** The selected quote, if any. */
    private function selectedQuote(RentalWorkOrder $wo): ?RentalWorkOrderQuote
    {
        return RentalWorkOrderQuote::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('is_selected', true)->first();
    }

    /**
     * The explicit go-ahead wording for the contractor - the CONTRACTOR's own figure (their quote, never the agency's selling price), or for a small job
     * with no quote the amount authorised. Null when there is no cost authorisation to speak of (old, grandfathered work keeps the plain sentence).
     */
    public function wordsFor(RentalWorkOrder $wo): ?string
    {
        $row = $this->auth()->activeFor($wo, RentalAuthorisation::POINT_COST);
        if (! $row || ! $row->isAuthorised()) {
            return null;
        }
        $address = $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id);
        $quote = $this->selectedQuote($wo);
        $rule = 'Please note: any work that would take the cost above %s must first be authorised by the owner before that work is done - tell us through the link below and wait for the answer.';

        if ($quote && (float) $quote->amount > 0) {
            $amount = self::rand((float) $quote->amount);

            return "Your quote of {$amount} for {$wo->title} at {$address} has been accepted. You may go ahead. " . sprintf($rule, $amount);
        }
        if ($row->amount_up_to !== null && (float) $row->amount_up_to > 0) {
            $amount = self::rand((float) $row->amount_up_to);

            return "You may go ahead with {$wo->title} at {$address} up to {$amount}. " . sprintf($rule, $amount);
        }

        return null;
    }

    /** Spec 22.17: has the cost been authorised (by anyone, on any basis, old work included) - the page then opens the "finish the job" cards. */
    public function jobOpenForWork(RentalWorkOrder $wo): bool
    {
        return ! in_array($wo->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)
            && $wo->assignment_type === RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER
            && $this->auth()->hasCostAuthority($wo);
    }

    /**
     * The most this contractor may invoice: what the owner authorised, less the agency's own fee on the selected quote (the contractor's figure, never the selling
     * price). Null when nothing was authorised in money (old / grandfathered work) - there is then nothing to hold the invoice to.
     */
    public function contractorCeiling(RentalWorkOrder $wo): ?float
    {
        if (! $wo->hasApprovedBaseline()) {
            return null;
        }
        $quote = $this->selectedQuote($wo);
        $fee = $quote && $quote->fee_amount !== null ? (float) $quote->fee_amount : 0.0;

        return round((float) $wo->approved_amount - $fee, 2);
    }

    /**
     * THE HARD LOCK on an invoice (Johan, 13 Oct 2026): the invoices already filed plus this one may not come to even one cent more than was authorised. Null =
     * fine; otherwise the plain refusal to show the contractor.
     */
    public function invoiceLockMessage(RentalWorkOrder $wo, float $amount, ?int $exceptInvoiceId = null): ?string
    {
        $ceiling = $this->contractorCeiling($wo);
        if ($ceiling === null) {
            return null;
        }
        $filed = (float) $wo->invoices()->when($exceptInvoiceId, fn ($q) => $q->where('id', '!=', $exceptInvoiceId))->sum('amount');
        $total = round($filed + $amount, 2);
        if (self::cents($total) <= self::cents($ceiling)) {
            return null;
        }

        return 'This invoice cannot be accepted: with it, your invoices come to ' . self::rand($total) . ', which is above the ' . self::rand($ceiling)
            . ' the owner authorised. No amount above the authorised amount can be accepted. If the extra work was really needed, use "Ask for extra money" first and wait for the owner\'s answer.';
    }

    /**
     * Does this authorisation cover what the contractor is to be told to do? Null = yes; otherwise the plain reason it does not (not an error - nothing is sent).
     */
    private function notCoveredReason(RentalWorkOrder $wo, RentalAuthorisation $row): ?string
    {
        if (in_array($wo->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return 'closed';
        }
        if ($wo->assignment_type !== RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER || $wo->isOwnerContractor() || ! $wo->agency_service_provider_id) {
            return 'not an outside contractor job';
        }
        $measure = $this->auth()->costMeasure($wo);
        if ($measure['source'] === 'crew') {
            return 'own crew';
        }
        $upTo = $row->amount_up_to !== null ? (float) $row->amount_up_to : null;
        if ($measure['source'] === 'quote') {
            $quote = $this->selectedQuote($wo);
            if (! $quote || (int) $quote->agency_service_provider_id !== (int) $wo->agency_service_provider_id) {
                return 'the selected quote is not from this contractor';
            }
            if ($upTo === null || self::cents($quote->ownerFacingAmount()) > self::cents($upTo)) {
                return 'the authorised amount does not cover the selected quote';
            }

            return null;
        }

        return ($upTo !== null && $upTo > 0) ? null : 'no amount authorised';
    }

    /**
     * Called by every writer of a cost authorisation, AFTER the surrounding transaction commits (so the work order is in its final state). Never throws:
     * a mail problem must not undo or fail an authorisation - it is logged on the work order and the agents are told instead.
     */
    public function onCostAuthorised(RentalWorkOrder $wo, RentalAuthorisation $row, ?User $by = null): void
    {
        if (! $row->isAuthorised() || $row->point !== RentalAuthorisation::POINT_COST) {
            return;
        }
        $woId = $wo->id;
        $rowId = $row->id;
        DB::afterCommit(function () use ($woId, $rowId, $by) {
            try {
                $this->sendFor($woId, $rowId, $by);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Go-ahead could not be sent', ['work_order' => $woId, 'authorisation' => $rowId, 'error' => $e->getMessage()]);
            }
        });
    }

    private function sendFor(int $woId, int $rowId, ?User $by): void
    {
        $wo = RentalWorkOrder::withoutGlobalScopes()->find($woId);
        $row = RentalAuthorisation::withoutGlobalScopes()->find($rowId);
        if (! $wo || ! $row) {
            return;
        }
        $active = $this->auth()->activeFor($wo, RentalAuthorisation::POINT_COST);
        if (! $active || (int) $active->id !== (int) $row->id || $this->notCoveredReason($wo, $row) !== null) {
            return;   // replaced by a newer record, or not something the contractor is to be told yet
        }

        $provider = $wo->supplier()->withoutGlobalScopes()->with('serviceContacts')->first();
        if (! $provider) {
            return;
        }
        $jobs = app(RentalContractorJobService::class);
        $contact = $jobs->contactFor($provider);
        $who = $this->auth()->recordTextFor($row->loadMissing(['byUser', 'ownerContact']));

        if ($held = app(RentalOwnerHoldService::class)->holdReason($wo)) {
            $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'job_not_sent', 'created_by_user_id' => $by?->id,
                'note' => 'Go-ahead NOT sent to ' . $provider->name . ' - ' . $held]);

            return;
        }
        if (! $contact['email']) {
            $dispatch = $this->claim($wo, $row, $provider, null, RentalJobDispatch::CHANNEL_NONE, RentalJobDispatch::STATUS_NOT_SENT, $by);
            if ($dispatch['fresh']) {
                $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'job_not_sent', 'created_by_user_id' => $by?->id,
                    'note' => 'Go-ahead NOT sent to ' . $provider->name . ' - no email address on file. ' . $who . ' Add their email and press "Send the go-ahead again".']);
                $this->tell($wo, 'Go-ahead NOT sent - no email on file', $provider->name . ': ' . $wo->title);
            }

            return;
        }

        $dispatch = $this->claim($wo, $row, $provider, $contact['email'], RentalJobDispatch::CHANNEL_EMAIL, RentalJobDispatch::STATUS_PREPARED, $by);
        if (! $dispatch['fresh']) {
            return;   // once per authorisation record
        }
        $sent = app(RentalWorkOrderService::class)->sendContractorWorkOrder($wo, $by, true, $who);
        $dispatch['row']->forceFill($sent
            ? ['status' => RentalJobDispatch::STATUS_SENT, 'sent_at' => now()]
            : ['status' => RentalJobDispatch::STATUS_FAILED, 'error' => 'The go-ahead email could not be sent.'])->save();

        if ($wo->status === RentalWorkOrder::STATUS_REPORTED && $sent) {
            $wo->forceFill(['status' => RentalWorkOrder::STATUS_ORDERED, 'ordered_at' => now()])->save();
            $wo->updates()->create(['agency_id' => $wo->agency_id, 'update_type' => 'status_change', 'from_status' => RentalWorkOrder::STATUS_REPORTED,
                'to_status' => RentalWorkOrder::STATUS_ORDERED, 'created_by_user_id' => $by?->id, 'note' => 'The go-ahead was sent to the contractor.']);
        }
        $this->tell($wo, $sent ? 'Go-ahead sent to the contractor' : 'Go-ahead NOT sent - email failed', $provider->name . ': ' . $wo->title);
    }

    /** One dispatch row per authorisation record (unique idempotency key) - a double call, a retry or two writers cannot send it twice. */
    private function claim(RentalWorkOrder $wo, RentalAuthorisation $row, $provider, ?string $destination, string $channel, string $status, ?User $by): array
    {
        $key = "wo{$wo->id}:goahead:auth{$row->id}";
        $existing = RentalJobDispatch::withoutGlobalScopes()->where('idempotency_key', $key)->first();
        if ($existing) {
            return ['row' => $existing, 'fresh' => false];
        }
        try {
            $created = RentalJobDispatch::create([
                'agency_id' => $wo->agency_id, 'rental_work_order_id' => $wo->id, 'recipient_kind' => RentalJobDispatch::KIND_CONTRACTOR, 'agency_service_provider_id' => $provider->id,
                'recipient_name' => $provider->name, 'channel' => $channel, 'destination' => $destination, 'status' => $status, 'reason' => self::REASON,
                'idempotency_key' => $key, 'sent_by_user_id' => $by?->id,
            ]);

            return ['row' => $created, 'fresh' => true];
        } catch (QueryException $e) {
            $existing = RentalJobDispatch::withoutGlobalScopes()->where('idempotency_key', $key)->first();
            if ($existing) {
                return ['row' => $existing, 'fresh' => false];
            }
            throw $e;
        }
    }

    private function tell(RentalWorkOrder $wo, string $title, string $body): void
    {
        try {
            $address = $wo->property?->buildDisplayAddress() ?: ('Property #' . $wo->property_id);
            app(RentalWorkOrderService::class)->notifyAgentOfOwnerAction($wo, RentalContractorJobService::EV_SENT, $title . ' - ' . $address, $body, now());
        } catch (\Throwable $e) {
            Log::warning('Go-ahead notification failed', ['work_order' => $wo->id, 'error' => $e->getMessage()]);
        }
    }
}
