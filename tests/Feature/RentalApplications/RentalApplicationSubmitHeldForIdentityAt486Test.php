<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\OtpMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use App\Services\RentalApplications\AuthorisationHandOffGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-486 - an applicant stopped at the identity screen has not submitted as far as the agency is concerned.
 * Spec: .ai/specs/rental-applications.md (AT-486).
 */
final class RentalApplicationSubmitHeldForIdentityAt486Test extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'ta-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
        ]);
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['required_field_keys' => ['full_name', 'declaration_signature', 'tpn_consent_signature']],
        );
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'in_progress', 'email' => 'applicant@example.co.za',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function payload(): array
    {
        return ['full_name' => 'Sipho Ndlovu', 'declaration_signature' => self::SIGNATURE, 'tpn_consent_signature' => self::SIGNATURE];
    }

    public function test_pressing_submit_saves_the_answers_but_does_not_mark_the_application_received(): void
    {
        Mail::fake();
        $app = $this->application();

        $this->post(route('rental-applications.public.submit', $app->token), $this->payload())
            ->assertRedirect(route('rental-applications.public.identity-gate', $app->token));

        $app->refresh();
        $this->assertSame('in_progress', $app->status);
        $this->assertNull($app->submitted_at);
        $this->assertNotNull($app->identity_hold_at);
        $this->assertSame('Sipho Ndlovu', $app->full_name);
        $this->assertDatabaseMissing('rental_application_status_history', ['rental_application_id' => $app->id, 'to_status' => 'returned']);
    }

    public function test_confirming_the_code_is_what_submits_it(): void
    {
        Mail::fake();
        $app = $this->application();
        $this->post(route('rental-applications.public.submit', $app->token), $this->payload());
        $this->get(route('rental-applications.public.identity-gate', $app->token))->assertOk();
        $code = null;
        Mail::assertSent(OtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $this->post(route('rental-applications.public.verify-identity-gate', $app->token), ['otp_code' => $code]);

        $app->refresh();
        $this->assertSame('returned', $app->status);
        $this->assertNotNull($app->submitted_at);
        $this->assertNotNull($app->identity_verified_at);
        $this->assertDatabaseHas('rental_application_status_history', ['rental_application_id' => $app->id, 'to_status' => 'returned']);
    }

    public function test_a_wrong_code_leaves_it_unsubmitted(): void
    {
        Mail::fake();
        $app = $this->application();
        $this->post(route('rental-applications.public.submit', $app->token), $this->payload());
        $this->get(route('rental-applications.public.identity-gate', $app->token));

        $this->post(route('rental-applications.public.verify-identity-gate', $app->token), ['otp_code' => '000000']);

        $app->refresh();
        $this->assertNull($app->submitted_at);
        $this->assertSame('in_progress', $app->status);
    }

    public function test_the_gate_page_says_the_answers_are_saved_and_waiting_for_the_code(): void
    {
        Mail::fake();
        $app = $this->application();
        $this->post(route('rental-applications.public.submit', $app->token), $this->payload());

        $page = $this->get(route('rental-applications.public.identity-gate', $app->token));

        $page->assertOk();
        $page->assertSee('identity-gate-saved-note', false);
        $page->assertSee('Your answers are saved.', false);
        $page->assertDontSee('identity-gate-delivery-failed', false);
    }

    public function test_when_the_code_email_cannot_be_sent_the_page_says_so_and_offers_resend(): void
    {
        $app = $this->application();
        $this->post(route('rental-applications.public.submit', $app->token), $this->payload());
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('smtp down'));

        $page = $this->get(route('rental-applications.public.identity-gate', $app->token));

        $page->assertOk();
        $page->assertSee('identity-gate-delivery-failed', false);
        $page->assertSee("couldn't send the code", false);
        $page->assertSee('Resend code');
        $page->assertDontSee("We've sent a 6-digit code", false);
    }

    public function test_the_agent_cannot_approve_or_hand_off_an_application_still_waiting_on_identity(): void
    {
        $held = $this->application(['status' => 'returned', 'identity_hold_at' => now()]);
        $refusal = AuthorisationHandOffGate::refusal($held);
        $this->assertSame('identity_unverified', $refusal['reason'] ?? null);

        $held->forceFill(['identity_verified_at' => now()])->save();
        $this->assertNotSame('identity_unverified', AuthorisationHandOffGate::refusal($held->fresh())['reason'] ?? null);
    }

    public function test_a_stale_hold_never_blocks_when_the_agency_switched_the_gate_off(): void
    {
        RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->update(['identity_gate_enabled' => false]);
        $held = $this->application(['status' => 'returned', 'identity_hold_at' => now()]);

        $this->assertFalse($held->identityHoldPending());
    }

    public function test_the_agent_screen_says_the_applicant_has_not_confirmed_identity(): void
    {
        $held = $this->application(['identity_hold_at' => now()]);

        $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $held))
            ->assertOk()->assertSee('identity-hold-banner', false);
    }

    public function test_a_form_post_that_hits_the_submit_limiter_gets_a_proper_page_not_bare_json(): void
    {
        RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)->update(['submit_rate_limit_max' => 1]);
        $app = $this->application();
        $this->post(route('rental-applications.public.submit', $app->token), $this->payload());

        $second = $this->post(route('rental-applications.public.submit', $app->token), $this->payload());
        $second->assertStatus(429);
        $second->assertSee('You\'ve pressed Submit a lot in a short time', false);
        $this->assertStringContainsString('text/html', (string) $second->headers->get('Content-Type'));

        $json = $this->postJson(route('rental-applications.public.submit', $app->token), $this->payload());
        $json->assertStatus(429)->assertJsonStructure(['message']);
    }
}
