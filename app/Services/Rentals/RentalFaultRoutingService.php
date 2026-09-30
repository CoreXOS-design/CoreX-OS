<?php

namespace App\Services\Rentals;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalFaultRoutingProfile;
use App\Models\RentalFaultRoutingRule;
use App\Models\RentalFaultType;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.3 — "don't build one way
 * where there are options; build the options and let the agency set it up;
 * it can vary PER PROPERTY." — Johan. Called the moment a fault report is
 * created, BEFORE the existing "agent reviews" step.
 */
class RentalFaultRoutingService
{
    public function resolve(RentalFaultReport $report): RentalFaultRoutingDecision
    {
        $profile = $this->profileFor($report);
        $isOverride = $profile->property_id !== null;

        /** @var RentalFaultType|null $faultType */
        $faultType = $report->faultType;
        $category = $faultType?->category;
        $urgency = $faultType?->urgency;

        // No fault type picked at all — nothing to route by category/urgency
        // against, so the safe, correct answer is the normal review path,
        // not a guess.
        if ($faultType === null) {
            return $this->agentReviewDecision($profile, $isOverride);
        }

        $rule = $this->matchRule($profile, $category, $urgency);
        if ($rule) {
            return $this->decisionForRoute($rule->route, $rule->spend_limit, $rule->agency_service_provider_id, $report, $profile, $isOverride, $rule);
        }

        // No rule matched — fall through to the profile's own
        // emergency_route/non_emergency_route per the fault's urgency.
        if ($urgency === RentalFaultType::URGENCY_EMERGENCY) {
            return match ($profile->emergency_route) {
                RentalFaultRoutingProfile::ROUTE_CARETAKER => $this->decisionForRoute(
                    RentalFaultRoutingProfile::ROUTE_CARETAKER, $profile->emergency_caretaker_spend_limit, null, $report, $profile, $isOverride, null
                ),
                RentalFaultRoutingProfile::ROUTE_SUPPLIER => $this->decisionForRoute(
                    RentalFaultRoutingProfile::ROUTE_SUPPLIER, $profile->emergency_supplier_spend_limit, $profile->emergency_supplier_id, $report, $profile, $isOverride, null
                ),
                default => $this->decisionForRoute(
                    RentalFaultRoutingProfile::ROUTE_OWNER_FIRST, $profile->emergency_owner_first_spend_limit, null, $report, $profile, $isOverride, null
                ),
            };
        }

        return match ($profile->non_emergency_route) {
            RentalFaultRoutingProfile::ROUTE_CARETAKER => $this->decisionForRoute(
                RentalFaultRoutingProfile::ROUTE_CARETAKER, $profile->non_emergency_caretaker_spend_limit, null, $report, $profile, $isOverride, null
            ),
            RentalFaultRoutingProfile::ROUTE_SUPPLIER => $this->decisionForRoute(
                RentalFaultRoutingProfile::ROUTE_SUPPLIER, $profile->non_emergency_supplier_spend_limit, $profile->non_emergency_supplier_id, $report, $profile, $isOverride, null
            ),
            default => $this->agentReviewDecision($profile, $isOverride),
        };
    }

    /** §13.5 — logged only when routing actually changed the path; the plain agent_review default logs nothing (none was made). */
    public function logDecision(RentalFaultReport $report, RentalFaultRoutingDecision $decision): void
    {
        if ($decision->isDefault()) {
            return;
        }

        RentalFaultReportUpdate::create([
            'agency_id' => $report->agency_id,
            'rental_fault_report_id' => $report->id,
            'update_type' => RentalFaultReportUpdate::TYPE_ROUTING_DECISION,
            'note' => $decision->describe(),
            'created_by_user_id' => null,
        ]);
    }

    private function profileFor(RentalFaultReport $report): RentalFaultRoutingProfile
    {
        $override = RentalFaultRoutingProfile::where('agency_id', $report->agency_id)
            ->where('property_id', $report->property_id)
            ->first();

        if ($override) {
            return $override;
        }

        $default = RentalFaultRoutingProfile::where('agency_id', $report->agency_id)
            ->whereNull('property_id')
            ->first();

        // Defensive — every agency is seeded a default profile (migration +
        // AgencyObserver), but never leave a fault unroutable if that row
        // is somehow missing.
        return $default ?? RentalFaultRoutingProfile::make([
            'agency_id' => $report->agency_id,
            'emergency_route' => RentalFaultRoutingProfile::ROUTE_OWNER_FIRST,
            'non_emergency_route' => RentalFaultRoutingProfile::ROUTE_AGENT_REVIEW,
        ]);
    }

    /**
     * §13.3 — most specific first: category+urgency beats category-only or
     * urgency-only beats a wildcard (both null) rule, ties broken by
     * sort_order.
     */
    private function matchRule(RentalFaultRoutingProfile $profile, ?string $category, ?string $urgency): ?RentalFaultRoutingRule
    {
        $rules = RentalFaultRoutingRule::where('rental_fault_routing_profile_id', $profile->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $scored = $rules->map(function ($rule) use ($category, $urgency) {
            $categoryMatches = $rule->category === null || strcasecmp((string) $rule->category, (string) $category) === 0;
            $urgencyMatches = $rule->urgency === null || $rule->urgency === $urgency;
            if (!$categoryMatches || !$urgencyMatches) {
                return null;
            }
            $specificity = ($rule->category !== null ? 2 : 0) + ($rule->urgency !== null ? 1 : 0);

            return ['rule' => $rule, 'specificity' => $specificity];
        })->filter()->values();

        if ($scored->isEmpty()) {
            return null;
        }

        return $scored->sortByDesc('specificity')->first()['rule'];
    }

    private function agentReviewDecision(RentalFaultRoutingProfile $profile, bool $isOverride): RentalFaultRoutingDecision
    {
        return new RentalFaultRoutingDecision(RentalFaultRoutingProfile::ROUTE_AGENT_REVIEW, null, $profile, $isOverride, null);
    }

    private function decisionForRoute(
        string $route,
        ?string $spendLimit,
        ?int $supplierId,
        RentalFaultReport $report,
        RentalFaultRoutingProfile $profile,
        bool $isOverride,
        ?RentalFaultRoutingRule $rule
    ): RentalFaultRoutingDecision {
        $caretaker = $route === RentalFaultRoutingProfile::ROUTE_CARETAKER
            ? $report->property?->caretakerContact()
            : null;
        $supplier = $route === RentalFaultRoutingProfile::ROUTE_SUPPLIER && $supplierId
            ? AgencyServiceProvider::find($supplierId)
            : null;

        // §13.6 — a caretaker route with no caretaker actually linked to the
        // property falls back to agent_review for THIS occurrence rather
        // than routing to nobody; the fault is not silently lost.
        if ($route === RentalFaultRoutingProfile::ROUTE_CARETAKER && $caretaker === null) {
            return $this->agentReviewDecision($profile, $isOverride);
        }
        if ($route === RentalFaultRoutingProfile::ROUTE_SUPPLIER && $supplier === null) {
            return $this->agentReviewDecision($profile, $isOverride);
        }

        return new RentalFaultRoutingDecision(
            $route,
            $spendLimit !== null ? (float) $spendLimit : null,
            $profile,
            $isOverride,
            $rule,
            $caretaker,
            $supplier,
        );
    }
}
