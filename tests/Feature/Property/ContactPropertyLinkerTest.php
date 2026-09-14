<?php

declare(strict_types=1);

namespace Tests\Feature\Property;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan: "we have to fix it. corex is a no delete system." Full
 * investigation: .ai/specs/rental-applications.md, "The contact_property
 * hard-delete fix". BUILD_STANDARD.md §5a: any table combining a UNIQUE
 * constraint with SoftDeletes needs an explicit create -> soft-delete ->
 * recreate pass, not just a fresh-fixture happy path — that IS this file's
 * spine, not an afterthought bolted onto a CRUD test.
 */
final class ContactPropertyLinkerTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Contact $contact;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za', 'phone' => '0821234567',
        ]);
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'House in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
    }

    public function test_a_fresh_link_creates_one_row_and_is_visible_via_the_scoped_relation(): void
    {
        $result = ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');

        $this->assertTrue($result->isNew);
        $this->assertFalse($result->wasRestored);
        $this->assertFalse($result->roleChanged);
        $this->assertNull($result->previousRole);
        $this->assertTrue($this->contact->properties()->where('properties.id', $this->property->id)->exists());
        $this->assertDatabaseCount('contact_property', 1);
    }

    /**
     * THE §5a TEST. A naive firstOrNew()-then-save(), or a bare attach(),
     * would throw a raw duplicate-key exception here — the whole point of
     * this fix is that it does not.
     */
    public function test_create_then_soft_delete_then_recreate_restores_the_same_row_not_a_duplicate(): void
    {
        ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');
        $this->assertDatabaseCount('contact_property', 1);

        $removed = ContactPropertyLinker::unlink($this->contact->id, $this->property->id);
        $this->assertNotNull($removed);
        $this->assertSame('owner', $removed->role);
        $this->assertFalse($this->contact->properties()->where('properties.id', $this->property->id)->exists());
        // Still there, just soft-deleted — the whole point of the fix.
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertNotNull(\App\Models\ContactProperty::onlyTrashed()->first());

        // Re-link — must not throw a duplicate-key exception, must not
        // create a second row, must restore the same one.
        $result = ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');

        $this->assertFalse($result->isNew);
        $this->assertTrue($result->wasRestored);
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertTrue($this->contact->properties()->where('properties.id', $this->property->id)->exists());
        $this->assertSame($removed->id, $result->row->id);
    }

    public function test_relinking_in_a_different_role_restores_and_changes_the_role_not_a_second_row(): void
    {
        $first = ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');
        ContactPropertyLinker::unlink($this->contact->id, $this->property->id);

        $result = ContactPropertyLinker::link($this->contact->id, $this->property->id, 'tenant');

        $this->assertFalse($result->isNew);
        $this->assertTrue($result->wasRestored);
        $this->assertTrue($result->roleChanged);
        $this->assertSame('owner', $result->previousRole);
        $this->assertSame('tenant', $result->row->fresh()->role);
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertSame($first->row->id, $result->row->id);
    }

    public function test_linking_an_already_active_contact_with_a_different_role_changes_it_in_place(): void
    {
        ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');

        $result = ContactPropertyLinker::link($this->contact->id, $this->property->id, 'tenant');

        $this->assertFalse($result->isNew);
        $this->assertFalse($result->wasRestored);
        $this->assertTrue($result->roleChanged);
        $this->assertSame('owner', $result->previousRole);
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertSame('tenant', $this->contact->fresh()->properties()->first()->pivot->role);
    }

    public function test_linking_the_same_role_again_is_a_no_op_not_a_role_change(): void
    {
        ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');

        $result = ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');

        $this->assertFalse($result->isNew);
        $this->assertFalse($result->wasRestored);
        $this->assertFalse($result->roleChanged);
        $this->assertNull($result->previousRole);
    }

    public function test_unlink_soft_deletes_and_never_removes_the_contact_or_property(): void
    {
        ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');

        ContactPropertyLinker::unlink($this->contact->id, $this->property->id);

        $this->assertNotNull($this->contact->fresh());
        $this->assertNotNull($this->property->fresh());
        $this->assertNotNull(\App\Models\ContactProperty::withTrashed()
            ->where('contact_id', $this->contact->id)->where('property_id', $this->property->id)->first());
    }

    public function test_unlink_scoped_to_a_role_only_removes_that_role(): void
    {
        // One contact, one role at a time (Johan's rule) — but this proves
        // the role-scoped unlink used by the rental tenant-unlink path
        // does not remove a DIFFERENT role's link to the same property for
        // a DIFFERENT contact.
        $otherContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->contact->branch_id,
            'first_name' => 'Other', 'last_name' => 'Person', 'email' => 'other@example.co.za', 'phone' => '0827654321',
        ]);
        ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');
        ContactPropertyLinker::link($otherContact->id, $this->property->id, 'tenant');

        $removed = ContactPropertyLinker::unlink($this->contact->id, $this->property->id, 'tenant');

        $this->assertNull($removed, 'wrong role for this contact, must not remove the owner link');
        $this->assertTrue($this->contact->properties()->where('properties.id', $this->property->id)->exists());
        $this->assertTrue($otherContact->properties()->where('properties.id', $this->property->id)->exists());
    }

    public function test_withtrashed_relations_see_the_removed_link_the_scoped_ones_do_not(): void
    {
        ContactPropertyLinker::link($this->contact->id, $this->property->id, 'owner');
        ContactPropertyLinker::unlink($this->contact->id, $this->property->id);

        $this->assertFalse($this->contact->properties()->where('properties.id', $this->property->id)->exists());
        $this->assertTrue($this->contact->withTrashedProperties()->where('properties.id', $this->property->id)->exists());
        $this->assertFalse($this->property->contacts()->where('contacts.id', $this->contact->id)->exists());
        $this->assertTrue($this->property->withTrashedContacts()->where('contacts.id', $this->contact->id)->exists());
    }
}
