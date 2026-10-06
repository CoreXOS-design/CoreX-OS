<?php

namespace App\Services\Rentals;

use App\Events\Rentals\RentalEmergencyApprovalRecorded;
use App\Events\Rentals\RentalVariationDecided;
use App\Events\Rentals\RentalVariationRaised;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApproval;
use App\Models\RentalApprovalDecision;
use App\Models\RentalEmergencyApproval;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;
use App\Models\RentalWorkOrderVariation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-work-orders.md §17.6.3 — the ONLY place the system decides
 * "does this need the owner?". No other code may. Every call that reaches a
 * decision writes a `rental_approval_decisions` row citing the term it relied on
 * (the term's key, its value, where the value came from, the amount tested and
 * the ceiling it was tested against), and the same sentence is stored in `note`
 * so a later change of a term never rewrites history (§17.6.4).
 *
 * BUILT in Build 2 (§17.21.3). The two owner work terms (§17.6.1) are
 * (i) the no-approval limit — per JOB, never per line — and (ii) the variation
 * tolerance, an increase of up to Y % above the APPROVED amount that is
 * auto-approved. Emergency work (§17.8) is never gated: the owner's agreement is
 * captured by the office (recordEmergency) and there is no override of it.
 */
class RentalApprovalGateService
{
    public function __construct(private RentalJobCardVatService $vat)
    {
    }

    // ───────────────────────── the terms ─────────────────────────

    /** The two owner work terms in force for a property, with where each came from (§17.6.1). */
    public function termsFor(Property $property): WorkTerms
    {
        if ($property->rental_no_approval_spend_threshold !== null) {
            $limit = (float) $property->rental_no_approval_spend_threshold;
            $limitSource = WorkTerms::SOURCE_PROPERTY;
        } else {
            $limit = RentalWorkOrderSetting::spendThresholdFor($property->agency_id);
            $limitSource = RentalWorkOrderSetting::hasAgencySpendThreshold($property->agency_id)
                ? WorkTerms::SOURCE_AGENCY_DEFAULT
                : WorkTerms::SOURCE_CONSTANT;
        }

        if ($property->rental_variation_tolerance_percent !== null) {
            $pct = (float) $property->rental_variation_tolerance_percent;
            $pctSource = WorkTerms::SOURCE_PROPERTY;
        } else {
            $pct = RentalWorkOrderSetting::variationTolerancePercentFor($property->agency_id);
            $pctSource = RentalWorkOrderSetting::hasAgencyVariationTolerance($property->agency_id)
                ? WorkTerms::SOURCE_AGENCY_DEFAULT
                : WorkTerms::SOURCE_CONSTANT;
        }

        return new WorkTerms($limit, $limitSource, $pct, $pctSource);
    }

    /** Same terms for a work order whose property row is somehow unavailable: the agency defaults only. */
    private function termsForWorkOrder(RentalWorkOrder $workOrder): WorkTerms
    {
        if ($workOrder->property) {
            return $this->termsFor($workOrder->property);
        }

        return new WorkTerms(
            RentalWorkOrderSetting::spendThresholdFor($workOrder->agency_id),
            RentalWorkOrderSetting::hasAgencySpendThreshold($workOrder->agency_id) ? WorkTerms::SOURCE_AGENCY_DEFAULT : WorkTerms::SOURCE_CONSTANT,
            RentalWorkOrderSetting::variationTolerancePercentFor($workOrder->agency_id),
            RentalWorkOrderSetting::hasAgencyVariationTolerance($workOrder->agency_id) ? WorkTerms::SOURCE_AGENCY_DEFAULT : WorkTerms::SOURCE_CONSTANT,
        );
    }

    // ───────────────────────── first quote ─────────────────────────

    /**
     * §17.6.3 — the existing selectQuote() rule moved here unchanged: amount <= the no-approval limit →
     * auto-approved (basis no_approval_limit, owner_approval_status not_required, approved_amount = amount);
     * otherwise needs_owner (status pending). The amount is ALWAYS the owner-facing one.
     */
    public function evaluateQuote(RentalWorkOrder $workOrder, float $ownerFacingAmount, ?User $by, ?RentalWorkOrderQuote $quote = null): GateDecision
    {
        $terms = $this->termsForWorkOrder($workOrder);
        $amount = round($ownerFacingAmount, 2);

        if ($amount <= $terms->noApprovalLimit) {
            $decision = new GateDecision(
                authorised: true,
                decision: RentalApprovalDecision::DECISION_AUTO_APPROVED,
                basis: RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT,
                termKey: RentalApprovalDecision::TERM_NO_APPROVAL_LIMIT,
                termValue: $terms->noApprovalLimit,
                termSource: $terms->limitSource,
                amountTested: $amount,
                limitAmount: $terms->noApprovalLimit,
                note: 'Auto-approved on ' . $this->when() . ' — ' . $this->money($amount) . " is within the owner's no-approval limit of "
                    . $this->money($terms->noApprovalLimit) . ' ' . $this->sourceLabel($terms->limitSource),
            );
            $workOrder->forceFill([
                'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED,
                'approved_amount' => $amount,
                'approval_basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT,
            ])->save();
        } else {
            $decision = new GateDecision(
                authorised: false,
                decision: RentalApprovalDecision::DECISION_NEEDS_OWNER,
                basis: null,
                termKey: RentalApprovalDecision::TERM_NO_APPROVAL_LIMIT,
                termValue: $terms->noApprovalLimit,
                termSource: $terms->limitSource,
                amountTested: $amount,
                limitAmount: $terms->noApprovalLimit,
                note: $this->money($amount) . " is above the owner's no-approval limit of " . $this->money($terms->noApprovalLimit)
                    . ' ' . $this->sourceLabel($terms->limitSource) . ' — the owner is asked to approve it.',
            );
            $workOrder->forceFill([
                'owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING,
                'approved_amount' => null,
                'approval_basis' => null,
            ])->save();
        }

        $this->record($workOrder, $decision, $by, quote: $quote);

        return $decision;
    }

    // ───────────────────────── the owner's own decision on a quote ─────────────────────────

