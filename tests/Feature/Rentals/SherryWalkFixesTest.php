<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Mail\RentalApplicationApprovedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 13 Oct 2026 - cc5's full walk on QA1 for Sherry (a ONE-PERSON rental agency going live Friday). One test group per finding:
 *  A. the last signer is not told "everyone has signed" as if it were finished, and the lease says what it waits for;
 *  B. One step approval works for the agency's only approver, including their own application; no hidden second tick;
 *  C. a landlord linked from a property holds the Landlord type, with ONE word for it;
 *  D. the approval email says what is true (named property, FICA state) and is confirmed before it goes;
 *  E. the price typed for a rental is the rent.
 */
final class SherryWalkFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Bus::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
    }

    private function user(string $role = 'agent'): User
    {
        return User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => $role]);
    }

    private function setting(array $values): void
    {
        $row = RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->first()
            ?? new RentalApplicationQualifyingSetting(['agency_id' => $this->agency->id]);
        $row->fill($values)->save();
    }

    private function application(User $creator, string $status = 'under_assessment', array $extra = []): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'email' => 'thandi-' . uniqid() . '@example.test', 'agent_id' => $creator->id, 'created_by_user_id' => $creator->id,
        ]);

        return RentalApplication::create($extra + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $creator->id, 'status' => $status, 'full_name' => 'Thandi Nkosi',
            'email' => 'applicant-' . uniqid() . '@example.test', 'token' => bin2hex(random_bytes(16)),
            'token_expires_at' => now()->addDays(7), 'submitted_at' => now()->subDay(),
        ]);
    }

    // ═══ A. the last signer's page, and what the lease says it waits for ═══════════════════════════════════════

    public function test_the_last_signers_page_says_the_agent_approves_next_not_that_it_is_all_done(): void
    {
        $template = new class {
            public string $status = 'pending_agent_approval';
            public $document;
            public $creator;
            public function fresh() { return $this; }
        };
        $template->document = (object) ['name' => 'Lease agreement'];
        $template->creator = (object) ['agency' => (object) ['name' => 'Cape Rentals']];
        $signing = (object) ['template' => $template, 'signer_name' => 'Landlord Lee', 'completed_at' => now()];

        $held = view('docuperfect.signatures.external.completed', ['request' => $signing, 'fullyComplete' => true])->render();
        $this->assertStringContainsString('Everyone has signed.', $held);
        $this->assertStringContainsString('data-qa="signed-awaiting-approval"', $held);
        $this->assertStringContainsString('now approves it', $held);
        $this->assertStringNotContainsString('All parties have signed!', $held);

        $template->status = 'completed';
        $done = view('docuperfect.signatures.external.completed', ['request' => $signing, 'fullyComplete' => true])->render();
        $this->assertStringContainsString('All parties have signed!', $done, 'once the document is truly complete the old wording stands');
    }

    public function test_a_lease_waiting_for_the_agents_approval_does_not_say_it_is_out_for_signing(): void
    {
        $lease = new Lease(['signing_status' => Lease::SIGNING_AWAITING_AGENT_REVIEW]);
        $this->assertStringContainsString('Everyone has signed', $lease->lockedMessage());
        $this->assertStringNotContainsString('out for signing', $lease->lockedMessage());

        $out = new Lease(['signing_status' => Lease::SIGNING_OUT_FOR_SIGNING]);
        $this->assertSame(Lease::LOCKED_FOR_SIGNING_MESSAGE, $out->lockedMessage());
    }

    // ═══ B. One step for a one-person agency ═══════════════════════════════════════════════════════════════════

    public function test_the_only_approver_approves_their_own_application_in_one_step(): void
    {
        $sherry = $this->user('agent');
        $this->agency->update(['rental_application_ro_user_ids' => [$sherry->id]]);
        $this->setting(['approval_mode' => 'one_step']);
        $app = $this->application($sherry);

        $this->assertTrue($sherry->mayDecideOwnRentalApplication($this->agency->id));
        $this->actingAs($sherry)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 14500])->assertRedirect();
        $this->assertSame('approved', $app->fresh()->status);
    }

    public function test_with_another_approver_in_the_agency_or_on_two_step_the_independent_eyes_rule_stands_and_says_so(): void
    {
        $sherry = $this->user('agent');
        $other = $this->user('agent');
        $this->agency->update(['rental_application_ro_user_ids' => [$sherry->id, $other->id]]);
        $this->setting(['approval_mode' => 'one_step']);
        $app = $this->application($sherry);

        $this->assertFalse($sherry->mayDecideOwnRentalApplication($this->agency->id));
        $this->actingAs($sherry)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 14500])
            ->assertForbidden()->assertSee('another approver must decide it');
        $this->assertSame('under_assessment', $app->fresh()->status);

        // two step: even the only approver hands it on
        $this->agency->update(['rental_application_ro_user_ids' => [$sherry->id]]);
        $this->setting(['approval_mode' => 'two_step']);
        $this->assertFalse($sherry->fresh()->mayDecideOwnRentalApplication($this->agency->id));
    }

    public function test_an_override_user_and_an_administrator_still_decide_their_own(): void
    {
        $co = $this->user('agent');
        $this->agency->update(['rental_application_co_user_ids' => [$co->id], 'rental_application_ro_user_ids' => [$this->user()->id]]);
        $this->assertTrue($co->mayDecideOwnRentalApplication($this->agency->id));
        $this->assertTrue($this->user('admin')->mayDecideOwnRentalApplication($this->agency->id));
        $this->assertFalse($this->user('agent')->mayDecideOwnRentalApplication($this->agency->id), 'nobody else');
    }

    public function test_the_review_screen_gives_the_only_approver_one_action_and_everyone_else_a_reason(): void
    {
        $sherry = $this->user('agent');
        $this->agency->update(['rental_application_ro_user_ids' => [$sherry->id]]);
        $this->setting(['approval_mode' => 'one_step']);
        $app = $this->application($sherry);

        $this->actingAs($sherry)->get(route('corex.rental-applications.review', $app))->assertOk()
            ->assertSee('data-qa="approve-application-open"', false)->assertDontSee('data-qa="submit-for-approval"', false);

        // a second approver joins: the same screen now hands over - and says why
        $other = $this->user('agent');
        $this->agency->update(['rental_application_ro_user_ids' => [$sherry->id, $other->id]]);
        $this->actingAs($sherry)->get(route('corex.rental-applications.review', $app))->assertOk()
            ->assertSee('data-qa="submit-for-approval"', false)->assertSee('data-qa="one-step-note"', false)->assertSee('another approver must approve it');
        // Johan, 14 Oct 2026: ONE submit button on this screen (the header's), never a twin in the side column
        $html = $this->actingAs($sherry)->get(route('corex.rental-applications.review', $app))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'data-qa="submit-for-approval"'));
        $this->assertSame(1, substr_count($html, "'Submit to authoriser'"));

        // someone with no tick at all is told that, not silently handed on
        $plain = $this->user('agent');
        $theirs = $this->application($plain);
        $this->actingAs($plain)->get(route('corex.rental-applications.review', $theirs))->assertOk()
            ->assertSee('not ticked as a Reviewer or Override approver');
    }

    public function test_the_settings_page_says_what_the_two_ticks_mean_in_one_line_each(): void
    {
        $admin = $this->user('admin');
        $res = $this->actingAs($admin)->get(route('corex.settings.rental-applications.edit'))->assertOk();
        $res->assertSee('data-qa="ro-meaning"', false)->assertSee('data-qa="co-meaning"', false)
            ->assertSee('Reviewer: approves, declines or asks for more information')
            ->assertSee('Override: everything a Reviewer can, plus deciding an application they created');
    }

    public function test_a_403_with_a_reason_shows_the_reason_to_a_signed_in_user(): void
    {
        $plain = $this->user('agent');
        $app = $this->application($plain);
        // an agent with no tick opens the authoriser screen directly
        $this->actingAs($plain)->get(route('corex.rental-applications.authorisation.show', $app))->assertForbidden();
        $this->setting(['approval_mode' => 'one_step']);
        $this->agency->update(['rental_application_ro_user_ids' => [$plain->id, $this->user()->id]]);
        $this->actingAs($plain)->post(route('corex.rental-applications.authorisation.approve', $app), ['approved_rental_amount' => 9000])
            ->assertForbidden()->assertSee('data-qa="forbidden-reason"', false)->assertSee('You created this application');
    }

    // ═══ C. the landlord: one word, and the type is there ═════════════════════════════════════════════════════

    public function test_a_contact_linked_to_a_property_as_landlord_holds_the_landlord_type_and_nothing_is_removed(): void
    {
        $lessor = ContactType::query()->parents()->where('esign_role', 'lessor')->firstOrFail();
        $owner = ContactType::query()->parents()->where('name', 'Owner')->first();
        $agent = $this->user('admin');
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Olive', 'last_name' => 'Landlord',
            'phone' => '0825551234', 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]);
        if ($owner) {
            $contact->syncTypeAssignments([(int) $owner->id], []);
        }
        $property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id, 'title' => 'Flat 1', 'status' => 'draft', 'listing_type' => 'rental',
        ]);

        ContactPropertyLinker::link($contact->id, $property->id, 'landlord');

        $held = $contact->fresh()->parentTypes()->pluck('contact_types.id')->map(fn ($i) => (int) $i)->all();
        $this->assertContains((int) $lessor->id, $held);
        if ($owner) {
            $this->assertContains((int) $owner->id, $held, 'added, never replacing a type the contact already had');
        }

        // linking again changes nothing, and a tenant link adds no landlord type
        ContactPropertyLinker::link($contact->id, $property->id, 'landlord');
        $this->assertSame(count($held), $contact->fresh()->parentTypes()->count());
        $tenant = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tim', 'last_name' => 'Tenant', 'phone' => '0825550000', 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id]);
        ContactPropertyLinker::link($tenant->id, $property->id, 'tenant');
        $this->assertFalse($tenant->fresh()->parentTypes()->where('contact_types.id', $lessor->id)->exists());
    }

    public function test_the_picker_says_landlord_and_prefills_it_for_a_contact_linked_before_this_fix(): void
    {
        $lessor = ContactType::query()->parents()->where('esign_role', 'lessor')->firstOrFail();
        $this->assertSame('Landlord', ContactType::displayName('lessor', 'Lessor'));
        $this->assertSame('Seller', ContactType::displayName('seller', 'Seller'));

        $agent = $this->user('admin');
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Old', 'last_name' => 'Landlord',
            'phone' => '0825559999', 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]);
        $property = Property::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id, 'title' => 'Flat 2', 'status' => 'draft', 'listing_type' => 'rental']);
        // a link made before the fix: the pivot row only, no type
        DB::table('contact_property')->insert(['contact_id' => $contact->id, 'property_id' => $property->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(0, $contact->parentTypes()->count());

        $html = $this->actingAs($agent)->get(route('corex.contacts.show', $contact))->assertOk()->getContent();
        // Js::from() writes the picker's start state as JSON.parse('...') with \u0022 for the quotes
        $this->assertMatchesRegularExpression('/parentId(?:\\\\u0022|")\s*:\s*' . $lessor->id . '\b/', $html, 'Landlord is pre-selected so the first save works');
        $this->assertStringContainsString('Landlord', $html);
    }

    // ═══ D. the approval email ═════════════════════════════════════════════════════════════════════════════════

    private function namedProperty(User $agent): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $agent->id, 'title' => 'Sea Point Flat', 'status' => 'to_let',
            'listing_type' => 'rental', 'rental_amount' => 14500, 'beds' => 2, 'property_type' => 'Apartment / Flat', 'suburb' => 'Uvongo', 'town' => 'Margate',
        ]);
    }

    public function test_the_approval_for_a_named_property_is_about_that_property_and_the_lease_with_no_other_listings(): void
    {
        $agent = $this->user('admin');
        $property = $this->namedProperty($agent);
        $other = $this->namedProperty($agent);
        $app = $this->application($agent, 'approved', ['property_id' => $property->id, 'approved_rental_amount' => 14500]);

        $html = (new RentalApplicationApprovedMail($app->fresh(), collect([$other]), false, null))->render();

        $this->assertStringContainsString($property->addressFreeDescriptor(), $html);
        $this->assertStringContainsString('prepare the lease', $html);
        $this->assertStringNotContainsString('find the right property', $html);
        $this->assertStringNotContainsString('Properties that could work', $html);
        $this->assertStringNotContainsString('View all properties', $html);
    }

    public function test_an_application_with_no_property_keeps_the_matched_list(): void
    {
        $agent = $this->user('admin');
        $app = $this->application($agent, 'approved', ['approved_rental_amount' => 9000]);
        $html = (new RentalApplicationApprovedMail($app->fresh(), collect([$this->namedProperty($agent)]), false, null))->render();
        $this->assertStringContainsString('find the right property', $html);
        $this->assertStringContainsString('Properties that could work', $html);
    }

    public function test_fica_already_submitted_is_not_asked_for_again_and_fica_still_to_do_is(): void
    {
        $agent = $this->user('admin');
        $app = $this->application($agent, 'approved', ['approved_rental_amount' => 9000]);

        $submitted = (new RentalApplicationApprovedMail($app->fresh(), collect(), true, null, true))->render();
        $this->assertStringContainsString('verifying them', $submitted);
        $this->assertStringNotContainsString('Verify my identity now', $submitted);
        $this->assertStringNotContainsString('Almost there', $submitted);

        $todo = (new RentalApplicationApprovedMail($app->fresh(), collect(), true, 'https://example.test/fica/abc', false))->render();
        $this->assertStringContainsString('Verify my identity now', $todo);
        $this->assertStringContainsString('Almost there', $todo);

        $clean = (new RentalApplicationApprovedMail($app->fresh(), collect(), false, null))->render();
        $this->assertStringNotContainsString('FICA', $clean);
        $this->assertSame("You're approved, subject to FICA verification — Cape Rentals", (new RentalApplicationApprovedMail($app->fresh(), collect(), true, null, false))->envelope()->subject);
        $this->assertStringContainsString('checking your FICA documents', (new RentalApplicationApprovedMail($app->fresh(), collect(), true, null, true))->envelope()->subject);
    }

    public function test_sending_the_approval_asks_first_and_a_named_property_sends_no_other_listings(): void
    {
        $agent = $this->user('admin');
        $property = $this->namedProperty($agent);
        $this->namedProperty($agent); // an unrelated listing that must not be suggested
        $app = $this->application($agent, 'approved', ['property_id' => $property->id, 'approved_rental_amount' => 14500]);

        $page = $this->actingAs($agent)->get(route('corex.rental-applications.review', $app))->assertOk();
        $page->assertSee('data-confirm="Send the approval to', false)->assertSee('It cannot be unsent.')
            ->assertSee('goes to the applicant for this property')->assertDontSee('Add tenant wishlist');

        $this->actingAs($agent)->post(route('corex.rental-applications.review.send', $app))->assertSessionHas('success');
        Mail::assertQueued(RentalApplicationApprovedMail::class, fn ($m) => $m->properties->isEmpty());
    }

    // ═══ E. the price typed for a rental is the rent ═══════════════════════════════════════════════════════════

    private ?array $p24 = null;

    private function seedP24(): array
    {
        if ($this->p24 !== null) {
            return $this->p24;
        }
        $countryId = (int) DB::table('p24_countries')->insertGetId(['p24_id' => 1, 'name' => 'South Africa', 'created_at' => now(), 'updated_at' => now()]);
        $provinceId = (int) DB::table('p24_provinces')->insertGetId(['p24_id' => 101, 'p24_country_id' => $countryId, 'name' => 'KwaZulu-Natal', 'created_at' => now(), 'updated_at' => now()]);
        $cityId = (int) DB::table('p24_cities')->insertGetId(['p24_id' => 201, 'p24_province_id' => $provinceId, 'name' => 'Margate', 'created_at' => now(), 'updated_at' => now()]);
        $suburbId = (int) DB::table('p24_suburbs')->insertGetId(['p24_id' => 301, 'p24_city_id' => $cityId, 'name' => 'Uvongo', 'slug' => 'uvongo-' . Str::random(5), 'p24_verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        return $this->p24 = [$provinceId, $cityId, $suburbId];
    }

    private function store(User $agent, string $listingType, array $extra = []): Property
    {
        [$provinceId, $cityId, $suburbId] = $this->seedP24();
        $contact = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Olive', 'last_name' => 'Owner', 'phone' => '0825551111', 'created_by_user_id' => $agent->id, 'agent_id' => $agent->id]);
        $title = 'Walk flat ' . uniqid();
        $this->actingAs($agent)->post(route('corex.properties.store'), $extra + [
            'title' => $title, 'listing_type' => $listingType, 'price' => 14500, 'suburb' => 'Uvongo',
            'p24_province_id' => $provinceId, 'p24_city_id' => $cityId, 'p24_suburb_id' => $suburbId,
            'beds' => 2, 'baths' => 1, 'garages' => 0, 'agent_id' => $agent->id, 'pending_contact_ids' => [$contact->id],
        ])->assertSessionHasNoErrors();

        return Property::withoutGlobalScopes()->where('title', $title)->firstOrFail();
    }

    public function test_the_price_typed_for_a_new_rental_becomes_the_rent_and_the_header_shows_it(): void
    {
        $agent = $this->user('admin');
        $p = $this->store($agent, 'rental');

        $this->assertSame('rental', $p->listing_type);
        $this->assertEquals(14500.0, (float) $p->rental_amount, 'the one price field IS the rent');
        $this->assertEquals(0.0, (float) $p->price, 'no stray sale price on a rental');
        $this->assertSame('R 14 500', $p->formattedPrice());
        $this->assertTrue($p->contacts()->wherePivot('role', 'landlord')->exists(), 'the contact is linked as the landlord');
    }

    public function test_a_rent_typed_on_the_rental_field_wins_and_a_sale_keeps_its_price(): void
    {
        $agent = $this->user('admin');
        $rental = $this->store($agent, 'rental', ['rental_amount' => 12000]);
        $this->assertEquals(12000.0, (float) $rental->rental_amount);

        $sale = $this->store($agent, 'sale');
        $this->assertEquals(14500.0, (float) $sale->price);
        $this->assertEquals(0.0, (float) ($sale->rental_amount ?? 0));
    }

    public function test_the_new_property_page_changes_its_chip_and_price_label_when_for_rental_is_picked(): void
    {
        $agent = $this->user('admin');
        $html = $this->actingAs($agent)->get(route('corex.properties.create'))->assertOk()->getContent();
        $this->assertStringContainsString('data-listing-chip', $html);
        $this->assertStringContainsString('id="prop-price-label"', $html);
        $this->assertStringContainsString("sel.value === 'rental'", $html);
        $this->assertStringContainsString('Monthly rent (ZAR)', $html);
    }
}
