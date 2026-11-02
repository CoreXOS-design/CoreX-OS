<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationDetailsUpdatedMail;
use App\Mail\RentalApplicationReopenedMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationFieldChange;
use App\Models\RentalApplicationGeneration;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-516 - an agent corrects what the applicant typed (change log + email, the signed submission untouched), and can send an
 * application back with the reason "Correct details" naming the fields. Spec: .ai/specs/rental-applications.md (AT-516).
 */
final class RentalApplicationCorrectDetailsAt516Test extends TestCase
{
    use RefreshDatabase;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Test Rentals', 'slug' => 'tr-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
            'name' => 'Anna Agent', 'cell' => '082 555 0100',
        ]);
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['identity_gate_enabled' => false]);
    }

    /** An application the applicant has really submitted (so generation 1 is sealed) and that sits with the agent as 'returned'. */
    private function submittedApplication(array $submit = []): RentalApplication
    {
        $application = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
        $this->publicSubmit($application, $submit);
        // The applicant's own "received" mail is not what these tests are about - start each one with a clean mail fake and a
        // submission that is clearly older than anything the agent does next.
        Mail::fake();
        $application->update(['submitted_at' => now()->subMinutes(5)]);

        return $application->refresh();
    }

    private function publicSubmit(RentalApplication $application, array $fields = []): void
    {
        $this->post(route('rental-applications.public.submit', $application->token), array_merge([
            'full_name' => 'Sipho Ndlovu', 'id_number' => '8501015800083',
            'email' => 'sipho@example.co.za', 'current_residential_address' => '1 Example Road, Ramsgate',
            'monthly_salary' => 20000, 'rental_term_months' => 12, 'employer_name' => 'Acme Pty Ltd',
            'declaration_signature' => self::SIG, 'tpn_consent_signature' => self::SIG,
        ], $fields))->assertRedirect();
    }

    private function correct(RentalApplication $application, array $fields, ?string $note = null)
    {
        return $this->actingAs($this->agent)->post(
            route('corex.rental-applications.review.details.update', $application),
            ['f' => $fields, 'note' => $note],
        );
    }

    public function test_a_correction_changes_the_live_value_and_logs_it_but_the_signed_submission_is_untouched(): void
    {
        $application = $this->submittedApplication();
        $sealedBefore = RentalApplicationGeneration::where('rental_application_id', $application->id)->firstOrFail();

        $this->correct($application, ['id_number' => '8001015800083', 'employer_name' => 'Acme Pty Ltd'], 'Mistyped; checked against the ID copy')
            ->assertRedirect(route('corex.rental-applications.review', $application));

        $application->refresh();
        $this->assertSame('8001015800083', $application->id_number, 'the current value is the corrected one');

        $changes = RentalApplicationFieldChange::where('rental_application_id', $application->id)->get();
        $this->assertCount(1, $changes, 'only the field that really changed is logged');
        $change = $changes->first();
        $this->assertSame('id_number', $change->field_key);
        $this->assertSame('8501015800083', $change->old_value);
        $this->assertSame('8001015800083', $change->new_value);
        $this->assertSame($this->agent->id, $change->changed_by_user_id);
        $this->assertSame('Mistyped; checked against the ID copy', $change->note);
        $this->assertSame(1, $change->generation);

        $sealedAfter = RentalApplicationGeneration::where('rental_application_id', $application->id)->firstOrFail();
        $this->assertSame($sealedBefore->snapshot_json, $sealedAfter->snapshot_json, 'the sealed submission is untouched');
        $this->assertSame('8501015800083', $sealedAfter->snapshot_json['id_number']);
        $this->assertSame($sealedBefore->content_hash, $sealedAfter->content_hash);
        $this->assertTrue(RentalApplicationGeneration::verifyChain($application->id)['ok']);
        $this->assertSame(1, RentalApplicationGeneration::where('rental_application_id', $application->id)->count(), 'no second snapshot is built');
    }

    public function test_the_applicant_is_emailed_one_line_per_corrected_field_and_the_email_is_logged(): void
    {
        $application = $this->submittedApplication();

        $this->correct($application, ['id_number' => '8001015800083', 'employer_name' => 'Beta CC']);

        Mail::assertQueued(RentalApplicationDetailsUpdatedMail::class, function (RentalApplicationDetailsUpdatedMail $mail) {
            $html = $mail->render();
            $this->assertTrue($mail->hasTo('sipho@example.co.za'));
            $this->assertStringContainsString('was updated by Test Rentals', $html);
            $text = html_entity_decode($html);
            // An ID number is masked: only the position that changed is shown (Johan's example), never the whole number.
            $this->assertMatchesRegularExpression('/ID number: \*5\*{11} (→|&rarr;) \*0\*{11}/u', $text);
            $this->assertStringNotContainsString('8501015800083', $text);
            $this->assertStringNotContainsString('8001015800083', $text);
            // A field that is not sensitive stays in clear.
            $this->assertMatchesRegularExpression('/Employer name: Acme Pty Ltd (→|&rarr;) Beta CC/u', $text);
            // The agent's cell is stored normalised (+27...), so match the shape: name, then a phone number.
            $this->assertMatchesRegularExpression('/If this is wrong, please contact Anna Agent, \+?[0-9 ]{9,}\./', $html);

            return true;
        });

        // The stored log keeps the full values (evidence); only the email / panel are masked.
        $stored = RentalApplicationFieldChange::where('rental_application_id', $application->id)->where('field_key', 'id_number')->sole();
        $this->assertSame('8501015800083', $stored->old_value);
        $this->assertSame('8001015800083', $stored->new_value);
        $this->assertSame(2, RentalApplicationFieldChange::where('rental_application_id', $application->id)->whereNotNull('emailed_at')->count());
        $this->assertDatabaseHas('rental_application_status_history', [
            'rental_application_id' => $application->id,
            'to_status' => 'returned',
        ]);
        $this->assertTrue($application->statusHistory()->get()->contains(fn ($h) => str_starts_with((string) $h->note, 'Emailed the applicant the corrected details')));
        $this->assertDatabaseHas('rental_application_audit_log', ['rental_application_id' => $application->id, 'event_type' => 'details_corrected_mail_sent']);
    }

    public function test_a_bank_account_of_a_different_length_shows_only_its_last_four_in_the_email_and_the_panel(): void
    {
        $application = $this->submittedApplication(['bank_account_no' => '6200000001']);

        $this->correct($application, ['bank_account_no' => '620000000099']);

        Mail::assertQueued(RentalApplicationDetailsUpdatedMail::class, function (RentalApplicationDetailsUpdatedMail $mail) {
            $text = html_entity_decode($mail->render());
            $this->assertMatchesRegularExpression('/Bank account number: \*{6}0001 (→|&rarr;) \*{8}0099/u', $text);
            $this->assertStringNotContainsString('6200000001', $text);
            $this->assertStringNotContainsString('620000000099', $text);

            return true;
        });

        // The Changes panel (not the rest of the screen, which shows the record's own current values) is masked.
        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $application))->assertOk()->getContent();
        $start = strpos($html, 'data-qa="field-changes-panel"');
        $this->assertNotFalse($start);
        $panel = substr($html, $start, strpos($html, '</ul>', $start) - $start);
        $this->assertStringContainsString('******0001', $panel);
        $this->assertStringContainsString('********0099', $panel);
        $this->assertStringNotContainsString('620000000099', $panel);
        $this->assertStringNotContainsString('6200000001', $panel);
        $this->assertSame('620000000099', $application->fresh()->bank_account_no, 'the record itself holds the full number');
    }

    public function test_nothing_is_changed_and_nothing_is_sent_when_no_value_differs(): void
    {
        $application = $this->submittedApplication();

        $this->correct($application, ['id_number' => '8501015800083', 'monthly_salary' => '20000.00']);

        $this->assertSame(0, RentalApplicationFieldChange::count());
        Mail::assertNothingQueued();
    }

    public function test_it_is_locked_while_the_application_is_with_the_authoriser_and_outside_returned_or_under_assessment(): void
    {
        $application = $this->submittedApplication();
        $application->update(['status' => 'under_assessment', 'submitted_for_approval_at' => now()]);

        $this->correct($application, ['id_number' => '8001015800083'])->assertSessionHas('error');
        $this->assertSame('8501015800083', $application->fresh()->id_number);
        $this->actingAs($this->agent)->get(route('corex.rental-applications.review.details.edit', $application))->assertRedirect(route('corex.rental-applications.review', $application));

        foreach (['approved', 'declined', 'withdrawn', 'reopened'] as $status) {
            $application->update(['status' => $status, 'submitted_for_approval_at' => null]);
            $this->correct($application, ['id_number' => '8001015800083'])->assertSessionHas('error');
            $this->assertSame('8501015800083', $application->fresh()->id_number, "no edit while {$status}");
        }
        $this->assertSame(0, RentalApplicationFieldChange::count());

        $application->update(['status' => 'under_assessment', 'submitted_for_approval_at' => null]);
        $this->correct($application, ['id_number' => '8001015800083']);
        $this->assertSame('8001015800083', $application->fresh()->id_number, 'under assessment with the agent is editable');
    }

    public function test_an_unacceptable_value_saves_nothing(): void
    {
        $application = $this->submittedApplication();

        $this->correct($application, ['id_number' => '8001015800083', 'email' => 'not-an-email'])->assertSessionHasErrors();

        $application->refresh();
        $this->assertSame('8501015800083', $application->id_number, 'the good value is not saved when another one is refused');
        $this->assertSame(0, RentalApplicationFieldChange::count());
        Mail::assertNothingQueued();
    }

    public function test_a_cell_inside_a_repeating_block_can_be_corrected_in_place(): void
    {
        $application = $this->submittedApplication([
            'co_occupants' => [['name' => 'Thandi', 'relationship' => 'Spouse', 'age' => '30']],
        ]);

        $this->correct($application, ['co_occupants.0.name' => 'Thandeka', 'co_occupants.0.age' => '30']);

        $this->assertSame('Thandeka', $application->fresh()->co_occupants[0]['name']);
        $change = RentalApplicationFieldChange::where('rental_application_id', $application->id)->sole();
        $this->assertSame('co_occupants.0.name', $change->field_key);
        $this->assertSame('Co-occupant 1 - name', $change->field_label);
        $this->assertSame('Thandi', $change->old_value);
    }

    public function test_a_field_that_is_not_on_the_application_is_refused(): void
    {
        $application = $this->submittedApplication();

        $this->correct($application, ['status' => 'approved'])->assertSessionHasErrors('fields');
        $this->assertSame('returned', $application->fresh()->status);
    }

    public function test_the_review_screen_shows_the_changes_panel_and_the_correct_details_page_renders(): void
    {
        $application = $this->submittedApplication();
        $this->correct($application, ['id_number' => '8001015800083']);

        $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $application))
            ->assertOk()
            ->assertSee('data-qa="field-changes-panel"', false)
            ->assertSee('ID number')
            ->assertSee('*5***********')
            ->assertSee('*0***********')
            ->assertDontSee('8501015800083')
            ->assertDontSee('8001015800083')
            ->assertSee('Anna Agent');

        $this->actingAs($this->agent)->get(route('corex.rental-applications.review.details.edit', $application))
            ->assertOk()
            ->assertSee('data-qa="edit-details-form"', false)
            ->assertSee('name="f[id_number]"', false);
    }

    public function test_send_back_with_correct_details_names_the_fields_everywhere_until_the_applicant_resubmits(): void
    {
        $application = $this->submittedApplication();

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.reopen', $application), [
            'note' => 'ID number looks wrong',
            'correction_fields' => ['id_number', 'employer_name'],
        ])->assertOk()->assertJson(['ok' => true]);

        $application->refresh();
        $this->assertSame('reopened', $application->status);
        $this->assertSame(['id_number', 'employer_name'], $application->correction_fields);
        $this->assertSame(['ID number', 'Employer name'], array_map(fn ($l) => $l, $application->correctionFieldLabels()));

        Mail::assertQueued(RentalApplicationReopenedMail::class, function (RentalApplicationReopenedMail $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('Please check and correct', $html);
            $this->assertStringContainsString('ID number', $html);
            $this->assertStringContainsString('Employer name', $html);

            return true;
        });

        // The applicant's link opens pre-filled, with the fields named and highlighted.
        $page = $this->get(route('rental-applications.public.show', $application->token))->assertOk();
        $page->assertSee('Please correct:')->assertSee('data-qa="correction-labels"', false)->assertSee('ID number looks wrong');
        $page->assertSee('value="8501015800083"', false);
        $page->assertSee('["id_number","employer_name"]', false);

        // Review screen and the list both say so.
        $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $application))
            ->assertOk()->assertSee('Sent back to correct:')->assertSee('ID number, Employer name');
        $this->actingAs($this->agent)->get(route('corex.rental-applications.index', ['tile' => 'reopened']))
            ->assertOk()->assertSee('data-qa="sent-back-to-correct"', false);

        // Resubmitting: the marker ends, a new generation is sealed, and the ORIGINAL signature survives.
        $original = RentalApplicationSignature::where('rental_application_id', $application->id)->where('generation', 1)->where('kind', 'declaration')->firstOrFail();
        $this->publicSubmit($application, ['id_number' => '8001015800083']);

        $application->refresh();
        $this->assertSame('returned', $application->status);
        $this->assertNull($application->correction_fields);
        $this->assertSame(2, $application->current_generation);
        $this->assertSame(2, RentalApplicationGeneration::where('rental_application_id', $application->id)->count());
        $this->assertSame(2, RentalApplicationSignature::where('rental_application_id', $application->id)->where('generation', 1)->count(), 'generation 1 signatures are still there');
        $this->assertSame($original->signature_path, $original->fresh()->signature_path);
        $this->assertTrue(RentalApplicationGeneration::verifyChain($application->id)['ok']);
    }

    public function test_a_send_back_without_ticked_fields_behaves_as_before_and_an_unknown_field_is_refused(): void
    {
        $application = $this->submittedApplication();

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.reopen', $application), [
            'note' => 'Wrong', 'correction_fields' => ['nonsense_field'],
        ])->assertStatus(422);
        $this->assertSame('returned', $application->fresh()->status);

        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.reopen', $application), ['note' => 'General fix'])->assertOk();
        $application->refresh();
        $this->assertSame('reopened', $application->status);
        $this->assertNull($application->correction_fields);
        $this->assertSame([], $application->correctionFieldLabels());
    }

    public function test_taking_the_application_back_from_the_applicant_ends_the_correction_marker(): void
    {
        $application = $this->submittedApplication();
        $this->actingAs($this->agent)->postJson(route('corex.rental-applications.review.reopen', $application), [
            'note' => 'Fix it', 'correction_fields' => ['id_number'],
        ])->assertOk();

        $this->actingAs($this->agent)->post(route('corex.rental-applications.cancel-reopen', $application), [])->assertRedirect();

        $application->refresh();
        $this->assertSame('returned', $application->status);
        $this->assertNull($application->correction_fields);
    }
}
