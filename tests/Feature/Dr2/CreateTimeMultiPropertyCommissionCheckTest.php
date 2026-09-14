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
 * AT-focus-fix, 2026-09-18 — Johan reported the commission check "off by
 * R17,800" against nothing visible on screen, while his own numbers (10,000
 * + 10,000 against a 20,000 deal commission) genuinely balanced. Root cause:
 * the balance banner compared the live sum against a STALE snapshot of the
 * hidden total_commission field, frozen from whenever a price/row edit had
 * last triggered a refresh — never updated when the Commission %/amount
 * fields themselves were edited afterward, because setting a hidden input's
 * .value programmatically never fires its own 'input' listener.
 *
 * Per the conductor's explicit instruction, the commission check gets more
 * scrutiny than anything else in this piece of work — "a wrong price on a
 * property is untidy; a wrong commission split pays a real person the wrong
 * amount." This file proves the SERVER-SIDE commission reconciliation
 * (validateAdditionalPropertiesPayload()) exactly, across the full matrix
 * she asked for. The CLIENT-SIDE fix (the stale hidden-field bug itself, and
 * the VAT-basis-aware conversion) is proven separately by a real browser
 * session — see the build report — since neither is reachable through an
 * HTTP feature test.
 */
final class CreateTimeMultiPropertyCommissionCheckTest extends TestCase
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
            'name' => 'CTMP Comm Co', 'slug' => 'ctmp-comm-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
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
            'external_id' => 'CTMPC-' . Str::random(8), 'title' => $address, 'address' => $address,
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

    private function linkOwner(Property $property, Contact $contact): void
    {
        DB::table('contact_property')->insert([
            'property_id' => $property->id, 'contact_id' => $contact->id, 'role' => 'seller',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function basePayload(): array
    {
        return [
            'period' => '2026-09', 'deal_date' => '2026-09-14', 'branch_id' => $this->branchId,
            'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
            'listing_agents' => [$this->bm->id], 'selling_agents' => [$this->bm->id],
        ];
    }

    /** @return array{0: Property, 1: Property} */
    private function twoOwnedProperties(): array
    {
        $propA = $this->makeProperty('Comm Check A Rd');
        $propB = $this->makeProperty('Comm Check B Rd');
        $steve = $this->makeContact('Steve');
        $this->linkOwner($propA, $steve);
        $this->linkOwner($propB, $steve);

        return [$propA, $propB];
    }

    private function submit(Property $propA, Property $propB, float $priceA, float $commA, float $priceB, float $commB, float $totalPrice, float $totalComm)
    {
        return $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => $totalPrice, 'total_commission' => $totalComm,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => $priceA, 'allocated_commission' => $commA],
                ['property_id' => $propB->id, 'allocated_price' => $priceB, 'allocated_commission' => $commB],
            ],
        ]));
    }

    public function test_equal_commission_split_balances(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $response = $this->submit($propA, $propB, 100_000, 10_000, 100_000, 10_000, 200_000, 20_000);

        $response->assertSessionDoesntHaveErrors(['property_value', 'total_commission']);
        $this->assertNotNull(Deal::where('property_id', $propA->id)->first());
    }

    public function test_unequal_commission_split_still_balances(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        // 7,000 + 13,000 = 20,000 — an uneven split, still correct.
        $response = $this->submit($propA, $propB, 100_000, 7_000, 100_000, 13_000, 200_000, 20_000);

        $response->assertSessionDoesntHaveErrors(['property_value', 'total_commission']);
        $this->assertNotNull(Deal::where('property_id', $propA->id)->first());
    }

    public function test_one_property_carrying_the_entire_commission_balances(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        // Property A carries all 20,000; property B carries none.
        $response = $this->submit($propA, $propB, 100_000, 20_000, 100_000, 0, 200_000, 20_000);

        $response->assertSessionDoesntHaveErrors(['property_value', 'total_commission']);
        $this->assertNotNull(Deal::where('property_id', $propA->id)->first());
    }

    public function test_zero_commission_on_one_property_with_correct_total_balances(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $response = $this->submit($propA, $propB, 100_000, 0, 100_000, 15_000, 200_000, 15_000);

        $response->assertSessionDoesntHaveErrors(['property_value', 'total_commission']);
        $this->assertNotNull(Deal::where('property_id', $propA->id)->first());
    }

    public function test_a_missing_commission_field_is_rejected_outright_not_silently_treated_as_zero(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 200_000, 'total_commission' => 10_000,
            'properties' => [
                // allocated_commission omitted entirely on the first row.
                ['property_id' => $propA->id, 'allocated_price' => 100_000],
                ['property_id' => $propB->id, 'allocated_price' => 100_000, 'allocated_commission' => 10_000],
            ],
        ]));

        $response->assertSessionHasErrors('properties.0.allocated_commission');
        $this->assertSame($countBefore, Deal::count(), 'a missing figure must be rejected outright, never coerced to zero and silently summed');
    }

    public function test_an_empty_string_commission_field_is_rejected_outright(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->actingAs($this->bm)->post(route('deals-dr2.store'), array_merge($this->basePayload(), [
            'property_id' => $propA->id,
            'property_value' => 200_000, 'total_commission' => 10_000,
            'properties' => [
                ['property_id' => $propA->id, 'allocated_price' => 100_000, 'allocated_commission' => ''],
                ['property_id' => $propB->id, 'allocated_price' => 100_000, 'allocated_commission' => 10_000],
            ],
        ]));

        $response->assertSessionHasErrors('properties.0.allocated_commission');
        $this->assertSame($countBefore, Deal::count());
    }

    public function test_decimal_commission_split_balances_to_the_cent(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        // 6,666.67 + 13,333.33 = 20,000.00 exactly.
        $response = $this->submit($propA, $propB, 100_000, 6_666.67, 100_000, 13_333.33, 200_000, 20_000);

        $response->assertSessionDoesntHaveErrors(['property_value', 'total_commission']);
        $this->assertNotNull(Deal::where('property_id', $propA->id)->first());
    }

    public function test_a_one_cent_decimal_mismatch_is_still_caught(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();
        // 6,666.66 + 13,333.33 = 19,999.99 — one cent short of 20,000.00.
        $response = $this->submit($propA, $propB, 100_000, 6_666.66, 100_000, 13_333.33, 200_000, 20_000);

        $response->assertSessionHasErrors('total_commission');
        $response->assertSessionDoesntHaveErrors('property_value');
        $this->assertSame($countBefore, Deal::count(), 'even a one-cent commission mismatch must block the save — money, not price, is the figure that pays people');
    }

    /**
     * Johan's exact real-world case, and the whole reason this file exists:
     * price genuinely wrong, commission genuinely right. The two checks are
     * independent — reported as such, never coupled.
     */
    public function test_price_wrong_commission_correct_reports_only_the_price_error(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->submit($propA, $propB, 100_000, 10_000, 100_000, 10_000, 220_000, 20_000);

        $response->assertSessionHasErrors('property_value');
        $response->assertSessionDoesntHaveErrors('total_commission');
        $this->assertSame($countBefore, Deal::count());
    }

    /** The mirror case: commission wrong, price correct. */
    public function test_commission_wrong_price_correct_reports_only_the_commission_error(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->submit($propA, $propB, 100_000, 10_000, 100_000, 10_000, 200_000, 25_000);

        $response->assertSessionHasErrors('total_commission');
        $response->assertSessionDoesntHaveErrors('property_value');
        $this->assertSame($countBefore, Deal::count());
    }

    public function test_both_price_and_commission_wrong_reports_both_independently(): void
    {
        [$propA, $propB] = $this->twoOwnedProperties();
        $countBefore = Deal::count();

        $response = $this->submit($propA, $propB, 100_000, 10_000, 100_000, 10_000, 250_000, 25_000);

        $response->assertSessionHasErrors(['property_value', 'total_commission']);
        $this->assertSame($countBefore, Deal::count());
    }
}
