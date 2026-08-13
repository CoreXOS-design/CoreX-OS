<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use App\Services\ContactDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact enhancement foundation (.ai/specs/contact-entity-type.md) — Johan's
 * decided minimal model: a Contact is natural_person OR entity, an entity
 * links to its natural-person representative(s) many-to-many (a director can
 * sit on multiple entities; an entity can have multiple representatives).
 *
 * Five concerns proven here: the migration/columns, the representatives
 * link, the first_name/last_name NOT-NULL mirror, dedup on entity_reg_no,
 * and the getFullNameAttribute() display fix.
 */
final class ContactEntityTypeTest extends TestCase
{
    use RefreshDatabase;

    // ── Columns + default ─────────────────────────────────────────────────

    public function test_existing_contacts_default_to_natural_person(): void
    {
        $agencyId = $this->makeAgency();
        $contact = $this->makeNaturalContact($agencyId, 'Sam', 'Seller');

        $this->assertSame(Contact::TYPE_NATURAL_PERSON, $contact->fresh()->type);
        $this->assertFalse($contact->isEntity());
    }

    public function test_entity_contact_carries_entity_name_and_reg_no(): void
    {
        $agencyId = $this->makeAgency();
        $entity = $this->makeEntityContact($agencyId, 'Coastal Holdings (Pty) Ltd', '2015/123456/07');

        $entity->refresh();
        $this->assertTrue($entity->isEntity());
        $this->assertSame('Coastal Holdings (Pty) Ltd', $entity->entity_name);
        $this->assertSame('2015/123456/07', $entity->entity_reg_no);
    }

    // ── The link: many-to-many, one director on multiple entities ────────

    public function test_one_director_can_represent_multiple_entities(): void
    {
        $agencyId = $this->makeAgency();
        $director = $this->makeNaturalContact($agencyId, 'John', 'Director');
        $entityA = $this->makeEntityContact($agencyId, 'Alpha Trust', 'IT1234/2020');
        $entityB = $this->makeEntityContact($agencyId, 'Beta CC', 'CK2010/056789/23');

        $entityA->representatives()->attach($director->id, ['is_primary' => true]);
        $entityB->representatives()->attach($director->id, ['is_primary' => true]);

        $this->assertCount(2, $director->fresh()->representedEntities);
        $this->assertTrue($entityA->fresh()->representatives->contains($director->id));
        $this->assertTrue($entityB->fresh()->representatives->contains($director->id));
        $this->assertTrue($entityA->fresh()->representatives->first()->pivot->is_primary);
    }

    public function test_entity_can_have_multiple_representatives(): void
    {
        $agencyId = $this->makeAgency();
        $entity = $this->makeEntityContact($agencyId, 'Multi Director Ltd', '2018/999999/07');
        $repA = $this->makeNaturalContact($agencyId, 'Rep', 'One');
        $repB = $this->makeNaturalContact($agencyId, 'Rep', 'Two');

        $entity->representatives()->attach([
            $repA->id => ['is_primary' => true],
            $repB->id => ['is_primary' => false],
        ]);

        $this->assertCount(2, $entity->fresh()->representatives);
    }

    public function test_entity_with_no_representative_yet_is_valid_the_scraper_case(): void
    {
        $agencyId = $this->makeAgency();
        $entity = $this->makeEntityContact($agencyId, 'Scraped Owner CC', 'CK2005/012345/23');

        $this->assertCount(0, $entity->fresh()->representatives);
        $this->assertTrue($entity->exists);
    }

    // ── first_name/last_name NOT-NULL mirror ──────────────────────────────

    public function test_observer_mirrors_entity_name_into_first_last_name(): void
    {
        $agencyId = $this->makeAgency();
        $entity = $this->makeEntityContact($agencyId, 'Mirror Test (Pty) Ltd', '2020/000111/07');

        $entity->refresh();
        $this->assertSame('Mirror Test (Pty) Ltd', $entity->first_name);
        $this->assertSame('', $entity->last_name);
    }

    public function test_observer_remirrors_on_entity_name_change(): void
    {
        $agencyId = $this->makeAgency();
        $entity = $this->makeEntityContact($agencyId, 'Original Name Ltd', '2020/000222/07');

        $entity->update(['entity_name' => 'Renamed Ltd']);

        $entity->refresh();
        $this->assertSame('Renamed Ltd', $entity->first_name);
        $this->assertSame('', $entity->last_name);
    }

    // ── Display fix ────────────────────────────────────────────────────────

    public function test_full_name_for_entity_returns_entity_name_no_trailing_space(): void
    {
        $agencyId = $this->makeAgency();
        $entity = $this->makeEntityContact($agencyId, 'Clean Display Ltd', '2020/000333/07');

        $this->assertSame('Clean Display Ltd', $entity->fresh()->full_name);
    }

    public function test_full_name_for_natural_person_unchanged(): void
    {
        $agencyId = $this->makeAgency();
        $contact = $this->makeNaturalContact($agencyId, 'Sam', 'Seller');

        $this->assertSame('Sam Seller', $contact->fresh()->full_name);
    }

    // ── Dedup: entity keys on reg number, natural person on ID number ────

    public function test_duplicate_service_finds_entity_by_reg_number(): void
    {
        $agencyId = $this->makeAgency();
        $existing = $this->makeEntityContact($agencyId, 'Dedup Target Ltd', '2020/000444/07');

        $service = app(ContactDuplicateService::class);
        $dupes = $service->findDuplicates([
            'entity_reg_no' => '2020/000444/07',
        ], $agencyId);

        $this->assertTrue($dupes->pluck('id')->contains($existing->id));
    }

    public function test_duplicate_service_ignores_entity_reg_no_for_natural_person_payload(): void
    {
        $agencyId = $this->makeAgency();
        $this->makeEntityContact($agencyId, 'Unrelated Ltd', '2020/000555/07');
        $natural = $this->makeNaturalContact($agencyId, 'Jane', 'Buyer', '0837778899', null, '8001015800083');

        $service = app(ContactDuplicateService::class);
        $dupes = $service->findDuplicates([
            'id_number' => '8001015800083',
        ], $agencyId);

        $this->assertTrue($dupes->pluck('id')->contains($natural->id));
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

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

    private function makeUser(int $agencyId): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);
    }

    private function makeNaturalContact(int $agencyId, string $first, string $last, ?string $phone = null, ?string $email = null, ?string $idNumber = null): Contact
    {
        $creator = $this->makeUser($agencyId);

        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $creator->id,
            'agent_id'  => $creator->id,
            'type' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => $first,
            'last_name' => $last,
            'phone' => $phone ?? '08' . random_int(10000000, 99999999),
            'email' => $email,
            'id_number' => $idNumber,
        ]);
    }

    private function makeEntityContact(int $agencyId, string $entityName, string $regNo): Contact
    {
        $creator = $this->makeUser($agencyId);

        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $creator->id,
            'agent_id'  => $creator->id,
            'type' => Contact::TYPE_ENTITY,
            'entity_name' => $entityName,
            'entity_reg_no' => $regNo,
            // NOT NULL columns — the observer mirror is what's under test in
            // some cases, but Eloquent requires SOME value present before
            // the observer runs on the very first insert attempt; passing
            // empty strings here proves the observer OVERWRITES them, not
            // that it merely leaves a pre-supplied value alone.
            'first_name' => '',
            'last_name' => '',
        ]);
    }
}
