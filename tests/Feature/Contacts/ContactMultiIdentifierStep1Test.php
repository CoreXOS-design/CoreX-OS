<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhone;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Contacts\ContactIdentifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-125 step 1 — child tables + mirror-sync foundation.
 *
 * Proves the load-bearing invariant: contacts.phone/email always mirrors the
 * primary child row, exactly one primary per kind, email-only contacts allowed.
 */
final class ContactMultiIdentifierStep1Test extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]));
    }

    private function contact(array $overrides = []): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $this->agencyId, 'first_name' => 'Bea', 'last_name' => 'Buyer',
            'phone' => '', 'email' => null,
        ], $overrides));
    }

    private function svc(): ContactIdentifierService
    {
        return app(ContactIdentifierService::class);
    }

    public function test_first_phone_becomes_primary_and_mirrors_to_contact(): void
    {
        $contact = $this->contact();
        $contact->phones()->create(['phone' => '082 123 4567']); // is_primary not set

        $contact->refresh();
        $this->assertSame('082 123 4567', $contact->phone, 'mirror = the primary raw value');
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count());

        $row = $contact->phones()->first();
        $this->assertTrue($row->is_primary);
        $this->assertSame('821234567', $row->phone_normalised, 'last-9 normalisation');
        $this->assertSame($this->agencyId, (int) $row->agency_id, 'agency auto-stamped');
    }

    public function test_adding_a_second_non_primary_phone_leaves_the_mirror_unchanged(): void
    {
        $contact = $this->contact();
        $contact->phones()->create(['phone' => '0821111111']);
        $contact->phones()->create(['phone' => '0822222222']); // non-primary

        $contact->refresh();
        $this->assertSame('0821111111', $contact->phone, 'mirror still the first (primary) phone');
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count(), 'still exactly one primary');
    }

    public function test_set_primary_flips_the_mirror_and_keeps_one_primary(): void
    {
        $contact = $this->contact();
        $contact->phones()->create(['phone' => '0821111111']);
        $second = $contact->phones()->create(['phone' => '0822222222']);

        $this->svc()->setPrimaryPhone($second);

        $contact->refresh();
        $this->assertSame('0822222222', $contact->phone, 'mirror now the newly-primary phone');
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count());
        $this->assertSame($second->id, $contact->phones()->where('is_primary', true)->first()->id);
    }

    public function test_deleting_the_primary_promotes_another_and_resyncs(): void
    {
        $contact = $this->contact();
        $first = $contact->phones()->create(['phone' => '0821111111']); // primary
        $contact->phones()->create(['phone' => '0822222222']);

        $first->delete(); // soft delete the primary

        $contact->refresh();
        $this->assertSame('0822222222', $contact->phone, 'another phone promoted + mirror re-synced');
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count());
        $this->assertSoftDeleted('contact_phones', ['id' => $first->id]);
    }

    public function test_deleting_the_last_phone_nulls_the_mirror(): void
    {
        $contact = $this->contact();
        $only = $contact->phones()->create(['phone' => '0821111111']);

        $only->delete();

        $contact->refresh();
        $this->assertNull($contact->phone, 'email-only allowed — mirror nulled when no phone remains');
        $this->assertSame(0, $contact->phones()->where('is_primary', true)->count());
    }

    public function test_email_only_contact_is_valid(): void
    {
        $contact = $this->contact(['phone' => null]);
        $contact->emails()->create(['email' => 'Bea@Example.COM']);

        $contact->refresh();
        $this->assertNull($contact->phone, 'no phone — contacts.phone nullable now');
        $this->assertSame('Bea@Example.COM', $contact->email, 'email mirror = primary raw value');
        $this->assertSame('bea@example.com', $contact->emails()->first()->email_normalised, 'lower(trim) key');
    }

    public function test_two_primaries_collapse_to_one(): void
    {
        $contact = $this->contact();
        // Force two primaries past the observer by inserting the second as primary.
        $contact->phones()->create(['phone' => '0821111111', 'is_primary' => true]);
        $contact->phones()->create(['phone' => '0822222222', 'is_primary' => true]);

        $contact->refresh();
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count(), 'reconcile keeps exactly one');
        $this->assertSame('0822222222', $contact->phone, 'the freshest primary wins');
    }

    public function test_agency_scope_isolates_child_rows(): void
    {
        $contact = $this->contact();
        $contact->phones()->create(['phone' => '0821111111']);

        // Switch to a different agency context → the row is invisible to the scope.
        $otherAgency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(5), 'slug' => 'other-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $otherAgency, 'agency_id' => $otherAgency, 'name' => 'D', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs(User::factory()->create(['agency_id' => $otherAgency, 'branch_id' => $otherAgency, 'role' => 'admin']));

        $this->assertSame(0, ContactPhone::count(), 'scoped query hides the other agency\'s phone');
        $this->assertSame(1, ContactPhone::withoutGlobalScope(AgencyScope::class)->count(), 'row still exists');
    }
}
