<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInventory;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §6 — archived records are invisible to the
 * tenant / landlord portal (web shell + API share these endpoints): not in any
 * list, and a 404 by id exactly like an out-of-scope record. RentalPortalScopeService
 * strips global scopes (a ClientUser has no staff user), which also strips
 * SoftDeletes — so each resource type is proved here, live-vs-archived.
 * Job cards are covered in TenantLandlordJobCardApiTest.
 */
class ArchivedRecordsHiddenFromPortalTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Archive Agency', 'slug' => 'archive-' . uniqid()]);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin']);
        $this->property = $this->makeProperty('Live Unit');
        $this->lease = $this->makeLease($this->property);
        $this->tenant = $this->makeContact('Tina');
        $this->landlord = $this->makeContact('Lenny');
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
    }

    // ── builders ─────────────────────────────────────────────────────────

    private function branchId(): int
    {
        return (int) Branch::query()->where('agency_id', $this->agency->id)->value('id');
    }

    private function makeContact(string $first): Contact
    {
        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchId(),
            'first_name' => $first, 'last_name' => 'Contact', 'email' => strtolower($first) . '+' . uniqid() . '@example.com',
        ]);
    }

    private function makeProperty(string $title): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branchId(),
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function makeLease(Property $property, string $status = 'active'): Lease
    {
        return Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchId(), 'property_id' => $property->id,
            'status' => $status, 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
    }

    private function makeFault(string $title, ?Lease $lease = null, ?Property $property = null, array $extra = []): RentalFaultReport
    {
        $property ??= $this->property;

        return RentalFaultReport::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id, 'lease_id' => ($lease ?? $this->lease)->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => $title, 'description' => $title,
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
    }

    private function makeWorkOrder(string $title, array $extra = []): RentalWorkOrder
    {
        return RentalWorkOrder::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchId(), 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'reported_by_type' => 'agent', 'reported_by_user_id' => $this->agent->id,
            'title' => $title, 'description' => $title,
            'status' => 'reported', 'owner_approval_status' => 'not_required', 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeInspection(): RentalInspection
    {
        // Completed = sent: the portal lists only sent inspections and booked dates (rental-portal-access.md §20), so a bare
        // draft would never be listed and this test could not tell "archived" from "not yet sent".
        return RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id,
            'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->agent->id,
            'status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()->subDay(),
        ]);
    }

    private function makeInventory(): RentalInventory
    {
        return RentalInventory::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'created_by_user_id' => $this->agent->id,
        ]);
    }

    private function makeDocument(string $name, Contact $contact, string $flag): Document
    {
        $doc = Document::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchId(),
            'original_name' => $name, 'storage_path' => 'documents/' . uniqid() . '.pdf', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10, 'uploaded_by' => $this->agent->id,
            $flag => true,
        ]);
        $doc->contacts()->attach($contact->id, ['party_role' => 'tenant']);

        return $doc;
    }

    private function actAs(Contact $contact): void
    {
        $clientUser = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();
        Sanctum::actingAs($clientUser, ['client']);
    }

    private function ids(string $url, string $key): array
    {
        $res = $this->getJson($url);
        $res->assertOk();

        return collect($res->json($key))->pluck('id')->all();
    }

    private const T = '/api/v1/client/rentals';
    private const L = '/api/v1/client/rentals/landlord';

    // ══ TENANT ═══════════════════════════════════════════════════════════

    public function test_tenant_lease_archived_is_not_listed_and_404s_by_id(): void
    {
        $archivedProperty = $this->makeProperty('Old Unit');
        $archived = $this->makeLease($archivedProperty, 'ended');
        LeaseTenant::create(['lease_id' => $archived->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->actAs($this->tenant);

        $this->assertEqualsCanonicalizing([$this->lease->id, $archived->id], $this->ids(self::T . '/leases', 'leases'));
        $archived->delete();

        $ids = $this->ids(self::T . '/leases', 'leases');
        $this->assertSame([$this->lease->id], $ids);
        $this->getJson(self::T . '/leases/' . $this->lease->id)->assertOk();
        $this->getJson(self::T . '/leases/' . $archived->id)->assertStatus(404);
    }

    public function test_tenant_fault_report_archived_is_not_listed_and_404s_by_id(): void
    {
        $live = $this->makeFault('Live leak');
        $archived = $this->makeFault('Archived leak');
        $archived->delete();
        $this->actAs($this->tenant);

        $this->assertSame([$live->id], $this->ids(self::T . '/fault-reports', 'fault_reports'));
        $this->getJson(self::T . '/fault-reports/' . $live->id)->assertOk();
        $this->getJson(self::T . '/fault-reports/' . $archived->id)->assertStatus(404);
    }

    public function test_tenant_work_order_archived_404s_by_id_and_cannot_be_confirmed(): void
    {
        $live = $this->makeWorkOrder('Live order');
        $archived = $this->makeWorkOrder('Archived order');
        $archived->delete();
        $this->actAs($this->tenant);

        $this->getJson(self::T . '/work-orders/' . $live->id)->assertOk();
        $this->getJson(self::T . '/work-orders/' . $archived->id)->assertStatus(404);
        $this->postJson(self::T . '/work-orders/' . $archived->id . '/confirm', ['fixed' => true])->assertStatus(404);
        $this->assertNull(RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($archived->id)->tenant_confirmed_at, 'An archived order must not accept a tenant confirmation.');
    }

    public function test_tenant_inspection_archived_is_not_listed(): void
    {
        $live = $this->makeInspection();
        $archived = $this->makeInspection();
        $archived->delete();
        $this->actAs($this->tenant);

        $this->assertSame([$live->id], $this->ids(self::T . '/inspections', 'inspections'));
    }

    public function test_tenant_inventory_archived_is_not_listed(): void
    {
        $live = $this->makeInventory();
        $archived = $this->makeInventory();
        $archived->delete();
        $this->actAs($this->tenant);

        $this->assertSame([$live->id], $this->ids(self::T . '/inventories', 'inventories'));
    }

    public function test_tenant_document_archived_is_not_listed(): void
    {
        $live = $this->makeDocument('Live.pdf', $this->tenant, 'tenant_portal_visible');
        $archived = $this->makeDocument('Archived.pdf', $this->tenant, 'tenant_portal_visible');
        $archived->delete();
        $this->actAs($this->tenant);

        $this->assertSame([$live->id], $this->ids(self::T . '/documents', 'documents'));
    }

    public function test_tenant_cannot_load_fault_types_or_report_on_an_archived_property(): void
    {
        $this->actAs($this->tenant);
        $this->getJson(self::T . '/properties/' . $this->property->id . '/fault-types')->assertOk();
        $before = RentalFaultReport::withoutGlobalScopes()->count();

        // A property with a live lease cannot be archived — the tenancy ends first, then the property is archived.
        $this->lease->forceFill(['status' => 'ended'])->save();
        $this->property->delete();

        $this->getJson(self::T . '/properties/' . $this->property->id . '/fault-types')->assertStatus(404);
        $this->postJson(self::T . '/properties/' . $this->property->id . '/fault-reports', [
            'rental_fault_type_id' => 1, 'resolution' => 'still_a_problem', 'title' => 'Leak',
        ])->assertStatus(404);
        $this->assertSame($before, RentalFaultReport::withoutGlobalScopes()->count());
    }

    /** Archiving the lease takes everything that hangs off it out of the tenant's reach. */
    public function test_everything_under_an_archived_lease_disappears_for_the_tenant(): void
    {
        $fault = $this->makeFault('Under the lease');
        $order = $this->makeWorkOrder('Under the lease');
        $this->makeInspection();
        $this->makeInventory();
        $this->actAs($this->tenant);
        $this->getJson(self::T . '/fault-reports/' . $fault->id)->assertOk();

        $this->lease->delete();

        $this->assertSame([], $this->ids(self::T . '/leases', 'leases'));
        $this->assertSame([], $this->ids(self::T . '/fault-reports', 'fault_reports'));
        $this->assertSame([], $this->ids(self::T . '/inspections', 'inspections'));
        $this->assertSame([], $this->ids(self::T . '/inventories', 'inventories'));
        $this->getJson(self::T . '/fault-reports/' . $fault->id)->assertStatus(404);
        $this->getJson(self::T . '/work-orders/' . $order->id)->assertStatus(404);
    }

    // ══ LANDLORD ═════════════════════════════════════════════════════════

    public function test_landlord_property_archived_is_not_listed_and_404s_by_id(): void
    {
        $other = $this->makeProperty('Second Unit');
        $other->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->actAs($this->landlord);
        $this->assertEqualsCanonicalizing([$this->property->id, $other->id], $this->ids(self::L . '/properties', 'properties'));

        $other->delete();

        $this->assertSame([$this->property->id], $this->ids(self::L . '/properties', 'properties'));
        $this->getJson(self::L . '/properties/' . $this->property->id)->assertOk();
        $this->getJson(self::L . '/properties/' . $other->id)->assertStatus(404);
        $this->getJson(self::L . '/properties/' . $other->id . '/fault-types')->assertStatus(404);
        $this->postJson(self::L . '/properties/' . $other->id . '/fault-reports', ['title' => 'Leak'])->assertStatus(404);
    }

    public function test_landlord_property_occupancy_history_leaves_out_an_archived_lease(): void
    {
        $archived = $this->makeLease($this->property, 'ended');
        $this->actAs($this->landlord);
        $res = $this->getJson(self::L . '/properties/' . $this->property->id)->assertOk();
        $this->assertEqualsCanonicalizing([$this->lease->id, $archived->id], collect($res->json('property.occupancy_history'))->pluck('id')->all());

        $archived->delete();

        $res = $this->getJson(self::L . '/properties/' . $this->property->id)->assertOk();
        $this->assertSame([$this->lease->id], collect($res->json('property.occupancy_history'))->pluck('id')->all());
    }

    public function test_landlord_fault_report_archived_is_not_listed_decided_or_in_decisions(): void
    {
        $pending = ['owner_approval_status' => RentalFaultReport::APPROVAL_PENDING];
        $live = $this->makeFault('Live', null, null, $pending);
        $archived = $this->makeFault('Archived', null, null, $pending);
        $archived->delete();
        $this->actAs($this->landlord);

        $this->assertSame([$live->id], $this->ids(self::L . '/fault-reports', 'fault_reports'));
        $this->assertSame([$live->id], $this->ids(self::L . '/decisions', 'fault_reports'));
        $this->postJson(self::L . '/fault-reports/' . $archived->id . '/decision', ['decision' => 'decline'])->assertStatus(404);
        $this->assertSame(
            RentalFaultReport::APPROVAL_PENDING,
            RentalFaultReport::withoutGlobalScopes()->withTrashed()->find($archived->id)->owner_approval_status,
            'A decision on an archived fault report must not be recorded.',
        );
    }

    public function test_landlord_work_order_archived_is_not_listed_decided_or_in_decisions(): void
    {
        $pending = ['owner_approval_status' => RentalWorkOrder::APPROVAL_PENDING];
        $live = $this->makeWorkOrder('Live', $pending);
        $archived = $this->makeWorkOrder('Archived', $pending);
        $archived->delete();
        $this->actAs($this->landlord);

        $this->assertSame([$live->id], $this->ids(self::L . '/work-orders', 'work_orders'));
        $this->assertSame([$live->id], $this->ids(self::L . '/decisions', 'work_orders'));
        $this->postJson(self::L . '/work-orders/' . $archived->id . '/decision', ['decision' => 'decline'])->assertStatus(404);
        $this->assertSame(
            RentalWorkOrder::APPROVAL_PENDING,
            RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($archived->id)->owner_approval_status,
        );
    }

    public function test_landlord_inspection_archived_is_not_listed(): void
    {
        $live = $this->makeInspection();
        $archived = $this->makeInspection();
        $archived->delete();
        $this->actAs($this->landlord);

        $this->assertSame([$live->id], $this->ids(self::L . '/inspections', 'inspections'));
    }

    public function test_landlord_document_archived_is_not_listed(): void
    {
        $live = $this->makeDocument('Live.pdf', $this->landlord, 'landlord_portal_visible');
        $archived = $this->makeDocument('Archived.pdf', $this->landlord, 'landlord_portal_visible');
        $archived->delete();
        $this->actAs($this->landlord);

        $this->assertSame([$live->id], $this->ids(self::L . '/documents', 'documents'));
    }
}
