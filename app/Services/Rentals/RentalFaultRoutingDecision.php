<?php

namespace App\Services\Rentals;

use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalFaultRoutingProfile;
use App\Models\RentalFaultRoutingRule;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.3 — the outcome of
 * RentalFaultRoutingService::resolve(). Plain value object, no persistence
 * of its own — the caller decides what to do with it and
 * RentalFaultRoutingService::logDecision() writes the evidence-log row.
 */
final class RentalFaultRoutingDecision
{
    public function __construct(
        public readonly string $route,
        public readonly ?float $spendLimit,
        public readonly RentalFaultRoutingProfile $profile,
        public readonly bool $isPropertyOverride,
        public readonly ?RentalFaultRoutingRule $rule,
        public readonly ?Contact $caretaker = null,
        public readonly ?AgencyServiceProvider $supplier = null,
    ) {
    }

    public function isDefault(): bool
    {
        return $this->route === RentalFaultRoutingProfile::ROUTE_AGENT_REVIEW && $this->rule === null;
    }

    /** §13.5 — plain-language description of which profile/rule fired, for the evidence log. */
    public function describe(): string
    {
        $source = $this->isPropertyOverride ? 'this property\'s own routing override' : 'the agency default routing profile';
        $ruleText = $this->rule
            ? sprintf(
                'rule matching category=%s/urgency=%s',
                $this->rule->category ?? 'any',
                $this->rule->urgency ?? 'any'
            )
            : 'the profile\'s own default route (no more specific rule matched)';
        $target = match (true) {
            $this->caretaker !== null => "caretaker {$this->caretaker->full_name}",
            $this->supplier !== null => "supplier {$this->supplier->name}",
            $this->route === RentalFaultRoutingProfile::ROUTE_OWNER_FIRST => 'the owner, first',
            default => 'the assigned agent (normal review)',
        };

        return sprintf(
            'Routed via %s (%s) to %s, spend limit %s.',
            $source,
            $ruleText,
            $target,
            $this->spendLimit !== null ? 'R' . number_format($this->spendLimit, 2) : 'none set'
        );
    }
}
