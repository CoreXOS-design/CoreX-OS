<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §7.2 — the New Lease form's Property field is a
 * type-to-search picker (it used to be a plain <select> that could not be
 * typed into). The endpoint behind it, corex.leases.search-rental-properties,
 * must search by address / property name / reference AND keep the acting
 * user's own / branch / agency visibility enforced at the query layer.
 *
 * Input paths proven: street search, property-name search, reference search,
 * multi-word narrowing, no-match (empty list, 200), blank term (recent stock,
 * never an error), sale listings excluded, 10-row cap, own scope, branch scope,
 * agency-wide scope, cross-agency isolation, missing permission (403), the
 * create form rendering the picker (no <select>), old('property_id') restored
 * after a validation error, an old id the user can no longer see coming back
 * empty, the friendly "choose a property" message, and direct-by-id POST of a
 * property outside the user's scope refused.
 *
 * leases.md §7.3 (2026-10-06) adds: store() refuses sale listings / out-of-scope /
 * other-agency / malformed property ids with the same plain message; picker and save
 * agree on every property (drift guard); ?property_id= pre-fills only a property the
 * picker could offer; every field and the tenant list survive a validation error.
 */
final class LeaseCreatePropertySearchTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $rival;
    private Branch $margate;
    private Branch $shelly;
    private User $ownAgent;
    private User $otherAgent;
    private User $wideUser;
    private Property $marineDrive;   // own agent's, Margate
    private Property $beachRoad;     // other agent's, Margate
    private Property $shellyFlat;    // other agent's, Shelly Beach branch
    private Property $marineForSale; // own agent's, but a SALE listing
    private Property $durbanMarine;  // different agency entirely

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-' . uniqid()]);
        $this->rival = Agency::create(['name' => 'Rival Realty', 'slug' => 'rival-' . uniqid()]);
        $this->margate = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->shelly = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Shelly Beach']);
        $rivalBranch = Branch::create(['agency_id' => $this->rival->id, 'name' => 'Durban']);

        // properties.view 'own' = own tier; any other stored value = branch when the agency
        // splits branches, agency-wide when it does not (PermissionService::getDataScope()).
        $this->grantRole('test_own', 'own');
        $this->grantRole('test_wide', 'all');

        $this->ownAgent = $this->makeUser('test_own', $this->margate);
        $this->otherAgent = $this->makeUser('test_own', $this->margate);
        $this->wideUser = $this->makeUser('test_wide', $this->margate);
        $rivalAgent = User::factory()->create(['agency_id' => $this->rival->id, 'branch_id' => $rivalBranch->id, 'role' => 'test_own', 'is_active' => true]);

        $this->marineDrive = $this->makeProperty($this->margate, $this->ownAgent, [
            'title' => 'Sea View Cottage', 'street_number' => '14', 'street_name' => 'Marine Drive',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'property_number' => 'HFC-RN-1042',
        ]);
        $this->beachRoad = $this->makeProperty($this->margate, $this->otherAgent, [
            'title' => 'Palm Court Unit 4', 'street_number' => '22', 'street_name' => 'Beach Road',
            'suburb' => 'Margate', 'city' => 'Margate', 'property_number' => 'HFC-RN-2200',
        ]);
        $this->shellyFlat = $this->makeProperty($this->shelly, $this->otherAgent, [
            'title' => 'Shelly Flat', 'street_number' => '9', 'street_name' => 'Main Street',
            'suburb' => 'Shelly Beach', 'city' => 'Margate', 'property_number' => 'HFC-RN-3300',
        ]);
        $this->marineForSale = $this->makeProperty($this->margate, $this->ownAgent, [
            'title' => 'Marine Heights', 'street_number' => '30', 'street_name' => 'Marine Drive',
            'suburb' => 'Uvongo', 'city' => 'Margate', 'property_number' => 'HFC-SL-0007', 'listing_type' => 'sale',
        ]);
        $this->durbanMarine = $this->makeProperty($rivalBranch, $rivalAgent, [
            'title' => 'Bay Rental', 'street_number' => '14', 'street_name' => 'Marine Parade',
            'suburb' => 'Durban Central', 'city' => 'Durban', 'property_number' => 'RV-RN-1',
        ], $this->rival);

        PermissionService::clearCache();
    }

    public function test_street_name_search_finds_the_property(): void
    {
        $this->assertSame([$this->marineDrive->id], $this->search($this->wideUser, 'marine'));
    }

    public function test_property_name_search_finds_the_property(): void
    {
        $this->assertSame([$this->marineDrive->id], $this->search($this->wideUser, 'Sea View'));
    }

    public function test_reference_search_finds_the_property(): void
    {
        $this->assertSame([$this->beachRoad->id], $this->search($this->wideUser, 'HFC-RN-2200'));
    }

    public function test_several_words_narrow_the_result_instead_of_widening_it(): void
    {
        // "beach" alone matches Beach Road AND Shelly Beach; adding "road" must narrow to one.
        $this->assertEqualsCanonicalizing([$this->beachRoad->id, $this->shellyFlat->id], $this->search($this->wideUser, 'beach'));
        $this->assertSame([$this->beachRoad->id], $this->search($this->wideUser, 'beach road'));
    }

    public function test_no_match_is_an_empty_list_not_an_error(): void
    {
        $this->actingAs($this->wideUser)
            ->getJson(route('corex.leases.search-rental-properties', ['q' => 'zzzz nothing']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_blank_term_returns_recent_rental_stock_not_an_error(): void
    {
        $ids = $this->search($this->wideUser, '');

        $this->assertEqualsCanonicalizing([$this->marineDrive->id, $this->beachRoad->id, $this->shellyFlat->id], $ids);
    }

    public function test_sale_listings_are_never_offered(): void
    {
        $this->assertNotContains($this->marineForSale->id, $this->search($this->wideUser, 'Marine Drive'));
        $this->assertNotContains($this->marineForSale->id, $this->search($this->wideUser, 'HFC-SL-0007'));
    }

    public function test_results_are_capped_at_ten(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->makeProperty($this->margate, $this->ownAgent, ['title' => "Bulk Unit {$i}", 'street_name' => 'Bulk Lane', 'street_number' => (string) $i]);
        }

        $this->assertCount(10, $this->search($this->wideUser, 'Bulk Lane'));
    }

    public function test_own_scope_user_only_finds_their_own_properties(): void
    {
        // otherAgent's Margate and Shelly Beach flats are in the agency but not this agent's book.
        $this->assertSame([$this->marineDrive->id], $this->search($this->ownAgent, 'marine'));
        $this->assertSame([], $this->search($this->ownAgent, 'beach'));
        $this->assertSame([$this->marineDrive->id], $this->search($this->ownAgent, ''));
    }

    public function test_branch_scope_user_only_finds_their_own_branchs_properties(): void
    {
        Agency::where('id', $this->agency->id)->update(['split_branches_enabled' => true]);
        PermissionService::clearCache();

        // Margate branch: sees Margate stock (both agents'), never the Shelly Beach branch's flat.
        $this->assertEqualsCanonicalizing([$this->beachRoad->id], $this->search($this->wideUser, 'beach'));
        $this->assertEqualsCanonicalizing([$this->marineDrive->id, $this->beachRoad->id], $this->search($this->wideUser, ''));
    }

    public function test_agency_wide_user_sees_the_whole_agency_but_never_another_agency(): void
    {
        Agency::where('id', $this->agency->id)->update(['split_branches_enabled' => false]);
        PermissionService::clearCache();

        $all = $this->search($this->wideUser, '');

        $this->assertContains($this->shellyFlat->id, $all);
        $this->assertNotContains($this->durbanMarine->id, $all);
        $this->assertNotContains($this->durbanMarine->id, $this->search($this->wideUser, 'Marine Parade'));
        $this->assertNotContains($this->durbanMarine->id, $this->search($this->wideUser, 'RV-RN-1'));
    }

    public function test_user_without_the_create_permission_is_refused(): void
    {
        $this->grantRole('test_view_only', 'all', ['leases.create']);
        $viewer = $this->makeUser('test_view_only', $this->margate);

        $this->actingAs($viewer)
            ->getJson(route('corex.leases.search-rental-properties', ['q' => 'marine']))
            ->assertForbidden();
    }

    public function test_create_form_renders_a_search_picker_not_a_dropdown(): void
    {
        $html = $this->actingAs($this->wideUser)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<select name="property_id"', $html);
        $this->assertStringContainsString('id="lease-property-search"', $html);
        $this->assertStringContainsString('name="property_id"', $html);
        // Js::from escapes the slashes inside the Alpine config, so compare with backslashes stripped.
        $this->assertStringContainsString(
            route('corex.leases.search-rental-properties', [], false),
            str_replace('\\', '', $html)
        );
    }

    public function test_create_form_with_a_preset_property_keeps_the_fixed_address_and_no_picker(): void
    {
        $html = $this->actingAs($this->wideUser)
            ->get(route('corex.leases.create', ['property_id' => $this->marineDrive->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('id="lease-property-search"', $html);
        $this->assertStringContainsString('value="' . $this->marineDrive->id . '"', $html);
    }

    public function test_property_picked_before_a_validation_error_is_restored_on_the_form(): void
    {
        $html = $this->actingAs($this->wideUser)
            ->withSession(['_old_input' => ['property_id' => (string) $this->marineDrive->id]])
            ->get(route('corex.leases.create'))->assertOk()->getContent();

        // The Alpine config carries the id and its label, so the box is pre-filled and still posts it.
        $this->assertStringContainsString('Marine Drive', $html);
        $this->assertMatchesRegularExpression('/u0022id\\\\?u0022:' . $this->marineDrive->id . '[,}]/', $html);
    }

    public function test_an_old_property_the_user_can_no_longer_see_comes_back_empty(): void
    {
        // ownAgent may not see otherAgent's flat: a stale/forged old id must not smuggle it onto the form.
        $html = $this->actingAs($this->ownAgent)
            ->withSession(['_old_input' => ['property_id' => (string) $this->beachRoad->id]])
            ->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Beach Road', $html);
    }

    public function test_submitting_without_a_property_shows_a_plain_message(): void
    {
        $this->actingAs($this->wideUser)
            ->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), [
                'rental_amount' => 8500, 'start_date' => '2026-11-01', 'tenant_contact_ids' => [],
            ])
            ->assertRedirect(route('corex.leases.create'))
            ->assertSessionHasErrors(['property_id' => 'Please choose a property from the list.']);
    }

    // ── 2. store() accepts only a rental property the user may see (leases.md §7.3) ──

    public function test_posting_a_property_outside_the_users_scope_by_id_is_refused(): void
    {
        // Direct-by-id: even bypassing the picker, store() must not accept another agent's property.
        $this->assertStoreRefused($this->ownAgent, $this->beachRoad->id);
    }

    public function test_posting_a_sale_listing_is_refused(): void
    {
        // marineForSale is in scope for ownAgent and wideUser — the ONLY thing wrong with it is listing_type.
        $this->assertStoreRefused($this->wideUser, $this->marineForSale->id);
        $this->assertStoreRefused($this->ownAgent, $this->marineForSale->id);
    }

    public function test_posting_another_agencys_rental_or_a_made_up_or_malformed_id_is_refused(): void
    {
        $this->assertStoreRefused($this->wideUser, $this->durbanMarine->id);
        $this->assertStoreRefused($this->wideUser, 99999999);
        $this->assertStoreRefused($this->wideUser, [$this->marineDrive->id]);
        $this->assertStoreRefused($this->wideUser, 'abc');
    }

    public function test_posting_an_in_scope_rental_property_still_creates_the_lease(): void
    {
        $this->actingAs($this->ownAgent)
            ->post(route('corex.leases.store'), $this->leasePayload($this->marineDrive->id))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('leases', ['property_id' => $this->marineDrive->id, 'rental_amount' => 8500]);
    }

    public function test_a_rental_property_of_any_status_is_findable_with_its_badge_and_can_be_saved_on_a_lease(): void
    {
        // Johan, 2026-10-06: an agent phones a withdrawn owner who agrees to rent out — the picker
        // must offer the property whatever its status, and say what that status is.
        $badges = ['active' => 'To Let', 'withdrawn' => 'Withdrawn', 'rented' => 'Rented', 'expired' => 'Expired'];

        foreach ($badges as $status => $badge) {
            $property = $this->makeProperty($this->margate, $this->ownAgent, [
                'title' => "Status {$status} Cottage", 'street_name' => 'Statusweg', 'street_number' => '1', 'status' => $status,
            ]);

            $row = collect($this->actingAs($this->ownAgent)
                ->getJson(route('corex.leases.search-rental-properties', ['q' => "Status {$status}"]))
                ->assertOk()->json())->firstWhere('id', $property->id);

            $this->assertNotNull($row, "a {$status} rental must be findable");
            $this->assertSame($badge, $row['status'], "{$status} keeps its status badge in the results");

            $this->actingAs($this->ownAgent)
                ->post(route('corex.leases.store'), $this->leasePayload($property->id))
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('leases', ['property_id' => $property->id]);
        }
    }

    public function test_a_withdrawn_sale_only_listing_is_still_excluded(): void
    {
        $withdrawnSale = $this->makeProperty($this->margate, $this->ownAgent, [
            'title' => 'Withdrawn Sale House', 'street_name' => 'Verkoopstraat', 'street_number' => '2',
            'status' => 'withdrawn', 'listing_type' => 'sale',
        ]);

        $this->assertNotContains($withdrawnSale->id, $this->search($this->wideUser, 'Withdrawn Sale'));
        $this->assertStoreRefused($this->wideUser, $withdrawnSale->id);
    }

    public function test_the_picker_and_the_save_accept_exactly_the_same_properties(): void
    {
        // Drift guard: for every fixture property and every kind of user, "the search offers it"
        // must equal "the save accepts it" (both resolve through Property::rentalVisibleTo()).
        $all = [$this->marineDrive, $this->beachRoad, $this->shellyFlat, $this->marineForSale, $this->durbanMarine];

        foreach ([$this->ownAgent, $this->wideUser] as $user) {
            $offered = $this->search($user, '');
            foreach ($all as $property) {
                $before = \App\Models\Lease::withoutGlobalScopes()->count();
                $this->actingAs($user)->post(route('corex.leases.store'), $this->leasePayload($property->id));
                $saved = \App\Models\Lease::withoutGlobalScopes()->count() > $before;

                $this->assertSame(
                    in_array($property->id, $offered, true),
                    $saved,
                    "picker vs save disagree on property #{$property->id} for user #{$user->id}"
                );
            }
        }
    }

    // ── 3. ?property_id= pre-fills only what the picker could have offered ───

    public function test_preset_property_outside_the_users_scope_is_not_prefilled(): void
    {
        $html = $this->actingAs($this->ownAgent)
            ->get(route('corex.leases.create', ['property_id' => $this->beachRoad->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Beach Road', $html);
        $this->assertStringContainsString('id="lease-property-search"', $html);
    }

    public function test_preset_sale_listing_other_agency_and_malformed_ids_are_not_prefilled(): void
    {
        foreach ([
            [$this->marineForSale->id, 'Marine Drive'],      // in scope, but a sale listing
            [$this->durbanMarine->id, 'Marine Parade'],      // another agency
            [99999999, null],
            ['abc', null],
        ] as [$id, $address]) {
            $html = $this->actingAs($this->wideUser)
                ->get(route('corex.leases.create', ['property_id' => $id]))
                ->assertOk()->getContent();

            $this->assertStringContainsString('id="lease-property-search"', $html, "no picker for preset {$id}");
            $address && $this->assertStringNotContainsString($address === 'Marine Drive' ? '30 Marine Drive' : $address, $html);
        }

        $this->actingAs($this->wideUser)
            ->get(route('corex.leases.create') . '?property_id[]=' . $this->marineDrive->id)
            ->assertOk();
    }

    // ── 1. every field survives a validation error (leases.md §7.3) ──────────

    public function test_every_field_comes_back_after_a_validation_error(): void
    {
        $thandi = $this->makeContact('Thandi', 'Nkosi');
        $sipho = $this->makeContact('Sipho', 'Dlamini');
        $leaseType = \App\Models\PropertySettingItem::create([
            'agency_id' => $this->agency->id, 'group' => 'lease_type', 'name' => 'Residential', 'active' => true,
        ]);

        // A real round trip: submit with an end date BEFORE the start date, follow the bounce back.
        $this->actingAs($this->wideUser)
            ->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), [
                'property_id' => $this->marineDrive->id,
                'rental_amount' => '8500.50', 'deposit_amount' => '17001', 'start_date' => '2026-11-01', 'end_date' => '2026-10-01',
                'is_month_to_month' => '1', 'lease_type' => 'Residential', 'activate_immediately' => '1',
                'tenant_contact_ids' => [$sipho->id, $thandi->id],
            ])
            ->assertRedirect(route('corex.leases.create'))
            ->assertSessionHasErrors('end_date');

        $html = $this->actingAs($this->wideUser)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="rental_amount" value="8500.50"', $html);
        $this->assertStringContainsString('name="deposit_amount" value="17001"', $html);
        $this->assertStringContainsString('name="start_date" value="2026-11-01"', $html);
        $this->assertStringContainsString('name="end_date" value="2026-10-01"', $html);
        $this->assertMatchesRegularExpression('/name="is_month_to_month" value="1" checked/', $html);
        $this->assertMatchesRegularExpression('/name="activate_immediately" value="1" checked/', $html);
        $this->assertMatchesRegularExpression('/<option value="Residential" selected>/', $html);
        // Tenants are seeded into the Alpine list, primary (first chosen) first.
        $seed = strpos($html, 'Sipho Dlamini');
        $this->assertNotFalse($seed);
        $this->assertNotFalse(strpos($html, 'Thandi Nkosi'));
        $this->assertLessThan(strpos($html, 'Thandi Nkosi'), $seed);
        // The picked property still comes back too.
        $this->assertMatchesRegularExpression('/u0022id\\\\?u0022:' . $this->marineDrive->id . '[,}]/', $html);
    }

    public function test_a_fresh_form_has_blank_fields_and_nothing_checked(): void
    {
        $html = $this->actingAs($this->wideUser)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="rental_amount" value=""', $html);
        $this->assertDoesNotMatchRegularExpression('/name="is_month_to_month" value="1" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="activate_immediately" value="1" checked/', $html);
    }

    public function test_an_old_tenant_from_another_agency_or_a_made_up_id_is_not_restored(): void
    {
        $mine = $this->makeContact('Thandi', 'Nkosi');
        $foreign = \App\Models\Contact::create(['agency_id' => $this->rival->id, 'first_name' => 'Rival', 'last_name' => 'Tenant']);

        $html = $this->actingAs($this->wideUser)
            ->withSession(['_old_input' => ['tenant_contact_ids' => [$foreign->id, 99999999, $mine->id, 'x']]])
            ->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Thandi Nkosi', $html);
        $this->assertStringNotContainsString('Rival Tenant', $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeContact(string $first, string $last): \App\Models\Contact
    {
        return \App\Models\Contact::create(['agency_id' => $this->agency->id, 'first_name' => $first, 'last_name' => $last]);
    }

    /** @return array<string,mixed> a complete, valid POST body for $propertyId */
    private function leasePayload(mixed $propertyId): array
    {
        return [
            'property_id' => $propertyId, 'rental_amount' => 8500, 'start_date' => '2026-11-01',
            'tenant_contact_ids' => [$this->makeContact('Thandi', 'Nkosi')->id],
        ];
    }

    /** The save must bounce with the plain "choose a property" message and create nothing. */
    private function assertStoreRefused(User $user, mixed $propertyId): void
    {
        $before = \App\Models\Lease::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->from(route('corex.leases.create'))
            ->post(route('corex.leases.store'), $this->leasePayload($propertyId))
            ->assertRedirect(route('corex.leases.create'))
            ->assertSessionHasErrors(['property_id' => 'Please choose a property from the list.']);

        $this->assertSame($before, \App\Models\Lease::withoutGlobalScopes()->count(), 'a lease was created for ' . json_encode($propertyId));
    }

    /** @return list<int> ids returned by the picker endpoint for $term, in the order returned. */
    private function search(User $user, string $term): array
    {
        $resp = $this->actingAs($user)
            ->getJson(route('corex.leases.search-rental-properties', ['q' => $term]))
            ->assertOk();

        return array_map(fn (array $row) => (int) $row['id'], $resp->json());
    }

    /** @param list<string> $except permission keys to leave out */
    private function grantRole(string $role, string $propertiesScope, array $except = []): void
    {
        foreach ([$this->agency, $this->rival] as $agency) {
            Role::create(['name' => $role, 'label' => $role, 'agency_id' => $agency->id]);
            foreach (['leases.view' => 'all', 'leases.create' => 'all', 'properties.view' => $propertiesScope] as $key => $scope) {
                if (in_array($key, $except, true)) {
                    continue;
                }
                RolePermission::updateOrCreate(
                    ['role' => $role, 'permission_key' => $key, 'agency_id' => $agency->id],
                    ['scope' => $scope],
                );
            }
        }
        PermissionService::clearCache();
    }

    private function makeUser(string $role, Branch $branch): User
    {
        return User::factory()->create([
            'agency_id' => $branch->agency_id, 'branch_id' => $branch->id, 'role' => $role, 'is_active' => true,
        ]);
    }

    private function makeProperty(Branch $branch, User $agent, array $overrides = [], ?Agency $agency = null): Property
    {
        $parts = array_filter([
            $overrides['street_number'] ?? null, $overrides['street_name'] ?? null,
            $overrides['suburb'] ?? null, $overrides['city'] ?? null,
        ]);

        return Property::create(array_merge([
            'agency_id' => ($agency ?? $this->agency)->id,
            'branch_id' => $branch->id,
            'agent_id' => $agent->id,
            'status' => 'active',
            'listing_type' => 'rental',
            'address' => implode(', ', $parts),
        ], $overrides));
    }
}
