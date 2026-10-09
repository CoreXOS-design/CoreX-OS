<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Events\AbstractDomainEvent;
use App\Events\Rentals\RentalWorkOrderClosed;
use App\Models\Agency;
use App\Models\Property;
use App\Models\RentalApprovalDecision;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalPortalSetting;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use App\Models\RentalWorkOrderSetting;
use App\Models\RolePermission;
use App\Services\Rentals\GateDecision;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalCloseGuards;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalPricingService;
use App\Services\Rentals\WorkTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.21.1 — the maintenance-flow FOUNDATION: schema, models, constants, service
 * shells, settings accessors, permission keys, events and the inert-until-their-build defaults. It changes no
 * behaviour except the two cost-term renames and the crew-leak stopper (CrewPayloadNeverCarriesSellingTest).
 *
 * No DDL is run inside a test (it would implicitly commit the wrapping transaction); the migrations'
 * data steps (grandfathering, grants) are exercised by calling their up() on rows the test creates.
 */
final class MaintenanceFlowFoundationTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Foundation');
    }

    private function workOrder(array $attrs = []): RentalWorkOrder
    {
        return RentalWorkOrder::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'reported_by_type' => RentalWorkOrder::REPORTED_BY_AGENT_NOTICED, 'reported_by_user_id' => $this->admin->id,
            'title' => 'Geyser burst', 'description' => 'x', 'status' => RentalWorkOrder::STATUS_REPORTED,
            'owner_approval_status' => RentalWorkOrder::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/' . $file);
    }

    // ── schema ──────────────────────────────────────────────────────────

    public function test_every_new_table_and_column_exists(): void
    {
        foreach ([
            'rental_job_card_price_requests', 'rental_work_order_variations', 'rental_property_work_term_changes',
            'rental_approval_decisions', 'rental_emergency_approvals', 'rental_work_completion_rounds',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "missing table {$table}");
        }

        $columns = [
            'rental_job_card_lines' => ['unit_cost', 'cost_total', 'markup_type', 'markup_value', 'selling_basis', 'origin', 'office_status', 'crew_note', 'crew_added_by_label', 'crew_added_at', 'office_decided_by_user_id', 'office_decided_at', 'reject_reason', 'rental_job_card_price_request_id', 'rental_work_order_variation_id'],
            'rental_job_cards' => ['markup_all_percent', 'markup_parts_percent', 'markup_labour_percent', 'total_cost'],
            'rental_catalogue_items' => ['default_cost'],
            'rental_work_order_settings' => ['default_parts_markup_percent', 'default_labour_markup_percent', 'variation_tolerance_percent', 'quote_estimate_term', 'completion_response_window_days', 'tenant_completion_check_enabled', 'notify_landlord_on_dispute', 'notify_landlord_on_auto_variation', 'external_quote_markup_type', 'external_quote_markup_value', 'dispute_notify_crew_immediately', 'show_costs_on_printed_job_card'],
            'rental_portal_settings' => ['crew_link_show_costs'],
            'properties' => ['rental_variation_tolerance_percent', 'rental_work_terms_updated_at', 'rental_work_terms_updated_by_user_id'],
            'rental_work_orders' => ['approved_amount', 'approval_basis', 'emergency_approval_id', 'external_markup_type', 'external_markup_value'],
            'rental_approvals' => ['rental_work_order_variation_id'],
            'rental_work_order_quotes' => ['term_text', 'fee_type', 'fee_value', 'fee_amount', 'selling_amount'],
            'rental_work_order_photos' => ['rental_job_card_line_id', 'rental_completion_round_id'],
            'rental_secure_access_tokens' => ['rental_completion_round_id'],
        ];
        foreach ($columns as $table => $cols) {
            foreach ($cols as $col) {
                $this->assertTrue(Schema::hasColumn($table, $col), "missing {$table}.{$col}");
            }
        }
    }

    public function test_the_two_show_prices_settings_are_renamed_to_cost_terms_and_the_old_columns_are_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('rental_work_order_settings', 'show_prices_on_printed_job_card'));
        $this->assertFalse(Schema::hasColumn('rental_portal_settings', 'crew_link_show_prices'));
        $this->assertTrue(Schema::hasColumn('rental_work_order_settings', 'show_costs_on_printed_job_card'));
        $this->assertTrue(Schema::hasColumn('rental_portal_settings', 'crew_link_show_costs'));
    }

    public function test_the_emergency_approval_table_has_no_cost_column(): void
    {
        // By ruling (R3b) nothing about cost is attached to an emergency approval.
        foreach (Schema::getColumnListing('rental_emergency_approvals') as $col) {
            $this->assertStringNotContainsString('amount', $col);
            $this->assertStringNotContainsString('cost', $col);
            $this->assertStringNotContainsString('price', $col);
        }
    }

    public function test_the_append_only_tables_have_no_updated_at_or_deleted_at(): void
    {
        foreach (['rental_approval_decisions', 'rental_emergency_approvals'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'updated_at'), $table);
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'), $table);
        }
        // No hard deletes anywhere: nothing new carries a delete path, rows are withdrawn / voided / cancelled.
        foreach (['rental_work_order_variations', 'rental_work_completion_rounds', 'rental_job_card_price_requests', 'rental_property_work_term_changes'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'), $table);
        }
    }

    // ── existing rows keep their meaning ────────────────────────────────

    public function test_a_line_created_the_old_way_defaults_to_accepted_office_manual_and_no_cost(): void
    {
        $card = $this->makeJobCard();
        $line = RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'part')->firstOrFail();

        $this->assertSame(RentalJobCardLine::OFFICE_ACCEPTED, $line->office_status);
        $this->assertSame(RentalJobCardLine::ORIGIN_OFFICE, $line->origin);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $line->selling_basis);
        $this->assertNull($line->unit_cost);
        $this->assertNull($line->cost_total);
        $this->assertTrue($line->isAccepted());
        $this->assertSame(2, RentalJobCardLine::where('rental_job_card_id', $card->id)->accepted()->count());
    }

    public function test_scope_accepted_leaves_out_every_other_office_status(): void
    {
        $card = $this->makeJobCard();
        $base = RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'part')->firstOrFail();

        foreach ([RentalJobCardLine::OFFICE_CREW_DRAFT, RentalJobCardLine::OFFICE_AWAITING, RentalJobCardLine::OFFICE_REJECTED, RentalJobCardLine::OFFICE_DECLINED_BY_OWNER] as $status) {
            RentalJobCardLine::create([
                'agency_id' => $this->agency->id, 'rental_job_card_id' => $card->id, 'type' => 'part',
                'description' => "Crew {$status}", 'quantity' => 1, 'unit_cost' => 10, 'cost_total' => 10, 'office_status' => $status,
                'origin' => RentalJobCardLine::ORIGIN_CREW_EXTRA,
            ]);
        }

        $this->assertSame(6, RentalJobCardLine::where('rental_job_card_id', $card->id)->count());
        $this->assertSame(2, RentalJobCardLine::where('rental_job_card_id', $card->id)->accepted()->count());
        $this->assertTrue($base->fresh()->isAccepted());
    }

    // ── settings: neutral defaults, per agency ──────────────────────────

    public function test_every_new_setting_resolves_to_its_neutral_default_with_no_row(): void
    {
        $id = $this->agency->id;

        $this->assertSame(0.0, RentalWorkOrderSetting::defaultPartsMarkupPercentFor($id));
        $this->assertSame(0.0, RentalWorkOrderSetting::defaultLabourMarkupPercentFor($id));
        $this->assertSame(0.0, RentalWorkOrderSetting::variationTolerancePercentFor($id));
        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, RentalWorkOrderSetting::quoteEstimateTermFor($id));
        $this->assertSame(5, RentalWorkOrderSetting::completionResponseWindowDaysFor($id));
        $this->assertTrue(RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($id));
        $this->assertTrue(RentalWorkOrderSetting::notifyLandlordOnDisputeFor($id));
        $this->assertTrue(RentalWorkOrderSetting::notifyLandlordOnAutoVariationFor($id));
        $this->assertSame('percent', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($id));
        $this->assertSame(0.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($id));
        $this->assertFalse(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($id));
        $this->assertFalse(RentalWorkOrderSetting::showCostsOnPrintedJobCardFor($id));
        $this->assertFalse(RentalPortalSetting::crewLinkShowCostsFor($id));
        // never HFC-specific: the estimate wording names no agency
        $this->assertStringNotContainsString('HFC', RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM);
        $this->assertStringNotContainsString('Home Finders', RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM);
    }

    public function test_stored_settings_win_and_only_for_their_own_agency(): void
    {
        $other = Agency::create(['name' => 'Second Agency', 'slug' => 'second-' . uniqid()]);
        RentalWorkOrderSetting::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id,
            'default_parts_markup_percent' => 20, 'default_labour_markup_percent' => 35.5, 'variation_tolerance_percent' => 10,
            'quote_estimate_term' => 'Our own wording.', 'completion_response_window_days' => 9, 'tenant_completion_check_enabled' => false,
            'notify_landlord_on_dispute' => false, 'notify_landlord_on_auto_variation' => false,
            'external_quote_markup_type' => 'amount', 'external_quote_markup_value' => 150, 'dispute_notify_crew_immediately' => true,
            'show_costs_on_printed_job_card' => true,
        ]);
        $id = $this->agency->id;

        $this->assertSame(20.0, RentalWorkOrderSetting::defaultPartsMarkupPercentFor($id));
        $this->assertSame(35.5, RentalWorkOrderSetting::defaultLabourMarkupPercentFor($id));
        $this->assertSame(10.0, RentalWorkOrderSetting::variationTolerancePercentFor($id));
        $this->assertSame('Our own wording.', RentalWorkOrderSetting::quoteEstimateTermFor($id));
        $this->assertSame(9, RentalWorkOrderSetting::completionResponseWindowDaysFor($id));
        $this->assertFalse(RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($id));
        $this->assertFalse(RentalWorkOrderSetting::notifyLandlordOnDisputeFor($id));
        $this->assertFalse(RentalWorkOrderSetting::notifyLandlordOnAutoVariationFor($id));
        $this->assertSame('amount', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($id));
        $this->assertSame(150.0, RentalWorkOrderSetting::externalQuoteMarkupValueFor($id));
        $this->assertTrue(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($id));
        $this->assertTrue(RentalWorkOrderSetting::showCostsOnPrintedJobCardFor($id));

        // the second agency is untouched — still every neutral default
        $this->assertSame(0.0, RentalWorkOrderSetting::defaultPartsMarkupPercentFor($other->id));
        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, RentalWorkOrderSetting::quoteEstimateTermFor($other->id));
        $this->assertSame(5, RentalWorkOrderSetting::completionResponseWindowDaysFor($other->id));
        $this->assertFalse(RentalWorkOrderSetting::showCostsOnPrintedJobCardFor($other->id));
    }

    public function test_a_blank_estimate_term_falls_back_to_the_built_in_wording_and_a_bad_markup_type_to_percent(): void
    {
        RentalWorkOrderSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'quote_estimate_term' => "  \n ", 'external_quote_markup_type' => 'bogus']);

        $this->assertSame(RentalWorkOrderSetting::DEFAULT_QUOTE_ESTIMATE_TERM, RentalWorkOrderSetting::quoteEstimateTermFor($this->agency->id));
        $this->assertSame('percent', RentalWorkOrderSetting::externalQuoteMarkupTypeFor($this->agency->id));
    }

    // ── the owner's work terms (read side) ──────────────────────────────

    public function test_work_terms_resolve_property_then_agency_default_then_constant_and_say_where_from(): void
    {
        $gate = app(RentalApprovalGateService::class);
        // the crew fixtures give their property a very high limit (so jobs under way are authorised) — this test starts from "none"
        $this->property->forceFill(['rental_no_approval_spend_threshold' => null])->save();
        $property = $this->property->fresh();

        $terms = $gate->termsFor($property);
        $this->assertSame(500.0, $terms->noApprovalLimit);
        $this->assertSame(WorkTerms::SOURCE_CONSTANT, $terms->limitSource);
        $this->assertSame(0.0, $terms->variationPct);
        $this->assertSame(WorkTerms::SOURCE_CONSTANT, $terms->pctSource);

        RentalWorkOrderSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 800, 'variation_tolerance_percent' => 5]);
        $terms = $gate->termsFor($property->fresh());
        $this->assertSame(800.0, $terms->noApprovalLimit);
        $this->assertSame(WorkTerms::SOURCE_AGENCY_DEFAULT, $terms->limitSource);
        $this->assertSame(5.0, $terms->variationPct);
        $this->assertSame(WorkTerms::SOURCE_AGENCY_DEFAULT, $terms->pctSource);

        $property->forceFill(['rental_no_approval_spend_threshold' => 1200, 'rental_variation_tolerance_percent' => 12.5])->save();
        $terms = $gate->termsFor($property->fresh());
        $this->assertSame(1200.0, $terms->noApprovalLimit);
        $this->assertSame(WorkTerms::SOURCE_PROPERTY, $terms->limitSource);
        $this->assertSame(12.5, $terms->variationPct);
        $this->assertSame(WorkTerms::SOURCE_PROPERTY, $terms->pctSource);
        $this->assertSame(12.5, RentalWorkOrderSetting::variationToleranceFor($property->fresh()));
        // the existing resolver is untouched and agrees with term (i)
        $this->assertSame(1200.0, RentalWorkOrderSetting::thresholdFor($property->fresh()));
    }

    public function test_a_property_with_a_zero_tolerance_override_is_not_treated_as_inherit(): void
    {
        RentalWorkOrderSetting::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'variation_tolerance_percent' => 15]);
        $this->property->forceFill(['rental_variation_tolerance_percent' => 0])->save();

        $terms = app(RentalApprovalGateService::class)->termsFor($this->property->fresh());

        $this->assertSame(0.0, $terms->variationPct);   // the owner agreed "never" — 0 is a value, not "blank"
        $this->assertSame(WorkTerms::SOURCE_PROPERTY, $terms->pctSource);
    }

    // ── service shells: signatures final, behaviour inert ───────────────

    /** Build 2 landed (§17.21.3): the gate is real now — covered end to end by RentalApprovalGateServiceTest. This only keeps the signatures honest. */
    public function test_the_gate_signatures_are_the_foundations_final_ones(): void
    {
        $gate = app(RentalApprovalGateService::class);
        $wo = $this->workOrder();

        $this->assertInstanceOf(GateDecision::class, $gate->authoriseToProceed($wo, false));
        $this->assertInstanceOf(GateDecision::class, $gate->evaluateVariation($wo, 100.0, $this->admin, false));
        $this->assertNull($gate->assessAfterLineChange($this->makeJobCard(), $this->admin), 'no approved amount yet: nothing to measure a variation against');
    }

    public function test_the_completion_service_is_live_since_build_three(): void
    {
        // Build 3 (§17.10) filled this shell: openRound() opens a real round, settleSilent() settles, sendBack() refuses
        // anything that is not disputed. The behaviour itself is proven in CompletionRoundTest / DisputeLifecycleTest /
        // SettleSilentRoundsCommandTest — here only that the foundation's "inert" contract is retired.
        $service = app(RentalCompletionService::class);
        $wo = $this->workOrder();

        $round = $service->openRound($wo, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
        $this->assertSame(1, $round->round_no);
        $this->assertSame(0, $service->settleSilent(), 'no tenant check was asked, so nothing is due to settle');

        $this->expectException(\LogicException::class);
        $service->sendBack($wo, $this->admin);
    }

    /**
     * Was "reads neutrally and refuses writes until Build 1" — Build 1 (§17.4) has landed, so the shell is now live. What the
     * foundation promised still holds and is what this pins: a pre-existing, hand-priced line is left EXACTLY as it was by the
     * resolver, a reprice and a card markup, and no cost is ever invented for it (the full rule set is RentalPricingServiceTest).
     */
    public function test_the_pricing_service_leaves_a_legacy_hand_priced_line_exactly_as_it_was(): void
    {
        $pricing = app(RentalPricingService::class);
        $card = $this->makeJobCard();
        $line = RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'part')->firstOrFail();

        $resolved = $pricing->resolveSelling($line, $card);
        $this->assertSame(450.0, $resolved->unitPrice);
        $this->assertSame(900.0, $resolved->lineTotal);
        $this->assertSame(RentalJobCardLine::BASIS_MANUAL, $resolved->basis);
        $this->assertSame(2, $pricing->marginFor($card->load('lines'))['linesWithoutCost'], 'both legacy lines have no cost recorded');

        $pricing->repriceCard($card);
        $this->assertSame('900.00', $line->fresh()->line_total);

        $pricing->applyJobMarkup($card, 'parts', 20.0, $this->admin);   // no longer refuses — and still never touches a typed price
        $this->assertSame('900.00', $line->fresh()->line_total);
        $this->assertNull($line->fresh()->cost_total, 'no cost is ever back-filled or invented');
    }

    public function test_the_dispute_guard_no_longer_blocks_a_close_and_the_cost_guard_ignores_unapproved_work(): void
    {
        $guards = app(RentalCloseGuards::class);
        $wo = $this->workOrder(['status' => RentalWorkOrder::STATUS_DISPUTED]);

        // T1 (Johan, 9 Oct 2026) - the tenant's answer never blocks closing: a work order in the disputed stage can still be closed by the agent.
        $guards->assertNotDisputed($wo);
        $guards->assertNotDisputed($this->workOrder());

        $guards->assertFinalCostWithinApproval($wo, 99999.0, $this->admin);   // Build 2: no approved amount, so nothing to measure against
        $this->assertTrue($wo->hasOpenDispute());
        $this->assertFalse($this->workOrder()->hasOpenDispute());
    }

    // ── statuses, constants, hooks ──────────────────────────────────────

    public function test_disputed_is_an_open_status_that_reaches_the_crew(): void
    {
        $card = $this->makeJobCard(['status' => RentalJobCard::STATUS_DISPUTED]);

        $this->assertTrue($card->isDisputed());
        $this->assertFalse($card->isClosed(), 'disputed is an OPEN state');
        $this->assertContains(RentalJobCard::STATUS_DISPUTED, RentalJobCard::CREW_VISIBLE_STATUSES);
        $this->assertNotContains(RentalJobCard::STATUS_DRAFT, RentalJobCard::CREW_VISIBLE_STATUSES);
    }

    public function test_closing_a_work_order_fires_the_closed_event_once_with_the_actor(): void
    {
        Event::fake([RentalWorkOrderClosed::class]);
        $wo = $this->workOrder();

        $wo->complete($this->admin, ['paid_by' => RentalWorkOrder::PAID_BY_OWNER, 'cost_amount' => 100]);

        Event::assertDispatchedTimes(RentalWorkOrderClosed::class, 1);
        Event::assertDispatched(RentalWorkOrderClosed::class, fn (RentalWorkOrderClosed $e) => $e->workOrder->is($wo) && $e->actorUserId === $this->admin->id
            && $e->agencyId() === $this->agency->id && $e->subject() === [RentalWorkOrder::class, $wo->id]);
        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
    }

    public function test_a_refused_close_fires_no_closed_event(): void
    {
        Event::fake([RentalWorkOrderClosed::class]);
        $wo = $this->workOrder(['status' => RentalWorkOrder::STATUS_CANCELLED]);

        try {
            $wo->complete($this->admin, ['paid_by' => RentalWorkOrder::PAID_BY_OWNER]);
            $this->fail('a cancelled work order cannot be completed');
        } catch (\LogicException) {
        }

        Event::assertNotDispatched(RentalWorkOrderClosed::class);
    }

    public function test_all_nine_domain_events_exist_and_are_domain_events(): void
    {
        foreach ([
            'RentalWorkOrderClosed', 'RentalCrewLinesSubmitted', 'RentalCrewLinesDecided', 'RentalVariationRaised',
            'RentalVariationDecided', 'RentalEmergencyApprovalRecorded', 'RentalWorkReportedDone', 'RentalCompletionResponded',
            'RentalCompletionSettledBySilence',
        ] as $name) {
            $class = "App\\Events\\Rentals\\{$name}";
            $this->assertTrue(class_exists($class), $class);
            $this->assertTrue(is_subclass_of($class, AbstractDomainEvent::class), $class);
        }
    }

    public function test_a_tenant_completion_token_with_no_round_is_never_live(): void
    {
        $token = RentalSecureAccessToken::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $this->workOrder()->id,
            'token_hash' => hash('sha256', 'x' . uniqid()), 'purpose' => RentalSecureAccessToken::PURPOSE_TENANT_COMPLETION,
            'expires_at' => now()->addDays(3), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->assertFalse($token->isLive(), 'must not fall through to the contractor rule');
    }

    public function test_a_quote_without_a_fee_is_owner_facing_at_its_own_amount(): void
    {
        $wo = $this->workOrder();
        $quote = RentalWorkOrderQuote::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'amount' => 1000, 'quote_date' => now()->toDateString(),
            'detail_text' => 'x', 'captured_by_user_id' => $this->admin->id,
        ]);

        $this->assertSame(1000.0, $quote->ownerFacingAmount());
        $this->assertSame('0.00', $quote->fresh()->fee_amount);

        $quote->forceFill(['fee_type' => 'percent', 'fee_value' => 10, 'fee_amount' => 100, 'selling_amount' => 1100])->save();

        $this->assertSame(1100.0, $quote->fresh()->ownerFacingAmount());
        $this->assertSame('1000.00', $quote->fresh()->amount, 'the contractor\'s own quote is never overwritten');
    }

    public function test_the_work_order_labels_its_approval_basis_in_plain_words(): void
    {
        $wo = $this->workOrder();
        $this->assertNull($wo->approvalBasisLabel());

        foreach ([
            RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, RentalWorkOrder::BASIS_OWNER_DECISION, RentalWorkOrder::BASIS_VARIATION_TOLERANCE,
            RentalWorkOrder::BASIS_EMERGENCY, RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED,
        ] as $basis) {
            $wo->forceFill(['approval_basis' => $basis])->save();
            $this->assertNotEmpty($wo->fresh()->approvalBasisLabel(), $basis);
        }
    }

    // ── grandfathering (in-flight jobs are never suddenly blocked) ──────

    public function test_in_flight_work_orders_are_grandfathered_and_others_are_not(): void
    {
        $ordered = $this->workOrder(['status' => RentalWorkOrder::STATUS_ORDERED]);
        $inProgress = $this->workOrder(['status' => RentalWorkOrder::STATUS_IN_PROGRESS]);
        $reportedWithScheduledCard = $this->workOrder();
        $reportedWithInProgressCard = $this->workOrder();
        $reportedNoCard = $this->workOrder();
        $completed = $this->workOrder(['status' => RentalWorkOrder::STATUS_COMPLETED]);
        $alreadyHasBasis = $this->workOrder(['status' => RentalWorkOrder::STATUS_IN_PROGRESS, 'approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION]);

        $this->makeJobCard(['rental_work_order_id' => $reportedWithScheduledCard->id, 'status' => RentalJobCard::STATUS_SCHEDULED]);
        $this->makeJobCard(['rental_work_order_id' => $reportedWithInProgressCard->id, 'status' => RentalJobCard::STATUS_IN_PROGRESS]);
        $draftCardWo = $this->workOrder();
        $this->makeJobCard(['rental_work_order_id' => $draftCardWo->id, 'status' => RentalJobCard::STATUS_DRAFT]);

        $this->migration('2026_10_11_100500_add_approval_model_to_rental_work_orders.php')->up();

        $basis = fn (RentalWorkOrder $w) => $w->fresh()->approval_basis;
        $this->assertSame(RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, $basis($ordered));
        $this->assertSame(RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, $basis($inProgress));
        $this->assertSame(RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, $basis($reportedWithScheduledCard));
        $this->assertSame(RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, $basis($reportedWithInProgressCard));
        $this->assertNull($basis($reportedNoCard));
        $this->assertNull($basis($draftCardWo), 'a draft card is not work under way');
        $this->assertNull($basis($completed), 'a closed work order needs no grandfathering');
        $this->assertSame(RentalWorkOrder::BASIS_OWNER_DECISION, $basis($alreadyHasBasis), 'an existing basis is never overwritten');

        // idempotent
        $this->migration('2026_10_11_100500_add_approval_model_to_rental_work_orders.php')->up();
        $this->assertSame(RentalWorkOrder::BASIS_LEGACY_GRANDFATHERED, $basis($ordered));
    }

    // ── permissions ─────────────────────────────────────────────────────

    public function test_the_five_new_permission_keys_are_defined_in_the_role_manager_config(): void
    {
        $byKey = collect(config('corex-permissions.permissions'))->keyBy('key');

        foreach ([
            'rental_job_cards.price', 'rental_job_cards.view_costs', 'rental_work_orders.record_emergency_approval',
            'rental_work_orders.manage_work_terms', 'rental_work_orders.manage_completion',
        ] as $key) {
            $this->assertTrue($byKey->has($key), "missing {$key}");
            $this->assertSame('agency-tracker', $byKey[$key]['section']);
            $this->assertSame('action', $byKey[$key]['type']);
            $this->assertNotEmpty($byKey[$key]['label']);
        }
        // sort orders within a module never collide
        foreach (['rental_work_orders', 'rental_job_cards'] as $module) {
            $orders = $byKey->where('module', $module)->pluck('sort_order');
            $this->assertSame($orders->unique()->count(), $orders->count(), "duplicate sort_order in {$module}");
        }
    }

    public function test_grants_copy_the_source_key_scope_per_role_idempotently(): void
    {
        $agency = $this->agency->id;
        $set = fn (string $role, string $key, string $scope) => RolePermission::updateOrCreate(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency], ['scope' => $scope]);

        $set('admin', 'rental_job_cards.send_quote', 'all');
        $set('branch_manager', 'rental_job_cards.send_quote', 'branch');
        $set('agent', 'rental_job_cards.create', 'own');                 // can create but NOT send quotes → gets no pricing keys
        $set('admin', 'rental_work_orders.record_approval', 'all');
        $set('admin', 'rental_work_orders.manage_settings', 'all');
        $set('branch_manager', 'rental_work_orders.record_approval', 'branch');
        $set('admin', 'rental_work_orders.complete', 'all');
        $set('agent', 'rental_work_orders.complete', 'own');

        $migration = $this->migration('2026_10_11_100800_grant_maintenance_flow_permissions.php');
        $migration->up();
        $migration->up();   // idempotent

        $scope = fn (string $role, string $key) => RolePermission::where(['role' => $role, 'permission_key' => $key, 'agency_id' => $agency])->value('scope');
        $count = fn (string $key) => RolePermission::where(['permission_key' => $key, 'agency_id' => $agency])->count();

        $this->assertSame('all', $scope('admin', 'rental_job_cards.price'));
        $this->assertSame('branch', $scope('branch_manager', 'rental_job_cards.price'));
        $this->assertSame('all', $scope('admin', 'rental_job_cards.view_costs'));
        $this->assertSame('branch', $scope('branch_manager', 'rental_job_cards.view_costs'));
        $this->assertNull($scope('agent', 'rental_job_cards.price'), 'a role that cannot send quotes gets no pricing key');
        $this->assertNull($scope('agent', 'rental_job_cards.view_costs'), 'everyone else sees selling only');
        $this->assertSame(2, $count('rental_job_cards.price'));
        $this->assertSame('all', $scope('admin', 'rental_work_orders.record_emergency_approval'));
        $this->assertSame('branch', $scope('branch_manager', 'rental_work_orders.record_emergency_approval'));
        $this->assertSame('all', $scope('admin', 'rental_work_orders.manage_work_terms'));
        $this->assertNull($scope('branch_manager', 'rental_work_orders.manage_work_terms'), 'owner work terms follow manage_settings holders only');
        $this->assertSame('all', $scope('admin', 'rental_work_orders.manage_completion'));
        $this->assertSame('own', $scope('agent', 'rental_work_orders.manage_completion'));
        $this->assertSame(1, $count('rental_work_orders.manage_work_terms'));
    }

    public function test_the_grants_never_overwrite_a_role_manager_choice_already_made(): void
    {
        $agency = $this->agency->id;
        RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'rental_job_cards.send_quote', 'agency_id' => $agency], ['scope' => 'all']);
        RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'rental_job_cards.price', 'agency_id' => $agency], ['scope' => 'own']);

        $this->migration('2026_10_11_100800_grant_maintenance_flow_permissions.php')->up();

        $this->assertSame('own', RolePermission::where(['role' => 'admin', 'permission_key' => 'rental_job_cards.price', 'agency_id' => $agency])->value('scope'));
    }

    // ── notification keys ───────────────────────────────────────────────

    public function test_the_five_staff_notification_keys_are_registered_for_the_rentals_group(): void
    {
        foreach ([
            'rental_job_card.crew_lines_submitted', 'rental_work_order.variation_raised', 'rental_work_order.disputed',
            'rental_work_order.completion_confirmed', 'rental_work_order.completion_accepted',
        ] as $key) {
            $row = DB::table('notification_event_types')->where('key', $key)->first();
            $this->assertNotNull($row, "unregistered {$key}");
            $this->assertSame('Rentals', $row->group_label);
            $this->assertSame('property', $row->pillar);
            $this->assertSame(1, (int) $row->supports_in_app);
        }

        $this->migration('2026_10_11_100700_register_maintenance_flow_notifications.php')->up();   // idempotent
        $this->assertSame(1, DB::table('notification_event_types')->where('key', 'rental_work_order.disputed')->count());
    }

    // ── models ──────────────────────────────────────────────────────────

    public function test_the_new_models_round_trip_and_stay_agency_scoped(): void
    {
        $wo = $this->workOrder();
        $decision = RentalApprovalDecision::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id,
            'decided_by' => RentalApprovalDecision::BY_SYSTEM, 'decision' => RentalApprovalDecision::DECISION_AUTO_APPROVED,
            'basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, 'term_key' => RentalApprovalDecision::TERM_NO_APPROVAL_LIMIT,
            'term_value' => 800, 'term_source' => RentalApprovalDecision::SOURCE_PROPERTY, 'amount_tested' => 620,
            'limit_amount' => 800, 'note' => 'R620 is within the owner\'s no-approval limit of R800 (set on this property).',
        ]);

        $this->assertNotNull($decision->created_at);
        $this->assertSame($decision->id, $wo->latestApprovalDecision()?->id);
        $this->assertSame('800.00', $decision->fresh()->term_value);

        $emergency = \App\Models\RentalEmergencyApproval::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'approved_by_name' => 'Mrs Owner',
            'approved_via' => 'phone', 'approved_at' => now()->subHour(), 'reason' => 'Burst pipe flooding the kitchen',
            'recorded_by_user_id' => $this->admin->id,
        ]);
        $this->assertSame($emergency->id, $wo->activeEmergencyApproval()?->id);
        $emergency->forceFill(['voided_at' => now(), 'voided_by_user_id' => $this->admin->id, 'void_reason' => 'wrong job'])->save();
        $this->assertNull($wo->fresh()->activeEmergencyApproval());
        $this->assertTrue($emergency->fresh()->isVoided());

        $variation = \App\Models\RentalWorkOrderVariation::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'origin' => 'crew_lines',
            'baseline_amount' => 2000, 'extra_amount' => 300, 'new_total' => 2300, 'raised_at' => now(),
        ]);
        $this->assertTrue($variation->isAwaitingOwner());
        $this->assertSame(1, $wo->variations()->count());

        $round = \App\Models\RentalWorkCompletionRound::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'round_no' => 1, 'opened_at' => now(),
            'reported_via' => 'crew_link', 'sign_off_snapshot' => ['worker' => 'Sam'],
        ]);
        $this->assertTrue($round->isAwaitingTenant());
        $this->assertSame(['worker' => 'Sam'], $round->fresh()->sign_off_snapshot);
        $this->assertSame(1, $wo->completionRounds()->count());

        $request = \App\Models\RentalJobCardPriceRequest::create([
            'agency_id' => $this->agency->id, 'rental_job_card_id' => $this->makeJobCard()->id, 'requested_at' => now(),
            'requested_by_user_id' => $this->admin->id,
        ]);
        $this->assertTrue($request->isOpen());

        // another agency can never see this agency's rows through the model's own scope
        $other = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherUser = \App\Models\User::factory()->create(['agency_id' => $other->id, 'role' => 'admin', 'email' => 'o-' . uniqid() . '@example.invalid']);
        $this->actingAs($otherUser);
        $this->assertSame(0, RentalApprovalDecision::count());
        $this->assertSame(0, \App\Models\RentalWorkCompletionRound::count());
        $this->assertSame(0, \App\Models\RentalWorkOrderVariation::count());
    }

    public function test_the_property_term_fields_are_fillable_and_cast(): void
    {
        $property = Property::find($this->property->id);
        $property->update(['rental_variation_tolerance_percent' => 7.5, 'rental_work_terms_updated_by_user_id' => $this->admin->id, 'rental_work_terms_updated_at' => now()]);

        $fresh = $property->fresh();
        $this->assertSame(7.5, $fresh->rental_variation_tolerance_percent);
        $this->assertSame($this->admin->id, $fresh->rental_work_terms_updated_by_user_id);
        $this->assertNotNull($fresh->rental_work_terms_updated_at);
    }
}
