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
        // Distinctive, collision-free names — the bare words "Residential"/
        // "Commercial" are too generic to assertDontSee() safely: the shared
        // layout's own sidebar nav links to "Commercial Evaluations" on
        // every page, unrelated to this list.
        $t1 = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Residential Zyx Lease', 'docuperfect_template_id' => $this->template->id, 'category' => 'residential', 'is_active' => true]);
        $t2 = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Commercial Zyx Lease', 'docuperfect_template_id' => $this->template->id, 'category' => 'commercial', 'is_active' => true]);

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index', ['q' => 'Resid']));
        $response->assertOk();
        $response->assertSee('Residential Zyx Lease');
        $response->assertDontSee('Commercial Zyx Lease');

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index', ['category' => 'commercial']));
        $response->assertSee('Commercial Zyx Lease');
        $response->assertDontSee('Residential Zyx Lease');

        $this->actingAs($this->user)->delete(route('corex.rental-lease-templates.destroy', $t1))->assertRedirect();
        self::assertSoftDeleted('rental_lease_templates', ['id' => $t1->id]);

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index', ['archived' => 1]));
        $response->assertSee('Residential Zyx Lease');

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

        // 404, not 403: Template::findOrFail() is itself agency-scoped (the
        // global AgencyScope hides another agency's row entirely), so the
        // lookup fails before assertAccessibleBy() ever runs — stricter
        // than a 403 (never confirms the row exists at all), not a gap.
        $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => 'Sneaky', 'docuperfect_template_id' => $otherTemplate->id, 'category' => 'residential',
        ])->assertNotFound();
    }

    // ── leases.md §15.12 (Build L0): the agency's OWN lease agreement — picker, guard, default, field map ──

    public function test_the_picker_lists_only_this_agencys_own_active_esign_templates(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oth-' . uniqid()]);
        $this->makeTemplate($other->id, ['name' => 'Karoo Foreign Lease']);
        $this->makeTemplate(null, ['name' => 'Built In Shared Lease', 'is_global' => true]);
        $this->makeTemplate($this->agency->id, ['name' => 'Archived Own Lease', 'archived_at' => now()]);
        $this->makeTemplate($this->agency->id, ['name' => 'Plain Pdf Own Document', 'is_esign' => false]);
        $own = $this->makeTemplate($this->agency->id, ['name' => 'Cape Own Lease']);

        $response = $this->actingAs($this->user)->get(route('corex.rental-lease-templates.create'));

        $response->assertOk();
        $response->assertSee('Cape Own Lease');
        $response->assertSee('value="' . $own->id . '"', false);
        $response->assertDontSee('Karoo Foreign Lease');
        $response->assertDontSee('Built In Shared Lease');
        $response->assertDontSee('Archived Own Lease');
        $response->assertDontSee('Plain Pdf Own Document');
    }

    public function test_store_refuses_a_shared_template_with_no_owner_and_creates_nothing(): void
    {
        $shared = $this->makeTemplate(null, ['name' => 'Built In Shared Lease', 'is_global' => true]);

        $response = $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => 'Sneaky', 'docuperfect_template_id' => $shared->id, 'category' => 'residential',
        ]);

        $response->assertSessionHasErrors('docuperfect_template_id');
        self::assertSame(0, RentalLeaseTemplate::query()->count());
    }

    public function test_a_forged_foreign_id_and_a_missing_id_get_the_same_answer(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oth-' . uniqid()]);
        $theirs = $this->makeTemplate($other->id);

        $foreign = $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), ['name' => 'X', 'docuperfect_template_id' => $theirs->id, 'category' => 'residential']);
        $missing = $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), ['name' => 'X', 'docuperfect_template_id' => 987654, 'category' => 'residential']);

        self::assertSame($missing->getStatusCode(), $foreign->getStatusCode());
        $foreign->assertNotFound();
        self::assertSame(0, RentalLeaseTemplate::query()->count());
    }

    public function test_store_refuses_an_archived_own_template(): void
    {
        $archived = $this->makeTemplate($this->agency->id, ['archived_at' => now()]);

        $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => 'Old', 'docuperfect_template_id' => $archived->id, 'category' => 'residential',
        ])->assertSessionHasErrors('docuperfect_template_id');
        self::assertSame(0, RentalLeaseTemplate::query()->count());
    }

    public function test_an_own_template_saves_as_needs_field_map_and_sends_the_admin_to_map_it(): void
    {
        $template = $this->makeTemplate($this->agency->id);

        $response = $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => 'Residential', 'docuperfect_template_id' => $template->id, 'category' => 'residential',
        ]);

        $row = RentalLeaseTemplate::firstOrFail();
        $response->assertRedirect(route('corex.rental-lease-templates.edit', $row));
        self::assertSame($this->agency->id, $row->agency_id);
        self::assertNotNull($row->validated_at);
        self::assertContains('Map the monthly rent field.', $row->validation_problems);

        $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index'))
            ->assertOk()->assertSee('Needs field map');
    }

    public function test_there_is_one_default_per_agency_and_category(): void
    {
        $t = $this->makeTemplate($this->agency->id);
        $post = fn (string $name, string $category) => $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => $name, 'docuperfect_template_id' => $t->id, 'category' => $category, 'is_default' => '1',
        ]);

        $post('First residential', 'residential');
        $post('Second residential', 'residential');
        $post('A commercial one', 'commercial');

        $rows = RentalLeaseTemplate::query()->orderBy('id')->get()->keyBy('name');
        self::assertFalse($rows['First residential']->is_default, 'the newer default replaces it');
        self::assertTrue($rows['Second residential']->is_default);
        self::assertTrue($rows['A commercial one']->is_default, 'another category keeps its own default');

        // Ticking default on edit moves it back.
        $this->actingAs($this->user)->put(route('corex.rental-lease-templates.update', $rows['First residential']), [
            'name' => 'First residential', 'category' => 'residential', 'is_active' => '1', 'is_default' => '1',
        ])->assertRedirect();
        self::assertTrue($rows['First residential']->fresh()->is_default);
        self::assertFalse($rows['Second residential']->fresh()->is_default);
    }

    public function test_another_agencys_defaults_are_never_touched(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oth-' . uniqid()]);
        $theirs = $this->makeTemplate($other->id);
        $theirRow = RentalLeaseTemplate::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'name' => 'Theirs', 'docuperfect_template_id' => $theirs->id, 'category' => 'residential', 'is_active' => true, 'is_default' => true,
        ]);

        $mine = $this->makeTemplate($this->agency->id);
        $this->actingAs($this->user)->post(route('corex.rental-lease-templates.store'), [
            'name' => 'Mine', 'docuperfect_template_id' => $mine->id, 'category' => 'residential', 'is_default' => '1',
        ]);

        self::assertTrue(RentalLeaseTemplate::withoutGlobalScopes()->find($theirRow->id)->is_default);
    }

    public function test_the_field_map_saves_and_the_row_becomes_ready(): void
    {
        $template = $this->makeTemplate($this->agency->id, ['fields_json' => $this->fieldNames(['monthly_rental', 'lease_start', 'lease_end', 'lessee_name', 'lessor_name', 'pets_allowed', 'extra_fee'])]);
        $row = $this->row($template);

        $response = $this->actingAs($this->user)->put(route('corex.rental-lease-templates.field-map.update', $row), [
            'map' => [
                'rent' => ['field' => 'monthly_rental'],
                'start_date' => ['field' => 'lease_start'],
                'end_date' => ['field' => 'lease_end'],
                'tenant_name' => ['field' => 'lessee_name'],
                'landlord_name' => ['field' => 'lessor_name'],
                'pets' => ['field' => 'pets_allowed', 'required' => '1'],
                // "required" only counts for agreement-term and schedule keys; the label only for the schedule.
                'deposit' => ['field' => '', 'required' => '1'],
                'other_deduction' => ['field' => 'extra_fee', 'label' => 'Extra fee'],
                'adults' => ['field' => ''],
            ],
        ]);

        $response->assertRedirect(route('corex.rental-lease-templates.edit', $row));
        $map = $row->fresh()->field_map;
        self::assertSame('monthly_rental', $map['rent']['field']);
        self::assertFalse($map['rent']['required'], 'a lease-record key is always needed; the tick does not apply');
        self::assertTrue($map['pets']['required']);
        self::assertSame('Extra fee', $map['other_deduction']['label']);
        self::assertArrayNotHasKey('deposit', $map, 'an unmapped line is simply not carried');
        self::assertArrayNotHasKey('adults', $map);
        self::assertNull($row->fresh()->validation_problems);

        $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index'))->assertOk()->assertSee('Ready');
    }

    public function test_a_field_that_is_not_one_of_the_templates_own_is_refused(): void
    {
        $template = $this->makeTemplate($this->agency->id, ['fields_json' => $this->fieldNames(['monthly_rental'])]);
        $row = $this->row($template);

        $this->actingAs($this->user)->put(route('corex.rental-lease-templates.field-map.update', $row), [
            'map' => ['rent' => ['field' => 'not_a_field_of_this_document']],
        ])->assertSessionHasErrors('map.rent.field');

        self::assertNull($row->fresh()->field_map);
    }

    public function test_month_to_month_is_the_one_way_to_leave_the_end_date_unmapped(): void
    {
        $template = $this->makeTemplate($this->agency->id, ['fields_json' => $this->fieldNames(['monthly_rental', 'lease_start', 'lessee_name', 'lessor_name'])]);
        $row = $this->row($template);
        $map = [
            'rent' => ['field' => 'monthly_rental'], 'start_date' => ['field' => 'lease_start'],
            'tenant_name' => ['field' => 'lessee_name'], 'landlord_name' => ['field' => 'lessor_name'],
        ];

        $this->actingAs($this->user)->put(route('corex.rental-lease-templates.field-map.update', $row), ['map' => $map]);
        self::assertContains('Map the end date field, or tick that this lease is month-to-month.', $row->fresh()->validation_problems);

        $this->actingAs($this->user)->put(route('corex.rental-lease-templates.field-map.update', $row), ['map' => $map, 'month_to_month' => '1']);
        self::assertNull($row->fresh()->validation_problems);
    }

    public function test_a_suggested_field_is_offered_but_never_saved_without_pressing_save(): void
    {
        $template = $this->makeTemplate($this->agency->id, ['fields_json' => $this->fieldNames(['monthly_rental', 'lease_start'])]);
        $row = $this->row($template);

        $this->actingAs($this->user)->get(route('corex.rental-lease-templates.edit', $row))
            ->assertOk()
            ->assertSee('suggested')
            ->assertSee('<option value="monthly_rental" selected>monthly_rental</option>', false);

        self::assertNull($row->fresh()->field_map, 'opening the screen saves nothing');
    }

    public function test_the_field_map_and_edit_screens_of_another_agencys_row_are_a_404(): void
    {
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oth-' . uniqid()]);
        $theirs = $this->makeTemplate($other->id);
        $theirRow = RentalLeaseTemplate::withoutGlobalScopes()->create([
            'agency_id' => $other->id, 'name' => 'Theirs', 'docuperfect_template_id' => $theirs->id, 'category' => 'residential', 'is_active' => true,
        ]);

        $this->actingAs($this->user)->get(route('corex.rental-lease-templates.edit', $theirRow->id))->assertNotFound();
        $this->actingAs($this->user)->put(route('corex.rental-lease-templates.field-map.update', $theirRow->id), ['map' => []])->assertNotFound();
        $this->actingAs($this->user)->put(route('corex.rental-lease-templates.update', $theirRow->id), ['name' => 'x', 'category' => 'residential'])->assertNotFound();
    }

    public function test_a_row_whose_template_was_archived_since_shows_as_not_usable(): void
    {
        $template = $this->makeTemplate($this->agency->id);
        $this->row($template);
        $template->update(['archived_at' => now()]);

        $this->actingAs($this->user)->get(route('corex.rental-lease-templates.index'))
            ->assertOk()
            ->assertSee('Not usable: This template is archived.');
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

    private function makeTemplate(?int $agencyId, array $overrides = []): Template
    {
        $template = new Template(array_merge([
            'name' => 'Lease ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true,
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'],
        ], $overrides));
        $template->agency_id = $agencyId;   // outside mass-assignment, so an ownerless template stays ownerless
        $template->save();

        return $template->fresh();
    }

    private function row(Template $template): RentalLeaseTemplate
    {
        return RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Residential', 'docuperfect_template_id' => $template->id,
            'category' => 'residential', 'is_active' => true,
        ]);
    }

    /** @param array<int,string> $names */
    private function fieldNames(array $names): array
    {
        return array_map(fn ($n) => ['id' => 'f_' . $n, 'type' => 'placeholder', 'field_name' => $n], $names);
    }
}
