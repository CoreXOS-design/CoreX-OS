<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalFaultType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AT-452 (Johan, 19 Oct 2026) - "Other - not on the list" is always the last choice on the problem list (tenant link and agent form): a required
 * description, no suggested self-checks, shown to the agent as "Other" with the tenant's words, re-classifiable later (logged), never a
 * "no contractor for this type" case, and the owner still sees only the agent's version. .ai/specs/rentals-faults-work-orders.md section 27.
 */
final class OtherFaultTypeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Other Type Agency', 'slug' => 'ot-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $branch->id, 'title' => 'Other Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'property_id' => $this->property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.com']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.com']);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function login(Contact $c): ClientUser
    {
        $cu = ClientUser::firstOrCreate(['email' => $c->email], ['current_agency_id' => $c->agency_id]);
        $c->forceFill(['client_user_id' => $cu->id])->saveQuietly();

        return $cu;
    }

    private function tenantPostsOther(array $extra = [])
    {
        Sanctum::actingAs($this->login($this->tenant), ['client']);

        return $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", array_merge([
            'other_type' => 1, 'resolution' => 'still_a_problem', 'description' => 'The garden tap spits brown water and the pump hums all night',
        ], $extra));
    }

    private function otherFault(): RentalFaultReport
    {
        $id = $this->tenantPostsOther()->assertCreated()->json('fault_report.id');

        return RentalFaultReport::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_the_tenant_can_report_other_with_only_a_description_and_it_carries_no_type_and_no_self_checks(): void
    {
        $res = $this->tenantPostsOther()->assertCreated();
        $res->assertJsonPath('fault_report.fault_type_name', 'Other')->assertJsonPath('fault_report.self_resolved', false);

        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($f->rental_fault_type_id);
        $this->assertTrue($f->isOtherType());
        $this->assertSame('Other', $f->typeName());
        $this->assertSame('The garden tap spits brown water and the pump hums all night', $f->description);
        $this->assertStringStartsWith('Other: The garden tap spits brown water', $f->title);
        $this->assertNull($f->tenant_self_checks);
        $this->assertSame(RentalFaultReport::STATUS_REPORTED, $f->status);
    }

    public function test_other_needs_a_description_and_can_never_be_that_fixed_it(): void
    {
        $this->tenantPostsOther(['description' => ''])->assertStatus(422);
        $this->tenantPostsOther(['description' => '   '])->assertStatus(422);
        $this->tenantPostsOther(['resolution' => 'first_aid_resolved'])->assertStatus(422);
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_the_normal_catalogue_report_is_unchanged(): void
    {
        Sanctum::actingAs($this->login($this->tenant), ['client']);
        $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", [
            'rental_fault_type_id' => $this->faultType->id, 'resolution' => 'still_a_problem', 'title' => 'Leaking tap', 'description' => 'Drips',
            'self_checks' => json_encode([['text' => 'Close the valve.', 'ticked' => true]]),
        ])->assertCreated()->assertJsonPath('fault_report.fault_type_name', 'Leaking tap');
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->faultType->id, (int) $f->rental_fault_type_id);
        $this->assertFalse($f->isOtherType());
        $this->assertCount(1, $f->tenantSelfChecks());

        // and a report with neither a type nor "other" is still refused
        $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", ['resolution' => 'still_a_problem', 'title' => 'x', 'description' => 'y'])->assertStatus(422);
    }

    public function test_both_forms_offer_other_as_the_last_choice_on_top_of_the_agencys_own_list(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.create', ['property_id' => $this->property->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Other — not on the list', $html);
        $this->assertGreaterThan(strpos($html, 'Leaking tap'), strpos($html, 'data-fault-type-other'), 'Other comes after the agency list');

        $shell = file_get_contents(base_path('resources/views/rentals/portal/shell.blade.php'));
        $this->assertStringContainsString('data-fault-type-other', $shell);
        $this->assertStringContainsString('What needs attention?', $shell);
        $this->assertLessThan(strpos($shell, 'data-fault-type-other'), strpos($shell, 'faultTypesByProperty[faultWizard.property]'), 'Other comes after the agency list on the tenant link');
    }

    public function test_the_agent_form_logs_other_the_same_way(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id, 'rental_fault_type_id' => 'other', 'reported_by_type' => RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_channel' => RentalFaultReport::CHANNEL_PHONE, 'title' => 'Strange noise', 'description' => 'A humming noise from the pool pump room',
        ])->assertRedirect();
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($f->rental_fault_type_id);
        $this->assertTrue($f->isOtherType());

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('data-type-other', $html);
        $this->assertStringContainsString('A humming noise from the pool pump room', $html);
    }

    public function test_the_agent_sees_other_with_the_tenants_words_and_can_reclassify_it_and_it_is_logged(): void
    {
        $f = $this->otherFault();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->assertOk()->getContent();
        $this->assertStringContainsString('data-type-other', $html);
        $this->assertStringContainsString('The garden tap spits brown water', $html);
        $this->assertStringContainsString('data-reclassify', $html);

        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.reclassify', $f), ['rental_fault_type_id' => $this->faultType->id])->assertRedirect();
        $f = $f->fresh();
        $this->assertSame($this->faultType->id, (int) $f->rental_fault_type_id);
        $this->assertFalse($f->isOtherType());
        $this->assertSame('Leaking tap', $f->typeName());
        $this->assertSame('The garden tap spits brown water and the pump hums all night', $f->description, 'the tenant\'s words are untouched');
        $log = $f->updates()->where('update_type', RentalFaultReportUpdate::TYPE_TYPE_CHANGED)->first();
        $this->assertNotNull($log);
        $this->assertSame($this->agent->id, (int) $log->created_by_user_id);
        $this->assertStringContainsString('"Other"', $log->note);
        $this->assertStringContainsString('"Leaking tap"', $log->note);

        // once re-classified there is nothing left to re-classify, and the form is gone
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.reclassify', $f), ['rental_fault_type_id' => $this->faultType->id])->assertStatus(409);
        $this->assertStringNotContainsString('data-reclassify', $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $f))->getContent());
    }

    public function test_reclassifying_refuses_another_agencys_type_and_a_normal_typed_fault(): void
    {
        $f = $this->otherFault();
        $other = Agency::create(['name' => 'Elsewhere', 'slug' => 'el-' . uniqid()]);
        $foreign = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $other->id, 'name' => 'Foreign', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'x', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.reclassify', $f), ['rental_fault_type_id' => $foreign->id])->assertSessionHasErrors('rental_fault_type_id');
        $this->assertTrue($f->fresh()->isOtherType());

        $typed = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'agent_noticed', 'reported_channel' => 'phone',
            'title' => 'Typed', 'description' => 'd', 'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.reclassify', $typed), ['rental_fault_type_id' => $this->faultType->id])->assertStatus(409);
    }

    public function test_the_no_contractor_warning_never_fires_just_because_it_is_other(): void
    {
        // the agency has NO contractors at all: a typed fault is a "no contractor" case, an Other fault is not
        $other = $this->otherFault();
        $svc = app(RentalFaultContractorService::class);
        $this->assertTrue($svc->hasContractorFor($other));

        $typed = $other->replicate();
        $typed->forceFill(['rental_fault_type_id' => $this->faultType->id, 'fault_type_other' => false])->save();
        $this->assertFalse($svc->hasContractorFor($typed->fresh()), 'control: the same agency with a typed fault does read as no contractor');

        $other->saveOwnerVersion(['owner_title' => 'Garden tap and pump', 'owner_description' => 'Brown water from the garden tap.'], $this->agent);
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.send-to-owner', $other))->assertRedirect();
        $this->assertSame(0, $other->updates()->where('update_type', RentalFaultReportUpdate::TYPE_SENT_WITHOUT_CONTRACTOR)->count());
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $other))->getContent();
        $this->assertStringNotContainsString('No contractor loaded for', $html);
    }

    public function test_the_owner_sees_only_the_agents_version_of_an_other_fault(): void
    {
        $f = $this->otherFault();
        $f->saveOwnerVersion(['owner_title' => 'Garden tap and pump noise', 'owner_description' => 'The garden tap runs discoloured and the pump is noisy.'], $this->agent);
        $f->fresh()->requestApproval($this->agent);

        Sanctum::actingAs($this->login($this->landlord), ['client']);
        foreach (["/fault-reports/{$f->id}", '/fault-reports'] as $path) {
            $raw = $this->getJson('/api/v1/client/rentals/landlord' . $path)->assertOk()->getContent();
            $this->assertStringNotContainsString('brown water', $raw);
            $this->assertStringNotContainsString('hums all night', $raw);
        }
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$f->id}")->assertOk()
            ->assertJsonPath('fault_report.title', 'Garden tap and pump noise')
            ->assertJsonPath('fault_report.awaiting_decision', true);
    }
}
