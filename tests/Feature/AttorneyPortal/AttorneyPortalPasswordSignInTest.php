<?php

namespace Tests\Feature\AttorneyPortal;

use App\Mail\OtpMail;
use App\Models\AttorneyPortalAccess;
use App\Services\AttorneyPortal\AttorneyPortalAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * attorney-portal.md "Sign-in method" - after the first PIN sign-in the attorney chooses an emailed PIN each time or a password;
 * the choice is remembered per attorney contact and can be changed; password sign-in uses the client portal's rules
 * (8+ characters, 10 wrong tries in 5 minutes locks), "forgot password" emails the PIN, revoking ends the password.
 */
class AttorneyPortalPasswordSignInTest extends TestCase
{
    use RefreshDatabase, AttorneyPortalFixtures;

    private function jar(string $cookie): array
    {
        return [config('attorney-portal.cookie') => $cookie];
    }

    /** A browser with no portal cookie (withUnencryptedCookies() accumulates across requests inside one test). */
    private function freshBrowser(): void
    {
        $this->flushSession();
        $this->unencryptedCookies = [];
    }

    private function access($w): AttorneyPortalAccess
    {
        return AttorneyPortalAccess::withoutGlobalScopes()->where('service_provider_contact_id', $w['a']->id)->firstOrFail();
    }

    private function withPassword(array $w, string $password = 'Correct-horse-9'): array
    {
        [$token, $cookie] = $this->signIn($w['a'], $w['agent']);
        $jar = $this->jar($cookie);
        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['password' => $password, 'password_confirmation' => $password])->assertRedirect(route('attorney.account'));

