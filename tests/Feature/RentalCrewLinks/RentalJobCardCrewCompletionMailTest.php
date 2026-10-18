<?php

declare(strict_types=1);

namespace Tests\Feature\RentalCrewLinks;

use App\Mail\Rentals\RentalJobCardCrewCompletedLandlordMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\Contact;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Services\Property\ContactPropertyLinker;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §14.27.1 Q11 / §14.28 — the landlord email
 * when the crew marks the work completed: sent when the setting is on, none
 * when off, for BOTH routes (link and signed copy), through the dispatcher —
 * never a plain Mailable (Mail::fake() receives nothing).
 */
final class RentalJobCardCrewCompletionMailTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private object $fake;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Crew Mail');
        $this->card = $this->makeJobCard();

        $this->fake = new class extends RentalMailDispatcher {
            public array $sent = [];

            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                $this->sent[] = [$recipientEmail, $mail];
            }
        };
        $this->app->instance(RentalMailDispatcher::class, $this->fake);
        Mail::fake();

        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card, $this->admin);
        $this->base = '/secure/job-cards/' . $issued['raw_token'];
    }

    private function linkLandlord(?string $email = 'landlord@example.invalid'): Contact
    {
        $landlord = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Lenny', 'last_name' => 'Landlordson', 'email' => $email,
        ]);
        ContactPropertyLinker::link($landlord->id, $this->property->id, 'landlord');

        return $landlord;
    }

    private function crewCompletes(): void
    {
        $this->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1'])->assertSessionHasNoErrors();
    }

    public function test_the_landlord_is_emailed_via_the_dispatcher_when_the_setting_is_on(): void
    {
        $this->linkLandlord();

        $this->crewCompletes();

        $this->assertCount(1, $this->fake->sent);
        [$to, $mail] = $this->fake->sent[0];
        $this->assertSame('landlord@example.invalid', $to);
        $this->assertInstanceOf(RentalJobCardCrewCompletedLandlordMail::class, $mail);
        $this->assertSame($this->admin->id, $mail->sendingAgentId(), 'sent AS the property\'s responsible agent');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        $this->assertSame(1, $this->card->updates()->where('update_type', 'landlord_notified')->count());
    }

    public function test_the_email_says_what_happened_and_nothing_about_money(): void
    {
        $this->linkLandlord();
        $this->crewCompletes();

        $html = $this->fake->sent[0][1]->render();

        $this->assertStringContainsString('Fix the geyser', $html);
        $this->assertStringContainsString('Sipho Dlamini', $html);
        $this->assertStringContainsString('12 Crew Street', $html);
        $this->assertStringNotContainsString('450', $html);
        $this->assertStringNotContainsString('1,800', $html);
        $this->assertStringNotContainsString('Home Finders', $html);
    }

    public function test_no_email_when_the_setting_is_off(): void
    {
        $this->linkLandlord();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_crew_completion' => false]);

        $this->crewCompletes();

        $this->assertCount(0, $this->fake->sent);
        $this->assertNotNull($this->card->fresh()->worker_signed_off_at, 'the completion itself still records');
    }

    public function test_the_signed_copy_route_also_notifies_the_landlord(): void
    {
        $this->linkLandlord();

        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.signed-copy.store', $this->card), [
            'signed_copy' => UploadedFile::fake()->create('signed.pdf', 10, 'application/pdf'), 'signed_by_name' => 'Sipho Dlamini',
        ])->assertSessionHasNoErrors();

        $this->assertCount(1, $this->fake->sent);
        $this->assertInstanceOf(RentalJobCardCrewCompletedLandlordMail::class, $this->fake->sent[0][1]);
    }

    private function uploadSignedCopy(string $file = 'signed.pdf', string $name = 'Sipho Dlamini'): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.signed-copy.store', $this->card), [
            'signed_copy' => UploadedFile::fake()->create($file, 10, 'application/pdf'), 'signed_by_name' => $name,
        ])->assertSessionHasNoErrors();
    }

    private function crewCompletedLines(): int
    {
        return $this->card->updates()->where('update_type', 'crew_completed')->count();
    }

    // ── Once per job card: the FIRST crew completion by either route emails; nothing after does. ──

    public function test_a_corrected_signed_copy_re_upload_does_not_email_the_landlord_again(): void
    {
        $this->linkLandlord();

        $this->uploadSignedCopy('first.pdf');
        $this->uploadSignedCopy('corrected.pdf', 'Sipho D.');

        $this->assertCount(1, $this->fake->sent, 'one email for the first completion, none for the re-upload');
        $this->assertSame(2, $this->crewCompletedLines(), 'the re-upload is still audited as a crew completion');
        $this->assertSame('Sipho D.', $this->card->fresh()->worker_sign_off_name, 'the corrected copy still supersedes the sign-off details');
        $this->assertSame(2, \App\Models\RentalJobCardSignedCopy::query()->where('rental_job_card_id', $this->card->id)->count(), 'both files are kept');
        $this->assertSame(1, \App\Models\RentalJobCardSignedCopy::query()->where('rental_job_card_id', $this->card->id)->whereNotNull('superseded_at')->count());
        $this->assertStringContainsString('not emailed again', $this->card->updates()->where('update_type', 'landlord_notified')->latest('id')->first()->note);
    }

    public function test_a_signed_copy_after_the_crew_link_completion_does_not_email_again(): void
    {
        $this->linkLandlord();

        $this->crewCompletes();
        $this->uploadSignedCopy();

        $this->assertCount(1, $this->fake->sent);
        $this->assertSame(2, $this->crewCompletedLines());
    }

    public function test_a_crew_completion_recorded_after_a_signed_copy_does_not_email_again(): void
    {
        $this->linkLandlord();
        $this->uploadSignedCopy();
        $this->assertCount(1, $this->fake->sent);

        // The link route refuses a second completion outright (worker_signed_off_at is already set) ...
        $this->post("{$this->base}/complete", ['full_name' => 'Sipho Dlamini', 'confirm' => '1']);
        // ... and even a completion that does reach the event (any route, now or later) is a no-mail.
        \App\Events\Rentals\RentalJobCardCrewCompleted::dispatch($this->card->fresh(), 'Sipho Dlamini', RentalJobCard::SIGN_OFF_VIA_CREW_LINK);

        $this->assertCount(1, $this->fake->sent);
    }

    public function test_the_first_completion_decides_even_when_the_setting_was_off_then(): void
    {
        $this->linkLandlord();
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_crew_completion' => false]);
        $this->crewCompletes();

        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['notify_landlord_on_crew_completion' => true]);
        $this->uploadSignedCopy();

        $this->assertCount(0, $this->fake->sent, 'the landlord notice was decided at the first completion');
    }

    public function test_two_completion_events_for_one_card_claim_the_notice_exactly_once(): void
    {
        $this->linkLandlord();
        $card = $this->card->fresh();

        \App\Events\Rentals\RentalJobCardCrewCompleted::dispatch($card, 'Sipho Dlamini', RentalJobCard::SIGN_OFF_VIA_CREW_PAGE);
        \App\Events\Rentals\RentalJobCardCrewCompleted::dispatch($card, 'Sipho Dlamini', RentalJobCard::SIGN_OFF_VIA_SIGNED_COPY);

        $this->assertCount(1, $this->fake->sent);
        $this->assertNotNull($this->card->fresh()->landlord_crew_notice_at);
    }

    public function test_a_landlord_with_no_email_is_noted_not_a_failure(): void
    {
        $this->linkLandlord(null);

        $this->crewCompletes();

        $this->assertCount(0, $this->fake->sent);
        $this->assertStringContainsString('no landlord email', strtolower($this->card->updates()->where('update_type', 'landlord_notified')->first()->note));
    }

    public function test_no_landlord_linked_is_noted_not_a_failure(): void
    {
        $this->crewCompletes();

        $this->assertCount(0, $this->fake->sent);
        $this->assertSame(1, $this->card->updates()->where('update_type', 'landlord_notified')->count());
    }

    public function test_a_send_failure_never_breaks_the_crews_completion(): void
    {
        $this->linkLandlord();
        $this->app->instance(RentalMailDispatcher::class, new class extends RentalMailDispatcher {
            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->crewCompletes();

        $this->assertNotNull($this->card->fresh()->worker_signed_off_at);
        $this->assertStringContainsString('could not be sent', $this->card->updates()->where('update_type', 'landlord_notified')->first()->note);
    }
}
