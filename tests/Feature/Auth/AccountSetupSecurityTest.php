<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * account-setup was an unauthenticated account-takeover vector: the POST that
 * sets a user's password carried no signature and no auth, and (unlike show())
 * no guard against an already-verified account. Anyone could POST
 * /account-setup/{id} with a password and seize any account by guessing its id.
 *
 * Fix: the POST is 'signed'; the form submits to a signed URL; store() re-checks
 * the account is still pending.
 */
final class AccountSetupSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function pendingInvitee(): User
    {
        // AT-268 — a real pending invite carries an UNUSABLE password (no longer the public constant).
        return User::factory()->create([
            'password'          => User::pendingInvitePassword(),
            'email_verified_at' => null,
        ]);
    }

    public function test_unsigned_post_is_rejected(): void
    {
        $invitee = $this->pendingInvitee();
        $originalHash = $invitee->fresh()->password;

        // The attack: POST straight to the endpoint with no signature.
        $this->post("/account-setup/{$invitee->id}", [
            'password'              => 'attackerpass123',
            'password_confirmation' => 'attackerpass123',
        ])->assertForbidden();   // 403 from the 'signed' middleware

        // The password is untouched — asserted by unchanged hash, NOT by the old public constant
        // (AT-268: pinning the constant was pinning the vulnerability itself).
        $this->assertSame($originalHash, $invitee->fresh()->password,
            'the password must be untouched after an unsigned attempt');
        $this->assertFalse(
            Hash::check('INVITE_PENDING', $invitee->fresh()->password),
            'a pending invite must never carry the public INVITE_PENDING constant (AT-268)'
        );
        $this->assertNull($invitee->fresh()->email_verified_at);
    }

    public function test_a_valid_signed_post_sets_the_password(): void
    {
        $invitee = $this->pendingInvitee();

        $signed = URL::temporarySignedRoute(
            'account.setup.store',
            now()->addDays(7),
            ['user' => $invitee->id]
        );

        $this->post($signed, [
            'password'              => 'ValidPass123',
            'password_confirmation' => 'ValidPass123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            Hash::check('ValidPass123', $invitee->fresh()->password),
            'the legitimate invitee must be able to set their password'
        );
        $this->assertNotNull($invitee->fresh()->email_verified_at);
    }

    public function test_an_active_account_cannot_be_reset_even_with_a_valid_signature(): void
    {
        $active = User::factory()->create(['password' => 'realpassword']);
        $active->forceFill(['email_verified_at' => now()])->save();   // not mass-assignable

        $signed = URL::temporarySignedRoute(
            'account.setup.store',
            now()->addDays(7),
            ['user' => $active->id]
        );

        // Even with a perfectly valid signature, an already-verified account is off limits.
        $this->post($signed, [
            'password'              => 'takeover12345',
            'password_confirmation' => 'takeover12345',
        ])->assertRedirect(route('login'));

        $this->assertTrue(
            Hash::check('realpassword', $active->fresh()->password),
            'an active account\'s password must never be overwritten via account-setup'
        );
    }

    public function test_a_tampered_user_id_fails_the_signature(): void
    {
        $invitee = $this->pendingInvitee();
        $victim  = User::factory()->create(['password' => 'victimpass']);
        $victim->forceFill(['email_verified_at' => now()])->save();

        // Sign for the invitee, then swap the id to the victim — signature breaks.
        $signed   = URL::temporarySignedRoute('account.setup.store', now()->addDays(7), ['user' => $invitee->id]);
        $tampered = str_replace("/account-setup/{$invitee->id}", "/account-setup/{$victim->id}", $signed);

        $this->post($tampered, [
            'password'              => 'hijack123456',
            'password_confirmation' => 'hijack123456',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('victimpass', $victim->fresh()->password));
    }
}
