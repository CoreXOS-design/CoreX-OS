<?php

namespace App\Services\Rentals;

use App\Events\Rentals\RentalCrewLinesDecided;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardPriceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-work-orders.md §17.5 — the OFFICE side of "the crew prices a job and adds extras":
 * ask the crew to price (R1b), then accept or reject what they send back.
 *
 * The invariant this class guards: a crew-added line changes NOTHING the owner sees until the office accepts it
 * (§17.5.3 step 4). `accept()` is the only door through which a crew line becomes an `accepted` line — it prices the
 * line by the one resolver (RentalPricingService), refreshes the card's totals and VAT freeze, and — once per batch —
 * hands the new total to RentalApprovalGateService::assessAfterLineChange() (inert until Build 2 lands, §17.21.1).
 *
 * The caller (a controller) enforces `rental_job_cards.share` (ask) / `rental_job_cards.price` (accept, reject) and the
 * own/branch/agency scope guard; this service writes the audit rows (§17.5.6) with the acting user.
 */
class RentalCrewPricingService
{
    public function __construct(
        private RentalPricingService $pricing,
        private RentalJobCardVatService $vat,
    ) {
    }

    /**
     * "Ask crew to price this job". One OPEN request per card; re-asking after the crew answered opens a new one.
     *
     * @throws \LogicException with a plain-language message when the card cannot be priced by a crew right now
     */
    public function requestPricing(RentalJobCard $card, ?string $note, User $by): RentalJobCardPriceRequest
    {
        $card->assertContentEditable();
        if ($card->trashed()) {
            throw new \LogicException('This job card is archived.');
        }
        if (! $card->rental_crew_id) {
            throw new \LogicException('Assign a crew to this job card first — there is nobody to ask yet.');
        }
        if (! \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id)) {
            throw new \LogicException('Job card pricing is switched off for this agency (Settings → Rental Work Orders), so there is nothing to price.');
        }
        if (! \App\Models\RentalPortalSetting::crewLinksEnabledFor($card->agency_id)) {
            throw new \LogicException('Crew links are switched off for this agency (Settings → Rental Portal), so the crew has no way to send prices back.');
        }
        if ($card->priceRequests()->where('status', RentalJobCardPriceRequest::STATUS_OPEN)->exists()) {
            throw new \LogicException('The crew has already been asked to price this job — wait for their prices, or close that request first.');
        }

        $note = $note !== null ? trim($note) : null;
        $note = $note === '' ? null : $note;
        if ($note !== null && mb_strlen($note) > 1000) {
            throw new \LogicException('Keep the note to the crew under 1000 characters.');
        }

        $request = $card->priceRequests()->create([
            'agency_id' => $card->agency_id,
            'requested_by_user_id' => $by->id,
            'requested_at' => now(),
            'note' => $note,
            'status' => RentalJobCardPriceRequest::STATUS_OPEN,
        ]);
        $card->logUpdate('pricing_requested', $by, $note ? "Crew asked to price the job — {$note}" : 'Crew asked to price the job');