    /**
     * §17.6.4 — the owner (portal) or the agent capturing the owner's reply decided on the work order's quote. Called by
     * RentalWorkOrder::recordApproval() after it wrote the evidence row. An approval makes the selected quote's
     * owner-facing amount the approved baseline that variations are measured against; a decline clears the baseline.
     */
    public function recordOwnerDecision(RentalWorkOrder $workOrder, bool $approved, User|Contact $by, string $evidenceType, ?RentalWorkOrderQuote $quote, ?Carbon $decidedAt = null): void
    {
        $decidedAt ??= now();
        $amount = $quote?->ownerFacingAmount();
        $isUser = $by instanceof User;

        if ($approved) {
            $workOrder->forceFill([
                'approved_amount' => $amount ?? $workOrder->approved_amount,
                'approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION,
            ])->save();
        } else {
            $workOrder->forceFill(['approved_amount' => null, 'approval_basis' => null])->save();
        }

        $channel = $isUser ? 'by ' . str_replace('_', ' ', $evidenceType) : 'in the portal';
        $sentence = ($approved ? 'Approved' : 'Declined') . ' by the owner ' . $channel . ' on ' . $this->when($decidedAt)
            . ($amount !== null ? ' — ' . $this->money($amount) : '') . ($isUser ? ' (recorded by ' . $by->name . ')' : '');

        RentalApprovalDecision::create([
            'agency_id' => $workOrder->agency_id,
            'rental_work_order_id' => $workOrder->id,
            'rental_work_order_quote_id' => $quote?->id,
            'decided_by' => $isUser ? RentalApprovalDecision::BY_USER : RentalApprovalDecision::BY_OWNER,
            'decided_by_user_id' => $isUser ? $by->id : null,
            'decided_by_contact_id' => $isUser ? null : $by->id,
            'decision' => $approved ? RentalApprovalDecision::DECISION_APPROVED : RentalApprovalDecision::DECISION_DECLINED,
            'basis' => RentalWorkOrder::BASIS_OWNER_DECISION,
            'term_key' => RentalApprovalDecision::TERM_OWNER_DECISION,
            'term_source' => RentalApprovalDecision::SOURCE_OWNER,
            'amount_tested' => $amount ?? 0,
            'note' => $sentence,
        ]);
    }

    // ───────────────────────── variations ─────────────────────────

    /**
     * §17.6.3 — the five-step variation rule, compared with `approved_amount` only:
     * 1 emergency → emergency_covered; 2 a decrease (or no baseline) → no change, writes nothing;
     * 3 within the no-approval limit → auto-approved (term i); 4 within the tolerance → auto-approved (term ii);
     * 5 otherwise needs the owner. $record = false lets assessAfterLineChange() write the row itself once the
     * variation exists (so the row can point at it).
     */
    public function evaluateVariation(RentalWorkOrder $workOrder, float $newTotal, ?User $by, bool $record = true, ?RentalWorkOrderVariation $variation = null): GateDecision
    {
        $terms = $this->termsForWorkOrder($workOrder);
        $newTotal = round($newTotal, 2);

        if ($this->isEmergency($workOrder)) {
            $decision = new GateDecision(
                authorised: true,
                decision: RentalApprovalDecision::DECISION_EMERGENCY_COVERED,
                basis: RentalWorkOrder::BASIS_EMERGENCY,
                termKey: RentalApprovalDecision::TERM_EMERGENCY,
                termSource: RentalApprovalDecision::SOURCE_EMERGENCY,
                amountTested: $newTotal,
                note: 'Emergency work the owner agreed to — no approval limit or tolerance applies; the final amount is settled afterwards.',
            );
            if ($record) {
                $this->record($workOrder, $decision, $by, variation: $variation);
            }

            return $decision;
        }

        if ($workOrder->approved_amount === null) {
            return GateDecision::noChange('Nothing has been approved yet, so there is nothing to compare against.');
        }

        $baseline = round((float) $workOrder->approved_amount, 2);

        if ($newTotal <= $baseline) {
            return GateDecision::noChange('A lower or equal total never needs approval.');
        }

        $toleranceCeiling = $terms->variationPct > 0 ? round($baseline * (1 + $terms->variationPct / 100), 2) : null;

        if ($newTotal <= $terms->noApprovalLimit) {
            $decision = new GateDecision(
                authorised: true,
                decision: RentalApprovalDecision::DECISION_AUTO_APPROVED,
                basis: RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT,
                termKey: RentalApprovalDecision::TERM_NO_APPROVAL_LIMIT,
                termValue: $terms->noApprovalLimit,
                termSource: $terms->limitSource,
                amountTested: $newTotal,
                baselineAmount: $baseline,
                limitAmount: $terms->noApprovalLimit,
                note: 'Auto-approved on ' . $this->when() . ' — the new total of ' . $this->money($newTotal) . " is within the owner's no-approval limit of "
                    . $this->money($terms->noApprovalLimit) . ' ' . $this->sourceLabel($terms->limitSource),
            );
        } elseif ($toleranceCeiling !== null && $newTotal <= $toleranceCeiling) {
            $decision = new GateDecision(
                authorised: true,
                decision: RentalApprovalDecision::DECISION_AUTO_APPROVED,
                basis: RentalWorkOrder::BASIS_VARIATION_TOLERANCE,
                termKey: RentalApprovalDecision::TERM_VARIATION_TOLERANCE,
                termValue: $terms->variationPct,
                termSource: $terms->pctSource,
                amountTested: $newTotal,
                baselineAmount: $baseline,
                limitAmount: $toleranceCeiling,
                note: 'Auto-approved on ' . $this->when() . ' — the new total of ' . $this->money($newTotal) . " is within the owner's agreed " . $this->pct($terms->variationPct)
                    . ' tolerance (' . $this->money($baseline) . ' + ' . $this->pct($terms->variationPct) . ' = ' . $this->money($toleranceCeiling) . '; ' . $this->sourceLabel($terms->pctSource, false) . ')',
            );
        } else {
            $ceiling = max($toleranceCeiling ?? $baseline, $terms->noApprovalLimit);
            $decision = new GateDecision(
                authorised: false,
                decision: RentalApprovalDecision::DECISION_NEEDS_OWNER,
                basis: null,
                termKey: RentalApprovalDecision::TERM_VARIATION_TOLERANCE,
                termValue: $terms->variationPct,
                termSource: $terms->pctSource,
                amountTested: $newTotal,
                baselineAmount: $baseline,
                limitAmount: $ceiling,
                note: 'The new total of ' . $this->money($newTotal) . ' is above what the owner approved (' . $this->money($baseline) . ')'
                    . ($toleranceCeiling !== null
                        ? ' plus the agreed ' . $this->pct($terms->variationPct) . ' tolerance (' . $this->money($toleranceCeiling) . '; ' . $this->sourceLabel($terms->pctSource, false) . ') — the owner is asked to approve the extra.'
                        : ' and no tolerance is agreed (' . $this->sourceLabel($terms->pctSource, false) . ') — the owner is asked to approve the extra.'),
            );
        }

        if ($record) {
            $this->record($workOrder, $decision, $by, variation: $variation);
        }

        return $decision;
    }

