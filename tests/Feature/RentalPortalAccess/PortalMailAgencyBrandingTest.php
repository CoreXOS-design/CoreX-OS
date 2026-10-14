<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\ClientAuthOtpMail;
use App\Mail\Rentals\RentalPortalInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Standing rule: agency mail goes out as the agency, never as CoreX. The tenant / owner PORTAL sign-in mail - the first-time code,
 * the password-reset code - and the portal link mails are checked here by rendering them (all mail is faked, nothing is sent).
 * Same bug-class as the rental application's verification-code email (OtpMail), which RentalsGoLiveWalkFixesTest pins.
 */
final class PortalMailAgencyBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = ['Origin' => 'http://localhost'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
    }

    private function agency(string $name, array $extra = []): Agency
    {
        $a = Agency::create(['name' => $name, 'slug' => str()->slug($name) . '-' . uniqid()]);
        $a->forceFill($extra + [])->save();
        Branch::create(['agency_id' => $a->id, 'name' => 'Main']);

        return $a->fresh();
    }

    private function contactAt(Agency $agency, string $email, ?int $clientUserId = null): Contact
    {
        $branch = Branch::where('agency_id', $agency->id)->first();

        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Thandi', 'last_name' => 'Ngcobo',
            'email' => $email, 'client_user_id' => $clientUserId,
        ]);
    }

    /** One request as the portal page makes it (same-origin, nobody signed in on the guard in memory). */
    private function browser(): static
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->withHeaders(self::BROWSER);
    }

    private function sentCode(): ClientAuthOtpMail
    {
        $sent = Mail::sent(ClientAuthOtpMail::class);
        $this->assertCount(1, $sent, 'exactly one code mail');

        return $sent->first();
    }

    private function assertTheAgenciesOwnMail(ClientAuthOtpMail $mail, Agency $agency): void
    {
        $html = $mail->render();
        $env = $mail->envelope();

        $this->assertStringContainsString($agency->name, $html);
        $this->assertStringContainsString((string) $agency->address, $html);
        $this->assertStringContainsString((string) $agency->phone, $html);
        $this->assertStringContainsString((string) $agency->email, $html);
        $this->assertStringContainsString($mail->code, $html);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', $html, 'nothing of CoreX in the body');
        $this->assertStringNotContainsString('Os</span>', $html);

        $this->assertSame($agency->name, $env->from->name, 'the agency is the sender');
        $this->assertStringContainsString($agency->name, (string) $env->subject);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', (string) $env->subject);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', (string) $env->from->name);
        $this->assertSame($agency->email, $env->replyTo[0]->address, 'replies go to the agency');
    }

    private function cape(array $extra = []): Agency
    {
        return $this->agency('Cape Rentals', $extra + ['address' => '12 Long Street, Cape Town', 'phone' => '021 555 0100', 'email' => 'hello@caperentals.test']);
    }

    public function test_a_first_time_owner_or_tenant_with_no_login_yet_gets_the_agencys_own_code_mail(): void
    {
        $cape = $this->cape(['logo_path' => 'agencies/9/logo.png']);
        $this->contactAt($cape, 'thandi@example.test');   // a contact only: no ClientUser exists yet

        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'thandi@example.test', 'purpose' => 'activation'])->assertOk();

        $mail = $this->sentCode();
        $this->assertTheAgenciesOwnMail($mail, $cape);
        $this->assertStringContainsString('storage/agencies/9/logo.png', $mail->render(), 'the agency logo is on top');
    }

    public function test_an_agency_with_no_logo_shows_its_name_on_top_instead(): void
    {
        $cape = $this->cape();
        $this->contactAt($cape, 'thandi@example.test');

        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'thandi@example.test'])->assertOk();

        $this->assertStringNotContainsString('<img', $this->sentCode()->render());
        $this->assertTheAgenciesOwnMail($this->sentCode(), $cape);
    }

    public function test_the_password_reset_code_from_either_entry_point_is_the_agencys_own_too(): void
    {
        $cape = $this->cape();
        $login = ClientUser::create(['email' => 'thandi@example.test']);
        $this->contactAt($cape, 'thandi@example.test', $login->id);

        // "Forgot password?" on the password step
        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'thandi@example.test', 'purpose' => 'recovery'])->assertOk();
        $this->assertTheAgenciesOwnMail($this->sentCode(), $cape);

        // the dedicated forgot-password endpoint
        Mail::fake();
        $this->browser()->postJson('/api/v1/client-auth/password/forgot', ['email' => 'thandi@example.test'])->assertOk();
        $this->assertTheAgenciesOwnMail($this->sentCode(), $cape);
    }

    public function test_the_second_agency_gets_its_own_branding_not_the_first(): void
    {
        $this->cape();
        $karoo = $this->agency('Karoo Lettings', ['address' => '3 Church Street, Graaff-Reinet', 'phone' => '049 555 0101', 'email' => 'desk@karoo.test']);
        $this->contactAt($karoo, 'lerato@example.test');

        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'lerato@example.test'])->assertOk();

        $mail = $this->sentCode();
        $this->assertTheAgenciesOwnMail($mail, $karoo);
        $this->assertStringNotContainsString('Cape Rentals', $mail->render());
    }

    public function test_a_person_with_logins_at_two_agencies_gets_the_agency_their_login_is_locked_to_else_a_plain_mail(): void
    {
        $cape = $this->cape();
        $karoo = $this->agency('Karoo Lettings', ['address' => '3 Church Street', 'phone' => '049 555 0101', 'email' => 'desk@karoo.test']);
        $login = ClientUser::create(['email' => 'thandi@example.test']);
        $this->contactAt($cape, 'thandi@example.test', $login->id);
        $this->contactAt($karoo, 'thandi@example.test', $login->id);

        // nothing chosen: a plain sign-in code - no agency guessed, and no CoreX either
        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'thandi@example.test'])->assertOk();
        $this->assertPlainCodeMail($this->sentCode());

        // locked to one: that agency's mail
        Mail::fake();
        $login->forceFill(['locked_to_agency_id' => $karoo->id])->save();
        \Illuminate\Support\Facades\RateLimiter::clear('otp.cooldown:client_login:thandi@example.test');
        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'thandi@example.test'])->assertOk();
        $this->assertTheAgenciesOwnMail($this->sentCode(), $karoo);
    }

    public function test_an_unknown_address_gets_the_same_plain_mail_and_it_says_nothing_of_corex(): void
    {
        $this->browser()->postJson('/api/v1/client-auth/otp/send', ['email' => 'nobody@example.test'])->assertOk();

        // an unknown address sends nothing at all (no enumeration hint, no mail to a stranger) - the plain shape is what a known-but-ambiguous address gets
        Mail::assertNotSent(ClientAuthOtpMail::class);
        $this->assertPlainCodeMail(new ClientAuthOtpMail('123456', 10));
    }

    private function assertPlainCodeMail(ClientAuthOtpMail $mail): void
    {
        $html = $mail->render();
        $env = $mail->envelope();

        $this->assertStringContainsString($mail->code, $html);
        $this->assertStringContainsString('Enter this code to sign in', $html);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', $html);
        $this->assertStringNotContainsString('Os</span>', $html);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', (string) $env->subject);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', (string) $env->from->name);
        $this->assertSame([], $env->replyTo);
    }

    public function test_the_portal_link_mail_is_the_agencys_and_does_not_call_the_portal_corex(): void
    {
        $cape = $this->cape();
        $branch = Branch::where('agency_id', $cape->id)->first();
        $agent = User::factory()->create(['agency_id' => $cape->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Sherry Agent']);
        $contact = $this->contactAt($cape, 'thandi@example.test');

        $mail = new RentalPortalInviteMail($contact, ['tenant'], url('/portal') . '?email=thandi%40example.test', $agent);
        $html = $mail->render();

        $this->assertStringContainsString('Cape Rentals', $html);
        $this->assertStringContainsString('Your portal is ready', $html);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', $html);
        $this->assertStringNotContainsStringIgnoringCase('CoreX', (string) $mail->envelope()->subject);
        $this->assertStringContainsString('Cape Rentals', (string) $mail->envelope()->subject);
    }

    // ── the portal pages themselves ───────────────────────────────────────

    public function test_the_portal_pages_never_say_corex_os_and_a_personal_link_shows_the_agency(): void
    {
        $cape = $this->cape();
        $login = ClientUser::create(['email' => 'thandi@example.test']);
        $this->contactAt($cape, 'thandi@example.test', $login->id);

        // any portal path (sign-in, the code step and set-password are screens of this one page; an unknown path is the same page)
        foreach (['/portal', '/portal/does-not-exist', '/portal?email=thandi%40example.test'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            $this->assertStringNotContainsString('CoreX OS', $html, $path);
            $this->assertStringNotContainsString('<title>CoreX', $html, $path);
        }

        $this->get('/portal?email=thandi%40example.test')->assertSee('Cape Rentals');
    }
}
