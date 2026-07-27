<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactIdentifierLabel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contact-details Phase 2 — settings-managed label list (admin CRUD) + the
 * real HTTP form submission proving an agent can assign a label from that
 * list to each of a contact's several phone numbers.
 */
final class ContactIdentifierLabelTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

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
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
        $this->actingAs($this->admin);
    }

    /** ContactType is a global fixed six-row table (AT-79) — store() requires
     *  at least one parent_type_ids match; this creates one of the six. */
    private function ownerContactTypeId(): int
    {
        return \App\Models\ContactType::create(['name' => 'Owner', 'esign_role' => null])->id;
    }

    /** An admin can add a new contact label from Settings. */
    public function test_admin_can_add_a_contact_label_from_settings(): void
    {
        $this->post(route('corex.settings.contact-identifier-labels.store'), [
            'name' => 'VIP',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contact_identifier_labels', [
            'agency_id' => $this->agencyId, 'name' => 'VIP',
        ]);
    }

    /** An admin can rename and delete a label; deleting just un-tags numbers/emails. */
    public function test_admin_can_update_and_delete_a_contact_label(): void
    {
        $label = ContactIdentifierLabel::create(['agency_id' => $this->agencyId, 'name' => 'Old Name']);
        $contact = Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'A', 'last_name' => 'B', 'phone' => '', 'email' => null]);
        app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true, 'label_id' => $label->id],
        ], []);

        $this->put(route('corex.settings.contact-identifier-labels.update', $label), [
            'name' => 'New Name',
        ])->assertSessionHasNoErrors();
        $this->assertSame('New Name', $label->fresh()->name);

        $this->delete(route('corex.settings.contact-identifier-labels.destroy', $label))
            ->assertSessionHasNoErrors();
        $this->assertSoftDeleted('contact_identifier_labels', ['id' => $label->id]);

        $phone = $contact->refresh()->phones()->first();
        $this->assertNull($phone->contact_identifier_label_id, 'the number itself survives; it just loses the tag');
        $this->assertSame('0821111111', $phone->phone);
    }

    /**
     * A label belonging to ANOTHER agency can never be assigned. The `exists:`
     * validation rule alone would NOT catch this (it's a raw table check, not
     * agency-scoped) — the real defense is ContactIdentifierService's
     * resolveIdentifierLabel(), scoped to the CONTACT's own agency_id. The
     * request still succeeds; the foreign id just silently resolves to "no
     * label" rather than being trusted.
     */
    public function test_a_label_from_another_agency_cannot_be_assigned(): void
    {
        $otherAgencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Other ' . Str::random(6), 'slug' => 'other-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // BelongsToAgency's creating() hook force-overrides agency_id to the
        // ACTING user's own agency for any non-owner auth'd user — that's a
        // model EVENT hook, not a query scope, so withoutGlobalScopes() alone
        // would NOT actually create this under the other agency (it would
        // silently land under $this->agencyId, making the test's premise
        // false). withoutEvents() is the real bypass — same idiom AgencyObserver
        // itself uses to seed a brand-new agency's rows.
        $foreignLabel = \Illuminate\Database\Eloquent\Model::withoutEvents(
            fn () => ContactIdentifierLabel::withoutGlobalScopes()->create(['agency_id' => $otherAgencyId, 'name' => 'Foreign'])
        );

        $this->post(route('corex.contacts.store'), [
            'first_name' => 'Cross',
            'last_name'  => 'Agency',
            'phones'     => [
                ['value' => '0825551111', 'is_primary' => true, 'label_id' => $foreignLabel->id],
            ],
            'parent_type_ids' => [$this->ownerContactTypeId()],
        ])->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Cross')->firstOrFail();
        $this->assertNull($contact->phones()->first()->contact_identifier_label_id, 'foreign-agency label silently NOT assigned');
    }

    /** THE PHASE 2 GOAL: a contact with 4 numbers can label each independently, via the real form. */
    public function test_a_contact_with_four_numbers_can_label_each_via_the_real_form(): void
    {
        $personal = ContactIdentifierLabel::create(['agency_id' => $this->agencyId, 'name' => 'Personal']);
        $business = ContactIdentifierLabel::create(['agency_id' => $this->agencyId, 'name' => 'Business']);
        $contactLabel = ContactIdentifierLabel::create(['agency_id' => $this->agencyId, 'name' => 'Contact']);

        $this->post(route('corex.contacts.store'), [
            'first_name' => 'Multi',
            'last_name'  => 'Number',
            'phones'     => [
                ['value' => '0821111111', 'is_primary' => true,  'label_id' => $personal->id],
                ['value' => '0822222222', 'is_primary' => false, 'label_id' => $business->id],
                ['value' => '0823333333', 'is_primary' => false, 'label_id' => $contactLabel->id],
                ['value' => '0824444444', 'is_primary' => false, 'label_id' => ''], // no label — allowed
            ],
            'parent_type_ids' => [$this->ownerContactTypeId()],
        ])->assertSessionHasNoErrors();

        $contact = Contact::withoutGlobalScopes()->where('first_name', 'Multi')->where('last_name', 'Number')->firstOrFail();
        $this->assertSame(4, $contact->phones()->count());

        $byPhone = $contact->phones()->get()->keyBy('phone');
        $this->assertSame($personal->id, $byPhone['0821111111']->contact_identifier_label_id);
        $this->assertSame('Personal', $byPhone['0821111111']->label);
        $this->assertSame($business->id, $byPhone['0822222222']->contact_identifier_label_id);
        $this->assertSame($contactLabel->id, $byPhone['0823333333']->contact_identifier_label_id);
        $this->assertNull($byPhone['0824444444']->contact_identifier_label_id);
    }

    /** A brand-new agency gets the Personal/Business/Contact defaults (AgencyObserver). */
    public function test_a_new_agency_is_seeded_with_default_labels(): void
    {
        $agency = \App\Models\Agency::create(['name' => 'Fresh ' . Str::random(6), 'slug' => 'fresh-' . Str::random(8)]);

        $names = ContactIdentifierLabel::withoutGlobalScopes()->where('agency_id', $agency->id)->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame(['Personal', 'Business', 'Contact'], $names);
    }
}
