<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-inspection-form.md §7 — an agent's own judgement on one
 * item's in-vs-out comparison finding on a specific out-inspection.
 * Deliberately does NOT store the classification itself (unchanged/
 * improved/declined/...) — that's always re-derived at read time by
 * RentalInspectionComparisonService, never a column here, for the same
 * reason RentalInspectionItem::currentObservation() is a query, not a
 * cached field: it can never drift out of sync with the observations it
 * describes.
 *
 * Audit A24 (2026-10-19) - Johan's ruling: money stays with reOS. A finding may RECORD an optional `amount` (rand) the agent has in mind
 * for the item, and that is all: no currency column, no deduction, nothing here turns it into an invoice or a payout.
 *
 * Audit 2026-10-19 B1 (same ruling, applied to deductions): a `charge_tenant` finding REQUIRES an `amount` above zero - that is a deposit
 * deduction - and the amounts of those findings roll up to the lease's "Deposit deductions" summary (RentalDepositDeductionsService) for the
 * agent to carry across to the accounting system. CoreX computes nothing else, pays nothing and settles nothing.
 */
class RentalInspectionItemFinding extends Model
{
    use BelongsToAgency;

    public const DISPOSITION_WEAR_AND_TEAR = 'wear_and_tear';
    public const DISPOSITION_FLAGGED = 'flagged';
    // §45.7a (Build I-7) — three more agent judgements. `disposition` is string(20), so no schema change; every key is
    // <= 20 characters. `charge_tenant` rows are the agent's recorded judgement and the intended SOURCE of deduction lines
    // when the finance build (parked I-8) is built — nothing in this module turns any of them into money.
    public const DISPOSITION_PRE_EXISTING = 'pre_existing';
    public const DISPOSITION_LANDLORD_COST = 'landlord_cost';
    public const DISPOSITION_CHARGE_TENANT = 'charge_tenant';

    /**
     * key => the words the screen shows. A judgement the AGENT recorded, never a computed fact. The three new
     * labels follow the spec's own wording (pre-existing, landlord's responsibility, charge to tenant). These are the
     * DEFAULTS and the fixed key list: each agency may reword the three move-out ones
     * (RentalInspectionSetting::dispositionLabelsFor(), §45.14) — validation and logic use the KEYS only.
     */
    public const DISPOSITION_LABELS = [
        self::DISPOSITION_WEAR_AND_TEAR => 'Fair wear and tear',
        self::DISPOSITION_FLAGGED => 'Flagged as a genuine difference',
        self::DISPOSITION_PRE_EXISTING => 'Pre-existing',
        self::DISPOSITION_LANDLORD_COST => 'Landlord\'s responsibility',
        self::DISPOSITION_CHARGE_TENANT => 'Charge to tenant',
    ];

    protected $fillable = [
        'agency_id',
        'rental_inspection_id',
        'rental_inspection_item_id',
        'disposition',
        'note',
        'amount',
        'recorded_by_user_id',
        'recorded_at',
        'superseded_at',
        'superseded_by_finding_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recorded_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(RentalInspectionItem::class, 'rental_inspection_item_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    /** The row this one was replaced by, if any. Never null'd out — the chain is the record. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_finding_id');
    }

    /**
     * Record or correct an agent's judgement for one item on one
     * out-inspection. If a live (non-superseded) finding already exists for
     * this item on this inspection, it is superseded first — never edited
     * in place, matching RentalInspectionSignature::supersedeWetInk()'s own
     * transaction shape.
     */
    public static function record(RentalInspection $outInspection, RentalInspectionItem $item, string $disposition, string $note, \App\Models\User $recordedBy, ?float $amount = null): self
    {
        if (! array_key_exists($disposition, self::DISPOSITION_LABELS)) {
            throw new \InvalidArgumentException("Unknown disposition: {$disposition}");
        }
        if (trim($note) === '') {
            throw new \InvalidArgumentException('A finding requires a note — the agent\'s reasoning is the record.');
        }
        // Johan's ruling 20 Oct 2026: money is not tracked unless the agency turns "Record deduction amounts" on. Off: no amount is required
        // and none is stored (a submitted one is dropped, not kept out of sight).
        $recordAmounts = \App\Models\RentalInspectionSetting::recordDeductionAmountsFor($outInspection->agency_id);
        if (! $recordAmounts) {
            $amount = null;
        }
        // Audit B1 - with amounts on, a charge to the tenant is a deposit deduction: it REQUIRES an amount above zero.
        if ($recordAmounts && $disposition === self::DISPOSITION_CHARGE_TENANT && ($amount === null || $amount <= 0)) {
            throw new \InvalidArgumentException('A charge to the tenant needs an amount greater than zero.');
        }
        if ($amount !== null && ($amount < 0 || $amount > 99999999.99)) {
            throw new \InvalidArgumentException('The amount must be between 0 and 99,999,999.99.');
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($outInspection, $item, $disposition, $note, $recordedBy, $amount) {
            $existing = self::where('rental_inspection_id', $outInspection->id)
                ->where('rental_inspection_item_id', $item->id)
                ->whereNull('superseded_at')
                ->first();

            $replacement = self::create([
                'agency_id' => $outInspection->agency_id,
                'rental_inspection_id' => $outInspection->id,
                'rental_inspection_item_id' => $item->id,
                'disposition' => $disposition,
                'note' => $note,
                'amount' => $amount !== null ? round($amount, 2) : null,
                'recorded_by_user_id' => $recordedBy->id,
                'recorded_at' => now(),
            ]);

            if ($existing) {
                $existing->forceFill([
                    'superseded_at' => now(),
                    'superseded_by_finding_id' => $replacement->id,
                ])->save();

                // §45.8 (Build I-6b) — a classification that replaces an earlier one is a judgement being revised.
                RentalInspectionAuditLog::record(
                    $outInspection,
                    RentalInspectionAuditLog::EVENT_FINDING_SUPERSEDED,
                    'Finding on "' . ($item->label ?? 'item') . '" changed from ' . str_replace('_', ' ', (string) $existing->disposition) . ' to ' . str_replace('_', ' ', $disposition)
                        . ($existing->amount !== null || $replacement->amount !== null
                            ? ' (amount ' . ($existing->amount !== null ? 'R' . number_format((float) $existing->amount, 2) : 'none') . ' to ' . ($replacement->amount !== null ? 'R' . number_format((float) $replacement->amount, 2) : 'none') . ')'
                            : '') . '.',
                    ['finding_id' => $existing->id, 'disposition' => $existing->disposition, 'amount' => $existing->amount],
                    ['finding_id' => $replacement->id, 'disposition' => $disposition, 'amount' => $replacement->amount],
                    $recordedBy,
                );
            } else {
                // Audit A24 - the first judgement on an item is on the record too (it can now carry an amount).
                RentalInspectionAuditLog::record(
                    $outInspection,
                    RentalInspectionAuditLog::EVENT_FINDING_RECORDED,
                    'Finding on "' . ($item->label ?? 'item') . '" recorded as ' . str_replace('_', ' ', $disposition)
                        . ($replacement->amount !== null ? ' (amount R' . number_format((float) $replacement->amount, 2) . ')' : '') . '.',
                    null,
                    ['finding_id' => $replacement->id, 'disposition' => $disposition, 'amount' => $replacement->amount],
                    $recordedBy,
                );
            }

            return $replacement;
        });
    }
}
