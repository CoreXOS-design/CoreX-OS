<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * #17 — foreign-national contact identity: a non-SA person is captured with a passport number
 * + a directly-entered DOB (the passport doesn't encode it), instead of a 13-digit SA ID. The
 * SouthAfricanIdNumber validation is CONDITIONAL (SA path vs passport path); existing SA-ID
 * captures are unaffected (no id_type → SA path).
 */
final class ContactForeignNationalIdTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:int,1:User} */
    private function seedFixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $agent = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent']);

        return [$agencyId, $agent];
    }

    private function ownerContactTypeId(): int
    {
        return ContactType::create(['name' => 'Owner', 'esign_role' => null])->id;
    }

    /** Foreign national: passport + DOB saves with id_type=passport; the SA-ID checksum is NOT applied. */
    public function test_foreign_national_saves_passport_and_dob(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->post(route('corex.contacts.store'), [
            'contact_kind' => 'natural_person', 'first_name' => 'Hans', 'last_name' => 'Muller',
            'id_type' => 'passport', 'id_number' => 'X1234567', 'birthday' => '1980-05-15',
            'phones' => [['value' => '0821234567', 'is_primary' => true]],
            'parent_type_ids' => [$this->ownerContactTypeId()],
        ])->assertSessionHasNoErrors()->assertRedirect(route('corex.contacts.index'));

        $c = Contact::withoutGlobalScopes()->where('first_name', 'Hans')->firstOrFail();
        $this->assertSame('passport', $c->id_type);
        $this->assertSame('X1234567', $c->id_number);
        $this->assertSame('1980-05-15', $c->birthday->format('Y-m-d'));
    }

    /** A passport with NO date of birth is rejected (foreign nationals must enter DOB). */
    public function test_foreign_national_requires_dob(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->from(route('corex.contacts.index'))->post(route('corex.contacts.store'), [
            'contact_kind' => 'natural_person', 'first_name' => 'No', 'last_name' => 'Dob',
            'id_type' => 'passport', 'id_number' => 'P999',
            'phones' => [['value' => '0821234568', 'is_primary' => true]],
            'parent_type_ids' => [$this->ownerContactTypeId()],
        ])->assertSessionHasErrors('birthday');

        $this->assertDatabaseMissing('contacts', ['first_name' => 'No', 'last_name' => 'Dob']);
    }

    /** SA path still enforces the SA-ID checksum — an invalid 13-digit ID is rejected. */
    public function test_sa_person_invalid_id_rejected(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->from(route('corex.contacts.index'))->post(route('corex.contacts.store'), [
            'contact_kind' => 'natural_person', 'first_name' => 'Bad', 'last_name' => 'Said',
            'id_type' => 'sa_id', 'id_number' => '1234567890123',
            'phones' => [['value' => '0821234569', 'is_primary' => true]],
            'parent_type_ids' => [$this->ownerContactTypeId()],
        ])->assertSessionHasErrors('id_number');
    }

    /** Backward-compat: a valid SA ID with NO id_type posted still saves via the default SA path. */
    public function test_valid_sa_id_default_path_unaffected(): void
    {
        [, $agent] = $this->seedFixture();

        $this->actingAs($agent)->post(route('corex.contacts.store'), [
            'contact_kind' => 'natural_person', 'first_name' => 'Good', 'last_name' => 'Sa',
            'id_number' => '8001015009087',
            'phones' => [['value' => '0821234570', 'is_primary' => true]],
            'parent_type_ids' => [$this->ownerContactTypeId()],
        ])->assertSessionHasNoErrors()->assertRedirect(route('corex.contacts.index'));

        $c = Contact::withoutGlobalScopes()->where('first_name', 'Good')->firstOrFail();
        $this->assertSame('8001015009087', $c->id_number);
    }
}
