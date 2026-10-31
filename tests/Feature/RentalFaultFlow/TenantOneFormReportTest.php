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
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Events\Rentals\RentalTenantFaultStatusChanged;
use App\Mail\Rentals\RentalTenantFaultReceivedMail;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31.2 (S2, one click per stage) - the tenant's report is ONE form and ONE submit: the problem type is optional (an
 * untyped report needs the description and is classified by the agent), the success state and Home show the progress line, and the tenant gets a confirmation at
 * report time (a phone push always, an email under the agency's "notify tenant on status change" setting, once).
 */
final class TenantOneFormReportTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Contact $tenant;
    private RentalFaultType $faultType;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'One Form Agency', 'slug' => 'of-' . uniqid()]);
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

    private function tenantPosts(array $body)
    {
        Sanctum::actingAs($this->login($this->tenant), ['client']);

        return $this->postJson("/api/v1/client/rentals/properties/{$this->property->id}/fault-reports", array_merge(['resolution' => 'still_a_problem'], $body));
    }

    public function test_a_report_with_no_type_is_accepted_when_it_has_a_description_and_is_untyped_for_the_agent_to_classify(): void
    {
        $res = $this->tenantPosts(['description' => 'Water is coming through the bedroom ceiling'])->assertCreated();
        $res->assertJsonPath('fault_report.self_resolved', false);
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertNull($f->rental_fault_type_id);
        $this->assertTrue($f->isOtherType());
        $this->assertSame('Water is coming through the bedroom ceiling', $f->title, 'no "Other:" prefix - the tenant never picked "other"');
    }

    public function test_a_report_with_no_type_and_no_description_is_refused_in_words(): void
    {
        $this->tenantPosts([])->assertStatus(422)->assertJsonPath('message', 'Tell us what needs attention.');
        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->count());
    }

    public function test_a_typed_report_needs_no_title_or_description(): void
    {
        $this->tenantPosts(['rental_fault_type_id' => $this->faultType->id])->assertCreated();
        $f = RentalFaultReport::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($this->faultType->id, $f->rental_fault_type_id);
        $this->assertSame('Leaking tap', $f->title);
    }

    public function test_the_response_carries_the_progress_line_so_the_success_card_shows_it(): void
    {
        $res = $this->tenantPosts(['description' => 'Gate will not close'])->assertCreated();
        $this->assertNotEmpty($res->json('fault_report.progress.steps'));
        $this->assertSame('sent_to_agent', $res->json('fault_report.progress.steps.0.key'));
    }

    public function test_the_tenant_is_told_it_arrived_once_by_email_and_phone(): void
    {
        Event::fake([RentalTenantFaultStatusChanged::class]);
        $this->tenantPosts(['description' => 'Gate will not close'])->assertCreated();
        Mail::assertQueued(RentalTenantFaultReceivedMail::class, fn ($m) => $m->hasTo($this->tenant->email));
        Mail::assertQueued(RentalTenantFaultReceivedMail::class, 1);
        Event::assertDispatched(RentalTenantFaultStatusChanged::class, fn ($e) => $e->stepKey === 'sent_to_agent');
    }

    public function test_an_agent_capturing_the_report_does_not_send_the_tenant_a_received_mail(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-fault-reports.store'), [
            'property_id' => $this->property->id, 'reported_by_type' => 'tenant', 'reported_channel' => 'phone', 'title' => 'Phoned in', 'description' => 'Tap',
        ]);
        Mail::assertNotQueued(RentalTenantFaultReceivedMail::class);
    }

    public function test_the_portal_home_has_a_report_a_fault_button_and_the_wizard_has_one_submit_and_a_non_blocking_aid_panel(): void
    {
        $home = file_get_contents(resource_path('views/rentals/portal/_home.blade.php'));
        $this->assertStringContainsString('data-home-report-fault', $home);
        $this->assertStringContainsString("rentals.portal._fault-wizard", $home);
        $this->assertStringContainsString('data-home-open-fault', $home);
        $wizard = file_get_contents(resource_path('views/rentals/portal/_fault-wizard.blade.php'));
        $this->assertSame(1, substr_count($wizard, 'data-fault-submit'));
        $this->assertStringContainsString('data-fault-done-progress', $wizard);
        $this->assertStringNotContainsString("step === 'aid'", $wizard, 'the advice never stands in front of the form');
    }
}
