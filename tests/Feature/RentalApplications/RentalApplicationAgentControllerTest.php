<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use App\Mail\RentalApplicationInviteMail;

/**
 * AT-392 — agent-facing Rental Applications. Spec: .ai/specs/rental-applications.md
 *
 * Johan tested the applicant side himself and hit a blocker within minutes;
 * this build assumed the agent side was equally under-tested and rendered
 * every screen through the kernel as a real user to check. It was — these
 * tests lock in the four real bugs found and fixed that session:
 *
 * 1. searchProperties() returned every listing type, not just rentals.
 * 2. show.blade.php rendered only ~40% of the V8 field list, so an agent
 *    could not see or edit most of what an applicant would submit.
 * 3. There was no document-download route at all on the agent side.
 * 4. There was no destroy/archive route — a full-CRUD gap.
 *
 * There was zero test coverage for this controller before this file.
 */
final class RentalApplicationAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        // This worktree has no built frontend bundle (no npm install/build
        // run here) — irrelevant to what these tests assert (rendered HTML
        // text, DB state), so fake Vite rather than requiring an asset
        // build just to exercise this controller.
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        // Guards the RA-05 timing test above: if a Carbon::setTestNow()
        // call there is ever followed by a failed assertion, the reset at
        // the end of that test never runs — this backstop stops fake time
        // leaking into whichever test runs next in the same process.
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    private function rentalProperty(string $title = 'House to let in Ramsgate'): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => $title, 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
    }

    private function saleProperty(string $title = 'House for sale in Ramsgate'): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => $title, 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '2 Test Road',
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za', 'phone' => '0821234567',
        ]);
    }

    private function application(Contact $contact, array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
        ], $attrs));
    }

    // ── Bug 1: property search must only return rental listings ─────────

    public function test_search_properties_returns_only_rental_listings(): void
    {
        $this->rentalProperty('Cosy flat to let in Ramsgate');
        $this->saleProperty('Beach house for sale in Ramsgate');

        $response = $this->actingAs($this->agent)->getJson(route('corex.rental-applications.search-properties', ['q' => 'Ramsgate']));

        $response->assertOk();
        $labels = collect($response->json())->pluck('label');
        $this->assertTrue($labels->contains('Cosy flat to let in Ramsgate'));
        $this->assertFalse($labels->contains('Beach house for sale in Ramsgate'), 'A for-sale listing must never appear in the rental-application property picker.');
    }

    // ── Bug 2: the show/edit form must render every V8 field ────────────

    public function test_show_renders_every_v8_field_and_the_applicants_real_data(): void
    {
        $app = $this->application($this->contact(), [
            'current_residential_address' => '14 Beacon Road, Ramsgate',
            'emergency_contact_name' => 'S Ndlovu',
            'current_landlord_name' => 'Coastal Rentals CC',
            'current_rental_amount' => 8500,
            'employer_address' => '1 Main Road, Margate',
            'employer_tel' => '0399121111',
            'occupation_date' => '2026-10-01',
            'special_conditions' => 'Has one small dog',
            'adults' => 2,
            'children' => 1,
        ]);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        foreach ([
            'property_address_override', 'current_residential_address', 'emergency_contact_name',
            'emergency_contact_cell', 'emergency_contact_work', 'current_landlord_name', 'current_landlord_tel',
            'current_rental_amount', 'current_rental_from', 'current_rental_to', 'employer_address',
            'employer_tel', 'occupation_date', 'rental_term_months', 'special_conditions', 'adults', 'children',
        ] as $field) {
            $response->assertSee('name="' . $field . '"', false);
        }
        $response->assertSee('Coastal Rentals CC', false);
        $response->assertSee('Has one small dog', false);
    }

    public function test_update_saves_every_v8_field_including_the_previously_unrenderable_ones(): void
    {
        $app = $this->application($this->contact());

        $response = $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'current_residential_address' => '9 Beach Road',
            'emergency_contact_name' => 'Emergency Person',
            'current_landlord_name' => 'Old Landlord',
            'current_rental_amount' => 5000,
            'occupation_date' => '2026-11-01',
            'adults' => 3,
            'children' => 0,
        ]);

        $response->assertRedirect(route('corex.rental-applications.show', $app));
        $app->refresh();
        $this->assertSame('9 Beach Road', $app->current_residential_address);
        $this->assertSame('Emergency Person', $app->emergency_contact_name);
        $this->assertSame('Old Landlord', $app->current_landlord_name);
        $this->assertEquals(5000, $app->current_rental_amount);
        $this->assertSame(3, $app->adults);
    }

    // ── Bug 3: document download route + access control ─────────────────

    public function test_document_download_works_for_the_owning_agency(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact());
        Storage::disk('local')->put('rental-applications/' . $app->id . '/id.pdf', 'fake-pdf-bytes');
        $doc = Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'ID.pdf', 'storage_path' => 'rental-applications/' . $app->id . '/id.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 14,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.documents.download', [$app, $doc]));

        $response->assertOk();
        $response->assertHeader('Content-Disposition', 'attachment; filename=ID.pdf');
    }

    public function test_document_download_is_blocked_across_agencies(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact());
        Storage::disk('local')->put('rental-applications/' . $app->id . '/id.pdf', 'fake-pdf-bytes');
        $doc = Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'ID.pdf', 'storage_path' => 'rental-applications/' . $app->id . '/id.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 14,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);

        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $response = $this->actingAs($otherAdmin)->get(route('corex.rental-applications.documents.download', [$app, $doc]));

        $response->assertStatus(404);
    }

    // ── Bug 4: full CRUD — archive is a soft delete, never a hard delete ─

    public function test_destroy_soft_deletes_and_never_hard_deletes(): void
    {
        $app = $this->application($this->contact());

        $response = $this->actingAs($this->agent)->delete(route('corex.rental-applications.destroy', $app));

        $response->assertRedirect(route('corex.rental-applications.index'));
        $this->assertSoftDeleted('rental_applications', ['id' => $app->id]);
        $this->assertDatabaseHas('rental_applications', ['id' => $app->id]);
    }

    public function test_archived_application_disappears_from_the_index(): void
    {
        $app = $this->application($this->contact(), ['full_name' => 'Archived Applicant']);
        $app->delete();

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.index'));

        $response->assertOk();
        $response->assertDontSee('Archived Applicant');
    }

    // ── Cross-agency isolation, direct model-level guarantee ─────────────

    public function test_a_second_agencys_application_is_never_visible(): void
    {
        $app = $this->application($this->contact());

        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $this->actingAs($otherAdmin)->get(route('corex.rental-applications.show', $app))->assertStatus(404);
        $this->actingAs($otherAdmin)->get(route('corex.rental-applications.index'))
            ->assertDontSee(route('corex.rental-applications.show', $app), false);
    }

    // ── Bug 5: "moans no email correctly, but adding and saving do not
    // persist" (Johan, QA1) — the email genuinely persisted the whole time;
    // send()/sendInvite() just never read the field this screen lets the
    // agent edit. See RentalApplication::recipientEmail(). ─────────────────

    public function test_editing_the_applications_own_email_field_is_what_send_actually_uses(): void
    {
        Mail::fake();

        $contactWithNoEmail = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'No', 'last_name' => 'Email', 'email' => null,
        ]);
        $app = $this->application($contactWithNoEmail);

        // Send correctly refuses to mail and says so — the contact has no email.
        // (Superseded by the later "disable Send, never enabled-then-error"
        // fix — this is now a hard refusal, not a soft "share links instead"
        // success message.)
        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Add an email address'));
        Mail::assertNothingSent();

        // The agent types an email into the application's OWN field (the only
        // one this screen offers) and saves.
        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'email' => 'corrected@example.com',
        ])->assertRedirect(route('corex.rental-applications.show', $app));

        $app->refresh();
        $this->assertSame('corrected@example.com', $app->email, 'The email must actually persist — it always did; this asserts it still does.');

        // Superseded by the later "email should flow back to the contact"
        // fix (Round 3): a contact that had no email IS now backfilled from
        // this save — the assertion here used to check the opposite,
        // before that rule existed.
        $this->assertSame('corrected@example.com', $contactWithNoEmail->fresh()->email, 'The contact had no email — it must now be backfilled.');
        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Sent to corrected@example.com'));

        Mail::assertSent(RentalApplicationInviteMail::class, function ($mail) {
            return $mail->hasTo('corrected@example.com');
        });
    }

    // ── Regression: validation fails, user corrects, save persists — and
    // every OTHER field on the form survives too, not just the one being
    // fixed. Root cause: three <textarea> fields and one <select> read
    // straight from the DB value on redisplay instead of old(), so ANY
    // validation failure elsewhere on this one-big-form silently reverted
    // them (while old()-aware fields correctly kept what was typed). ──────

    public function test_every_field_on_the_form_survives_a_validation_failure_not_just_the_corrected_one(): void
    {
        $app = $this->application($this->contact(), [
            'full_name' => 'Original Name',
            'current_residential_address' => 'Original Address',
            'employer_address' => 'Original Employer Address',
            'special_conditions' => 'Original Conditions',
            'employment_type' => 'permanently_employed',
        ]);

        // Fill the whole form with new values, but deliberately break ONE
        // field (adults must be an integer) to force a validation failure.
        $response = $this->actingAs($this->agent)->from(route('corex.rental-applications.show', $app))
            ->put(route('corex.rental-applications.update', $app), [
                'full_name' => 'New Name',
                'email' => 'new@example.com',
                'current_residential_address' => 'New Address',
                'employer_address' => 'New Employer Address',
                'special_conditions' => 'New Conditions',
                'employment_type' => 'business_owner_personal_account',
                'adults' => 'not-a-number',
            ]);

        $response->assertRedirect(route('corex.rental-applications.show', $app));
        $response->assertSessionHasErrors('adults');

        // Nothing was written to the DB — this is an all-or-nothing form save.
        $app->refresh();
        $this->assertSame('Original Name', $app->full_name);
        $this->assertSame('Original Address', $app->current_residential_address);

        // Every field the agent typed — including the three raw <textarea>s
        // and the <select> that had no old() at all — must still show what
        // was typed on the redisplayed page, not the stale DB value.
        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertSee('New Name');
        $show->assertSee('new@example.com');
        $show->assertSee('New Address');
        $show->assertSee('New Employer Address');
        $show->assertSee('New Conditions');
        $show->assertSee('business_owner_personal_account', false);
        $show->assertDontSee('Original Address');
        $show->assertDontSee('Original Employer Address');
        $show->assertDontSee('Original Conditions');

        // The agent then fixes the one broken field and saves again —
        // everything, including the previously-reverting fields, must land.
        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'full_name' => 'New Name',
            'email' => 'new@example.com',
            'current_residential_address' => 'New Address',
            'employer_address' => 'New Employer Address',
            'special_conditions' => 'New Conditions',
            'employment_type' => 'business_owner_personal_account',
            'adults' => 3,
        ])->assertSessionDoesntHaveErrors();

        $app->refresh();
        $this->assertSame('New Name', $app->full_name);
        $this->assertSame('new@example.com', $app->email);
        $this->assertSame('New Address', $app->current_residential_address);
        $this->assertSame('New Employer Address', $app->employer_address);
        $this->assertSame('New Conditions', $app->special_conditions);
        $this->assertSame('business_owner_personal_account', $app->employment_type);
        $this->assertSame(3, $app->adults);
    }

    // ── Defect 1 — Johan, QA1: "have not even sent anything, yet top left
    // shows sent?" The column defaulted to 'sent' on insert; there was no
    // status value at all for "created, not yet sent." ───────────────────

    public function test_a_brand_new_application_is_draft_not_sent(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'No', 'last_name' => 'Email', 'email' => null,
        ]);

        $response = $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $contact->id,
        ]);

        $app = RentalApplication::where('contact_id', $contact->id)->latest('id')->first();
        $response->assertRedirect(route('corex.rental-applications.show', $app));

        $this->assertSame('draft', $app->status, 'A newly created application must never claim to be sent.');
        $this->assertNotNull($app->token, 'The token/link must exist from creation so it can be shared manually even before an email is added.');

        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertSee('draft');
        $show->assertDontSee('>sent<', false);
    }

    // ── Defect 2 — "before clicking send - no email in mailbox arrived?"
    // Confirmed: correct behaviour when nothing was ever sent. This locks
    // in the OTHER half — a genuine send attempt with no email on record
    // must be refused server-side (never trust the disabled button alone)
    // and must never claim the application was sent. ─────────────────────

    public function test_send_is_refused_server_side_without_a_saved_email(): void
    {
        Mail::fake();
        $noEmailContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'No', 'last_name' => 'Email', 'email' => null,
        ]);
        $app = $this->application($noEmailContact, ['status' => 'draft']);

        $response = $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app));

        $response->assertRedirect(route('corex.rental-applications.show', $app));
        $response->assertSessionHas('error');
        Mail::assertNothingSent();

        $app->refresh();
        $this->assertSame('draft', $app->status, 'A refused send must never flip status to sent.');
    }

    // ── Defect 3 — "i enter the email, hit send and it goes - no email
    // present and resets the form because of bad design." Structural fix:
    // Send requires an email already ON RECORD (Save must happen first),
    // so there is no path where clicking Send can discard an unsaved typed
    // value — proven end to end: add email, save, THEN send succeeds, and
    // status only becomes 'sent' once mail genuinely goes. ───────────────

    public function test_full_send_flow_only_marks_sent_after_mail_actually_leaves(): void
    {
        Mail::fake();
        $noEmailContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'No', 'last_name' => 'Email', 'email' => null,
        ]);
        $app = $this->application($noEmailContact, ['status' => 'draft']);

        // Send blocked before the email is saved.
        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))
            ->assertSessionHas('error');
        Mail::assertNothingSent();
        $this->assertSame('draft', $app->fresh()->status);

        // Save the email (the header's Save button, same update() route).
        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'email' => 'now-has-email@example.com',
        ])->assertRedirect(route('corex.rental-applications.show', $app));

        $this->assertSame('now-has-email@example.com', $app->fresh()->email);
        $this->assertSame('draft', $app->fresh()->status, 'Saving alone must never mark the application sent.');

        // Now Send succeeds.
        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))
            ->assertSessionHas('success', fn ($msg) => str_contains($msg, 'now-has-email@example.com'));

        Mail::assertSent(RentalApplicationInviteMail::class, fn ($mail) => $mail->hasTo('now-has-email@example.com'));
        $this->assertSame('sent', $app->fresh()->status);
    }

    // ── A resend of an application the applicant has already progressed
    // past 'sent' must not regress its status. ────────────────────────────

    public function test_resending_an_in_progress_application_does_not_regress_its_status(): void
    {
        Mail::fake();
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Has', 'last_name' => 'Email', 'email' => 'has-email@example.com',
        ]);
        $app = $this->application($contact, ['status' => 'in_progress', 'email' => 'has-email@example.com']);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))
            ->assertSessionHas('success');

        $this->assertSame('in_progress', $app->fresh()->status, 'Resending must not reset an application that has already progressed.');
    }

    // ── Bug 2 — Johan, QA1: "once we have an email for a contact we
    // update the contact." Fill-only: never overwrite a contact's
    // existing, different email from this screen. ─────────────────────────

    public function test_saving_an_email_backfills_a_contact_that_had_none(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'No', 'last_name' => 'Email', 'email' => null,
        ]);
        $app = $this->application($contact);

        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'email' => 'backfilled@example.com',
        ])->assertRedirect();

        $this->assertSame('backfilled@example.com', $contact->fresh()->email, 'A contact with no email must be backfilled from the application.');
    }

    public function test_saving_an_email_never_overwrites_a_contacts_existing_different_email(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Has', 'last_name' => 'Different', 'email' => 'real-crm-email@example.com',
        ]);
        $app = $this->application($contact);

        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'email' => 'typo-or-different@example.com',
        ])->assertRedirect();

        $this->assertSame('real-crm-email@example.com', $contact->fresh()->email, 'A contact already carrying an email must never be silently overwritten from a document-edit screen.');
        $this->assertSame('typo-or-different@example.com', $app->fresh()->email, 'The application itself still saves whatever was typed.');
    }

    public function test_email_backfill_never_crosses_agency_boundaries(): void
    {
        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherContact = Contact::create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id,
            'first_name' => 'Other', 'last_name' => 'Agency', 'email' => null,
        ]);
        $ownContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Own', 'last_name' => 'Agency', 'email' => null,
        ]);
        $app = $this->application($ownContact);

        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'email' => 'own-agency@example.com',
        ])->assertRedirect();

        $this->assertSame('own-agency@example.com', $ownContact->fresh()->email);
        $this->assertNull($otherContact->fresh()->email, 'The backfill must only ever reach the application\'s own contact, never any other agency\'s data.');
    }

    // ── Bug 1 — Johan, QA1: "list of rental applications piling up, yet
    // no way to remove / mark as sent / nothing here?" Full CRUD + list
    // standard: archive, restore, and a status filter on the main list. ───

    public function test_archiving_from_the_list_soft_deletes_and_it_is_findable_and_restorable(): void
    {
        $app = $this->application($this->contact());

        $this->actingAs($this->agent)->delete(route('corex.rental-applications.destroy', $app))
            ->assertRedirect(route('corex.rental-applications.index'));

        $this->assertNotNull($app->fresh()->deleted_at, 'Archive must be a soft delete.');
        // assertDatabaseHas()'s 3rd arg is a connection name, not a message
        // — the row existing at all (soft-deleted or not) IS the assertion.
        $this->assertDatabaseHas('rental_applications', ['id' => $app->id]);

        // Leaves the default view.
        $this->actingAs($this->agent)->get(route('corex.rental-applications.index'))
            ->assertDontSee(route('corex.rental-applications.show', $app), false);

        // Findable and restorable via the archived tab.
        $archivedView = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['archived' => 1]));
        $archivedView->assertOk();
        $archivedView->assertSee($app->contact->full_name);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.restore', $app->id))
            ->assertRedirect();

        $this->assertNull($app->fresh()->deleted_at, 'Restore must bring it back.');
        $this->actingAs($this->agent)->get(route('corex.rental-applications.index'))
            ->assertSee($app->contact->full_name);
    }

    public function test_the_status_filter_on_the_main_list_actually_filters(): void
    {
        $draftApp = $this->application($this->contact(), ['status' => 'draft']);
        $sentContact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sent', 'last_name' => 'One', 'email' => 'sent@example.com',
        ]);
        $sentApp = $this->application($sentContact, ['status' => 'sent']);

        $draftOnly = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['status' => 'draft']));
        $draftOnly->assertSee($draftApp->contact->full_name);
        $draftOnly->assertDontSee($sentApp->contact->full_name);

        $sentOnly = $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['status' => 'sent']));
        $sentOnly->assertSee($sentApp->contact->full_name);
        $sentOnly->assertDontSee($draftApp->contact->full_name);
    }

    // ── Round 4 (Johan, QA1): "on returned applications theres statuses at
    // the top, but theres no way to mark application status to what it is?"
    // ──────────────────────────────────────────────────────────────────────

    public function test_agent_can_set_an_agent_owned_status_once_the_application_has_been_returned(): void
    {
        $app = $this->application($this->contact(), ['status' => 'returned']);

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-applications.update-status', $app),
            ['status' => 'under_assessment', 'note' => 'Checking payslips.']
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame('under_assessment', $app->fresh()->status);
    }

    public function test_every_status_change_is_recorded_with_who_when_from_and_to(): void
    {
        // Deliberately not approved/declined: a cross-lane authoriser flow
        // is narrowing AGENT_SETTABLE_STATUSES to remove those two (agent
        // recommends, a designated authoriser decides) — this test is about
        // the generic recording mechanism, not about which specific status
        // is set, so it stays valid regardless of that narrowing.
        $app = $this->application($this->contact(), ['status' => 'returned']);

        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.update-status', $app),
            ['status' => 'withdrawn', 'note' => 'Applicant called to withdraw.']
        );

        $this->assertDatabaseHas('rental_application_status_history', [
            'rental_application_id' => $app->id,
            'agency_id' => $this->agency->id,
            'from_status' => 'returned',
            'to_status' => 'withdrawn',
            'changed_by_user_id' => $this->agent->id,
            'note' => 'Applicant called to withdraw.',
        ]);
    }

    public function test_a_system_owned_status_cannot_be_hand_set_even_by_a_crafted_request(): void
    {
        $app = $this->application($this->contact(), ['status' => 'returned']);

        foreach (['draft', 'sent', 'in_progress', 'returned'] as $fakedStatus) {
            $response = $this->actingAs($this->agent)->post(
                route('corex.rental-applications.update-status', $app),
                ['status' => $fakedStatus]
            );

            $response->assertSessionHasErrors('status');
        }

        $this->assertSame('returned', $app->fresh()->status, 'None of the system-owned statuses may be hand-set.');
        $this->assertSame(0, RentalApplicationStatusHistory::count(), 'A rejected status change must never be recorded as if it happened.');
    }

    public function test_status_cannot_be_set_on_an_application_that_has_not_been_returned_yet(): void
    {
        $app = $this->application($this->contact(), ['status' => 'sent']);

        $response = $this->actingAs($this->agent)->post(
            route('corex.rental-applications.update-status', $app),
            ['status' => 'under_assessment']
        );

        $response->assertSessionHas('error');
        $this->assertSame('sent', $app->fresh()->status, 'Nothing to assess before the applicant has actually submitted.');
        $this->assertSame(0, RentalApplicationStatusHistory::count());
    }

    public function test_resubmitting_the_same_status_is_a_no_op_and_does_not_duplicate_history(): void
    {
        $app = $this->application($this->contact(), ['status' => 'under_assessment']);

        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.update-status', $app),
            ['status' => 'under_assessment']
        );

        $this->assertSame('under_assessment', $app->fresh()->status);
        $this->assertSame(0, RentalApplicationStatusHistory::count(), 'Resubmitting the current value must not write a fake transition.');
    }

    public function test_status_change_respects_agency_scoping(): void
    {
        $app = $this->application($this->contact(), ['status' => 'returned']);

        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $response = $this->actingAs($otherAdmin)->post(
            route('corex.rental-applications.update-status', $app),
            ['status' => 'under_assessment']
        );

        $response->assertStatus(404);
        $this->assertSame('returned', $app->fresh()->status);
    }

    public function test_the_show_page_actually_renders_the_status_control_and_history_once_returned(): void
    {
        // Regression for the exact class of bug found earlier this round: a
        // real render, not just php -l, is the only thing that proves a
        // Blade change actually compiles and executes correctly.
        $app = $this->application($this->contact(), ['status' => 'returned']);
        RentalApplicationStatusHistory::record($app, 'in_progress', 'returned', $this->agent, null);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertSee('Application Status');
        $response->assertSee('name="status"', false);
        $response->assertSee('Under assessment');
    }

    public function test_the_show_page_hides_the_status_control_before_the_application_is_returned(): void
    {
        $app = $this->application($this->contact(), ['status' => 'sent']);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));

        $response->assertOk();
        $response->assertDontSee('Application Status');
    }

    public function test_the_returned_applications_list_actually_renders_the_inline_status_control(): void
    {
        $app = $this->application($this->contact(), ['status' => 'under_assessment']);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.returned'));

        $response->assertOk();
        $response->assertSee('name="status"', false);
        $response->assertSee(route('corex.rental-applications.update-status', $app), false);
    }

    public function test_status_change_never_hard_deletes_anything_and_stays_soft_deletable(): void
    {
        $app = $this->application($this->contact(), ['status' => 'returned']);

        $this->actingAs($this->agent)->post(
            route('corex.rental-applications.update-status', $app),
            ['status' => 'withdrawn']
        );

        $app->refresh();
        $this->assertSame('withdrawn', $app->status);
        $app->delete();
        $this->assertSoftDeleted('rental_applications', ['id' => $app->id]);
        $this->assertDatabaseHas('rental_application_status_history', ['rental_application_id' => $app->id]);
    }

    // ── Round 6 (Johan): "agent should in any case be able to add docs as
    // client can be in the office so agent scans docs to themselves, or
    // even receive via whatsapp etc." ────────────────────────────────────

    public function test_agent_can_upload_a_document_and_it_is_attributed_to_them(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact(), ['status' => 'returned']);

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.documents.upload', $app),
            ['supporting_files' => [UploadedFile::fake()->create('scanned-id.pdf', 100, 'application/pdf')]]
        );

        $response->assertOk();
        $response->assertJsonStructure(['documents' => [['id', 'name', 'view_url']]]);

        $doc = $app->documents()->first();
        $this->assertSame('scanned-id.pdf', $doc->original_name);
        $this->assertSame($this->agent->id, $doc->uploaded_by, 'An agent-added document must be attributed to the agent who added it.');
        $this->assertSame($this->agency->id, $doc->agency_id);
    }

    public function test_a_rejected_agent_upload_reports_why_and_never_attaches(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact());

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.documents.upload', $app),
            ['supporting_files' => [UploadedFile::fake()->create('not-a-doc.exe', 100, 'application/octet-stream')]]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['supporting_files.0']);
        $this->assertSame(0, $app->refresh()->documents()->count());
    }

    public function test_agent_upload_respects_agency_scoping(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact());

        $otherAgency = Agency::create(['name' => 'A Different Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'HQ']);
        $otherAdmin = User::factory()->create(['agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'role' => 'admin']);

        $response = $this->actingAs($otherAdmin)->postJson(
            route('corex.rental-applications.documents.upload', $app),
            ['supporting_files' => [UploadedFile::fake()->create('sneaky.pdf', 100, 'application/pdf')]]
        );

        $response->assertStatus(404);
        $this->assertSame(0, $app->refresh()->documents()->count());
    }

    public function test_the_detail_page_distinguishes_applicant_documents_from_agent_added_ones(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact(), ['status' => 'returned']);

        // Applicant-uploaded: uploaded_by is naturally null (no authenticated
        // user on that public, token-based path) — this is the existing,
        // already-correct signal; no new column needed.
        Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'from-applicant.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);

        $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.documents.upload', $app),
            ['supporting_files' => [UploadedFile::fake()->create('from-agent.pdf', 100, 'application/pdf')]]
        )->assertOk();

        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertOk();
        $show->assertSee('from-applicant.pdf');
        $show->assertSee('from applicant');
        $show->assertSee('from-agent.pdf');
        $show->assertSee('added by ' . $this->agent->name, false);
    }

    public function test_agent_added_documents_are_never_hard_deleted(): void
    {
        Storage::fake('local');
        $app = $this->application($this->contact());

        $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.documents.upload', $app),
            ['supporting_files' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')]]
        );
        $doc = $app->documents()->first();

        $doc->delete();
        $this->assertSoftDeleted('documents', ['id' => $doc->id]);
        $this->assertDatabaseHas('documents', ['id' => $doc->id]);
    }

    // ── RA-03 (cc5, independent testing): "A document the applicant adds
    // AFTER submission renders identically to one submitted originally —
    // no badge, no timestamp." Agreed with cc3: created_at >= submitted_at. ─

    public function test_the_detail_page_badges_a_document_added_after_submission(): void
    {
        $app = $this->application($this->contact(), ['status' => 'returned', 'submitted_at' => now()->subDay()]);

        // created_at is deliberately not mass-assignable on Document (no
        // legitimate request path ever needs to fake it) — set it via
        // direct property assignment after create(), which bypasses the
        // $fillable guard the way mass assignment never can.
        $original = Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'original-at-submit.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);
        $original->created_at = $app->submitted_at->copy()->subMinute();
        $original->save();

        $late = Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'added-later.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);
        $late->created_at = $app->submitted_at->copy()->addHour();
        $late->save();

        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertOk();
        $show->assertSee('Added after submission');

        // The original-at-submission document must NOT carry the server-
        // rendered badge — isolate the actual document-list markup (not
        // just "before the first <script> tag", which cuts off way too
        // early since the layout has its own <script> tags before this
        // section) and count only within it.
        preg_match('/<ul id="supportingDocumentsList".*?<\/ul>/s', $show->getContent(), $matches);
        $this->assertNotEmpty($matches, 'The document list markup must be present to check.');
        $this->assertSame(1, substr_count($matches[0], 'Added after submission'));
    }

    public function test_a_document_added_before_submission_is_never_badged(): void
    {
        $app = $this->application($this->contact(), ['status' => 'in_progress']);
        Document::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'original_name' => 'pre-submit.pdf', 'storage_path' => 'x', 'disk' => 'local',
            'mime_type' => 'application/pdf', 'size' => 10,
            'source_type' => 'rental_application', 'source_id' => $app->id,
        ]);

        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertOk();
        $show->assertDontSee('Added after submission');
    }

    // ── RA-05 (cc5, independent testing): "Tab 1 saves a field. Tab 2,
    // opened earlier and unaware, then saves a different field and
    // SILENTLY BLANKS Tab 1's genuine save." ──────────────────────────────

    public function test_two_tabs_second_stale_save_is_blocked_not_silently_overwritten(): void
    {
        // updated_at is second-precision (a plain timestamps() column) —
        // a real two-tab race always has human reaction time between the
        // two saves, but a fast test can genuinely execute both PUTs
        // within the same wall-clock second, which would falsely pass.
        // Carbon::setTestNow() controls time explicitly so this proves
        // the real mechanism, not an accident of test execution speed.
        \Illuminate\Support\Carbon::setTestNow(now());
        $app = $this->application($this->contact(), ['full_name' => 'Original Name']);

        // Both tabs load the page at the same moment — both capture the
        // SAME expected_updated_at.
        $openedAt = $app->updated_at->timestamp;

        // Tab 1 saves first, genuinely, several seconds later.
        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(5));
        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'expected_updated_at' => $openedAt,
            'full_name' => 'Saved By Tab One',
        ])->assertSessionDoesntHaveErrors();
        $this->assertSame('Saved By Tab One', $app->fresh()->full_name);

        // Tab 2, unaware, saves later still, using its STALE
        // expected_updated_at (captured before Tab 1's save) — must be
        // refused, not applied.
        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(10));
        $response = $this->actingAs($this->agent)->from(route('corex.rental-applications.show', $app))
            ->put(route('corex.rental-applications.update', $app), [
                'expected_updated_at' => $openedAt,
                'full_name' => 'Overwritten By Tab Two',
            ]);

        $response->assertRedirect(route('corex.rental-applications.show', $app));
        $response->assertSessionHas('error');
        $this->assertSame('Saved By Tab One', $app->fresh()->full_name, "Tab 1's genuine save must survive Tab 2's stale write.");
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_save_with_no_conflict_still_works_normally(): void
    {
        $app = $this->application($this->contact());
        $openedAt = $app->updated_at->timestamp;

        $this->actingAs($this->agent)->put(route('corex.rental-applications.update', $app), [
            'expected_updated_at' => $openedAt,
            'full_name' => 'No Conflict Here',
        ])->assertSessionDoesntHaveErrors();

        $this->assertSame('No Conflict Here', $app->fresh()->full_name);
    }

    public function test_the_blocked_tabs_typed_input_is_preserved_for_retry(): void
    {
        $app = $this->application($this->contact());
        $staleTimestamp = $app->updated_at->timestamp - 100; // simulate a genuinely older load

        $response = $this->actingAs($this->agent)->from(route('corex.rental-applications.show', $app))
            ->put(route('corex.rental-applications.update', $app), [
                'expected_updated_at' => $staleTimestamp,
                'full_name' => 'What I Actually Typed',
            ]);

        $response->assertSessionHas('error');
        $show = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app));
        $show->assertSee('What I Actually Typed', false);
    }
}
