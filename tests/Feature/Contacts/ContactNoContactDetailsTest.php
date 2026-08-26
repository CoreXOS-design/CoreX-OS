<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactDeadEndFlag;
use App\Models\ContactType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "No contact details available" escape hatch on the Contact record edit
 * form — reuses the same ContactDeadEndFlag the MIC compose screen writes
 * (ComposeSellerService::markSellerDeadEnd/clearSellerDeadEnd), so a contact
 * flagged as a dead end in one place reads correctly in the other.
 */
final class ContactNoContactDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_without_tick_still_requires_a_phone_or_email(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $contact = $this->makeContactWithPhone($agencyId, $user->id);

        $resp = $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'first_name'      => $contact->first_name,
            'last_name'       => $contact->last_name,
            'phones'          => [],
            'emails'          => [],
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertSessionHasErrors('phones');
        $this->assertNull(ContactDeadEndFlag::withoutGlobalScopes()->where('contact_id', $contact->id)->first());
    }

    public function test_update_with_tick_bypasses_the_requirement_and_saves(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $contact = $this->makeContactWithPhone($agencyId, $user->id);

        $resp = $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'first_name'         => $contact->first_name,
            'last_name'          => $contact->last_name,
            'phones'             => [],
            'emails'             => [],
            'parent_type_ids'    => [$parentTypeId],
            'no_contact_details' => '1',
        ]);

        $resp->assertSessionHasNoErrors();
        $resp->assertRedirect();
    }

    public function test_update_with_tick_creates_a_dead_end_flag(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $contact = $this->makeContactWithPhone($agencyId, $user->id);

        $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'first_name'         => $contact->first_name,
            'last_name'          => $contact->last_name,
            'phones'             => [],
            'emails'             => [],
            'parent_type_ids'    => [$parentTypeId],
            'no_contact_details' => '1',
        ]);

        $flag = ContactDeadEndFlag::withoutGlobalScopes()->where('contact_id', $contact->id)->first();
        $this->assertNotNull($flag);
        $this->assertSame($agencyId, $flag->agency_id);
        $this->assertSame(ContactDeadEndFlag::REASON_NO_RECORD, $flag->reason);
        $this->assertSame('contact_record', $flag->source);
        $this->assertSame($user->id, $flag->created_by_user_id);
    }

    public function test_adding_a_phone_later_clears_the_dead_end_flag(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $contact = $this->makeContactWithPhone($agencyId, $user->id);

        ContactDeadEndFlag::create([
            'agency_id' => $agencyId,
            'contact_id' => $contact->id,
            'reason' => ContactDeadEndFlag::REASON_NO_RECORD,
            'source' => 'contact_record',
            'created_by_user_id' => $user->id,
        ]);
        $this->assertNotNull(ContactDeadEndFlag::withoutGlobalScopes()->where('contact_id', $contact->id)->first());

        $resp = $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'first_name'      => $contact->first_name,
            'last_name'       => $contact->last_name,
            'phones'          => [['value' => '0821239999', 'is_primary' => true]],
            'emails'          => [],
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertSessionHasNoErrors();
        $this->assertNull(ContactDeadEndFlag::withoutGlobalScopes()->where('contact_id', $contact->id)->first());
    }

    public function test_ticking_no_contact_details_while_also_providing_a_phone_does_not_flag_dead_end(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        $contact = $this->makeContactWithPhone($agencyId, $user->id);

        $resp = $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'first_name'         => $contact->first_name,
            'last_name'          => $contact->last_name,
            'phones'             => [['value' => '0821230011', 'is_primary' => true]],
            'emails'             => [],
            'parent_type_ids'    => [$parentTypeId],
            'no_contact_details' => '1',
        ]);

        $resp->assertSessionHasNoErrors();
        $this->assertNull(ContactDeadEndFlag::withoutGlobalScopes()->where('contact_id', $contact->id)->first());
    }

    public function test_editing_unrelated_field_does_not_disturb_existing_dead_end_flag(): void
    {
        [$agencyId, $user, $parentTypeId] = $this->seedFixture();
        // Deliberately WITHOUT a phone/email — a flag co-existing with real
        // identifiers is a contradictory state that can't occur via this same
        // reconciliation logic, so it isn't the scenario this test targets.
        $contact = $this->makeContactWithoutIdentifiers($agencyId, $user->id);

        ContactDeadEndFlag::create([
            'agency_id' => $agencyId,
            'contact_id' => $contact->id,
            'reason' => ContactDeadEndFlag::REASON_NO_RECORD,
            'source' => 'contact_record',
            'created_by_user_id' => $user->id,
        ]);

        $resp = $this->actingAs($user)->put(route('corex.contacts.update', $contact), [
            'first_name'      => $contact->first_name,
            'last_name'       => $contact->last_name,
            'notes'           => 'Unrelated edit, identifiers untouched.',
            'parent_type_ids' => [$parentTypeId],
        ]);

        $resp->assertSessionHasNoErrors();
        $this->assertNotNull(ContactDeadEndFlag::withoutGlobalScopes()->where('contact_id', $contact->id)->first());
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function seedFixture(): array
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
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);
        $parentType = ContactType::create(['name' => 'Owner', 'esign_role' => null]);

        return [$agencyId, $user, $parentType->id];
    }

    private function makeContactWithPhone(int $agencyId, int $userId): Contact
    {
        $contact = Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $userId, 'agent_id' => $userId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => 'Dead', 'last_name' => 'End',
            'phone' => '08' . random_int(10000000, 99999999),
        ]);
        DB::table('contact_phones')->insert([
            'agency_id' => $agencyId,
            'contact_id' => $contact->id,
            'phone' => $contact->phone,
            'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $contact;
    }

    private function makeContactWithoutIdentifiers(int $agencyId, int $userId): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'created_by_user_id' => $userId, 'agent_id' => $userId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => 'Dead', 'last_name' => 'End',
        ]);
    }
}
