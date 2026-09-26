<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationDecisionMail;
use App\Mail\RentalApplicationDeclineMail;
use App\Mail\RentalApplicationMoreInfoRequestMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * QA2 audit follow-up to f03d6238c — that commit dropped each mailable's own
 * `public $queue = 'mail';` (a same-class redeclaration of Queueable's own
 * $queue property, which PHP fatals on at class-load time, not just
 * construction) in favour of `$this->onQueue('mail')`. The fix itself was
 * correct and `php -l` was clean, but nothing asserted these mailables can
 * actually be built and rendered — a regression of this exact conflict would
 * not have been caught by CI. RentalApplicationApprovedMail already has its
 * own dedicated test file (address-privacy); the other 3 fixed here did not.
 */
final class RentalApplicationMailablesQueueableConflictTest extends TestCase
{
    use RefreshDatabase;

    private function makeApplication(): RentalApplication
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Test',
            'email' => 'contact-' . uniqid() . '@example.test',
        ]);

        return RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $agent->id,
            'status' => 'under_assessment', 'current_generation' => 1,
            'token' => 'test-token-' . uniqid(),
        ]);
    }

    public function test_decision_mail_builds_and_queues_on_the_mail_queue(): void
    {
        Mail::fake();
        $application = $this->makeApplication();

        $mail = new RentalApplicationDecisionMail($application, 'declined', 'Income requirements not met');

        $this->assertSame('mail', $mail->queue);
        Mail::to('applicant@example.test')->send($mail);
        Mail::assertQueued(RentalApplicationDecisionMail::class, fn ($m) => str_contains($m->render(), 'Income requirements not met'));
    }

    public function test_decline_mail_builds_and_queues_on_the_mail_queue(): void
    {
        Mail::fake();
        $application = $this->makeApplication();

        $mail = new RentalApplicationDeclineMail($application, 'Your application', 'Thank you for applying.');

        $this->assertSame('mail', $mail->queue);
        Mail::to('applicant@example.test')->send($mail);
        Mail::assertQueued(RentalApplicationDeclineMail::class, fn ($m) => str_contains($m->render(), 'Thank you for applying.'));
    }

    public function test_more_info_request_mail_builds_and_queues_on_the_mail_queue(): void
    {
        Mail::fake();
        $application = $this->makeApplication();

        $mail = new RentalApplicationMoreInfoRequestMail($application, 'Please attach your latest payslip.');

        $this->assertSame('mail', $mail->queue);
        Mail::to('applicant@example.test')->send($mail);
        Mail::assertQueued(RentalApplicationMoreInfoRequestMail::class, fn ($m) => str_contains($m->render(), 'Please attach your latest payslip.'));
    }
}
