<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-398 — HTTP-level proof for DealRegisterController::addProperty()/
 * removeProperty(), the screen-facing edge of the same-owner gate and the
 * branch co-sharing wire-up. Unit coverage for the gate itself lives in
 * DealPropertyOwnerGateTest; this file proves the CONTROLLER wires it up
 * correctly end to end — validation, the plain-English refusal reaching the
 * response, the branch auto-share firing, the audit note, and the
 * primary-property removal guard.
 */
final class DealAddRemovePropertyControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private int $otherBranchId;
    private User $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'AddRemove Co', 'slug' => 'ar-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->otherBranchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Satellite', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();

        $this->bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeProperty(string $address, ?int $branchId = null): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $branchId ?? $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'AR-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $branchId ?? $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function makeContact(string $first): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'first_name' => $first, 'last_name' => 'Owner', 'phone' => '0' . random_int(600000000, 699999999),
        ]);
    }

    private function linkOwner(Property $property, Contact $contact, string $role = 'seller'): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeDeal(Property $primary): Deal
    {
        return Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(6000, 6999999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $primary->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);
    }

    public function test_adding_a_property_with_an_identical_owner_set_succeeds_and_is_audited(): void
    {
        $propA = $this->makeProperty('1 Add A Rd');
        $propB = $this->makeProperty('1 Add B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);

        $response = $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id, 'is_primary' => 0, 'allocated_price' => 500000, 'allocated_commission' => 28750]);
        $this->assertDatabaseHas('deal_logs', ['deal_id' => $deal->id, 'event_type' => 'property_added']);

        $fresh = $deal->fresh();
        $this->assertSame('1500000.00', $fresh->property_value, 'Johan: the existing price is kept, the new property\'s price is added on top');
        $this->assertSame('86250.00', $fresh->total_commission, 'the deal total is always the sum of every property\'s own price — never a separately-entered figure');
    }

    public function test_removing_a_property_reduces_the_deal_total_back_down_by_its_own_allocation(): void
    {
        $propA = $this->makeProperty('1b Sum Down A Rd');
        $propB = $this->makeProperty('1b Sum Down B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA); // 1,000,000 / 57,500

        $this->actingAs($this->bm)->post(route('deals-dr2.properties.add', $deal), [
            'property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750,
        ]);
        $this->assertSame('1500000.00', $deal->fresh()->property_value);

        $this->actingAs($this->bm)->delete(route('deals-dr2.properties.remove', [$deal, $propB]))
            ->assertSessionDoesntHaveErrors();

        $fresh = $deal->fresh();
        $this->assertSame('1000000.00', $fresh->property_value, 'removing a property drops its allocation back out of the sum');
        $this->assertSame('57500.00', $fresh->total_commission);
    }

    public function test_updating_a_linked_propertys_price_recalculates_the_deal_total(): void
    {
        $propA = $this->makeProperty('1c Update Price A Rd');
        $propB = $this->makeProperty('1c Update Price B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA); // 1,000,000 / 57,500

        $this->actingAs($this->bm)->post(route('deals-dr2.properties.add', $deal), [
            'property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750,
        ]);

        $this->actingAs($this->bm)
            ->patch(route('deals-dr2.properties.updatePrice', [$deal, $propB]), ['allocated_price' => 600000, 'allocated_commission' => 34500])
            ->assertSessionDoesntHaveErrors();

        $fresh = $deal->fresh();
        $this->assertSame('1600000.00', $fresh->property_value);
        $this->assertSame('92000.00', $fresh->total_commission);
    }

    public function test_updating_a_price_on_a_single_property_deal_is_refused_with_a_clear_message(): void
    {
        $propA = $this->makeProperty('1d Single Update Rd');
        $this->linkOwner($propA, $this->makeContact('Steve'));
        $deal = $this->makeDeal($propA);

        $response = $this->actingAs($this->bm)
            ->patch(route('deals-dr2.properties.updatePrice', [$deal, $propA]), ['allocated_price' => 999, 'allocated_commission' => 99]);

        $response->assertSessionHasErrors('allocated_price');
    }

    public function test_restoring_a_removed_property_re_links_it_and_recalculates_the_total(): void
    {
        $propA = $this->makeProperty('1e Restore A Rd');
        $propB = $this->makeProperty('1e Restore B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);

        $this->actingAs($this->bm)->post(route('deals-dr2.properties.add', $deal), [
            'property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750,
        ]);
        $this->actingAs($this->bm)->delete(route('deals-dr2.properties.remove', [$deal, $propB]));
        $this->assertSame('1000000.00', $deal->fresh()->property_value);

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.properties.restore', [$deal, $propB]));

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id, 'deleted_at' => null]);
        $this->assertSame('1500000.00', $deal->fresh()->property_value, 'restoring brings its allocation back into the sum');
    }

    public function test_adding_a_property_with_a_partially_overlapping_owner_set_is_refused_with_the_plain_english_message(): void
    {
        $propA = $this->makeProperty('2 Refuse A Rd'); // Steve, sole
        $propB = $this->makeProperty('2 Refuse B Rd'); // Steve + Dave, joint
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $this->linkOwner($propB, $dave);
        $deal = $this->makeDeal($propA);

        $response = $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);

        $response->assertSessionHasErrors('property_id');
        $message = session('errors')->get('property_id')[0];
        $this->assertStringContainsString('Dave', $message);
        $this->assertStringContainsString('separate deal', $message);
        $this->assertDatabaseMissing('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id]);
    }

    public function test_adding_a_property_with_no_known_owner_is_refused(): void
    {
        $propA = $this->makeProperty('3 NoOwner A Rd');
        $propB = $this->makeProperty('3 NoOwner B Rd'); // no contact_property rows
        $this->linkOwner($propA, $this->makeContact('Steve'));
        $deal = $this->makeDeal($propA);

        $response = $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);

        $response->assertSessionHasErrors('property_id');
        $this->assertDatabaseMissing('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id]);
    }

    public function test_adding_a_property_in_a_different_branch_auto_shares_the_deal_across_both_branches(): void
    {
        $propA = $this->makeProperty('4 Branch A Rd', $this->branchId);
        $propB = $this->makeProperty('4 Branch B Rd', $this->otherBranchId);
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);

        $this->actingAs($this->bm)
            ->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750])
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue(
            $deal->fresh()->branches()->pluck('branches.id')->contains($this->otherBranchId),
            'Johan (Q3): a linked property in a different branch shares the deal across both branches automatically'
        );
    }

    public function test_removing_the_primary_property_is_blocked_with_a_clear_message(): void
    {
        $propA = $this->makeProperty('5 Primary Block Rd');
        $propB = $this->makeProperty('5 Primary Block B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);
        $this->actingAs($this->bm)->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);

        $response = $this->actingAs($this->bm)
            ->delete(route('deals-dr2.properties.remove', [$deal, $propA]));

        $response->assertSessionHasErrors('property_id');
        $this->assertDatabaseHas('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propA->id, 'is_primary' => 1, 'deleted_at' => null]);
    }

    public function test_removing_a_non_primary_property_succeeds_soft_deletes_and_is_audited(): void
    {
        $propA = $this->makeProperty('6 Remove A Rd');
        $propB = $this->makeProperty('6 Remove B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);
        $deal = $this->makeDeal($propA);
        $this->actingAs($this->bm)->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750]);

        $response = $this->actingAs($this->bm)
            ->delete(route('deals-dr2.properties.remove', [$deal, $propB]));

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();
        $this->assertSoftDeleted('deal_properties', ['deal_id' => $deal->id, 'property_id' => $propB->id]);
        $this->assertDatabaseHas('deal_logs', ['deal_id' => $deal->id, 'event_type' => 'property_removed']);
    }

    public function test_an_agent_without_create_or_edit_permission_is_forbidden_from_adding_a_property(): void
    {
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'deals.view'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        Role::clearCache();
        PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);

        $propA = $this->makeProperty('7 Agent Block A Rd');
        $propB = $this->makeProperty('7 Agent Block B Rd');
        $this->linkOwner($propA, $this->makeContact('Steve'));
        $deal = $this->makeDeal($propA);

        $this->actingAs($agent)
            ->post(route('deals-dr2.properties.add', $deal), ['property_id' => $propB->id, 'allocated_price' => 500000, 'allocated_commission' => 28750])
            ->assertForbidden();
    }
}