        return [$token, $jar];
    }

    public function test_the_first_sign_in_asks_the_attorney_to_choose_and_the_choice_is_remembered(): void
    {
        $w = $this->world();
        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $jar = $this->jar($cookie);

        $page = $this->withUnencryptedCookies($jar)->get(route('attorney.deals'))->assertOk();
        $page->assertSee('How would you like to sign in next time?')->assertSee('Keep using an emailed PIN each time')->assertSee('Set up a password');
        $this->assertNull($this->access($w)->sign_in_choice);

        $this->withUnencryptedCookies($jar)->post(route('attorney.account.pin'))->assertRedirect(route('attorney.account'));
        $this->assertSame('pin', $this->access($w)->sign_in_choice);
        $this->withUnencryptedCookies($jar)->get(route('attorney.deals'))->assertOk()->assertDontSee('How would you like to sign in next time?');
    }

    public function test_setting_a_password_needs_eight_characters_and_matching_twice_and_is_stored_hashed(): void
    {
        $w = $this->world();
        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $jar = $this->jar($cookie);

        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['password' => 'Long-enough-1', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertNull($this->access($w)->fresh()->password);

        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['password' => 'Long-enough-1', 'password_confirmation' => 'Long-enough-1'])->assertSessionHasNoErrors();
        $a = $this->access($w)->fresh();
        $this->assertSame('password', $a->sign_in_choice);
        $this->assertNotSame('Long-enough-1', $a->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Long-enough-1', $a->password));
    }

    public function test_an_attorney_with_a_password_signs_in_with_email_and_password_and_a_wrong_one_does_not(): void
    {
        $w = $this->world();
        $this->withPassword($w);
        $this->freshBrowser();

        $this->get(route('attorney.sign-in'))->assertOk()->assertSee('Forgot password?');
        $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'nope-nope-nope'])->assertSessionHasErrors('password');
        $this->get(route('attorney.deals'))->assertStatus(401);

        $ok = $this->post(route('attorney.sign-in.post'), ['email' => 'BOTHA@bbb.co.za', 'password' => 'Correct-horse-9'])->assertRedirect(route('attorney.deals'));
        $cookie = $ok->getCookie(config('attorney-portal.cookie'), false);
        $this->assertNotNull($cookie);
        $this->withUnencryptedCookies($this->jar($cookie->getValue()))->get(route('attorney.deals'))->assertOk();
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'signed_in_password']);
        $this->assertDatabaseHas('attorney_portal_logs', ['event' => 'password_failed']);
    }

    public function test_a_pin_attorney_cannot_use_password_sign_in(): void
    {
        $w = $this->world();
        [, $cookie] = $this->signIn($w['a'], $w['agent']);
        $this->withUnencryptedCookies($this->jar($cookie))->post(route('attorney.account.pin'));

        $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'anything-at-all'])->assertSessionHasErrors('password');
    }

    public function test_ten_wrong_passwords_lock_password_sign_in_even_for_the_right_password(): void
    {
        $w = $this->world();
        $this->withPassword($w);
        $this->freshBrowser();
        RateLimiter::clear('attorney.login:127.0.0.1:botha@bbb.co.za');

        for ($i = 0; $i < 10; $i++) {   // the client portal's rule: 10 wrong tries within 5 minutes
            RateLimiter::hit('attorney.login:127.0.0.1:botha@bbb.co.za', 300);
        }
        $locked = $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'Correct-horse-9']);
        $locked->assertSessionHasErrors('password');
        $this->assertStringContainsString('Too many wrong tries', session('errors')->first('password'));
        $this->get(route('attorney.deals'))->assertStatus(401);
    }

    public function test_changing_the_password_needs_the_current_one(): void
    {
        $w = $this->world();
        [, $jar] = $this->withPassword($w);

        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['current_password' => 'not-it-not-it', 'password' => 'Brand-new-pass-2', 'password_confirmation' => 'Brand-new-pass-2'])->assertSessionHasErrors('current_password');
        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['password' => 'Brand-new-pass-2', 'password_confirmation' => 'Brand-new-pass-2'])->assertSessionHasErrors('current_password');
        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['current_password' => 'Correct-horse-9', 'password' => 'Brand-new-pass-2', 'password_confirmation' => 'Brand-new-pass-2'])->assertSessionHasNoErrors();

        $this->freshBrowser();
        $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'Correct-horse-9'])->assertSessionHasErrors('password');
        $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'Brand-new-pass-2'])->assertRedirect(route('attorney.deals'));
    }

    public function test_forgot_password_emails_the_pin_and_lets_them_choose_a_new_password_without_the_old_one(): void
    {
        $w = $this->world();
        $this->withPassword($w);
        $this->freshBrowser();
        Mail::fake();

        // an unknown address gets the same quiet answer and no mail
        $this->post(route('attorney.forgot.post'), ['email' => 'nobody@nowhere.co.za'])->assertRedirect(route('attorney.forgot'));
        Mail::assertNotSent(OtpMail::class);

        RateLimiter::clear('otp.cooldown:attorney_portal:contact-' . $w['a']->id);   // the 60-second pause after the earlier sign-in code
        $this->post(route('attorney.forgot.post'), ['email' => 'botha@bbb.co.za'])->assertRedirect(route('attorney.forgot'));
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) { $code = $m->code; return true; });

        $this->post(route('attorney.forgot.verify'), ['email' => 'botha@bbb.co.za', 'code' => '000000'])->assertSessionHasErrors('code');
        $ok = $this->post(route('attorney.forgot.verify'), ['email' => 'botha@bbb.co.za', 'code' => $code])->assertRedirect(route('attorney.account'));
        $jar = $this->jar($ok->getCookie(config('attorney-portal.cookie'), false)->getValue());

        $this->withUnencryptedCookies($jar)->post(route('attorney.account.password'), ['password' => 'Fresh-start-77', 'password_confirmation' => 'Fresh-start-77'])->assertSessionHasNoErrors();
        $this->freshBrowser();
        $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'Fresh-start-77'])->assertRedirect(route('attorney.deals'));
    }

    public function test_revoking_access_ends_the_password_and_the_choice(): void
    {
        $w = $this->world();
        $this->withPassword($w);
        $a = $this->access($w);

        app(AttorneyPortalAccessService::class)->revoke($a, $w['agent']);

        $a = $a->fresh();
        $this->assertNull($a->password);
        $this->assertNull($a->sign_in_choice);
        $this->freshBrowser();
        $this->post(route('attorney.sign-in.post'), ['email' => 'botha@bbb.co.za', 'password' => 'Correct-horse-9'])->assertSessionHasErrors('password');
        $this->get(route('attorney.deals'))->assertStatus(401);
    }

    public function test_the_overview_shows_how_each_attorney_signs_in(): void
    {
        $w = $this->world();
        $this->withPassword($w);
        app(AttorneyPortalAccessService::class)->invite($w['b'], $w['agent']);

        $admin = \App\Models\User::factory()->create(['agency_id' => $w['agency']->id, 'branch_id' => $w['branch']->id, 'role' => 'admin']);
        foreach (['deals_v2.invite_attorney', 'view_deals', 'sidebar.section.agents'] as $key) {
            \App\Models\RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'scope' => null, 'agency_id' => $w['agency']->id]);
        }
        \App\Services\PermissionService::clearCache();

        $html = $this->actingAs($admin)->get(route('deals-v2.attorney-portal.index'))->assertOk()->getContent();
        $this->assertStringContainsString('How they sign in', $html);
        $this->assertStringContainsString('Password', $html);
        $this->assertStringContainsString('Not chosen yet', $html);
    }
}