        return $request;
    }

    /** "Close request": an unanswered request is cancelled, an answered one is closed. Lines already sent stay for the office to decide. */
    public function closeRequest(RentalJobCard $card, RentalJobCardPriceRequest $request, User $by): void
    {
        abort_unless((int) $request->rental_job_card_id === (int) $card->id, 404);
        if (! in_array($request->status, [RentalJobCardPriceRequest::STATUS_OPEN, RentalJobCardPriceRequest::STATUS_SUBMITTED], true)) {
            throw new \LogicException('That price request is already closed.');
        }

        $request->forceFill([
            'status' => $request->status === RentalJobCardPriceRequest::STATUS_OPEN ? RentalJobCardPriceRequest::STATUS_CANCELLED : RentalJobCardPriceRequest::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by_user_id' => $by->id,
        ])->save();
        $card->logUpdate('pricing_requested', $by, 'Price request closed');
    }

    /**
     * Accept ONE crew line: it becomes a real line (counts in every total, document and signature), priced by the §17.4.3
     * rules unless the office overrides.
     *
     * $overrides (all optional, the controller strips what the user may not set):
     *   unit_cost  — the office may correct the crew's cost
     *   unit_price — the office types the selling price (→ manual)
     *   markup_type / markup_value — a line markup (→ line_markup)
     *
     * @param array{unit_cost?: mixed, unit_price?: mixed, markup_type?: ?string, markup_value?: mixed} $overrides
     */
    public function accept(RentalJobCard $card, RentalJobCardLine $line, User $by, array $overrides = []): RentalJobCardLine
    {
        $this->accepted($card, [$line], $by, [$line->id => $overrides]);

        return $line->refresh();
    }

    /** Reject ONE crew line with a reason the crew will read ("not accepted — reason"). */
    public function reject(RentalJobCard $card, RentalJobCardLine $line, string $reason, User $by): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \LogicException('Give a short reason — the crew will see it.');
        }
        if (mb_strlen($reason) > 1000) {
            throw new \LogicException('Keep the reason under 1000 characters.');
        }
        $this->guardAwaiting($card, $line);

        DB::transaction(function () use ($card, $line, $by, $reason) {
            $line->forceFill([
                'office_status' => RentalJobCardLine::OFFICE_REJECTED,
                'reject_reason' => $reason,
                'office_decided_by_user_id' => $by->id,
                'office_decided_at' => now(),
            ])->save();
            $card->logUpdate('crew_line_rejected', $by, $line->description . ' — ' . $reason);
            $this->settle($card, [$line->rental_job_card_price_request_id], $by, 0, 1);
        });
    }

    /** "Accept all": every line the crew sent, priced by the rules. Returns how many were accepted. */
    public function acceptAll(RentalJobCard $card, User $by): int
    {
        $lines = $card->lines()->where('office_status', RentalJobCardLine::OFFICE_AWAITING)->get()->all();
        if ($lines === []) {
            return 0;
        }
        $this->accepted($card, $lines, $by, []);

        return count($lines);
    }

    /**
     * @param array<int, RentalJobCardLine> $lines
     * @param array<int, array<string, mixed>> $overridesByLineId
     */
    private function accepted(RentalJobCard $card, array $lines, User $by, array $overridesByLineId): void
    {
        foreach ($lines as $line) {
            $this->guardAwaiting($card, $line);
        }

        DB::transaction(function () use ($card, $lines, $by, $overridesByLineId) {
            $requestIds = [];
            foreach ($lines as $line) {
                $o = $overridesByLineId[$line->id] ?? [];
                $has = fn (string $k): bool => array_key_exists($k, $o) && $o[$k] !== null && $o[$k] !== '';

                $changes = [
                    'office_status' => RentalJobCardLine::OFFICE_ACCEPTED,
                    'office_decided_by_user_id' => $by->id,
                    'office_decided_at' => now(),
                    'reject_reason' => null,
                ];
                if ($has('unit_cost')) {
                    $changes['unit_cost'] = round((float) $o['unit_cost'], 2);
                }
                if ($has('markup_type') && $has('markup_value')
                    && in_array($o['markup_type'], [RentalJobCardLine::MARKUP_PERCENT, RentalJobCardLine::MARKUP_AMOUNT], true)) {
                    $changes['markup_type'] = $o['markup_type'];
                    $changes['markup_value'] = round((float) $o['markup_value'], 2);
                }
                if ($has('unit_price')) {
                    // A price the office typed is its own word (rule 1); it replaces any markup set in the same breath.
                    $changes['unit_price'] = round((float) $o['unit_price'], 2);
                    $changes['selling_basis'] = RentalJobCardLine::BASIS_MANUAL;
                    $changes['markup_type'] = null;
                    $changes['markup_value'] = null;
                }

                $line->forceFill($changes)->save();
                $line->setRelation('jobCard', $card);
                // Priced by the one resolver; syncs cost_total and, on a frozen card, freezes THIS line's VAT straight away.
                $this->pricing->repriceLine($line);
                $card->logUpdate('crew_line_accepted', $by, $line->description . ($has('unit_price') ? ' (price set by hand)' : ''));
                $requestIds[] = $line->rental_job_card_price_request_id;
            }

            $this->settle($card, $requestIds, $by, count($lines), 0);
        });

        // ONCE per batch (§17.5.3): before approval this only feeds the next "Send quote"; after approval it may raise a variation.
        $card->refresh();
        app(RentalApprovalGateService::class)->assessAfterLineChange($card, $by);
    }

    /** After a decision: refresh totals, close any request whose lines are all decided, announce the batch once. */
    private function settle(RentalJobCard $card, array $requestIds, User $by, int $accepted, int $rejected): void
    {
        $card->recalcTotal();

        foreach (array_unique(array_filter($requestIds)) as $requestId) {
            $stillWaiting = RentalJobCardLine::withoutGlobalScopes()
                ->where('rental_job_card_price_request_id', $requestId)->whereNull('deleted_at')
                ->where('office_status', RentalJobCardLine::OFFICE_AWAITING)->exists();
            if (! $stillWaiting) {
                RentalJobCardPriceRequest::withoutGlobalScopes()->whereKey($requestId)
                    ->where('status', RentalJobCardPriceRequest::STATUS_SUBMITTED)
                    ->update(['status' => RentalJobCardPriceRequest::STATUS_CLOSED, 'closed_at' => now(), 'closed_by_user_id' => $by->id]);
            }
        }

        RentalCrewLinesDecided::dispatch($card, $accepted, $rejected, $by->id);
    }

    private function guardAwaiting(RentalJobCard $card, RentalJobCardLine $line): void
    {
        abort_unless((int) $line->rental_job_card_id === (int) $card->id, 404);
        $card->assertContentEditable();
        if ($line->office_status !== RentalJobCardLine::OFFICE_AWAITING) {
            throw new \LogicException('That line is not waiting for you — it may already have been accepted or rejected.');
        }
    }
}
