<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalLeaseTemplate;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RenewalDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AT-444 item 5 — GATE 1 (approved 2026-10-04): rental_lease_templates
 * CRUD (list-screen floor: search/sort/filter/archive/restore/empty-state),
 * eligibility (missing required fields), and draftFromTemplate()'s
 * block-until-filled rule.
 */
final class RentalLeaseTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private Property $property;
    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Template Agency', 'slug' => 'tpl-' . uniqid()]);
        $this->branch = Branch::forceCreate(['agency_id' => $this->agency->id, 'name' => 'Branch A']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->user->id,
            'title' => 'Template test property', 'status' => 'active', 'listing_type' => 'rental', 'suburb' => 'Shelly Beach',
        ]);
        $this->template = Template::create(['name' => 'Residential Lease', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $this->agency->id]);
    }

    public function test_full_crud_list_screen_search_sort_filter_archive_restore(): void
    {
        $t1 = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $this->template->id, 'category' => 'residential', 'is_active' => true]);
        $t2 = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Commercial', 'docuperfect_template_id' => $this->template->id, 'category' => 'commercial', 'is_active' => true]);

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index', ['q' => 'Resid']));
        $response->assertOk();
        $response->assertSee('Residential');
        $response->assertDontSee('Commercial');

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index', ['category' => 'commercial']));
        $response->assertSee('Commercial');
        $response->assertDontSee('Residential');

        $this->actingAs($this->user)->delete(route('corex.rental-lease-templates.destroy', $t1))->assertRedirect();
        self::assertSoftDeleted('rental_lease_templates', ['id' => $t1->id]);

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index', ['archived' => 1]));
        $response->assertSee('Residential');

        $this->actingAs($this->user)->post(route('corex.rental-lease-templates.restore', $t1->id))->assertRedirect();
        self::assertNotSoftDeleted('rental_lease_templates', ['id' => $t1->id]);
    }

    public function test_empty_state_renders_when_nothing_set_up(): void
    {
        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index'));
        $response->assertOk();
        $response->assertSee('Nothing set up yet');
    }

    public function test_store_rejects_a_template_from_another_agency(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherTemplate = Template::create(['name' => 'Not mine', 'render_type' => 'pdf', 'is_esign' => true, 'agency_id' => $otherAgency->id]);

        $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => 'Sneaky', 'docuperfect_template_id' => $otherTemplate->id, 'category' => 'residential',
        ])->assertForbidden();
    }

    public function test_missing_required_fields_lists_every_blank(): void
    {
        $rlt = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $this->template->id, 'category' => 'residential', 'is_active' => true]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->user->id,
        ]);
        // No tenant linked, no landlord linked — both must be reported missing.

        $missing = app(RenewalDraftService::class)->missingRequiredFields($lease, $rlt, [], $this->user);

        self::assertContains('Landlord name', $missing);
        self::assertContains('Tenant name', $missing);
        self::assertNotContains('Rent', $missing, 'Rent is already set on the lease.');
    }

    public function test_draft_from_template_succeeds_when_everything_is_complete(): void
    {
        $rlt = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $this->template->id, 'category' => 'residential', 'is_active' => true]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->user->id,
        ]);
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tenant', 'last_name' => 'One', 'email' => uniqid() . '@example.test']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenant->id, 'is_primary' => true]);
        $landlord = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Owner', 'last_name' => 'Person', 'email' => uniqid() . '@example.test']);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        $result = app(RenewalDraftService::class)->draftFromTemplate($lease->fresh(), $rlt, [
            'start_date' => now()->addDay()->toDateString(), 'rental_amount' => 9500,
        ], $this->user);

        self::assertSame($this->template->id, $result['flow']->template_id);
        self::assertSame($result['flow']->id, $result['lease']->renewal_draft_flow_id);
    }

    public function test_draft_from_template_blocks_when_fields_are_missing(): void
    {
        $rlt = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $this->template->id, 'category' => 'residential', 'is_active' => true]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->user->id,
        ]);
        // No tenant, no landlord.

        $this->expectException(ValidationException::class);

        app(RenewalDraftService::class)->draftFromTemplate($lease, $rlt, [
            'start_date' => now()->addDay()->toDateString(), 'rental_amount' => 9500,
        ], $this->user);
    }

    public function test_draft_from_template_rejects_an_archived_template(): void
    {
        $rlt = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $this->template->id, 'category' => 'residential', 'is_active' => false]);
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9000, 'start_date' => now()->toDateString(),
            'source' => 'manual', 'created_by_user_id' => $this->user->id,
        ]);

        $this->expectException(ValidationException::class);

        app(RenewalDraftService::class)->draftFromTemplate($lease, $rlt, [
            'start_date' => now()->addDay()->toDateString(), 'rental_amount' => 9500,
        ], $this->user);
    }
}
