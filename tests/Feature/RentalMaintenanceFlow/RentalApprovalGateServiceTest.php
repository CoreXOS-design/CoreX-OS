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

    public function test_a_first_quote_within_the_owners_repair_limit_approves_nothing_and_cites_the_limit(): void
    {
        $this->ownerLimits(800.0);
        $wo = $this->externalWorkOrder();

        $decision = $this->gate->evaluateQuote($wo, 620.0, $this->admin);

        // section 22.6: inside the limit an agent MAY authorise it - but nothing is approved until someone presses Authorise
        $this->assertFalse($decision->authorised);
        $this->assertSame('needs_agent', $decision->decision);
        $fresh = $wo->fresh();
        $this->assertSame(RentalWorkOrder::APPROVAL_NOT_REQUIRED, $fresh->owner_approval_status, 'the owner is not asked');
        $this->assertNull($fresh->approved_amount);
        $this->assertNull($fresh->approval_basis);

        $row = $this->rows($wo)->sole();
        $this->assertSame('system', $row->decided_by);
        $this->assertSame('needs_agent', $row->decision);
        $this->assertSame('no_approval_limit', $row->term_key);
        $this->assertSame('800.00', $row->term_value);
        $this->assertSame('owner', $row->term_source);
        $this->assertSame('620.00', $row->amount_tested);
        $this->assertSame('800.00', $row->limit_amount);
        $this->assertStringContainsString("R620.00 is inside the owner's repair limit of R800.00 - an agent can authorise it. Nothing is approved until someone presses Authorise.", $row->note);
    }

    public function test_a_first_quote_over_the_limit_or_with_no_limit_needs_the_owner_and_nothing_is_approved(): void
    {
        $this->ownerLimits(500.0);
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
        $this->assertStringContainsString("is above the owner's repair limit of R500.00 - the owner is asked to approve it.", $row->note);

        // no limit set: the owner is asked, whatever the amount
        $this->ownerLimits(null);
        $none = $this->externalWorkOrder();
        $this->assertSame('needs_owner', $this->gate->evaluateQuote($none, 1.0, $this->admin)->decision);
        $this->assertStringContainsString('the owner has not set a repair limit', $this->rows($none)->sole()->note);
    }

    public function test_exactly_the_limit_is_within_it(): void
    {
        $this->ownerLimits(500.0);
        $wo = $this->externalWorkOrder();

        $this->assertSame('needs_agent', $this->gate->evaluateQuote($wo, 500.00, $this->admin)->decision);
    }

    public function test_the_limit_comes_only_from_the_owners_settings_never_from_the_agency_default_or_the_old_property_figure(): void
    {
        $this->setting(['no_approval_spend_threshold' => 700]);
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 900])->save();
        $wo = $this->externalWorkOrder();

        $this->gate->evaluateQuote($wo, 650.0, $this->admin);

        $row = $this->rows($wo)->sole();
        $this->assertSame('needs_owner', $row->decision, 'neither the agency default nor the old property figure counts as the owner\'s say-so');
        $this->assertSame('owner', $row->term_source);
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

    public function test_an_increase_is_never_approved_just_because_it_is_inside_the_repair_limit(): void
    {
        $this->ownerLimits(5000.0);
        $wo = $this->approved(400, ['approval_basis' => RentalWorkOrder::BASIS_AGENT_REPAIR_LIMIT]);

        $decision = $this->gate->evaluateVariation($wo, 480.0, $this->admin);

        $this->assertFalse($decision->authorised, 'extra money goes to the owner (section 22.6) - R480 is inside R5,000, and still not approved');
        $this->assertSame('needs_owner', $decision->decision);
        $row = $this->rows($wo)->sole();
        $this->assertSame('needs_owner', $row->decision);
        $this->assertSame('400.00', $row->baseline_amount);
    }

    public function test_the_authorised_amount_is_a_hard_lock_the_exact_amount_passes_and_one_cent_over_goes_to_the_owner(): void
    {
        // a stored tolerance (the old work term) is NOT read any more - set it high on the property AND the agency to prove it
        $this->property->forceFill(['rental_variation_tolerance_percent' => 50])->save();
        $this->setting(['variation_tolerance_percent' => 50]);
        $wo = $this->approved(1000);

        $this->assertSame('no_change', $this->gate->evaluateVariation($wo, 1000.00, $this->admin)->decision, 'exactly the authorised amount needs nothing');

        $over = $this->gate->evaluateVariation($wo, 1000.01, $this->admin);
        $this->assertFalse($over->authorised, 'R0.01 above the authorised amount is NOT passed');
        $this->assertSame('needs_owner', $over->decision);
        $row = $this->rows($wo)->sole();
        $this->assertSame('needs_owner', $row->decision);
        $this->assertSame('owner_decision', $row->term_key);
        $this->assertSame('1000.00', $row->limit_amount);
        $this->assertStringContainsString('above what was authorised (R1,000.00)', $row->note);
        $this->assertStringContainsString('extra of R0.01', $row->note);

        $this->assertSame('needs_owner', $this->gate->evaluateVariation($wo, 1500.00, $this->admin)->decision, 'and so does 50 % over');
    }

    public function test_no_tolerance_is_read_anywhere_in_the_terms(): void
    {
        $this->property->forceFill(['rental_variation_tolerance_percent' => 25])->save();
        $terms = $this->gate->termsFor($this->property->fresh());

        $this->assertFalse(property_exists($terms, 'variationPct'));
        $this->assertFalse(property_exists($terms, 'pctSource'));
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

    public function test_a_small_extra_on_a_real_job_goes_to_the_owner_and_the_baseline_does_not_move(): void
    {
        $this->property->forceFill(['rental_variation_tolerance_percent' => 10])->save();   // ignored: the amount is a hard lock
        [$card, $wo] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status, 'R1,000 is over the R500 limit');
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Owner OK']);
        $this->assertSame('1000.00', $wo->fresh()->approved_amount);

        $this->travel(5)->seconds();
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Valve', 'quantity' => 1, 'unit_price' => 80], $this->admin);
        $open = $wo->fresh()->openVariation();
        $this->assertNotNull($open, 'an R80 extra (8 %) is not passed by any tolerance: it waits for the owner');
        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $open->status);
        $this->assertSame('1000.00', $open->baseline_amount);
        $this->assertSame('1000.00', $wo->fresh()->approved_amount, 'the approved amount does not move until the owner decides');

        $this->travel(5)->seconds();
        app(\App\Services\Rentals\RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Pipe', 'quantity' => 1, 'unit_price' => 60], $this->admin);
        $open = $wo->fresh()->openVariation();
        $this->assertSame('140.00', $open->extra_amount, 'the one open request is revised, measured from what the owner approved');
        $this->assertSame(1, $wo->fresh()->variations()->count());
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

        \App\Models\RentalPropertyFaultSetting::withoutGlobalScopes()->create(['agency_id' => $other->id, 'property_id' => $property->id, 'status' => 'confirmed_by_owner', 'repair_limit' => 2500, 'emergency_limit' => null, 'callout_mode' => 'no', 'version' => 1]);
        $this->ownerLimits(100.0);
        $this->assertSame('needs_agent', $this->gate->evaluateQuote($otherWo, 2000.0, $agent)->decision, 'R2,000 is inside the Cape owner\'s own R2,500 limit');
        $this->assertSame('needs_owner', $this->gate->evaluateQuote($ourWo, 2000.0, $this->admin)->decision, 'and outside ours (R100)');
        $this->assertSame(1, RentalApprovalDecision::withoutGlobalScopes()->where('agency_id', $other->id)->count());
        $this->assertSame(1, RentalApprovalDecision::withoutGlobalScopes()->where('agency_id', $this->agency->id)->count());
    }
}
