<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplicationCustomField;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md §7, piece (c)(1) — custom
 * fields, the definition side only. Full CRUD (create/update/archive/
 * restore/reorder), same shape and same test coverage class as
 * RentalApplicationHighlighterTest — the established pattern for this
 * class of agency-owned, archive-not-delete list in this module.
 */
final class RentalApplicationCustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
    }

    private function owner(): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    // ── generateKey() ───────────────────────────────────────────────────

    public function test_generate_key_slugifies_and_namespaces_the_label(): void
    {
        $key = RentalApplicationCustomField::generateKey($this->agency->id, 'Pet Deposit');

        $this->assertSame('custom_pet_deposit', $key);
    }

    public function test_generate_key_dedupes_against_every_key_this_agency_has_ever_used_including_retired(): void
    {
        RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_pet_deposit', 'label' => 'Pet Deposit',
            'field_type' => 'text', 'sort_order' => 0,
        ])->delete(); // retired

        $key = RentalApplicationCustomField::generateKey($this->agency->id, 'Pet Deposit');

        $this->assertSame('custom_pet_deposit_2', $key);
    }

    // ── store() ──────────────────────────────────────────────────────────

    public function test_store_creates_a_custom_field_with_a_generated_key(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.store'), [
            'label' => 'Pet Deposit', 'field_type' => 'text', 'help_text' => 'If applicable',
        ])->assertSessionDoesntHaveErrors();

        $field = RentalApplicationCustomField::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame('custom_pet_deposit', $field->key);
        $this->assertSame('Pet Deposit', $field->label);
        $this->assertTrue($field->shown, 'shown defaults true on creation');
        $this->assertFalse($field->required, 'required defaults false when unchecked');
        $this->assertSame($owner->id, $field->created_by);
    }

    public function test_store_a_choice_list_field_parses_comma_separated_options(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.store'), [
            'label' => 'Preferred Move-in Window', 'field_type' => 'choice_list',
            'options_text' => 'Morning, Afternoon,  Evening ',
        ])->assertSessionDoesntHaveErrors();

        $field = RentalApplicationCustomField::where('agency_id', $this->agency->id)->firstOrFail();
        $this->assertSame(['Morning', 'Afternoon', 'Evening'], $field->options);
    }

    public function test_store_a_choice_list_field_with_no_options_is_rejected(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.store'), [
            'label' => 'Broken', 'field_type' => 'choice_list', 'options_text' => '',
        ])->assertSessionHasErrors('options_text');

        $this->assertSame(0, RentalApplicationCustomField::where('agency_id', $this->agency->id)->count());
    }

    public function test_store_rejects_an_invalid_field_type(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.store'), [
            'label' => 'Bad', 'field_type' => 'not-a-real-type',
        ])->assertSessionHasErrors('field_type');
    }

    public function test_store_rejects_a_duplicate_label(): void
    {
        $owner = $this->owner();
        RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_pet_deposit', 'label' => 'Pet Deposit',
            'field_type' => 'text', 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.store'), [
            'label' => 'Pet Deposit', 'field_type' => 'number',
        ])->assertSessionHasErrors('label');

        $this->assertSame(1, RentalApplicationCustomField::where('agency_id', $this->agency->id)->count());
    }

    public function test_store_allows_a_label_matching_a_retired_field(): void
    {
        $owner = $this->owner();
        RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_pet_deposit', 'label' => 'Pet Deposit',
            'field_type' => 'text', 'sort_order' => 0,
        ])->delete();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.store'), [
            'label' => 'Pet Deposit', 'field_type' => 'number',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame(2, RentalApplicationCustomField::withTrashed()->where('agency_id', $this->agency->id)->count());
    }

    // ── update() ─────────────────────────────────────────────────────────

    public function test_update_can_hide_a_field_without_retiring_it(): void
    {
        $owner = $this->owner();
        $field = RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_pet_deposit', 'label' => 'Pet Deposit',
            'field_type' => 'text', 'shown' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->put(route('corex.settings.rental-applications.custom-fields.update', $field), [
            'label' => 'Pet Deposit', 'field_type' => 'text',
            // 'shown' omitted — an unchecked checkbox.
        ])->assertSessionDoesntHaveErrors();

        $field->refresh();
        $this->assertFalse($field->shown);
        $this->assertNull($field->deleted_at, 'hiding is not the same as retiring');
    }

    public function test_a_user_cannot_update_another_agencys_custom_field(): void
    {
        $owner = $this->owner();
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $foreign = RentalApplicationCustomField::create([
            'agency_id' => $otherAgency->id, 'key' => 'custom_not_yours', 'label' => 'Not Yours',
            'field_type' => 'text', 'sort_order' => 0,
        ]);

        $this->actingAs($owner)->put(route('corex.settings.rental-applications.custom-fields.update', $foreign), [
            'label' => 'Hijacked', 'field_type' => 'text',
        ])->assertNotFound();

        $this->assertSame('Not Yours', $foreign->fresh()->label);
    }

    // ── archive() / restore() — the historical-integrity rule itself ──────

    public function test_archiving_a_custom_field_never_touches_an_applications_already_captured_answer(): void
    {
        $owner = $this->owner();
        $contact = \App\Models\Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Sipho', 'last_name' => 'Ndlovu']);
        $field = RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_pet_deposit', 'label' => 'Pet Deposit',
            'field_type' => 'text', 'sort_order' => 0,
        ]);
        $application = \App\Models\RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'token' => \Illuminate\Support\Str::random(64),
            'custom_field_values' => [$field->key => 'Yes, one small dog'],
        ]);

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.archive', $field))
            ->assertSessionDoesntHaveErrors();

        $this->assertNotNull($field->fresh()->deleted_at);
        $this->assertSame('Yes, one small dog', $application->fresh()->custom_field_values[$field->key]);
    }

    public function test_restore_brings_a_retired_field_back(): void
    {
        $owner = $this->owner();
        $field = RentalApplicationCustomField::create([
            'agency_id' => $this->agency->id, 'key' => 'custom_pet_deposit', 'label' => 'Pet Deposit',
            'field_type' => 'text', 'sort_order' => 0,
        ]);
        $field->delete();

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.restore', $field->id))
            ->assertSessionDoesntHaveErrors();

        $this->assertNull($field->fresh()->deleted_at);
    }

    // ── reorder() ────────────────────────────────────────────────────────

    public function test_reorder_persists_the_new_sort_order(): void
    {
        $owner = $this->owner();
        $a = RentalApplicationCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_a', 'label' => 'A', 'field_type' => 'text', 'sort_order' => 0]);
        $b = RentalApplicationCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_b', 'label' => 'B', 'field_type' => 'text', 'sort_order' => 1]);

        $this->actingAs($owner)->post(route('corex.settings.rental-applications.custom-fields.reorder'), [
            'order' => [$b->id, $a->id],
        ])->assertSessionDoesntHaveErrors();

        $reloaded = RentalApplicationCustomField::where('agency_id', $this->agency->id)->orderBy('sort_order')->pluck('id')->all();
        $this->assertSame([$b->id, $a->id], $reloaded);
    }

    // ── allFor() / activeFor() ──────────────────────────────────────────

    public function test_active_for_excludes_retired_and_hidden_fields(): void
    {
        RentalApplicationCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_shown', 'label' => 'Shown', 'field_type' => 'text', 'shown' => true, 'sort_order' => 0]);
        RentalApplicationCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_hidden', 'label' => 'Hidden', 'field_type' => 'text', 'shown' => false, 'sort_order' => 1]);
        RentalApplicationCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_retired', 'label' => 'Retired', 'field_type' => 'text', 'shown' => true, 'sort_order' => 2])->delete();

        $active = RentalApplicationCustomField::activeFor($this->agency->id);

        $this->assertSame(['Shown'], $active->pluck('label')->all());
    }

    public function test_all_for_includes_retired_fields(): void
    {
        RentalApplicationCustomField::create(['agency_id' => $this->agency->id, 'key' => 'custom_retired', 'label' => 'Retired', 'field_type' => 'text', 'sort_order' => 0])->delete();

        $all = RentalApplicationCustomField::allFor($this->agency->id);

        $this->assertSame(['Retired'], $all->pluck('label')->all());
    }
}
