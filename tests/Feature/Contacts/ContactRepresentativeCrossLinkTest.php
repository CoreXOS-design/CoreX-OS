<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Entity <-> natural-person representative link, BIDIRECTIONAL surface
 * (.ai/specs/contact-entity-type.md §11 phase 2b, Johan's cross-link ask,
 * 2026-08-13). Guardrail under test throughout: ONE contact_representatives
 * pivot, surfaced from both the entity's "Representatives" panel and the
 * natural person's "Linked Entities" panel — a write from either side must
 * be visible from the other, never a parallel/duplicate relationship.
 */
final class ContactRepresentativeCrossLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_from_entity_side_is_visible_from_person_side(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Symmetric A Ltd', '2025/000001/07');
        $rep = $this->makeNaturalContact($agencyId, $user->id, 'Alpha', 'Director');

        $this->actingAs($user)->post(route('corex.contacts.representatives.link', $entity), [
            'representative_contact_id' => $rep->id,
        ])->assertRedirect();

        // Same pivot, opposite relation — must see it immediately.
        $this->assertTrue($rep->fresh()->representedEntities->contains('id', $entity->id));
    }

    public function test_link_from_person_side_is_visible_from_entity_side(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Symmetric B Ltd', '2025/000002/07');
        $rep = $this->makeNaturalContact($agencyId, $user->id, 'Beta', 'Director');

        // The person-side form POSTs to the SAME link() endpoint with the
        // entity as {contact} — no separate linking code path.
        $this->actingAs($user)->post(route('corex.contacts.representatives.link', $entity), [
            'representative_contact_id' => $rep->id,
        ])->assertRedirect();

        $this->assertTrue($entity->fresh()->representatives->contains('id', $rep->id));
        $this->assertTrue($rep->fresh()->representedEntities->contains('id', $entity->id));
    }

    public function test_unlink_from_either_side_removes_from_both(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Symmetric C Ltd', '2025/000003/07');
        $rep = $this->makeNaturalContact($agencyId, $user->id, 'Gamma', 'Director');
        $entity->representatives()->attach($rep->id);

        $this->actingAs($user)->delete(route('corex.contacts.representatives.unlink', [$entity, $rep]))
            ->assertRedirect();

        $this->assertFalse($entity->fresh()->representatives->contains('id', $rep->id));
        $this->assertFalse($rep->fresh()->representedEntities->contains('id', $entity->id));
    }

    public function test_create_and_link_representative_from_entity_side(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Onthefly Rep Ltd', '2025/000004/07');

        $resp = $this->actingAs($user)->post(route('corex.contacts.representatives.create-and-link', $entity), [
            'first_name' => 'Fresh',
            'last_name'  => 'Director',
            'phone'      => '0821119977',
            'is_primary' => '1',
        ]);
        $resp->assertRedirect();

        $created = Contact::withoutGlobalScopes()->where('first_name', 'Fresh')->where('last_name', 'Director')->first();
        $this->assertNotNull($created);
        $this->assertSame(Contact::TYPE_NATURAL_PERSON, $created->contact_kind);
        $this->assertTrue($entity->fresh()->representatives->contains('id', $created->id));
        $this->assertTrue($created->representedEntities->contains('id', $entity->id));
        $this->assertTrue($entity->representatives()->first()->pivot->is_primary);
    }

    public function test_create_and_link_representative_reuses_existing_match_no_duplicate(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $entity = $this->makeEntityContact($agencyId, $user->id, 'Dedup Rep Ltd', '2025/000005/07');
        $existing = $this->makeNaturalContact($agencyId, $user->id, 'Existing', 'Person', '0821119988');

        $this->actingAs($user)->post(route('corex.contacts.representatives.create-and-link', $entity), [
            'first_name' => 'Existing',
            'last_name'  => 'Person',
            'phone'      => '0821119988',
        ])->assertRedirect();

        $matches = Contact::withoutGlobalScopes()->where('first_name', 'Existing')->where('last_name', 'Person')->get();
        $this->assertCount(1, $matches, 'must reuse the existing contact, not create a duplicate');
        $this->assertTrue($entity->fresh()->representatives->contains('id', $existing->id));
    }

    public function test_create_and_link_entity_from_person_side(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $person = $this->makeNaturalContact($agencyId, $user->id, 'Delta', 'Director');

        $resp = $this->actingAs($user)->post(route('corex.contacts.representatives.create-and-link-entity', $person), [
            'entity_name'   => 'Onthefly Entity Ltd',
            'entity_reg_no' => '2025/000006/07',
        ]);
        $resp->assertRedirect();

        $entity = Contact::withoutGlobalScopes()->where('entity_name', 'Onthefly Entity Ltd')->first();
        $this->assertNotNull($entity);
        $this->assertSame(Contact::TYPE_ENTITY, $entity->contact_kind);
        // Symmetric: the entity's representatives list AND the person's
        // representedEntities list must both show this exact same link.
        $this->assertTrue($entity->representatives()->pluck('contacts.id')->contains($person->id));
        $this->assertTrue($person->fresh()->representedEntities->contains('id', $entity->id));
    }

    public function test_create_and_link_entity_reuses_existing_match_by_registration_number(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $person = $this->makeNaturalContact($agencyId, $user->id, 'Echo', 'Director');
        $existingEntity = $this->makeEntityContact($agencyId, $user->id, 'Dedup Entity Ltd', '2025/000007/07');

        $this->actingAs($user)->post(route('corex.contacts.representatives.create-and-link-entity', $person), [
            'entity_name'   => 'Different Name Typed In',
            'entity_reg_no' => '2025/000007/07',
        ])->assertRedirect();

        $matches = Contact::withoutGlobalScopes()->where('entity_reg_no', '2025/000007/07')->get();
        $this->assertCount(1, $matches, 'must reuse the existing entity by reg number, not create a duplicate');
        $this->assertTrue($person->fresh()->representedEntities->contains('id', $existingEntity->id));
    }

    public function test_search_entities_excludes_already_linked_and_only_returns_entities(): void
    {
        [$agencyId, $user] = $this->seedFixture();
        $person = $this->makeNaturalContact($agencyId, $user->id, 'Foxtrot', 'Director');
        $linkedEntity = $this->makeEntityContact($agencyId, $user->id, 'Already Linked Ltd', '2025/000008/07');
        $findableEntity = $this->makeEntityContact($agencyId, $user->id, 'Findable Corp Ltd', '2025/000009/07');
        $linkedEntity->representatives()->attach($person->id);

        $resp = $this->actingAs($user)->get(route('corex.contacts.representatives.search-entities', $person) . '?q=Findable');

        $resp->assertOk();
        $ids = collect($resp->json())->pluck('id')->all();
        $this->assertContains($findableEntity->id, $ids);
        $this->assertNotContains($linkedEntity->id, $ids);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function seedFixture(): array
    {
        $agencyId = $this->makeAgency();
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        return [$agencyId, $user];
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

    private function makeNaturalContact(int $agencyId, int $userId, string $first, string $last, ?string $phone = null): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $userId, 'agent_id' => $userId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => $first, 'last_name' => $last,
            'phone' => $phone ?? '08' . random_int(10000000, 99999999),
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
