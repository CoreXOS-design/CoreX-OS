<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\User;
use App\Services\Rentals\FirstResponderService;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md 29.R3 (Johan, 20 Oct 2026): the property's default contractor (section 21 first responder) is the one that loads
 * on "Who goes to look first?" and "Create work order" when the owner has not picked one. No default = nothing is ticked, the agent picks. The contractor
 * the agent proposed to the owner is not a pick by itself, and a contractor is never guessed from list position or from there being only one.
 */
final class ContractorDefaultOnlyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private RentalFaultType $plumbing;
    private AgencyServiceProvider $defaultMan;
    private AgencyServiceProvider $plumber;
    private AgencyServiceProvider $ownersPick;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = Agency::create(['name' => 'Default Only Agency', 'slug' => 'do-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'DO Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->plumbing = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->plumber = $this->supplier('Drip Plumbing');
        $this->defaultMan = $this->supplier('Dee Fault Contractor');
        $this->ownersPick = $this->supplier('Owner Preferred Plumbing');
    }

    private function supplier(string $name): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => $name, 'specialty' => 'plumber', 'kind' => 'contractor', 'is_active' => true,
            'email' => strtolower(preg_replace('/\W+/', '', $name)) . '@example.invalid', 'phone' => '0825550000',
        ]);
    }

    private function setDefault(AgencyServiceProvider $s): void
    {
        app(FirstResponderService::class)->save($this->property, [['supplier_id' => $s->id, 'fault_scope' => 'all', 'fault_type_ids' => [], 'tell_mode' => 'inspect']], $this->agent);
    }

    private function fault(array $over = []): RentalFaultReport
    {
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);

        return RentalFaultReport::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $lease->id,
            'rental_fault_type_id' => $this->plumbing->id, 'reported_by_type' => 'agent_noticed', 'reported_by_user_id' => $this->agent->id,
            'reported_channel' => 'phone', 'title' => 'Tap leaks', 'description' => 'The kitchen tap drips',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ], $over));
    }

    private function svc(): RentalFaultContractorService
    {
        return app(RentalFaultContractorService::class);
    }

    /** The agent has the fault with the owner and a contractor proposed (the "Send to owner to authorise" press), nothing decided yet. */
    private function proposedFault(AgencyServiceProvider $proposed): RentalFaultReport
    {
        return $this->fault([
            'status' => RentalFaultReport::STATUS_AWAITING_APPROVAL, 'owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now(),
            'proposed_assignment' => 'outside_supplier', 'proposed_supplier_id' => $proposed->id,
        ]);
    }

    /** What "Who goes to look first?" ticks, read from the x-data the page ships (digits only: "" = nothing). */
    private function decisionOneTicked(string $html): string
    {
        $this->assertSame(1, preg_match('/data-decision-one\s+x-data="\{\s*q: \'\', picked: (.*?), crew:/s', $html, $m), 'Decision 1 block is on the page');

        return preg_replace('/\D/', '', str_replace(['"', '&#039;'], '', $m[1]));
    }

    public function test_no_default_contractor_means_nothing_is_loaded_even_with_a_proposed_one_and_with_only_one_contractor(): void
    {
        // the agency has several contractors, none is the property's default, and the agent had proposed one to the owner
        $fault = $this->proposedFault($this->plumber);
        $this->assertNull($this->svc()->defaultSupplierId($fault));

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertSame('', $this->decisionOneTicked($html), 'the proposed contractor is not ticked in "Who goes to look first?"');

        // owner approved, agency appoints, nobody named: "Create work order" opens with nobody chosen
        $fault->forceFill(['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => 'agency_appoints'])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-raise-work-order', $html);
        $this->assertStringContainsString("picked: ''", $html);
        $this->assertStringContainsString('name="agency_service_provider_id" value=""', $html);

        // and an agency with exactly ONE contractor still gets no guess
        foreach ([$this->defaultMan, $this->ownersPick] as $s) {
            $s->delete();
        }
        $this->assertNull($this->svc()->defaultSupplierId($this->fault()));
    }

    public function test_the_property_default_is_what_loads_and_the_proposed_contractor_does_not_replace_it(): void
    {
        $this->setDefault($this->defaultMan);
        $fault = $this->proposedFault($this->plumber);

        $this->assertSame($this->defaultMan->id, $this->svc()->defaultSupplierId($fault));
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertSame((string) $this->defaultMan->id, $this->decisionOneTicked($html));

        $fault->forceFill(['status' => RentalFaultReport::STATUS_APPROVED, 'owner_approval_status' => RentalFaultReport::APPROVAL_APPROVED, 'approval_route' => 'agency_appoints'])->save();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString("picked: '" . $this->defaultMan->id . "'", $html);
        $this->assertStringContainsString('name="agency_service_provider_id" value="' . $this->defaultMan->id . '"', $html);
    }

    public function test_the_contractor_the_owner_picked_wins_over_the_property_default(): void
    {
        $this->setDefault($this->defaultMan);
        $fault = $this->proposedFault($this->plumber);
        $fault->recordApproval($this->agent, [
            'decision' => 'approved', 'approval_route' => 'agency_appoints', 'agency_service_provider_id' => $this->ownersPick->id,
            'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner named their own preferred plumber from the list.',
        ]);
        $fault = $fault->fresh();

        $this->assertSame($this->ownersPick->id, $this->svc()->defaultSupplierId($fault));
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString("picked: '" . $this->ownersPick->id . "'", $html);
    }

    public function test_an_owner_on_their_link_who_says_you_arrange_it_does_not_turn_the_proposed_contractor_into_their_pick(): void
    {
        $this->setDefault($this->defaultMan);
        $fault = $this->proposedFault($this->plumber);
        $owner = \App\Models\Contact::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid',
        ]);
        $fault->recordApproval($owner, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'portal', 'evidence_text' => null]);

        $this->assertSame($this->defaultMan->id, $this->svc()->defaultSupplierId($fault->fresh()), 'the agent proposed the plumber, the owner only said "you arrange it": the property default loads');
    }

    public function test_when_the_agent_records_the_owners_decision_the_contractor_chosen_with_it_is_kept(): void
    {
        $this->setDefault($this->defaultMan);
        $fault = $this->proposedFault($this->plumber);
        $fault->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner said yes by phone.']);

        $this->assertSame($this->plumber->id, $this->svc()->defaultSupplierId($fault->fresh()), 'spec 28.2: the agent\'s pick travels with the decision they record');
    }

    public function test_the_owners_own_contractor_loads_no_supplier(): void
    {
        $this->setDefault($this->defaultMan);
        $fault = $this->proposedFault($this->plumber);
        $fault->recordApproval($this->agent, [
            'decision' => 'approved', 'approval_route' => 'owner_handles', 'contractor_name' => 'Owner Man', 'contractor_phone' => '0821110000',
            'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner uses their own man.',
        ]);

        $this->assertNull($this->svc()->defaultSupplierId($fault->fresh()), 'the owner handles it: no agency contractor is loaded');
    }

    public function test_a_default_that_is_no_longer_active_loads_nothing(): void
    {
        $this->setDefault($this->defaultMan);
        $this->defaultMan->forceFill(['is_active' => false])->save();

        $this->assertNull($this->svc()->defaultSupplierId($this->fault()), 'an inactive supplier is never offered, so never ticked');
    }
}
