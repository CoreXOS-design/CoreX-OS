<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AT-268 — a pending-invite account cannot be logged into.
 *
 * THE DEFECT: invited users were created with password = 'INVITE_PENDING' (a public constant), which
 * the `hashed` cast turned into a real bcrypt hash of that constant — so anyone typing it authenticated
 * as any un-accepted invite. Two independent fixes are proven here: the invite password is now unusable
 * and unguessable, AND the login gate refuses a pending invite regardless of the password supplied.
 */
final class PendingInviteLoginTest extends TestCase
{
    use RefreshDatabase;

    /** A user created through the invite path no longer carries the public constant. */
    public function test_an_invited_user_password_is_not_the_public_constant(): void
    {
        $user = User::factory()->create([
            'password'          => User::pendingInvitePassword(),
            'email_verified_at' => null,
            'is_active'         => true,
        ]);

        $this->assertFalse(
            Hash::check('INVITE_PENDING', $user->fresh()->password),
            'A pending invite must never be loginable with the public INVITE_PENDING constant.'
        );
    }

    /** The old attack — POST /login with the constant — is now rejected. */
    public function test_login_with_the_constant_is_denied(): void
    {
        $user = User::factory()->create([
            'email'             => 'invitee@example.co.za',
            'password'          => User::pendingInvitePassword(),
            'email_verified_at' => null,
            'is_active'         => true,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'INVITE_PENDING'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * BELT-AND-BRACES: even if a pending account somehow held a KNOWN, correct password, the gate
     * refuses it with the honest message — a pending invite can never hold a session.
     */
    public function test_the_gate_refuses_a_pending_invite_even_with_a_correct_password(): void
    {
        $user = User::factory()->create([
            'email'             => 'pending@example.co.za',
            'password'          => 'a-real-known-password-123',   // hashed by the cast
            'email_verified_at' => null,                          // ...but still not accepted
            'is_active'         => true,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'a-real-known-password-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /** An accepted user (verified) with a real password logs in normally — no regression. */
    public function test_an_accepted_user_logs_in_normally(): void
    {
        $user = User::factory()->create([
            'email'             => 'accepted@example.co.za',
            'password'          => 'her-real-password-456',
            'email_verified_at' => now(),
            'is_active'         => true,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'her-real-password-456']);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_pending_invite_predicate(): void
    {
        $pending  = User::factory()->create(['email_verified_at' => null]);
        $accepted = User::factory()->create(['email_verified_at' => now()]);

        $this->assertTrue($pending->isPendingInvite());
        $this->assertFalse($accepted->isPendingInvite());
    }
}