    /**
     * Called after any change that can raise a card's accepted total (accepting crew lines, adding / editing /
     * restoring a line, repricing). Does nothing while the work order has no approved amount yet (the normal
     * "send the quote" path applies) and never touches emergency work. Otherwise it creates, revises or withdraws
     * the work order's variation (§17.7).
     */
    public function assessAfterLineChange(RentalJobCard $card, ?User $by): ?RentalWorkOrderVariation
    {
        $workOrder = $card->workOrder()->first();
        if (! $workOrder || $workOrder->approved_amount === null) {
            return null;
        }
        if ($this->isEmergency($workOrder) || in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return null;
        }
        if (! RentalWorkOrderSetting::capturePricesOnJobCardsFor($workOrder->agency_id)) {
            return null;
        }

        $card->recalcTotal();
        $card->refresh();
        $newTotal = round($this->vat->inclusiveTotal($card), 2);
        $baseline = round((float) $workOrder->approved_amount, 2);

        $result = DB::transaction(function () use ($workOrder, $card, $by, $newTotal, $baseline) {
            $open = $workOrder->variations()->where('status', RentalWorkOrderVariation::STATUS_AWAITING_OWNER)->lockForUpdate()->first();

            if ($newTotal <= $baseline) {
                if ($open) {
                    $this->withdraw($open, 'The job total is back within what the owner approved.', $by);
                }

                return null;
            }

            $decision = $this->evaluateVariation($workOrder, $newTotal, $by, false);
            if ($decision->decision === 'no_change' || $decision->decision === RentalApprovalDecision::DECISION_EMERGENCY_COVERED) {
                return null;
            }

            $newLines = $this->newLinesSinceApproval($workOrder, $card);
            $linesExplain = round((float) $newLines->sum(fn (RentalJobCardLine $l) => $this->lineOwnerFacing($l)), 2);
            $extra = round($newTotal - $baseline, 2);
            $priceChange = max(0.0, round($extra - $linesExplain, 2));
            $origin = $newLines->contains(fn (RentalJobCardLine $l) => $l->origin !== RentalJobCardLine::ORIGIN_OFFICE)
                ? RentalWorkOrderVariation::ORIGIN_CREW_LINES
                : RentalWorkOrderVariation::ORIGIN_OFFICE_EDIT;

            if ($decision->decision === RentalApprovalDecision::DECISION_AUTO_APPROVED) {
                $last = $workOrder->variations()->where('status', RentalWorkOrderVariation::STATUS_AUTO_APPROVED)->orderByDesc('id')->first();
                if (! $open && $last && abs((float) $last->new_total - $newTotal) < 0.005) {
                    return [$last, false]; // nothing changed since the last auto-approved step — idempotent, nothing to announce
                }
                if ($open) {
                    // the extra now fits the owner's terms after all — the open request is moot
                    $this->withdraw($open, 'The job total now falls within the owner\'s agreed terms.', $by);
                }

                $variation = $this->createVariation($workOrder, $card, $by, $decision, RentalWorkOrderVariation::STATUS_AUTO_APPROVED, $origin, $baseline, $newTotal, $extra, $priceChange);
                $this->linkLines($variation, $newLines);
                $this->record($workOrder, $decision, $by, variation: $variation);
                $this->logVariation($workOrder, $by, 'variation_raised', 'Extra work of ' . $this->money($extra) . ' auto-approved — ' . $decision->note);

                return [$variation, true];
            }

            // needs the owner
            if ($open) {
                $open->forceFill([
                    'revision' => (int) $open->revision + 1,
                    'extra_amount' => $extra,
                    'new_total' => $newTotal,
                    'price_change_amount' => $priceChange,
                    'term_basis' => $decision->termKey,
                    'term_value' => $decision->termValue,
                    'term_source' => $decision->termSource,
                    'baseline_amount' => $baseline,
                ])->save();
                $this->linkLines($open, $newLines);
                $this->record($workOrder, $decision, $by, variation: $open);
                $this->logVariation($workOrder, $by, 'variation_raised', 'Request to the owner revised (revision ' . $open->revision . '): new total ' . $this->money($newTotal));

                return [$open->fresh(), true];
            }

            $variation = $this->createVariation($workOrder, $card, $by, $decision, RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $origin, $baseline, $newTotal, $extra, $priceChange);
            $this->linkLines($variation, $newLines);
            $this->record($workOrder, $decision, $by, variation: $variation);
            $this->logVariation($workOrder, $by, 'variation_raised', 'Extra work of ' . $this->money($extra) . ' needs the owner — ' . $decision->note);

            return [$variation, true];
        });

        if (! $result) {
            return null;
        }
        [$variation, $announce] = $result;
        if ($announce) {
            $this->announceVariation($variation, $by);
        }

        return $variation;
    }

