<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, QA1 rentals test, 2026-10-07:
 *  - on the lease screen the property, tenant and landlord open in a NEW tab, so the agent keeps the screen;
 *  - the Tenancy log's type boxes are FILTERS (a "Show:" label, chips, all types when none is on, a clear link);
 *  - the property's Rentals tab shows the ACTIVE LEASE's dates, read-only and not posted, instead of empty
 *    property date fields (one source of truth).
 */
final class LeaseScreenLinksAndRentalTabTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $this->user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $this->user->id,
            'title' => 'Flat', 'status' => 'active', 'listing_type' => 'rental', 'address' => '401 Margate Boulevard',
            'suburb' => 'Margate', 'city' => 'Margate', 'rental_amount' => 11400,
        ]);
        $tenant = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Ayanda', 'last_name' => 'Tenant', 'email' => 't@example.test']);
        $landlord = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Lando', 'last_name' => 'Lord', 'email' => 'l@example.test']);
        \DB::table('contact_property')->insert([
            'contact_id' => $landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 11400, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->user->id,
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $this->tenant = $tenant;
        $this->landlord = $landlord;
    }

    private Contact $tenant;
    private Contact $landlord;

    public function test_property_tenant_and_landlord_links_open_in_a_new_tab(): void
    {
        $html = $this->actingAs($this->user)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        foreach ([
            'lease-property-link' => route('corex.properties.show', $this->property),
            'lease-tenant-link' => route('corex.contacts.show', $this->tenant),
            'lease-landlord-link' => route('corex.contacts.show', $this->landlord),
        ] as $marker => $url) {
            self::assertMatchesRegularExpression(
                '#<a href="' . preg_quote($url, '#') . '" target="_blank" rel="noopener"[^>]*data-test="' . $marker . '"#',
                $html,
                "{$marker} must open in a new tab"
            );
        }
    }

    public function test_the_tenancy_log_type_boxes_read_as_filters(): void
    {
        $this->actingAs($this->user)->get(route('corex.leases.show', $this->lease))
            ->assertOk()
            ->assertSee('data-test="tenancy-log-filter-chips"', false)
            ->assertSee('Show:')
            ->assertSee('All types shown')
            ->assertDontSee('data-test="tenancy-log-filter-clear"', false);

        $this->actingAs($this->user)->get(route('corex.leases.show', $this->lease) . '?type[]=lease')
            ->assertOk()
            ->assertSee('Only the highlighted types')
            ->assertSee('data-test="tenancy-log-filter-clear"', false);
    }

    public function test_the_rentals_tab_shows_the_active_leases_dates_read_only(): void
    {
        $html = $this->actingAs($this->user)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();

        self::assertMatchesRegularExpression('#type="date" value="2026-11-01" disabled[^>]*data-test="property-lease-start-from-lease"#', $html);
        self::assertMatchesRegularExpression('#type="date" value="2027-10-31" disabled[^>]*data-test="property-lease-end-from-lease"#', $html);
        // Not posted: no editable lease date inputs while a lease is active.
        self::assertStringNotContainsString('name="lease_start_date"', $html);
        self::assertStringNotContainsString('name="lease_end_date"', $html);
    }

    public function test_without_an_active_lease_the_property_keeps_its_own_editable_dates(): void
    {
        $this->lease->update(['status' => 'expired']);

        $html = $this->actingAs($this->user)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();

        self::assertStringContainsString('name="lease_start_date"', $html);
        self::assertStringNotContainsString('data-test="property-lease-start-from-lease"', $html);
    }
}
