<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultType;
use App\Models\User;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 13 Oct 2026, Johan: a contractor added as "Steve" of "Cape Town Contractors" was searched, picked and listed as only "Steve".
 * ONE label (AgencyServiceProvider::displayName - business name, then the contact person) and ONE search (scopeMatching - business name,
 * contact person, email, phone, trade) used by the directory, the fault contractor picker, the work order screens and the pipeline dropdown.
 */
final class SupplierLabelAndSearchTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private AgencyServiceProvider $steve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Label Agency', 'slug' => 'lb-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $plumbing = AgencyServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'code' => 'Plumbing', 'label' => 'Plumbing', 'sort_order' => 1, 'is_active' => true]);
        $this->steve = AgencyServiceProvider::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Steve', 'company' => 'Cape Town Contractors', 'email' => 'steve@capetowncontractors.com', 'phone' => '0210212001',
            'specialty' => 'electrician', 'is_active' => true, 'created_by_id' => $this->admin->id, 'callout_fee_mode' => 'yes', 'callout_fee_amount' => 850,
        ]);
        AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'service_provider_id' => $this->steve->id, 'service_type' => $plumbing->code]);
        AgencyServiceProvider::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Other Electrics', 'email' => 'o@example.invalid', 'specialty' => 'electrician', 'is_active' => true, 'created_by_id' => $this->admin->id]);
    }

    public function test_one_label_business_name_first_then_the_contact_person(): void
    {
        $this->assertSame('Cape Town Contractors - Steve', $this->steve->displayName());
        $this->assertSame('Cape Town Contractors - Steve', AgencyServiceProvider::labelFor('Cape Town Contractors', 'Steve'));
        $this->assertSame('Steve', AgencyServiceProvider::labelFor(null, 'Steve'));
        $this->assertSame('Steve', AgencyServiceProvider::labelFor('  ', 'Steve'));
        $this->assertSame('Cape Town Contractors', AgencyServiceProvider::labelFor('Cape Town Contractors', ''));
        $this->assertSame('Acme Plumbing', AgencyServiceProvider::labelFor('Acme Plumbing', 'Acme Plumbing'));
        $this->assertSame('Acme Plumbing', AgencyServiceProvider::labelFor('Acme Plumbing', 'acme plumbing'), 'no duplicate when both are equal');
        $this->assertSame('Unnamed supplier', AgencyServiceProvider::labelFor('', ''));
    }

    public function test_one_search_finds_him_by_business_contact_email_phone_and_trade(): void
    {
        foreach (['Cape', 'Steve', 'contractors', 'plumb', 'Plumbing', 'capetowncontractors', '021021', 'electric', 'ape town'] as $term) {
            $ids = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $this->agency->id)->matching($term)->pluck('id')->all();
            $this->assertContains($this->steve->id, $ids, "searching \"{$term}\" must find him");
        }
        $this->assertSame([], AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $this->agency->id)->matching('zzzz-nothing')->pluck('id')->all());
        // another supplier is not dragged in by the business name
        $this->assertNotContains(
            AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Other Electrics')->value('id'),
            AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $this->agency->id)->matching('Cape')->pluck('id')->all()
        );
        // a blank search is no filter
        $this->assertCount(2, AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $this->agency->id)->matching('  ')->get());
    }

    public function test_the_directory_search_returns_him_showing_the_business_name(): void
    {
        foreach (['Cape', 'Steve', 'plumb'] as $q) {
            $res = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index', ['q' => $q]));
            $res->assertOk()->assertSee('Cape Town Contractors - Steve')->assertDontSee('Other Electrics');
        }
    }

    public function test_the_picker_service_search_and_json_row_use_the_same_rule(): void
    {
        $found = app(\App\Services\DealV2\AgencyServiceProviderService::class)->search($this->agency->id, null, 'Cape');
        $this->assertSame([$this->steve->id], $found->pluck('id')->all());
        $this->assertSame([$this->steve->id], app(\App\Services\DealV2\AgencyServiceProviderService::class)->search($this->agency->id, null, 'plumb')->pluck('id')->all());
        $row = $this->actingAs($this->admin)->getJson(route('deals-v2.suppliers.search', ['q' => 'Steve']))->assertOk()->json('results.0');
        $this->assertSame('Cape Town Contractors - Steve', $row['label']);
        $this->assertSame('Steve', $row['name'], 'the stored contact name is unchanged');
    }

    public function test_the_fault_contractor_picker_labels_him_and_its_search_text_covers_every_field(): void
    {
        $type = RentalFaultType::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine', 'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true]);
        $fault = new RentalFaultReport(['agency_id' => $this->agency->id]);
        $fault->setRelation('faultType', $type);
        $rows = app(RentalFaultContractorService::class)->classify($fault);
        $row = $rows['suits']->firstWhere('id', $this->steve->id);
        $this->assertNotNull($row, 'he suits a plumbing fault by his ticked trade');
        $this->assertSame('Cape Town Contractors - Steve', $row['name']);
        foreach (['cape', 'steve', 'contractors', 'plumb', 'capetowncontractors', '0210212001'] as $needle) {
            $this->assertStringContainsString($needle, $row['search'], $needle);
        }
        $this->assertStringContainsString('Cape Town Contractors - Steve charges a call-out fee of R850', $row['callout_notice']);
    }

    public function test_the_pipeline_supplier_payload_carries_the_label(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/DealV2/WorkOrderController.php'));
        $this->assertSame(2, substr_count($src, "setAttribute('label', \$p->displayName())"));
        $this->assertStringContainsString('x-text="s.label || s.name"', file_get_contents(base_path('resources/views/dr2/_supplier-work-orders.blade.php')));
    }
}
