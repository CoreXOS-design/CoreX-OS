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
 * "Add another property" eligibility, Johan 2026-09-16, verbatim: "add
 * another property should only display the other properties on this
 * seller. why offer a search, it can be a plain dropdown." His own
 * refinement, before any code was written, is the one this file actually
 * proves: "properties on this seller" and "properties this deal will
 * accept" are NOT the same set — DealPropertyOwnerGate compares exact
 * OWNER SETS, not a single seller, so a seller who owns one property
 * solely and another jointly has two DIFFERENT owner sets. Sized on QA1's
 * own real data before this was built: 31 properties fell in that exact
 * gap (a search-by-seller design would have offered them; the gate would
 * then have refused them) — reported back rather than decided silently.
 *
 * DealRegisterController::eligibleProperties() is the ONE shared endpoint
 * both create and edit call — no second implementation.
 */
final class PropertyEligibilityDropdownTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $bm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Eligibility Co', 'slug' => 'elig-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agencyId]);
        foreach (['access_deal_register', 'view_deals', 'create_deals', 'deals.view', 'deals.create', 'deals.edit'] as $key) {
            RolePermission::create(['role' => 'branch_manager', 'permission_key' => $key, 'agency_id' => $this->agencyId]);
        }
        // eligibleProperties() calls Property::visibleTo($user), a SEPARATE
        // permission domain from deals — PermissionService::getDataScope()
        // reads a row with NO 'scope' value as ungranted (getScopesForRole()
        // filters ->whereNotNull('scope')), which scopeVisibleTo() then
        // treats as "see nothing" (whereRaw 1=0), not "see everything".
        // Without an explicit scope here, every candidate is filtered out
        // before the owner-set comparison ever runs.
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'access_properties', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'properties.view', 'scope' => 'branch', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();
        $this->bm = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'branch_manager', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeProperty(string $address): Property
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'ELIG-' . Str::random(8), 'title' => $address, 'address' => $address,
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
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

    public function test_a_property_with_an_identical_owner_set_is_returned(): void
    {
        $steve = $this->makeContact('Steve');
        $reference = $this->makeProperty('1 Elig Match Rd');
        $match = $this->makeProperty('2 Elig Match Rd');
        $this->linkOwner($reference, $steve);
        $this->linkOwner($match, $steve);

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]));

        $response->assertOk();
        $ids = collect($response->json('properties'))->pluck('id');
        $this->assertTrue($ids->contains($match->id));
    }

    /**
     * THE gap Johan named specifically: "properties on this seller" and
     * "properties this deal will accept" are not the same set. Steve owns
     * the reference solely; Steve+Dave jointly own the candidate. Same
     * seller, different owner SET — must be excluded.
     */
    public function test_a_property_sharing_the_seller_but_with_a_different_owner_set_is_excluded(): void
    {
        $steve = $this->makeContact('Steve');
        $dave = $this->makeContact('Dave');
        $reference = $this->makeProperty('1 Elig Gap Rd');
        $jointlyOwned = $this->makeProperty('2 Elig Gap Rd');
        $this->linkOwner($reference, $steve);
        $this->linkOwner($jointlyOwned, $steve);
        $this->linkOwner($jointlyOwned, $dave);

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]));

        $response->assertOk();
        $ids = collect($response->json('properties'))->pluck('id');
        $this->assertFalse($ids->contains($jointlyOwned->id), 'Same seller, different owner set — the gate would refuse this, so the dropdown must never offer it.');
    }

    public function test_the_reference_property_itself_is_never_offered(): void
    {
        $steve = $this->makeContact('Steve');
        $reference = $this->makeProperty('1 Elig Self Rd');
        $this->linkOwner($reference, $steve);

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]));

        $response->assertOk();
        $ids = collect($response->json('properties'))->pluck('id');
        $this->assertFalse($ids->contains($reference->id));
    }

    public function test_properties_already_excluded_are_not_offered_again(): void
    {
        $steve = $this->makeContact('Steve');
        $reference = $this->makeProperty('1 Elig Exclude Rd');
        $alreadyOnDeal = $this->makeProperty('2 Elig Exclude Rd');
        $stillEligible = $this->makeProperty('3 Elig Exclude Rd');
        $this->linkOwner($reference, $steve);
        $this->linkOwner($alreadyOnDeal, $steve);
        $this->linkOwner($stillEligible, $steve);

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', [
            'reference_property_id' => $reference->id, 'exclude' => [$alreadyOnDeal->id],
        ]));

        $response->assertOk();
        $ids = collect($response->json('properties'))->pluck('id');
        $this->assertFalse($ids->contains($alreadyOnDeal->id));
        $this->assertTrue($ids->contains($stillEligible->id));
    }

    public function test_a_reference_property_with_no_resolvable_owner_returns_nothing(): void
    {
        $reference = $this->makeProperty('1 Elig No Owner Rd'); // no owner linked at all

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]));

        $response->assertOk();
        $this->assertSame([], $response->json('properties'));
    }

    public function test_a_seller_with_no_other_properties_at_all_returns_an_empty_list(): void
    {
        $reference = $this->makeProperty('1 Elig Alone Rd');
        $this->linkOwner($reference, $this->makeContact('Solo'));

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]));

        $response->assertOk();
        $this->assertSame([], $response->json('properties'));
    }

    public function test_the_response_carries_enough_to_tell_two_of_the_same_sellers_properties_apart(): void
    {
        $steve = $this->makeContact('Steve');
        $reference = $this->makeProperty('1 Elig Label Rd');
        $match = $this->makeProperty('2 Elig Label Rd');
        $match->property_number = 'REF-999';
        $match->save();
        $this->linkOwner($reference, $steve);
        $this->linkOwner($match, $steve);

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]));

        $response->assertOk();
        $row = collect($response->json('properties'))->firstWhere('id', $match->id);
        $this->assertNotNull($row);
        $this->assertSame('2 Elig Label Rd', $row['label']);
        $this->assertSame('REF-999', $row['ref']);
    }

    /**
     * Same G/R exclusivity rule addProperty() already runs — never a
     * second, looser set of status checks invented for this dropdown.
     */
    public function test_a_property_already_committed_elsewhere_is_excluded_when_this_deal_is_granted(): void
    {
        $steve = $this->makeContact('Steve');
        $reference = $this->makeProperty('1 Elig Committed Rd');
        $committedElsewhere = $this->makeProperty('2 Elig Committed Rd');
        $this->linkOwner($reference, $steve);
        $this->linkOwner($committedElsewhere, $steve);

        Deal::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(7000, 7999999), 'period' => '2026-09', 'deal_date' => '2026-09-16',
            'property_id' => $committedElsewhere->id, 'accepted_status' => 'G', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ]);

        $response = $this->actingAs($this->bm)->getJson(route('deals-dr2.search.eligible-properties', [
            'reference_property_id' => $reference->id, 'accepted_status' => 'G',
        ]));

        $response->assertOk();
        $ids = collect($response->json('properties'))->pluck('id');
        $this->assertFalse($ids->contains($committedElsewhere->id), 'A property already Granted/Registered on another deal must be excluded when THIS deal is also G/R, same rule addProperty() already enforces.');
    }

    public function test_an_ordinary_agent_cannot_reach_the_eligibility_endpoint(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true]);
        $reference = $this->makeProperty('1 Elig Perm Rd');

        $this->actingAs($agent)->getJson(route('deals-dr2.search.eligible-properties', ['reference_property_id' => $reference->id]))
            ->assertForbidden();
    }
}
