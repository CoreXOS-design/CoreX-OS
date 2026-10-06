<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalApprovalDecision;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Models\User;
use App\Services\Rentals\RentalApprovalGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.6.3 / §17.6.4 — the ONE place the system decides "does this need the owner?".
 * Every branch of the first-quote rule and the five-step variation rule, the no-creep rule (10 % means 10 % above what the owner
 * approved, ONCE), and the decision rows that cite the term relied on (key, value, where it came from, amount tested, ceiling).
 */
final class RentalApprovalGateServiceTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private RentalApprovalGateService $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Gate');
        $this->gate = app(RentalApprovalGateService::class);
    }

    /** @return \Illuminate\Support\Collection<int, RentalApprovalDecision> */
    private function rows(RentalWorkOrder $wo)
    {
        return RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->orderBy('id')->get();
    }

    private function approved(float $amount, array $attrs = []): RentalWorkOrder
    {
        return $this->externalWorkOrder(array_merge([
            'owner_approval_status' => RentalWorkOrder::APPROVAL_APPROVED, 'approved_amount' => $amount, 'approval_basis' => RentalWorkOrder::BASIS_OWNER_DECISION,
        ], $attrs));
    }

    // ── first quote ──────────────────────────────────────────────────

    public function test_a_first_quote_within_the_property_limit_is_auto_approved_and_cites_the_term(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 800])->save();
        $wo = $this->externalWorkOrder();

        $decision = $this->gate->evaluateQuote($wo, 620.0, $this->admin);

        $this->assertTrue($decision->authorised);
        $this->assertSame('auto_approved', $decision->decision);
        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $fresh->owner_approval_status);
        $this->assertSame('620.00', $fresh->approved_amount);
        $this->assertSame(RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, $fresh->approval_basis);

        $row = $this->rows($wo)->sole();
        $this->assertSame('system', $row->decided_by);
        $this->assertSame('auto_approved', $row->decision);
        $this->assertSame('no_approval_limit', $row->term_key);
        $this->assertSame('800.00', $row->term_value);
        $this->assertSame('property', $row->term_source);
        $this->assertSame('620.00', $row->amount_tested);
        $this->assertSame('800.00', $row->limit_amount);
        $this->assertStringContainsString("R620.00 is within the owner's no-approval limit of R800.00 (set on this property)", $row->note);
        $this->assertStringStartsWith('Auto-approved on ', $row->note);
    }

    public function test_a_first_quote_over_the_limit_needs_the_owner_and_nothing_is_approved(): void
    {
        $wo = $this->externalWorkOrder();

        $decision = $this->gate->evaluateQuote($wo, 500.01, $this->admin);

        $this->assertFalse($decision->authorised);
        $this->assertSame('needs_owner', $decision->decision);
        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $fresh->owner_approval_status);
        $this->assertNull($fresh->approved_amount);
        $this->assertNull($fresh->approval_basis);
        $row = $this->rows($wo)->sole();
        $this->assertSame('needs_owner', $row->decision);
        $this->assertSame('500.00', $row->limit_amount);
        $this->assertStringContainsString('is above the owner\'s no-approval limit of R500.00 (built-in default)', $row->note);
    }

    public function test_exactly_the_limit_is_within_it(): void
    {
        $wo = $this->externalWorkOrder();

        $this->assertSame('auto_approved', $this->gate->evaluateQuote($wo, 500.00, $this->admin)->decision);
    }

    public function test_the_row_says_where_the_limit_came_from(): void
    {
        $this->setting(['no_approval_spend_threshold' => 700]);
        $wo = $this->externalWorkOrder();

        $this->gate->evaluateQuote($wo, 650.0, $this->admin);

        $row = $this->rows($wo)->sole();
        $this->assertSame('agency_default', $row->term_source);
        $this->assertStringContainsString('(agency default)', $row->note);
    }

    // ── variations: the five steps ───────────────────────────────────

    public function test_a_decrease_or_no_baseline_is_not_a_decision_and_writes_nothing(): void
    {
        $wo = $this->approved(1000);

        $this->assertSame('no_change', $this->gate->evaluateVariation($wo, 900.0, $this->admin)->decision);
        $this->assertSame('no_change', $this->gate->evaluateVariation($wo, 1000.0, $this->admin)->decision);

        $noBaseline = $this->externalWorkOrder();
        $this->assertSame('no_change', $this->gate->evaluateVariation($noBaseline, 5000.0, $this->admin)->decision);

        $this->assertSame(0, RentalApprovalDecision::withoutGlobalScopes()->count());
    }

    public function test_an_increase_that_stays_within_the_no_approval_limit_is_auto_approved_under_term_one(): void
    {
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 500])->save();
        $wo = $this->approved(400, ['approval_basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT]);

        $decision = $this->gate->evaluateVariation($wo, 480.0, $this->admin);

        $this->assertTrue($decision->authorised);
        $this->assertSame('no_approval_limit', $decision->basis);
        $row = $this->rows($wo)->sole();
        $this->assertSame('auto_approved', $row->decision);
        $this->assertSame('no_approval_limit', $row->term_key);
        $this->assertSame('400.00', $row->baseline_amount);
        $this->assertSame('500.00', $row->limit_amount);
    }

    public function test_the_tolerance_is_measured_above_the_approved_amount_and_the_boundary_is_inclusive(): void
    {
        $this->property->forceFill(['rental_variation_tolerance_percent' => 10])->save();
        $wo = $this->approved(1000);

        $within = $this->gate->evaluateVariation($wo, 1080.0, $this->admin);
        $this->assertTrue($within->authorised);
        $this->assertSame('variation_tolerance', $within->basis);
        $row = $this->rows($wo)->sole();
        $this->assertSame('variation_tolerance', $row->term_key);
        $this->assertSame('10.00', $row->term_value);
        $this->assertSame('property', $row->term_source);
        $this->assertSame('1100.00', $row->limit_amount, 'the ceiling is baseline + 10 %');
        $this->assertStringContainsString('R1,000.00 + 10 % = R1,100.00', $row->note);

        $this->assertTrue($this->gate->evaluateVariation($wo, 1100.00, $this->admin)->authorised, 'exactly the ceiling is inside it');
        $over = $this->gate->evaluateVariation($wo, 1100.01, $this->admin);
        $this->assertFalse($over->authorised);
        $this->assertSame('needs_owner', $over->decision);
        $this->assertStringContainsString('above what the owner approved (R1,000.00) plus the agreed 10 % tolerance (R1,100.00', $this->rows($wo)->last()->note);
    }

    public function test_a_zero_tolerance_sends_every_increase_to_the_owner(): void
    {
        $wo = $this->approved(1000);

        $decision = $this->gate->evaluateVariation($wo, 1000.01, $this->admin);

        $this->assertFalse($decision->authorised);
        $this->assertStringContainsString('no tolerance is agreed', $this->rows($wo)->sole()->note);
    }

    public function test_emergency_work_is_covered_and_never_gated(): void
    {
        $wo = $this->externalWorkOrder();
        $this->gate->recordEmergency($wo, ['approved_by_name' => 'Mrs Owner', 'approved_via' => 'phone', 'approved_at' => now()->subMinutes(5), 'reason' => 'Burst main'], $this->admin);

        $decision = $this->gate->evaluateVariation($wo->fresh(), 250000.0, $this->admin);

        $this->assertTrue($decision->authorised);
        $this->assertSame('emergency_covered', $decision->decision);
        $this->assertSame('emergency', $this->rows($wo)->last()->term_key);
    }

    // ── the no-creep rule, end to end on a real job ──────────────────

    public function test_small_auto_approved_steps_cannot_creep_past_the_ceiling_set_by_what_the_owner_approved(): void
    {
        $this->property->forceFill(['rental_variation_tolerance_percent' => 10])->save();
        [$card, $wo] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status, 'R1,000 is over the R500 limit');
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Owner OK']);
        $this->assertSame('1000.00', $wo->fresh()->approved_amount);

        $this->travel(5)->seconds();
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Valve', 'quantity' => 1, 'unit_price' => 80], $this->admin);
        $first = $wo->fresh()->variations()->sole();
        $this->assertSame(RentalWorkOrderVariation::STATUS_AUTO_APPROVED, $first->status);
        $this->assertSame('1080.00', $first->new_total);
        $this->assertSame('1000.00', $wo->fresh()->approved_amount, 'an auto-approved extra does NOT move the baseline');

        $this->travel(5)->seconds();
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Pipe', 'quantity' => 1, 'unit_price' => 60], $this->admin);
        $open = $wo->fresh()->openVariation();
        $this->assertNotNull($open, '1,140 is 14 % above what the owner approved — NOT 5 % above the last step');
        $this->assertSame('140.00', $open->extra_amount);
        $this->assertSame('1000.00', $open->baseline_amount);
    }

    // ── multi-agency ─────────────────────────────────────────────────

    public function test_a_second_agency_with_its_own_terms_is_judged_by_its_own_terms(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Sea Point', 'agency_id' => $other->id]);
        $agent = User::factory()->create(['agency_id' => $other->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $other->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id, 'title' => '5 Beach Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        \App\Models\RentalWorkOrderSetting::withoutGlobalScopes()->create(['agency_id' => $other->id, 'no_approval_spend_threshold' => 2500, 'variation_tolerance_percent' => 20]);
        $this->setting(['no_approval_spend_threshold' => 100, 'variation_tolerance_percent' => 0]);
        $otherWo = RentalWorkOrder::create([
            'agency_id' => $other->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'title' => 'Gate motor', 'description' => 'x',
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $agent->id,
        ]);
        $ourWo = $this->externalWorkOrder();

        $this->assertSame('auto_approved', $this->gate->evaluateQuote($otherWo, 2000.0, $agent)->decision, 'R2,000 is inside the Cape agency\'s own R2,500 limit');
        $this->assertSame('needs_owner', $this->gate->evaluateQuote($ourWo, 2000.0, $this->admin)->decision, 'and outside ours (R100)');
        $this->assertSame(1, RentalApprovalDecision::withoutGlobalScopes()->where('agency_id', $other->id)->count());
        $this->assertSame(1, RentalApprovalDecision::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }
}
