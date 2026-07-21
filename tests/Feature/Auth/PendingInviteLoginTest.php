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
 * and unguessable, AND the login gate refuses a genuine unredeemed invite.
 *
 * AT-268 follow-up (production login outage): the gate originally fired on the bare
 * email_verified_at IS NULL predicate, which also caught established accounts created outside the
 * invite flow (CoreX never enforced MustVerifyEmail) — the two super_admin owner accounts carried a
 * NULL marker with real passwords and were locked out of live. The gate now additionally requires the
 * account to still hold the public 'INVITE_PENDING' constant, so it can only ever fire on the exact
 * hole it closes. These tests pin the corrected behaviour on both sides.
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
     * BELT-AND-BRACES, correctly scoped: a genuine unredeemed invite that still holds the public
     * 'INVITE_PENDING' constant is refused by the gate even though the supplied password is correct —
     * the security intent of AT-268 stays intact.
     */
    public function test_the_gate_still_blocks_a_pending_invite_holding_the_constant(): void
    {
        $user = User::factory()->create([
            'email'             => 'pending@example.co.za',
            'password'          => 'INVITE_PENDING',   // the cast hashes the public constant
            'email_verified_at' => null,               // ...never accepted
            'is_active'         => true,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'INVITE_PENDING'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * REGRESSION (production login outage): a null-verified account with a REAL bcrypt password — e.g.
     * a super_admin owner account created outside the invite flow — logs in normally. The narrowed gate
     * must NOT fire for it (it does not hold the constant), even though isPendingInvite() is true.
     */
    public function test_a_null_verified_account_with_a_real_password_logs_in(): void
    {
        $user = User::factory()->create([
            'email'             => 'owner@example.co.za',
            'password'          => 'a-real-owner-password-789',   // NOT the constant
            'email_verified_at' => null,                          // established, but never "accepted"
            'is_active'         => true,
        ]);

        $this->assertTrue($user->isPendingInvite(), 'predicate is still email_verified_at IS NULL');

        $this->post('/login', ['email' => $user->email, 'password' => 'a-real-owner-password-789']);

        $this->assertAuthenticatedAs($user);
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
