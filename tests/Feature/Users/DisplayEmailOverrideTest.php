<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Mail\Signatures\SalesDocumentMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-79 — users.display_email outward-facing override.
 *
 * Proves: outward_email returns the override when set, falls back to the
 * login email otherwise, the real login email is never mutated, and an
 * outward surface (the signature mailable From/Reply-To/footer) honours it.
 */
class DisplayEmailOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_outward_email_uses_override_when_set(): void
    {
        $user = User::factory()->create([
            'email'         => 'elizesouthbroom@hfcoastal.co.za',
            'display_email' => 'elize@hfcoastal.co.za',
        ]);

        $this->assertSame('elize@hfcoastal.co.za', $user->outward_email);
        // Login credential is untouched.
        $this->assertSame('elizesouthbroom@hfcoastal.co.za', $user->email);
    }

    public function test_outward_email_falls_back_to_login_email_when_null(): void
    {
        $user = User::factory()->create([
            'email'         => 'agent@hfcoastal.co.za',
            'display_email' => null,
        ]);

        $this->assertSame('agent@hfcoastal.co.za', $user->outward_email);
    }

    public function test_blank_override_falls_back_to_login_email(): void
    {
        $user = User::factory()->create([
            'email'         => 'agent2@hfcoastal.co.za',
            'display_email' => '',
        ]);

        $this->assertSame('agent2@hfcoastal.co.za', $user->outward_email);
    }

    public function test_set_display_email_command_sets_and_clears(): void
    {
        $user = User::factory()->create([
            'email'         => 'bm@hfcoastal.co.za',
            'display_email' => null,
        ]);

        $this->artisan('users:set-display-email', [
            'email'         => 'bm@hfcoastal.co.za',
            'display_email' => 'central@hfcoastal.co.za',
        ])->assertExitCode(0);

        $this->assertSame('central@hfcoastal.co.za', $user->fresh()->display_email);
        $this->assertSame('bm@hfcoastal.co.za', $user->fresh()->email);

        $this->artisan('users:set-display-email', [
            'email'   => 'bm@hfcoastal.co.za',
            '--clear' => true,
        ])->assertExitCode(0);

        $this->assertNull($user->fresh()->display_email);
    }

    public function test_signature_mailable_uses_outward_email_for_from_replyto_footer(): void
    {
        $agent = User::factory()->create([
            'name'          => 'Elize',
            'email'         => 'elizeballito@hfcoastal.co.za',
            'display_email' => 'elize@hfcoastal.co.za',
        ]);

        $mail = (new SalesDocumentMail(
            recipientName: 'Seller',
            documentName: 'Mandate',
            uploadUrl: 'https://example.test/sign',
            personalMessage: null,
            expiresAt: now()->addDays(7),
        ))->fromAgent($agent);

        $envelope = $mail->envelope();

        $this->assertSame('elize@hfcoastal.co.za', $envelope->from->address);
        $this->assertNotEmpty($envelope->replyTo);
        $this->assertSame('elize@hfcoastal.co.za', $envelope->replyTo[0]->address);
    }
}
