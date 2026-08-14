<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\ContactType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Entity-type foundation, UI/controller layer (.ai/specs/contact-entity-type.md
 * §11 phase 2) — the Contact create/edit screen threading: the type radio,
 * store()/update() handling an entity payload, dedup on entity_reg_no through
 * the real HTTP path, and the representative link/unlink/search endpoints.
 *
 * Foundation-layer concerns (migration/columns, ContactObserver mirror,
 * getFullNameAttribute()) are covered by ContactEntityTypeTest.php — this
 * file is the controller/route layer built on top of it.
 */
final class ContactEntityTypeUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_entity_contact_with_mirrored_names(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();

        $resp = $this->actingAs($user)->post(route('corex.contacts.store'), [
            'contact_kind'    => Contact::TYPE_ENTITY,
            'entity_name'     => 'Coastal Holdings (Pty) Ltd',
            'entity_reg_no'   => '2015/123456/07',
            'phones'          => [['value' => '0821230001', 'is_primary' => true]],
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertRedirect();
        $contact = Contact::withoutGlobalScopes()->where('entity_name', 'Coastal Holdings (Pty) Ltd')->first();
        $this->assertNotNull($contact);
        $this->assertSame(Contact::TYPE_ENTITY, $contact->contact_kind);
        $this->assertSame('2015/123456/07', $contact->entity_reg_no);
        $this->assertSame('Coastal Holdings (Pty) Ltd', $contact->first_name);
        $this->assertSame('', $contact->last_name);
    }

    public function test_store_still_creates_natural_person_contact_unaffected(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();

        $resp = $this->actingAs($user)->post(route('corex.contacts.store'), [
            'contact_kind'    => Contact::TYPE_NATURAL_PERSON,
            'first_name'      => 'Sam',
            'last_name'       => 'Seller',
            'phones'          => [['value' => '0821230002', 'is_primary' => true]],
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertRedirect();
        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Sam')->where('last_name', 'Seller')->first();
        $this->assertNotNull($contact);
        $this->assertSame(Contact::TYPE_NATURAL_PERSON, $contact->contact_kind);
    }

    public function test_store_rejects_missing_entity_name_when_kind_is_entity(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();

        $resp = $this->actingAs($user)->post(route('corex.contacts.store'), [
            'contact_kind'    => Contact::TYPE_ENTITY,
            'phones'          => [['value' => '0821230003', 'is_primary' => true]],
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertSessionHasErrors('entity_name');
    }

    public function test_store_dedups_entity_on_registration_number(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $this->makeEntityContact($agencyId, $user->id, 'Existing Ltd', '2020/999999/07');

        $resp = $this->actingAs($user)->post(route('corex.contacts.store'), [
            'contact_kind'    => Contact::TYPE_ENTITY,
            'entity_name'     => 'Duplicate Attempt Ltd',
            'entity_reg_no'   => '2020/999999/07',
            'phones'          => [['value' => '0821230004', 'is_primary' => true]],
            'parent_type_ids' => [$parentTypeId],
        ]);

        // soft_warn default mode -> redirect back with duplicate_detected, no create.
        $this->assertNull(Contact::withoutGlobalScopes()->where('entity_name', 'Duplicate Attempt Ltd')->first());
        $resp->assertSessionHas('duplicate_detected');
    }

    public function test_update_converts_natural_person_to_entity(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $contact = $this->makeNaturalContact($agencyId, $user->id, 'Convert', 'Me');

        $resp = $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'contact_kind'    => Contact::TYPE_ENTITY,
            'entity_name'     => 'Converted Ltd',
            'entity_reg_no'   => '2021/111222/07',
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertRedirect();
        $contact->refresh();
        $this->assertSame(Contact::TYPE_ENTITY, $contact->contact_kind);
        $this->assertSame('Converted Ltd', $contact->first_name);
        $this->assertSame('', $contact->last_name);
    }

    public function test_representative_link_unlink_and_relink_via_real_routes(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Rep Test Ltd', '2022/333444/07');
        $rep = $this->makeNaturalContact($agencyId, $user->id, 'John', 'Director');

        $linkResp = $this->actingAs($user)->post(route('corex.contacts.representatives.link', $entity), [
            'representative_contact_id' => $rep->id,
            'is_primary' => '1',
        ]);
        $linkResp->assertRedirect();
        $this->assertSame(1, $entity->representatives()->count());
        $this->assertTrue($entity->representatives()->first()->pivot->is_primary);

        $unlinkResp = $this->actingAs($user)->delete(route('corex.contacts.representatives.unlink', [$entity, $rep]));
        $unlinkResp->assertRedirect();
        $this->assertSame(0, $entity->representatives()->count());

        // The pivot row must be SOFT-deleted (Non-Negotiable #1), not gone —
        // and the table name must resolve correctly (regression guard: Pivot's
        // AsPivot::getTable() singularises by convention, which is wrong here).
        $pivot = ContactRepresentative::withTrashed()
            ->where('entity_contact_id', $entity->id)
            ->where('representative_contact_id', $rep->id)
            ->first();
        $this->assertNotNull($pivot, 'pivot row must still exist (soft-deleted), not be hard-deleted');
        $this->assertNotNull($pivot->deleted_at);

        // Re-linking the same pair after an unlink must not hit the unique
        // index on the still-present (soft-deleted) row.
        $relinkResp = $this->actingAs($user)->post(route('corex.contacts.representatives.link', $entity), [
            'representative_contact_id' => $rep->id,
        ]);
        $relinkResp->assertRedirect();
        $this->assertSame(1, $entity->representatives()->count());
    }

    public function test_representative_link_rejects_non_natural_person(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entityA = $this->makeEntityContact($agencyId, $user->id, 'Entity A', '2023/111111/07');
        $entityB = $this->makeEntityContact($agencyId, $user->id, 'Entity B', '2023/222222/07');

        $resp = $this->actingAs($user)->post(route('corex.contacts.representatives.link', $entityA), [
            'representative_contact_id' => $entityB->id,
        ]);

        $resp->assertStatus(422);
        $this->assertSame(0, $entityA->representatives()->count());
    }

    public function test_representative_search_excludes_already_linked_and_entities(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Search Test Ltd', '2024/555666/07');
        $linkedRep = $this->makeNaturalContact($agencyId, $user->id, 'Already', 'Linked');
        $findableRep = $this->makeNaturalContact($agencyId, $user->id, 'Findable', 'Person');
        $entity->representatives()->attach($linkedRep->id);

        $resp = $this->actingAs($user)->get(route('corex.contacts.representatives.search', $entity) . '?q=Findable');

        $resp->assertOk();
        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($findableRep->id, $ids);
        $this->assertNotContains($linkedRep->id, $ids);
        $this->assertNotContains($entity->id, $ids);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function seedFixture(): array
    {
        $agencyId = $this->makeAgency();
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $parentType = ContactType::create(['name' => 'Owner', 'esign_role' => null]);

        return [$agencyId, $user, $parentType->id];
    }

    private function makeAgency(): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $agencyId;
    }

    private function makeNaturalContact(int $agencyId, int $userId, string $first, string $last): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $userId, 'agent_id' => $userId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => $first, 'last_name' => $last,
            'phone' => '08' . random_int(10000000, 99999999),
        ]);
    }

    private function makeEntityContact(int $agencyId, int $userId, string $entityName, string $regNo): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $userId, 'agent_id' => $userId,
            'contact_kind' => Contact::TYPE_ENTITY,
            'entity_name' => $entityName, 'entity_reg_no' => $regNo,
            'first_name' => '', 'last_name' => '',
        ]);
    }
}
