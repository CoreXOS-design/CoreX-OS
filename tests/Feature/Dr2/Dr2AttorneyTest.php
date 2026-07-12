<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-217 (DR2 walk fix 2) — attorney respec: a supplier is a FIRM with 1..n contact
 * persons; the deal links FIRM + the specific contact. Johan's case: BBB Inc has
 * attorney X (via his assistant) and attorney Y (via his paralegal).
 */
final class Dr2AttorneyTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Att Co', 'slug' => 'att-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => 'super_admin', 'is_admin' => true, 'is_active' => true,
        ]);
    }

    public function test_one_firm_holds_multiple_attorney_contacts(): void
    {
        // Attorney X via his assistant.
        $r1 = $this->actingAs($this->admin)->postJson(route('deals-dr2.attorney.inline'), [
            'firm' => 'BBB Inc', 'attorney' => 'Attorney X', 'contact' => 'His Assistant',
            'email' => 'x@bbb.example', 'address' => '1 Law St, Margate',
        ]);
        $r1->assertCreated();
        $firmId = $r1->json('provider_id');
        $this->assertNotNull($firmId);
        $this->assertStringContainsString('BBB Inc', (string) $r1->json('label'));
        $this->assertStringContainsString('via His Assistant', (string) $r1->json('label'));

        // Attorney Y via his paralegal — SAME firm (find-or-create), new contact.
        $r2 = $this->actingAs($this->admin)->postJson(route('deals-dr2.attorney.inline'), [
            'firm' => 'BBB Inc', 'attorney' => 'Attorney Y', 'contact' => 'His Paralegal',
        ]);
        $r2->assertCreated();
        $this->assertSame($firmId, $r2->json('provider_id'), 'same firm reused, not duplicated');

        // One firm, two contacts.
        $this->assertSame(1, AgencyServiceProvider::withoutGlobalScopes()->where('name', 'BBB Inc')->count());
        $this->assertSame(2, AgencyServiceProviderContact::withoutGlobalScopes()->where('service_provider_id', $firmId)->count());
        $this->assertDatabaseHas('agency_service_providers', ['id' => $firmId, 'address' => '1 Law St, Margate']);
    }

    public function test_attorney_search_flattens_firm_by_contact(): void
    {
        $firm = AgencyServiceProvider::create([
            'agency_id' => $this->agencyId, 'name' => 'BBB Inc', 'specialty' => 'transfer_attorney', 'is_active' => true,
        ]);
        AgencyServiceProviderContact::create(['agency_id' => $this->agencyId, 'service_provider_id' => $firm->id, 'attorney_name' => 'Attorney X', 'contact_person' => 'Assistant', 'is_active' => true]);
        AgencyServiceProviderContact::create(['agency_id' => $this->agencyId, 'service_provider_id' => $firm->id, 'attorney_name' => 'Attorney Y', 'contact_person' => 'Paralegal', 'is_active' => true]);

        $resp = $this->actingAs($this->admin)->getJson(route('deals-dr2.attorney.search', ['q' => 'BBB']));
        $resp->assertOk();
        $results = $resp->json('results');
        $this->assertCount(2, $results, 'firm × 2 contacts = 2 pick options');
        $labels = array_column($results, 'label');
        $this->assertTrue((bool) array_filter($labels, fn ($l) => str_contains($l, 'Attorney X')));
        $this->assertTrue((bool) array_filter($labels, fn ($l) => str_contains($l, 'Attorney Y')));
    }

    public function test_supplier_setup_manages_firm_contacts(): void
    {
        $firm = AgencyServiceProvider::create(['agency_id' => $this->agencyId, 'name' => 'BBB Inc', 'specialty' => 'transfer_attorney', 'is_active' => true]);

        // Add a contact via the setup page endpoint.
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.contacts.store', $firm), [
            'attorney_name' => 'Attorney X', 'contact_person' => 'His Assistant', 'email' => 'x@bbb.example',
        ])->assertRedirect();
        $this->assertDatabaseHas('agency_service_provider_contacts', [
            'service_provider_id' => $firm->id, 'attorney_name' => 'Attorney X', 'contact_person' => 'His Assistant',
        ]);

        $contact = AgencyServiceProviderContact::withoutGlobalScopes()->where('service_provider_id', $firm->id)->first();

        // Deactivate = soft delete (historic deals keep resolving).
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.contacts.deactivate', $contact))->assertRedirect();
        $this->assertSoftDeleted('agency_service_provider_contacts', ['id' => $contact->id]);
    }

    public function test_deal_persists_firm_and_contact_link(): void
    {
        $firm = AgencyServiceProvider::create(['agency_id' => $this->agencyId, 'name' => 'BBB Inc', 'specialty' => 'transfer_attorney', 'is_active' => true]);
        $contact = AgencyServiceProviderContact::create(['agency_id' => $this->agencyId, 'service_provider_id' => $firm->id, 'attorney_name' => 'Attorney X', 'contact_person' => 'Assistant', 'is_active' => true]);

        $l = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);
        $s = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent']);

        $this->actingAs($this->admin)->post(route('deals-dr2.store'), [
            'period' => '2026-06', 'deal_date' => '2026-06-10', 'deal_type' => 'bond',
            'branch_id' => $this->agencyId, 'property_value' => 1000000, 'total_commission' => 57500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_agents' => [(string) $l->id], 'selling_agents' => [(string) $s->id],
            'attorney_name' => 'BBB Inc — Attorney X (via Assistant)',
            'attorney_provider_id' => $firm->id, 'attorney_contact_id' => $contact->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('deals', [
            'agency_id' => $this->agencyId,
            'attorney_provider_id' => $firm->id,
            'attorney_contact_id' => $contact->id,
        ]);
    }
}
