<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * property-identity.md 16.6: ONE address rule. A row whose own address text is blank shows its property file's address; the With-address-only
 * filter and the row title can never disagree ("No address yet" under "With address only").
 */
final class ListingResolvedAddressTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private User $admin;
    private Agency $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
    }

    private function tp(Agency $agency, string $ref, string $number, string $street): TrackedProperty
    {
        return app(TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $agency->id, ['street_number' => $number, 'street_name' => $street, 'suburb' => 'Port Edward'], ['type' => 'p24', 'ref' => $ref],
        );
    }

    private function listing(Agency $agency, string $ref, ?string $address, ?TrackedProperty $tp, string $portal = 'pp'): ProspectingListing
    {
        return ProspectingListing::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'portal_source' => $portal, 'portal_ref' => $ref, 'portal_url' => 'https://portal.test/' . $ref, 'address' => $address,
            'suburb' => 'Port Edward', 'property_type' => 'House', 'price' => 1_000_000, 'captured_by_user_id' => $this->admin->id,
            'tracked_property_id' => $tp?->id, 'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ]);
    }

    public function test_a_blank_listing_gets_its_property_files_address_and_never_across_agencies(): void
    {
        $tp = $this->tp($this->agency, 'P24-118000001', '13', 'Newport Road');
        $tpOther = $this->tp($this->b, 'P24-118000002', '7', 'Beach Road');
        $blank = $this->listing($this->agency, 'PP-T9000001', null, $tp);
        $placeholder = $this->listing($this->agency, 'PP-T9000002', ProspectingListing::ADDRESS_PLACEHOLDER, $tp);
        $own = $this->listing($this->agency, 'PP-T9000003', '99 Own Text Street', $tp);
        $cross = $this->listing($this->agency, 'PP-T9000004', null, $tpOther); // file belongs to another agency: never shown
        $noFile = $this->listing($this->agency, 'PP-T9000005', null, null);
        $complex = $this->listing($this->agency, 'PP-T9000006', '', $this->tp($this->agency, 'P24-118000003', '1', 'Entaba Hibberdene'));

        $map = ProspectingListing::resolveDisplayAddresses([$blank, $placeholder, $own, $cross, $noFile, $complex], $this->agency->id);

        $this->assertSame('13 Newport Road', $map[$blank->id]);
        $this->assertSame('13 Newport Road', $map[$placeholder->id]);
        $this->assertSame('1 Entaba Hibberdene', $map[$complex->id], 'a complex or estate name is a street name');
        $this->assertArrayNotHasKey($own->id, $map, 'real own text is never replaced');
        $this->assertArrayNotHasKey($cross->id, $map);
        $this->assertArrayNotHasKey($noFile->id, $map);
    }

    public function test_every_row_the_with_address_filter_passes_has_a_line_to_show(): void
    {
        $tp = $this->tp($this->agency, 'P24-118000004', '13', 'Newport Road');
        $this->listing($this->agency, 'PP-T9000011', null, $tp);
        $this->listing($this->agency, 'PP-T9000012', '5 Real Street', null);
        $this->listing($this->agency, 'PP-T9000013', null, null); // no address anywhere: filtered out

        $passed = ProspectingListing::query()->withAddress()->get();
        $this->assertCount(2, $passed);
        $map = ProspectingListing::resolveDisplayAddresses($passed, $this->agency->id);
        foreach ($passed as $l) {
            $this->assertTrue(trim((string) $l->address) !== '' || isset($map[$l->id]), "listing {$l->portal_ref} passes the filter but would show no address");
        }
    }

    public function test_work_page_under_with_address_only_never_says_no_address_yet_for_a_file_with_a_street(): void
    {
        $tp = $this->tp($this->agency, 'P24-118000005', '13', 'Newport Road Road');
        $this->listing($this->agency, 'PP-T4203603', null, $tp);

        $this->actingAs($this->admin)->get(route('market-intelligence.work', ['address_filter' => 'with_address']))
            ->assertOk()->assertSee('PP-T4203603', false)->assertSee('13 Newport Road Road', false)->assertDontSee('No address yet', false);
    }
}