    /**
     * §17.9.4 — a HIGHER external quote selected AFTER the owner approved an amount opens (or revises) a variation
     * (origin external_quote) instead of resetting the approval. A lower or equal quote changes nothing.
     */
    public function assessExternalQuote(RentalWorkOrder $workOrder, RentalWorkOrderQuote $quote, ?User $by): ?RentalWorkOrderVariation
    {
        if ($workOrder->approved_amount === null || $this->isEmergency($workOrder)) {
            return null;
        }
        $newTotal = round($quote->ownerFacingAmount(), 2);
        $baseline = round((float) $workOrder->approved_amount, 2);

        $variation = DB::transaction(function () use ($workOrder, $quote, $by, $newTotal, $baseline) {
            $open = $workOrder->variations()->where('status', RentalWorkOrderVariation::STATUS_AWAITING_OWNER)->lockForUpdate()->first();
            if ($newTotal <= $baseline) {
                if ($open) {
                    $this->withdraw($open, 'The selected quote is back within what the owner approved.', $by);
                }

                return null;
            }

            $decision = $this->evaluateVariation($workOrder, $newTotal, $by, false);
            if ($decision->decision === 'no_change') {
                return null;
            }
            $extra = round($newTotal - $baseline, 2);

            if ($decision->decision === RentalApprovalDecision::DECISION_AUTO_APPROVED) {
                if ($open) {
                    $this->withdraw($open, 'The selected quote now falls within the owner\'s agreed terms.', $by);
                }
                $variation = $this->createVariation($workOrder, null, $by, $decision, RentalWorkOrderVariation::STATUS_AUTO_APPROVED, RentalWorkOrderVariation::ORIGIN_EXTERNAL_QUOTE, $baseline, $newTotal, $extra, $extra, $quote);
                $this->record($workOrder, $decision, $by, variation: $variation, quote: $quote);
                $this->logVariation($workOrder, $by, 'variation_raised', 'Higher quote auto-approved — ' . $decision->note);

                return $variation;
            }

            if ($open) {
                $open->forceFill([
                    'revision' => (int) $open->revision + 1, 'extra_amount' => $extra, 'new_total' => $newTotal, 'price_change_amount' => $extra,
                    'baseline_amount' => $baseline, 'rental_work_order_quote_id' => $quote->id,
                    'term_basis' => $decision->termKey, 'term_value' => $decision->termValue, 'term_source' => $decision->termSource,
                ])->save();
                $this->record($workOrder, $decision, $by, variation: $open, quote: $quote);
                $this->logVariation($workOrder, $by, 'variation_raised', 'Request to the owner revised (revision ' . $open->revision . '): new total ' . $this->money($newTotal));

                return $open->fresh();
            }

            $variation = $this->createVariation($workOrder, null, $by, $decision, RentalWorkOrderVariation::STATUS_AWAITING_OWNER, RentalWorkOrderVariation::ORIGIN_EXTERNAL_QUOTE, $baseline, $newTotal, $extra, $extra, $quote);
            $this->record($workOrder, $decision, $by, variation: $variation, quote: $quote);
            $this->logVariation($workOrder, $by, 'variation_raised', 'A higher quote of ' . $this->money($newTotal) . ' needs the owner — ' . $decision->note);

            return $variation;
        });

        if ($variation) {
            $this->announceVariation($variation, $by);
        }

        return $variation;
    }

    /** The owner-facing figure of one line (VAT-inclusive when the card's VAT is frozen; else its selling total). */
    private function lineOwnerFacing(RentalJobCardLine $line): float
    {
        return (float) ($line->vat_incl_snapshot ?? $line->line_total ?? 0);
    }

