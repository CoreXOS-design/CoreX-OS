<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan, from his own live walk, 2026-09-21 — an approved application
 * linked to a property and an active lease still read as merely
 * "Approved" everywhere (the applications list, the application itself,
 * the contact record) — indistinguishable from a decision made last week
 * with no tenant yet.
 *
 * Deliberately NOT a new RentalApplication.status value — approved is the
 * decision, made once, and every historical field_config_snapshot/
 * generation record this module builds on depends on a past decision
 * never being reinterpreted by a later, unrelated fact. This is a further
 * state, derived live from Lease.status every time it's asked
 * (RentalApplication::isTenanted()), never cached, never a flag that has
 * to be remembered and cleared.
 *
 * The real gap found building this: RentalApplicationController::show()
 * redirects an approved application to a COMPLETELY DIFFERENT view
 * (view-readonly.blade.php) than the one an unsubmitted application uses
 * (show.blade.php) — a fix applied only to show.blade.php would have
 * looked complete (it compiled, it rendered, "Rented Out" even appeared
 * on the tile) while the actual page an agent lands on for an approved
 * application kept saying "Approved". Found by tracing the real route,
 * not assumed — fixed by converting RentalApplication::displayStatusLabel()
 * from a static string-in-string-out helper into the ONE shared instance
 * method every status badge on every rental-application screen now calls,
 * so this class of drift can't recur.
 */
final class RentalApplicationTenantedStatusTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private Property $property;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'HQ']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Test Property', 'status' => 'active',
        ]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function approvedApplication(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'property_id' => $this->property->id, 'status' => 'approved',
            'token' => Str::random(64), 'full_name' => 'Sipho Ndlovu',
            'approved_rental_amount' => 12500,
        ], $attrs));
    }

    private function activeLease(RentalApplication $application): Lease
    {
        return Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12500, 'start_date' => now()->subDays(5),
            'source' => 'rental_application', 'rental_application_id' => $application->id,
        ]);
    }

    // ── The derivation itself ────────────────────────────────────────────

    public function test_an_approved_application_with_no_lease_is_not_tenanted(): void
    {
        $app = $this->approvedApplication();

        $this->assertFalse($app->isTenanted());
    }

    public function test_an_approved_application_with_an_active_linked_lease_is_tenanted(): void
    {
        $app = $this->approvedApplication();
        $this->activeLease($app);

        $this->assertTrue($app->fresh()->isTenanted());
    }

    public function test_a_non_approved_application_is_never_tenanted_even_with_a_lease_row(): void
    {
        // Defensive — a lease should never legitimately exist against a
        // declined/withdrawn application, but the derivation must not
        // trust the lease alone if it somehow does.
        $app = $this->approvedApplication(['status' => 'declined']);
        $this->activeLease($app);

        $this->assertFalse($app->fresh()->isTenanted());
    }

    public function test_survives_the_lease_ending(): void
    {
        $app = $this->approvedApplication();
        $lease = $this->activeLease($app);
        $this->assertTrue($app->fresh()->isTenanted());

        $lease->update(['status' => Lease::STATUS_EXPIRED]);

        $this->assertFalse($app->fresh()->isTenanted());
    }

    public function test_survives_the_lease_being_cancelled(): void
    {
        $app = $this->approvedApplication();
        $lease = $this->activeLease($app);

        $lease->update(['status' => Lease::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->assertFalse($app->fresh()->isTenanted());
    }

    public function test_survives_a_tenant_being_replaced_a_new_lease_new_application_pair(): void
    {
        $app = $this->approvedApplication();
        $lease = $this->activeLease($app);
        $lease->update(['status' => Lease::STATUS_EXPIRED]);

        $newContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'New', 'last_name' => 'Tenant', 'email' => 'new@example.co.za',
        ]);
        $newApp = $this->approvedApplication(['contact_id' => $newContact->id]);
        $this->activeLease($newApp);

        $this->assertFalse($app->fresh()->isTenanted(), 'the original application must not read as tenanted once its own lease ended');
        $this->assertTrue($newApp->fresh()->isTenanted(), 'the replacement tenant\'s own application must read as tenanted');
    }

    // ── The label ────────────────────────────────────────────────────────

    public function test_default_label_is_rented_out(): void
    {
        $this->assertSame('Rented Out', RentalApplicationQualifyingSetting::tenantedLabelFor($this->agency->id));
    }

    public function test_agency_can_configure_its_own_label(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['tenanted_label' => 'Tenant Placed']
        );

        $this->assertSame('Tenant Placed', RentalApplicationQualifyingSetting::tenantedLabelFor($this->agency->id));

        $app = $this->approvedApplication();
        $this->activeLease($app);
        $this->assertSame('Tenant Placed', $app->fresh()->tenantedLabel());
    }

    public function test_saving_the_label_persists_it(): void
    {
        $this->actingAs($this->agent)->post(route('corex.settings.rental-applications.tenanted-label'), [
            'tenanted_label' => 'Occupied',
        ])->assertRedirect(route('corex.settings.rental-applications.edit'));

        $this->assertSame('Occupied', RentalApplicationQualifyingSetting::tenantedLabelFor($this->agency->id));
    }

    public function test_saving_a_blank_label_clears_it_to_the_default(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['tenanted_label' => 'Occupied']
        );

        $this->actingAs($this->agent)->post(route('corex.settings.rental-applications.tenanted-label'), [
            'tenanted_label' => '',
        ]);

        $this->assertSame('Rented Out', RentalApplicationQualifyingSetting::tenantedLabelFor($this->agency->id));
    }

    // ── displayStatusLabel() — the one shared decision point ─────────────

    public function test_display_status_label_reads_tenanted_when_applicable(): void
    {
        $app = $this->approvedApplication();
        $this->activeLease($app);

        $this->assertSame('Rented Out', $app->fresh()->displayStatusLabel());
    }

    public function test_display_status_label_reads_approved_when_not_tenanted(): void
    {
        $app = $this->approvedApplication();

        $this->assertSame('Approved', $app->displayStatusLabel());
    }

    // ── The applications list — tiles, filter, mutual exclusivity ────────

    public function test_approved_tile_excludes_a_tenanted_application(): void
    {
        $app = $this->approvedApplication();
        $this->activeLease($app);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['tile' => 'approved']));

        $response->assertOk();
        $response->assertDontSee($this->contact->full_name);
    }

    public function test_tenanted_tile_shows_a_tenanted_application(): void
    {
        $app = $this->approvedApplication();
        $this->activeLease($app);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['tile' => 'tenanted']));

        $response->assertOk();
        $response->assertSee($this->contact->full_name);
        $response->assertSee('Rented Out');
    }

    public function test_tenanted_tile_never_shows_a_merely_approved_application(): void
    {
        $this->approvedApplication();

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['tile' => 'tenanted']));

        $response->assertOk();
        $response->assertDontSee($this->contact->full_name);
    }

    public function test_a_row_is_never_double_counted_in_both_tiles(): void
    {
        $tenantedApp = $this->approvedApplication();
        $this->activeLease($tenantedApp);
        $newContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Merely', 'last_name' => 'Approved', 'email' => 'merely@example.co.za',
        ]);
        $this->approvedApplication(['contact_id' => $newContact->id]);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['tile' => 'all']));

        $response->assertOk();
        // Both rows appear in the unfiltered 'all' list — the mutual
        // exclusivity is about which TILE a row belongs to, never about
        // hiding it from 'all'. Not order-dependent: the list's own
        // default sort (newest first) is a separate concern from this test.
        $response->assertSee($this->contact->full_name);
        $response->assertSee('Merely');
    }

    // ── The application detail screen — the real route, not the file name ─

    public function test_the_actual_approved_application_route_shows_the_tenanted_label(): void
    {
        // RentalApplicationController::show() redirects an 'approved'
        // application to view-readonly.blade.php, NOT show.blade.php — the
        // real gap found building this. This test exercises the REAL route
        // an agent actually lands on for an approved application.
        $app = $this->approvedApplication();
        $this->activeLease($app);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertSee('Rented Out');
    }

    // ── The contact record ────────────────────────────────────────────────

    public function test_contact_page_current_status_reads_tenanted(): void
    {
        $app = $this->approvedApplication();
        $this->activeLease($app);

        $response = $this->actingAs($this->agent)->get(route('corex.contacts.show', $this->contact) . '?tab=rental');

        $response->assertOk();
        $response->assertSee('Rented Out');
    }

    public function test_contact_page_current_status_stays_approved_when_not_tenanted(): void
    {
        $this->approvedApplication();

        $response = $this->actingAs($this->agent)->get(route('corex.contacts.show', $this->contact) . '?tab=rental');

        $response->assertOk();
        $response->assertSee('Approved');
    }
}
