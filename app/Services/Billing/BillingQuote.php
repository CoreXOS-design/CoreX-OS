<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * An immutable answer to "what does this agency owe CoreX this month, and why?"
 *
 * Spec: .ai/specs/agency-billing.md §6  (AT-11)
 *
 * Carries both the COMPUTED list price and the PAYABLE price, always — so a
 * custom amount or a discount is legible AGAINST the list price rather than
 * silently replacing it. The `lines` array is the arithmetic, itemised, so an
 * agency can check our maths by hand. That is the whole point of the page.
 */
final class BillingQuote
{
    /**
     * @param  list<array{group:string,label:string,note:string,qty:int,unit:float,amount:float}>  $lines
     * @param  self::BASIS_*  $basis
     */
    public function __construct(
        public readonly int $agencyId,
        public readonly int $seats,
        public readonly int $branches,
        public readonly int $billableBranches,
        public readonly string $derivedPlan,
        public readonly string $storedPlan,
        public readonly string $planLabel,
        public readonly array $lines,
        public readonly float $computedZar,
        public readonly float $payableZar,
        public readonly string $basis,
        public readonly ?float $customAmountZar = null,
        public readonly ?string $customAmountNote = null,
        public readonly bool $discountActive = false,
        public readonly ?float $discountPercent = null,
        public readonly int $discountMonthsRemaining = 0,
        public readonly ?string $discountEndsOn = null,
        public readonly ?string $discountNote = null,
    ) {
    }

    public const BASIS_AUTOMATIC  = 'automatic';
    public const BASIS_CUSTOM     = 'custom';
    public const BASIS_DISCOUNTED = 'discounted';

    /** The rand value the override/discount takes off the list price (0 when neither). */
    public function savingZar(): float
    {
        return round(max(0.0, $this->computedZar - $this->payableZar), 2);
    }

    /**
     * The lines belonging to one section of the receipt (base | seats | branches).
     *
     * @return list<array{group:string,label:string,note:string,qty:int,unit:float,amount:float}>
     */
    public function linesIn(string $group): array
    {
        return array_values(array_filter($this->lines, fn (array $l) => $l['group'] === $group));
    }

    /** What one section of the receipt adds up to. */
    public function subtotalIn(string $group): float
    {
        return round(array_sum(array_column($this->linesIn($group), 'amount')), 2);
    }

    /** Is the stored plan out of step with what the headcount says it should be? */
    public function planIsDrifting(): bool
    {
        return $this->storedPlan !== $this->derivedPlan;
    }

    /** Nothing to bill — a brand-new agency with no active users yet. */
    public function isEmpty(): bool
    {
        return $this->seats === 0 && $this->billableBranches === 0;
    }
}
