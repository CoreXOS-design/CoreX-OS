<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\User;
use App\Services\Rentals\LeaseHubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-490 - "Start in-inspection" / "Start out-inspection" on a lease begins the inspection for THAT lease straight away
 * (no property picker), like the property tab's Start. Spec: .ai/specs/rental-inspections.md (AT-490).
 */
final class LeaseStartInspectionDirectAt490Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Auth::logout();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Direct Agency', 'slug' => 'da-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '9 Direct Road', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        foreach (['rental_inspections.view', 'rental_inspections.create', 'leases.view', 'properties.view', 'access_properties'] as $key) {
            DB::table('role_permissions')->insert([
                'role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($this->agent);
    }

    private function lease(array $over = [], ?Property $property = null): Lease
    {
        $lease = Lease::create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => ($property ?? $this->property)->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'deposit_amount' => 19000,
            'start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addMonths(12)->toDateString(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thandi', 'last_name' => 'T' . uniqid(), 'email' => 'direct-' . uniqid() . '@example.co.za',
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);

        return $lease;
    }

    public function test_the_lease_screens_next_step_is_a_post_that_names_the_lease_and_the_type(): void
    {
        $lease = $this->lease();

        $next = app(LeaseHubService::class)->nextStep($lease->fresh(), $this->agent);

        $this->assertSame('Start in-inspection', $next['label']);
        $this->assertSame('corex.rental-inspections.start-for-lease', $next['route_name']);
        $this->assertSame(['lease' => $lease->id, 'type' => 'in'], $next['route_param']);
        $this->assertTrue($next['post']);

        $html = $this->get(route('corex.leases.show', $lease))->assertOk()->getContent();
        $this->assertStringContainsString('action="' . route('corex.rental-inspections.start-for-lease', ['lease' => $lease->id, 'type' => 'in']) . '"', $html);
    }

    public function test_pressing_it_starts_the_inspection_for_that_lease_and_lands_on_the_property_tab_not_a_picker(): void
    {
        $lease = $this->lease();

        $response = $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $lease->id, 'type' => 'in']));

        $inspection = RentalInspection::where('lease_id', $lease->id)->firstOrFail();
        $this->assertSame('in', $inspection->type);
        $this->assertSame($this->property->id, (int) $inspection->property_id);
        $response->assertRedirect(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']));
    }

    public function test_with_two_active_leases_on_the_property_the_inspection_goes_on_the_one_clicked(): void
    {
        $first = $this->lease(['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString()]);
        $second = $this->lease();

        $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $second->id, 'type' => 'out']));

        $this->assertSame(1, RentalInspection::where('lease_id', $second->id)->count());
        $this->assertSame(0, RentalInspection::where('lease_id', $first->id)->count());
    }

    public function test_the_model_refuses_a_lease_that_is_not_on_the_property_or_not_active(): void
    {
        $other = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '1 Other Road', 'status' => 'let_out', 'listing_type' => 'rental',
        ]);
        $foreign = $this->lease([], $other);
        $ended = $this->lease(['status' => Lease::STATUS_EXPIRED]);

        $this->expectException(\LogicException::class);
        RentalInspection::start($this->property, 'in', $this->agent, $foreign);
    }

    public function test_an_ended_lease_cannot_have_one_started(): void
    {
        $this->lease();
        $ended = $this->lease(['status' => Lease::STATUS_EXPIRED]);

        $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $ended->id, 'type' => 'in']))
            ->assertRedirect(route('corex.leases.show', $ended))->assertSessionHas('error');
        $this->assertSame(0, RentalInspection::where('lease_id', $ended->id)->count());
    }

    public function test_a_second_press_is_refused_not_duplicated(): void
    {
        $lease = $this->lease();
        $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $lease->id, 'type' => 'in']));

        $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $lease->id, 'type' => 'in']))
            ->assertRedirect(route('corex.leases.show', $lease))->assertSessionHas('error');
        $this->assertSame(1, RentalInspection::where('lease_id', $lease->id)->count());
    }

    public function test_a_lease_in_another_agency_is_a_404_and_needs_the_create_permission(): void
    {
        $otherAgency = Agency::create(['name' => 'Elsewhere', 'slug' => 'el-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'X', 'agency_id' => $otherAgency->id]);
        $otherProperty = Property::forceCreate(['agency_id' => $otherAgency->id, 'agent_id' => $this->agent->id, 'branch_id' => $otherBranch->id, 'title' => 'Far Away', 'status' => 'let_out', 'listing_type' => 'rental']);
        // BelongsToAgency would stamp the acting user's agency over this on create - keep it genuinely foreign.
        $foreign = Lease::withoutAgencyStamping(fn () => Lease::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'property_id' => $otherProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->toDateString(), 'end_date' => now()->addYear()->toDateString(),
        ]));

        $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $foreign->id, 'type' => 'in']))->assertNotFound();

        DB::table('role_permissions')->where('permission_key', 'rental_inspections.create')->delete();
        \App\Services\PermissionService::clearCache();
        $mine = $this->lease();
        $this->post(route('corex.rental-inspections.start-for-lease', ['lease' => $mine->id, 'type' => 'in']))->assertForbidden();
    }

    public function test_the_generic_form_opened_for_a_lease_carries_the_lease_and_always_lists_its_property(): void
    {
        $lease = $this->lease();

        $html = $this->get(route('corex.rental-inspections.create', ['lease_id' => $lease->id, 'type' => 'in']))->assertOk()->getContent();

        $this->assertStringContainsString('name="lease_id" value="' . $lease->id . '"', $html);
        $this->assertMatchesRegularExpression('/<option value="' . $this->property->id . '"[^>]*selected/', $html);

        $first = $this->lease(['start_date' => now()->subYear()->toDateString()]);
        $this->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'lease_id' => $lease->id, 'type' => 'in']);
        $this->assertSame(1, RentalInspection::where('lease_id', $lease->id)->count());
        $this->assertSame(0, RentalInspection::where('lease_id', $first->id)->count());
    }
}
