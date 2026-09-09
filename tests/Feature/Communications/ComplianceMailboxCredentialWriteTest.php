<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-09 (Johan, real-attempt-honesty incident) — Compliance → Archive
 * Mailboxes (CommunicationMailboxController) had no dedicated test file at
 * all before this. Narrow, focused coverage for the one thing this incident
 * needs proven here: fill()'s password write used `! empty($data['password'])`,
 * the same bug as Settings → Email Setup and My Portal self-service — a
 * password of exactly "0" silently discarded while the form looks like it
 * saved. Fixed identically in all three controllers, same commit.
 */
final class ComplianceMailboxCredentialWriteTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        // super_admin bypasses both PermissionService's grant check AND
        // scopeVisibleTo()'s data-scope check (isOwnerRole()) — the simplest
        // correct fixture for a test whose only concern is the password-write
        // fix, not this controller's separate data-scope rules.
        $this->admin = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => 'super_admin', 'is_active' => true,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email_address'         => 'box@hfcoastal.co.za',
            'imap_host'             => 'imap.hfcoastal.co.za',
            'imap_port'             => 993,
            'username'              => 'box@hfcoastal.co.za',
            'password'              => 'Some-Pw',
            'poll_inbox'            => 1,
            'poll_sent'             => 1,
            'poll_interval_minutes' => 15,
            'active'                => 1,
        ], $overrides);
    }

    private function seedMailbox(string $password): CommunicationMailbox
    {
        return CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'set_by' => 'agency',
            'auth_type' => 'imap', 'email_address' => 'box@hfcoastal.co.za', 'imap_host' => 'imap.x.co.za',
            'imap_port' => 993, 'username' => 'box@hfcoastal.co.za', 'encrypted_password' => $password,
            'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    public function test_update_with_blank_password_keeps_the_current_one(): void
    {
        $mbx = $this->seedMailbox('Original-Pw');

        $this->actingAs($this->admin)
            ->put(route('compliance.comm-mailboxes.update', $mbx), $this->validPayload(['password' => '']))
            ->assertRedirect();

        $this->assertSame('Original-Pw', $mbx->fresh()->encrypted_password);
    }

    /** The bug this incident is about — same fix as the other two controllers. */
    public function test_update_with_a_password_of_literally_zero_actually_saves_it(): void
    {
        $mbx = $this->seedMailbox('Original-Pw');

        $this->actingAs($this->admin)
            ->put(route('compliance.comm-mailboxes.update', $mbx), $this->validPayload(['password' => '0']))
            ->assertRedirect();

        $this->assertSame('0', $mbx->fresh()->encrypted_password, 'a password of "0" must overwrite the old one, not be silently discarded');
    }
}
