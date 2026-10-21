<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-485 - one live application per applicant; the refusal is a visible warning with a link, the no-email reason is
 * always on screen, the create screen can capture a missing email, and a failed send says why.
 * Spec: .ai/specs/rental-applications.md (AT-485).
 */
final class RentalApplicationDuplicateGuardAt485Test extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'ta-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function contact(?string $email = 'dup@example.com'): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Dup', 'last_name' => 'Applicant', 'email' => $email,
        ]);
    }

    private function application(Contact $contact, string $status): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status,
        ]);
    }

    public function test_create_is_refused_when_the_contact_already_has_a_sent_application_and_links_to_it(): void
    {
        $contact = $this->contact();
        $existing = $this->application($contact, 'sent');

        $response = $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), ['contact_id' => $contact->id]);

        $response->assertSessionHasErrors(['contact_id' => 'This applicant has already been sent an application.']);
        $response->assertSessionHas('duplicate_application', fn ($d) => $d['id'] === $existing->id);
        $this->assertSame(1, RentalApplication::where('contact_id', $contact->id)->count());
    }

    public function test_create_is_refused_for_an_unsent_draft_with_its_own_wording(): void
    {
        $contact = $this->contact();
        $this->application($contact, 'draft');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), ['contact_id' => $contact->id])
            ->assertSessionHasErrors(['contact_id' => 'This applicant already has an application started that has not been sent yet.']);
        $this->assertSame(1, RentalApplication::where('contact_id', $contact->id)->count());
    }

    public function test_declined_and_withdrawn_applications_do_not_block_a_new_one(): void
    {
        $contact = $this->contact();
        $this->application($contact, 'declined');
        $this->application($contact, 'withdrawn');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), ['contact_id' => $contact->id])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, RentalApplication::where('contact_id', $contact->id)->count());
    }

    public function test_an_archived_application_does_not_block_a_new_one(): void
    {
        $contact = $this->contact();
        $this->application($contact, 'sent')->delete();

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), ['contact_id' => $contact->id])
            ->assertSessionHasNoErrors();
    }

    public function test_the_error_banner_on_the_create_screen_carries_the_link(): void
    {
        $contact = $this->contact();
        $existing = $this->application($contact, 'sent');

        $this->actingAs($this->agent)->from(route('corex.rental-applications.create'))
            ->post(route('corex.rental-applications.store'), ['contact_id' => $contact->id]);
        $page = $this->actingAs($this->agent)->get(route('corex.rental-applications.create'));

        $page->assertSee('This applicant has already been sent an application.');
        $page->assertSee(route('corex.rental-applications.show', $existing), false);
    }

    public function test_first_send_of_a_draft_is_refused_when_another_sent_application_exists(): void
    {
        Mail::fake();
        $contact = $this->contact();
        $this->application($contact, 'sent');
        $draft = $this->application($contact, 'draft');

        $response = $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $draft));

        $response->assertSessionHas('error', 'This applicant has already been sent an application.');
        $response->assertSessionHas('duplicate_application');
        Mail::assertNothingSent();
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_resending_the_same_application_and_sending_a_lone_draft_are_not_duplicates(): void
    {
        Mail::fake();
        $contact = $this->contact();
        $sent = $this->application($contact, 'sent');
        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $sent))->assertSessionHas('success');

        $other = $this->contact('other@example.com');
        $draft = $this->application($other, 'draft');
        $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $draft))->assertSessionHas('success');
        Mail::assertQueued(RentalApplicationInviteMail::class, 2);
    }

    public function test_an_email_typed_on_create_is_saved_to_a_contact_that_has_none(): void
    {
        $contact = $this->contact(null);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $contact->id, 'contact_email' => 'typed@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('typed@example.com', $contact->fresh()->email);
        $this->assertSame('typed@example.com', RentalApplication::where('contact_id', $contact->id)->first()->recipientEmail());
    }

    public function test_an_email_typed_on_create_never_overwrites_an_existing_contact_email(): void
    {
        $contact = $this->contact('keep@example.com');

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $contact->id, 'contact_email' => 'other@example.com',
        ])->assertSessionHasNoErrors();

        $this->assertSame('keep@example.com', $contact->fresh()->email);
    }

    public function test_a_failed_send_says_why_and_is_not_marked_sent(): void
    {
        $contact = $this->contact();
        $app = $this->application($contact, 'draft');
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Connection refused by smtp.example.test'));

        $response = $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app));

        $response->assertSessionHas('error', fn ($m) => str_contains($m, 'Connection refused by smtp.example.test') && str_contains($m, 'dup@example.com'));
        $this->assertSame('draft', $app->fresh()->status);
    }

    public function test_the_no_email_reason_is_not_hidden_on_small_screens(): void
    {
        $app = $this->application($this->contact(null), 'draft');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->getContent();

        $this->assertMatchesRegularExpression('/<span class="text-xs"[^>]*data-qa="send-disabled-reason"/', $html);
        $this->assertStringNotContainsString('hidden sm:inline', $html);
    }
}