    /** @return \Illuminate\Support\Collection<int, RentalJobCardLine> accepted, unlinked lines added/accepted after the approved baseline */
    private function newLinesSinceApproval(RentalWorkOrder $workOrder, RentalJobCard $card)
    {
        $since = $this->baselineMoment($workOrder);

        return $card->lines()
            ->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED)
            ->whereNull('rental_work_order_variation_id')
            ->whereRaw('COALESCE(office_decided_at, created_at) > ?', [$since->toDateTimeString()])
            ->get();
    }

    /** When the current approved baseline was set: the latest approving decision (or approved variation). */
    private function baselineMoment(RentalWorkOrder $workOrder): Carbon
    {
        $decision = RentalApprovalDecision::query()
            ->where('rental_work_order_id', $workOrder->id)
            ->whereNull('rental_work_order_variation_id')
            ->whereIn('decision', [RentalApprovalDecision::DECISION_AUTO_APPROVED, RentalApprovalDecision::DECISION_APPROVED])
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $variation = RentalWorkOrderVariation::query()
            ->where('rental_work_order_id', $workOrder->id)
            ->where('status', RentalWorkOrderVariation::STATUS_APPROVED)
            ->orderByDesc('decided_at')->first();

        $moments = array_filter([$decision?->created_at, $variation?->decided_at]);
        if ($moments) {
            return collect($moments)->max();
        }

        return ($workOrder->quotes()->where('is_selected', true)->first()?->created_at) ?? Carbon::createFromTimestamp(0);
    }

    private function createVariation(RentalWorkOrder $workOrder, ?RentalJobCard $card, ?User $by, GateDecision $decision, string $status, string $origin, float $baseline, float $newTotal, float $extra, float $priceChange, ?RentalWorkOrderQuote $quote = null): RentalWorkOrderVariation
    {
        return RentalWorkOrderVariation::create([
            'agency_id' => $workOrder->agency_id,
            'rental_work_order_id' => $workOrder->id,
            'rental_job_card_id' => $card?->id,
            'rental_work_order_quote_id' => $quote?->id,
            'revision' => 1,
            'status' => $status,
            'origin' => $origin,
            'baseline_amount' => $baseline,
            'extra_amount' => $extra,
            'new_total' => $newTotal,
            'price_change_amount' => $priceChange,
            'term_basis' => $decision->termKey,
            'term_value' => $decision->termValue,
            'term_source' => $decision->termSource,
            'term_text' => RentalWorkOrderSetting::quoteEstimateTermFor($workOrder->agency_id),
            'raised_by_user_id' => $by?->id,
            'raised_at' => now(),
            'decided_at' => $status === RentalWorkOrderVariation::STATUS_AUTO_APPROVED ? now() : null,
        ]);
    }

    /** @param \Illuminate\Support\Collection<int, RentalJobCardLine> $lines */
    private function linkLines(RentalWorkOrderVariation $variation, $lines): void
    {
        foreach ($lines as $line) {
            $line->forceFill(['rental_work_order_variation_id' => $variation->id])->save();
        }
    }

    private function withdraw(RentalWorkOrderVariation $variation, string $why, ?User $by): void
    {
        $variation->forceFill([
            'status' => RentalWorkOrderVariation::STATUS_WITHDRAWN,
            'decided_at' => now(),
            'decision_note' => $why,
        ])->save();
        $variation->workOrder?->updates()->create([
            'agency_id' => $variation->agency_id, 'update_type' => 'variation_decided',
            'note' => 'Variation withdrawn — ' . $why, 'created_by_user_id' => $by?->id,
        ]);
    }

    /** Cancelling a work order or card withdraws its open variations (§17.7.2). */
    public function withdrawOpenVariations(RentalWorkOrder $workOrder, string $why, ?User $by): void
    {
        $workOrder->variations()->where('status', RentalWorkOrderVariation::STATUS_AWAITING_OWNER)->get()
            ->each(fn (RentalWorkOrderVariation $v) => $this->withdraw($v, $why, $by));
    }

    private function logVariation(RentalWorkOrder $workOrder, ?User $by, string $type, string $note): void
    {
        $workOrder->updates()->create([
            'agency_id' => $workOrder->agency_id, 'update_type' => $type, 'note' => $note, 'created_by_user_id' => $by?->id,
        ]);
    }

    /** After the transaction: tell the owner, the agent, and the rest of the system (never fails the office's action). */
    private function announceVariation(RentalWorkOrderVariation $variation, ?User $by): void
    {
        try {
            $service = app(RentalWorkOrderService::class);
            if ($variation->isAwaitingOwner()) {
                $service->sendOwnerVariation($variation, $by);
            } elseif ($variation->status === RentalWorkOrderVariation::STATUS_AUTO_APPROVED && RentalWorkOrderSetting::notifyLandlordOnAutoVariationFor($variation->agency_id)) {
                $service->sendOwnerVariationAuto($variation, $by);
            }
            $service->notifyVariationRaised($variation);
        } catch (\Throwable $e) {
            Log::warning('Variation announcement failed', ['variation_id' => $variation->id, 'error' => $e->getMessage()]);
        }

        RentalVariationRaised::dispatch($variation, $by?->id);
    }

    /**
     * Record the owner's (or the agent-captured) decision on a variation, at the revision the owner saw (a stale
     * revision is refused). $decision: approve | approved | decline | declined. $evidence: revision?, note?, via
     * (portal | agent_capture), and for an agent capture: evidence_type, evidence_text, evidence_file_path?, decided_at?.
     * $actor: ['user' => User] or ['contact' => Contact].
     *
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $actor
     */
    public function recordVariationDecision(RentalWorkOrderVariation $variation, string $decision, array $evidence, array $actor): void
    {
        $approved = match (strtolower(trim($decision))) {
            'approve', 'approved' => true,
            'decline', 'declined' => false,
            default => throw new \InvalidArgumentException('The decision must be approve or decline.'),
        };

        DB::transaction(function () use ($variation, $approved, $evidence, $actor) {
            /** @var RentalWorkOrderVariation $locked */
            $locked = RentalWorkOrderVariation::withoutGlobalScopes()->lockForUpdate()->findOrFail($variation->id);
            if (! $locked->isAwaitingOwner()) {
                throw new \LogicException('This request is not waiting for a decision any more.');
            }
            if (isset($evidence['revision']) && $evidence['revision'] !== null && (int) $evidence['revision'] !== (int) $locked->revision) {
                throw new StaleVariationRevision('This request changed — please refresh and look at the latest version.');
            }

            $workOrder = RentalWorkOrder::withoutGlobalScopes()->findOrFail($locked->rental_work_order_id);
            $user = $actor['user'] ?? null;
            $contact = $actor['contact'] ?? null;
            $via = $evidence['via'] ?? ($contact ? RentalWorkOrderVariation::VIA_PORTAL : RentalWorkOrderVariation::VIA_AGENT_CAPTURE);
            $decidedAt = isset($evidence['decided_at']) && $evidence['decided_at'] ? Carbon::parse($evidence['decided_at']) : now();
            $note = isset($evidence['note']) ? trim((string) $evidence['note']) : null;

            $locked->forceFill([
                'status' => $approved ? RentalWorkOrderVariation::STATUS_APPROVED : RentalWorkOrderVariation::STATUS_DECLINED,
                'decided_at' => $decidedAt,
                'decided_by_user_id' => $user?->id,
                'decided_by_contact_id' => $contact?->id,
                'decided_via' => $via,
                'decision_note' => $note ?: null,
            ])->save();

            $recordedBy = $user ? ' (recorded by ' . $user->name . ')' : '';
            $channel = $via === RentalWorkOrderVariation::VIA_PORTAL
                ? 'in the portal'
                : 'by ' . str_replace('_', ' ', (string) ($evidence['evidence_type'] ?? 'the owner'));
            $sentence = ($approved ? 'Approved' : 'Declined') . ' by the owner ' . $channel . ' on ' . $this->when($decidedAt)
                . ' — the extra work of ' . $this->money((float) $locked->extra_amount) . ' (new total ' . $this->money((float) $locked->new_total) . ')' . $recordedBy;

            if ($approved) {
                $workOrder->forceFill([
                    'approved_amount' => $locked->new_total,
                    'approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION,
                    'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED,
                ])->save();
            } else {
                // the declined extra comes out of every total; the main job continues on the approved scope
                // (explicit, scope-free reads: a portal decision has no staff user to resolve the agency from)
                RentalJobCardLine::withoutGlobalScopes()->where('rental_work_order_variation_id', $locked->id)
                    ->get()->each(fn (RentalJobCardLine $l) => $l->forceFill(['office_status' => RentalJobCardLine::OFFICE_DECLINED_BY_OWNER])->save());
                $cardId = $locked->rental_job_card_id ?? RentalJobCard::withoutGlobalScopes()->where('rental_work_order_id', $workOrder->id)->value('id');
                if ($cardId) {
                    RentalJobCard::withoutGlobalScopes()->find($cardId)?->recalcTotal();
                }
            }

            RentalApprovalDecision::create([
                'agency_id' => $workOrder->agency_id,
                'rental_work_order_id' => $workOrder->id,
                'rental_work_order_variation_id' => $locked->id,
                'rental_work_order_quote_id' => $locked->rental_work_order_quote_id,
                'decided_by' => $contact ? RentalApprovalDecision::BY_OWNER : RentalApprovalDecision::BY_USER,
                'decided_by_user_id' => $user?->id,
                'decided_by_contact_id' => $contact?->id,
                'decision' => $approved ? RentalApprovalDecision::DECISION_APPROVED : RentalApprovalDecision::DECISION_DECLINED,
                'basis' => RentalWorkOrder::BASIS_OWNER_DECISION,
                'term_key' => RentalApprovalDecision::TERM_OWNER_DECISION,
                'term_source' => RentalApprovalDecision::SOURCE_OWNER,
                'amount_tested' => $locked->new_total,
                'baseline_amount' => $locked->baseline_amount,
                'note' => $sentence . ($note ? ' — "' . $note . '"' : ''),
            ]);

            RentalApproval::create([
                'agency_id' => $workOrder->agency_id,
                'rental_work_order_variation_id' => $locked->id,
                'decision' => $approved ? RentalApproval::DECISION_APPROVED : RentalApproval::DECISION_DECLINED,
                'evidence_type' => $via === RentalWorkOrderVariation::VIA_PORTAL ? RentalApproval::EVIDENCE_PORTAL : ($evidence['evidence_type'] ?? RentalApproval::EVIDENCE_VERBAL_NOTE),
                'evidence_text' => $via === RentalWorkOrderVariation::VIA_PORTAL ? $note : ($evidence['evidence_text'] ?? $note),
                'evidence_file_path' => $evidence['evidence_file_path'] ?? null,
                'decided_at' => $decidedAt,
                'recorded_by_user_id' => $user?->id,
                'recorded_by_contact_id' => $contact?->id,
            ]);

            $workOrder->updates()->create([
                'agency_id' => $workOrder->agency_id, 'update_type' => 'variation_decided',
                'note' => $sentence, 'created_by_user_id' => $user?->id,
            ]);

            RentalVariationDecided::dispatch($locked->refresh(), $approved ? 'approved' : 'declined', (string) $via, $user?->id);
        });
    }

    // ───────────────────────── authorisation to proceed ─────────────────────────

    /**
     * §17.6.5 — may work start / be scheduled / be completed by the crew? Authorised when ANY of: an active
     * emergency approval exists; the owner approved; the in-flight grandfathering applies; or the work order is
     * `not_required` AND (a quote is selected OR the job's current owner-facing amount is within the no-approval
     * limit — evaluated now and recorded as an auto-approved decision). NOT authorised when approval is
     * pending/declined, or when nothing is priced yet and there is no emergency approval.
     *
     * $record = false makes it a pure read (for a screen deciding whether to enable a button) — it then writes nothing.
     */
    public function authoriseToProceed(RentalWorkOrder $workOrder, bool $record = true): GateDecision
    {
        if ($this->isEmergency($workOrder)) {
            return new GateDecision(true, RentalApprovalDecision::DECISION_EMERGENCY_COVERED, RentalWorkOrder::BASIS_EMERGENCY, note: 'Approved to proceed as emergency work — the owner agreed.');
        }
        if ($workOrder->approval_basis === RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED) {
            return new GateDecision(true, 'no_change', RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, note: 'Already under way before approvals were recorded.');
        }

        // An outside contractor's whole job is their quote: while a higher (revised) quote waits for the owner there is no approved
        // scope to start on. (An internal job carries on with the approved scope; only the extra waits — §17.7.2.)
        if (! $workOrder->jobCard && ($open = $workOrder->openVariation())) {
            return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED, amountTested: (float) $open->new_total,
                note: 'The contractor\'s revised quote (' . $this->money((float) $open->new_total) . ') is waiting for the owner\'s approval — the work order can go out once the owner has approved it.');
        }

        $status = $workOrder->owner_approval_status;
        if ($status === RentalWorkOrder::APPROVAL_APPROVED) {
            return new GateDecision(true, 'no_change', $workOrder->approval_basis ?? RentalWorkOrder::BASIS_OWNER_DECISION, note: 'The owner approved this work.');
        }
        if ($status === RentalWorkOrder::APPROVAL_PENDING) {
            return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED, note: 'This job has not been approved by the owner yet — it is waiting for the owner\'s decision.');
        }
        if ($status === RentalWorkOrder::APPROVAL_DECLINED) {
            return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED, note: 'The owner declined this work.');
        }

        // not_required
        if ($workOrder->approved_amount !== null && $workOrder->approval_basis === RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT) {
            return new GateDecision(true, 'no_change', RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, note: "Within the owner's no-approval limit.");
        }
        if ($workOrder->quotes()->where('is_selected', true)->exists()) {
            return new GateDecision(true, 'no_change', note: 'A quote is selected and needs no approval.');
        }

        $card = $workOrder->jobCard;
        if ($card) {
            // pricing off for the agency: nothing can ever be priced, so there is no amount for an owner to approve
            if (! RentalWorkOrderSetting::capturePricesOnJobCardsFor($workOrder->agency_id)) {
                return new GateDecision(true, 'no_change', note: 'Pricing is switched off for this agency.');
            }
            if ($record) {
                $card->recalcTotal();   // a pure read (a screen asking) never writes
            }
            $card->refresh();
            $amount = round($this->vat->inclusiveTotal($card), 2);
            if ($amount > 0) {
                $terms = $this->termsForWorkOrder($workOrder);
                if ($amount <= $terms->noApprovalLimit) {
                    $decision = new GateDecision(
                        authorised: true,
                        decision: RentalApprovalDecision::DECISION_AUTO_APPROVED,
                        basis: RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT,
                        termKey: RentalApprovalDecision::TERM_NO_APPROVAL_LIMIT,
                        termValue: $terms->noApprovalLimit,
                        termSource: $terms->limitSource,
                        amountTested: $amount,
                        limitAmount: $terms->noApprovalLimit,
                        note: 'Auto-approved on ' . $this->when() . ' — ' . $this->money($amount) . " is within the owner's no-approval limit of "
                            . $this->money($terms->noApprovalLimit) . ' ' . $this->sourceLabel($terms->limitSource),
                    );
                    if ($record) {
                        $workOrder->forceFill(['approved_amount' => $amount, 'approval_basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT])->save();
                        $this->record($workOrder, $decision, null);
                    }

                    return $decision;
                }

                return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED, amountTested: $amount, limitAmount: $terms->noApprovalLimit,
                    note: 'This job (' . $this->money($amount) . ") is above the owner's no-approval limit of " . $this->money($terms->noApprovalLimit)
                        . ' — send the quote to the owner and wait for the approval before the work starts.');
            }
        }

        return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED,
            note: 'Nothing has been priced or approved for this job yet — price the job and send the quote first, or record the owner\'s emergency approval.');
    }

    /**
     * §17.6.5 for a job card: judged through its work order. A card with NO work order (older rows; §17.3 makes sure new
     * ones always have one) is judged on its own amount against the property's no-approval limit — nothing is recorded
     * (there is no work order to hang a decision on); a job above the limit must be quoted to the owner first, which
     * creates the work order.
     */
    public function authoriseCard(RentalJobCard $card, bool $record = true): GateDecision
    {
        $workOrder = $card->workOrder()->first();
        if ($workOrder) {
            return $this->authoriseToProceed($workOrder, $record);
        }
        if (! RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id)) {
            return new GateDecision(true, 'no_change', note: 'Pricing is switched off for this agency.');
        }

        if ($record) {
            $card->recalcTotal();
        }
        $card->refresh();
        $amount = round($this->vat->inclusiveTotal($card), 2);
        $terms = $card->property ? $this->termsFor($card->property) : null;
        $limit = $terms?->noApprovalLimit ?? RentalWorkOrderSetting::spendThresholdFor($card->agency_id);
        if ($amount > 0 && $amount <= $limit) {
            return new GateDecision(true, RentalApprovalDecision::DECISION_AUTO_APPROVED, RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, amountTested: $amount, limitAmount: $limit,
                note: $this->money($amount) . " is within the owner's no-approval limit of " . $this->money($limit));
        }
        if ($amount > $limit) {
            return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED, amountTested: $amount, limitAmount: $limit,
                note: 'This job (' . $this->money($amount) . ") is above the owner's no-approval limit of " . $this->money($limit)
                    . ' — send the quote to the owner and wait for the approval before the work starts.');
        }

        return new GateDecision(false, RentalApprovalDecision::DECISION_BLOCKED,
            note: 'Nothing has been priced or approved for this job yet — price the job and send the quote first, or record the owner\'s emergency approval.');
    }

    /** The refusal every guard site throws (the plain-language message of authoriseToProceed()). */
    public function assertAuthorised(RentalWorkOrder $workOrder): void
    {
        $decision = $this->authoriseToProceed($workOrder);
        if (! $decision->authorised) {
            throw new \LogicException($decision->note);
        }
    }

    // ───────────────────────── emergency work ─────────────────────────

    /**
     * §17.8 — capture the owner's emergency agreement (no amount). $data: approved_by_name, owner_contact_id?,
     * approved_via, approved_at, reason, reported_by_crew_name?, notes?, attachment_path?. There is no override:
     * this record IS the owner's agreement.
     *
     * @param array<string, mixed> $data
     */
    public function recordEmergency(RentalWorkOrder $workOrder, array $data, User $by): RentalEmergencyApproval
    {
        if (in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            throw new \LogicException('This work order is already closed.');
        }

        $name = trim((string) ($data['approved_by_name'] ?? ''));
        $reason = trim((string) ($data['reason'] ?? ''));
        $via = (string) ($data['approved_via'] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Say who at the owner\'s end agreed to the work.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('Say why this is emergency work.');
        }
        if (! in_array($via, RentalEmergencyApproval::VIAS, true)) {
            throw new \InvalidArgumentException('Say how the owner agreed (phone, WhatsApp, email, in person or other).');
        }
        $approvedAt = isset($data['approved_at']) && $data['approved_at'] ? Carbon::parse($data['approved_at']) : now();
        if ($approvedAt->isFuture()) {
            throw new \InvalidArgumentException('The time the owner agreed cannot be in the future.');
        }

        $ownerContactId = $data['owner_contact_id'] ?? null;
        if ($ownerContactId) {
            $allowed = $this->ownerContacts($workOrder)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if (! in_array((int) $ownerContactId, $allowed, true)) {
                throw new \InvalidArgumentException('That contact is not an owner of this property.');
            }
        }

        return DB::transaction(function () use ($workOrder, $data, $by, $name, $reason, $via, $approvedAt, $ownerContactId) {
            $locked = RentalWorkOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($workOrder->id);
            if ($locked->activeEmergencyApproval()) {
                throw new \LogicException('An emergency approval is already recorded for this work order — void it first if it was a mistake.');
            }

            $approval = RentalEmergencyApproval::create([
                'agency_id' => $locked->agency_id,
                'rental_work_order_id' => $locked->id,
                'approved_by_name' => $name,
                'owner_contact_id' => $ownerContactId ?: null,
                'approved_via' => $via,
                'approved_at' => $approvedAt,
                'reason' => $reason,
                'reported_by_crew_name' => ($data['reported_by_crew_name'] ?? null) ?: null,
                'notes' => ($data['notes'] ?? null) ?: null,
                'attachment_path' => $data['attachment_path'] ?? null,
                'recorded_by_user_id' => $by->id,
            ]);

            $locked->forceFill([
                'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED,
                'approval_basis' => RentalWorkOrder::BASIS_EMERGENCY,
                'approved_amount' => null,
                'emergency_approval_id' => $approval->id,
            ])->save();

            $viaLabel = str_replace('_', ' ', $via);
            $sentence = 'Approved as emergency work on ' . $this->when($approvedAt) . " (owner agreed by {$viaLabel} to {$name}, recorded by {$by->name})";
            RentalApprovalDecision::create([
                'agency_id' => $locked->agency_id,
                'rental_work_order_id' => $locked->id,
                'decided_by' => RentalApprovalDecision::BY_EMERGENCY,
                'decided_by_user_id' => $by->id,
                'decision' => RentalApprovalDecision::DECISION_EMERGENCY_COVERED,
                'basis' => RentalWorkOrder::BASIS_EMERGENCY,
                'term_key' => RentalApprovalDecision::TERM_EMERGENCY,
                'term_source' => RentalApprovalDecision::SOURCE_EMERGENCY,
                'amount_tested' => 0,
                'note' => $sentence,
            ]);
            $locked->updates()->create([
                'agency_id' => $locked->agency_id, 'update_type' => 'emergency_approved',
                'note' => $sentence . ' — reason: ' . $reason, 'created_by_user_id' => $by->id,
            ]);

            // an open request for extra money is moot: no variation applies to emergency work
            $this->withdrawOpenVariations($locked, 'The work was approved as emergency work.', $by);

            $workOrder->refresh();
            RentalEmergencyApprovalRecorded::dispatch($approval, $by->id);

            return $approval;
        });
    }

    /**
     * §17.8.3 — void a mistaken emergency approval (reason required) and re-run the gate on whatever quote is
     * selected (else the work order goes back to waiting for the owner). Returns a plain warning when work is
     * already under way, so the office is told.
     */
    public function voidEmergency(RentalEmergencyApproval $approval, string $reason, User $by): ?string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException('Say why the emergency approval is being voided.');
        }
        if ($approval->isVoided()) {
            throw new \LogicException('This emergency approval was already voided.');
        }

        return DB::transaction(function () use ($approval, $reason, $by) {
            $approval->forceFill(['voided_at' => now(), 'voided_by_user_id' => $by->id, 'void_reason' => $reason])->save();
            $workOrder = RentalWorkOrder::withoutGlobalScopes()->lockForUpdate()->findOrFail($approval->rental_work_order_id);

            $workOrder->forceFill(['emergency_approval_id' => null, 'approval_basis' => null, 'approved_amount' => null])->save();
            $selected = $workOrder->quotes()->where('is_selected', true)->first();
            if ($selected) {
                $this->evaluateQuote($workOrder, $selected->ownerFacingAmount(), $by, $selected);
            } else {
                $workOrder->forceFill(['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING])->save();
                $this->record($workOrder, new GateDecision(false, RentalApprovalDecision::DECISION_NEEDS_OWNER, termKey: RentalApprovalDecision::TERM_OWNER_DECISION, termSource: RentalApprovalDecision::SOURCE_OWNER, amountTested: 0.0,
                    note: 'Emergency approval voided (' . $reason . ') — the owner\'s approval is needed again.'), $by);
            }

            $workOrder->updates()->create([
                'agency_id' => $workOrder->agency_id, 'update_type' => 'emergency_voided',
                'note' => 'Emergency approval voided: ' . $reason, 'created_by_user_id' => $by->id,
            ]);

            $card = $workOrder->jobCard;
            $underWay = in_array($workOrder->status, [RentalWorkOrder::STATUS_ORDERED, RentalWorkOrder::STATUS_IN_PROGRESS], true)
                || ($card && in_array($card->status, [RentalJobCard::STATUS_SCHEDULED, RentalJobCard::STATUS_IN_PROGRESS], true));

            return $underWay
                ? 'Work is already under way on this job without the owner\'s approval — get the owner\'s agreement or approve the quote now.'
                : null;
        });
    }

    /** True when an active (un-voided) emergency approval covers this work order. */
    public function isEmergency(RentalWorkOrder $workOrder): bool
    {
        return $workOrder->approval_basis === RentalWorkOrder::BASIS_EMERGENCY && $workOrder->activeEmergencyApproval() !== null;
    }

    /** The property's owner/landlord contacts — the only people an emergency approval can be attributed to. */
    public function ownerContacts(RentalWorkOrder $workOrder)
    {
        $property = $workOrder->property;
        if (! $property) {
            return collect();
        }
        $roles = ['seller', 'owner', 'landlord', 'lessor'];

        return $property->contacts()->get()
            ->filter(fn ($c) => in_array(strtolower(trim((string) ($c->pivot->role ?? ''))), $roles, true))
            ->unique('id')->values();
    }

    // ───────────────────────── decision rows ─────────────────────────

    private function record(RentalWorkOrder $workOrder, GateDecision $d, ?User $by, ?RentalWorkOrderVariation $variation = null, ?RentalWorkOrderQuote $quote = null): RentalApprovalDecision
    {
        return RentalApprovalDecision::create([
            'agency_id' => $workOrder->agency_id,
            'rental_work_order_id' => $workOrder->id,
            'rental_work_order_variation_id' => $variation?->id,
            'rental_work_order_quote_id' => $quote?->id,
            'decided_by' => $d->decision === RentalApprovalDecision::DECISION_EMERGENCY_COVERED ? RentalApprovalDecision::BY_EMERGENCY : RentalApprovalDecision::BY_SYSTEM,
            'decided_by_user_id' => $by?->id,
            'decision' => $d->decision,
            // `basis` and `term_key` are NOT NULL on the table: a refusal still cites the term it was tested against.
            'basis' => $d->basis ?? match ($d->termKey) {
                RentalApprovalDecision::TERM_VARIATION_TOLERANCE => RentalWorkOrder::BASIS_VARIATION_TOLERANCE,
                RentalApprovalDecision::TERM_OWNER_DECISION => RentalWorkOrder::BASIS_OWNER_DECISION,
                RentalApprovalDecision::TERM_EMERGENCY => RentalWorkOrder::BASIS_EMERGENCY,
                default => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT,
            },
            'term_key' => $d->termKey ?? RentalApprovalDecision::TERM_NO_APPROVAL_LIMIT,
            'term_value' => $d->termValue,
            'term_source' => $d->termSource,
            'amount_tested' => $d->amountTested ?? 0,
            'baseline_amount' => $d->baselineAmount,
            'limit_amount' => $d->limitAmount,
            'note' => $d->note,
        ]);
    }

    // ───────────────────────── words ─────────────────────────

    private function money(float $amount): string
    {
        return 'R' . number_format($amount, 2, '.', ',');
    }

    private function pct(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.') . ' %';
    }

    private function when(?Carbon $at = null): string
    {
        return ($at ?? now())->format('j M H:i');
    }

    private function sourceLabel(string $source, bool $wrap = true): string
    {
        $text = match ($source) {
            WorkTerms::SOURCE_PROPERTY => 'set on this property',
            WorkTerms::SOURCE_AGENCY_DEFAULT => 'agency default',
            default => 'built-in default',
        };

        return $wrap ? "({$text})" : $text;
    }
}
