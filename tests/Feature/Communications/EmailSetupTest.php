<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\Communications\MailboxCredentialReveal;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-37 — Communication Capture Setup Phase 1. Proves the security contract:
 * credentials are write-only (never returned by an endpoint/view), the password
 * reveal is gated by the principal-only permission and audited, and setting
 * credentials links the mailbox to the target user with agency provenance.
 *
 * Input paths proven: store-links-user, password-never-serialised,
 * index-never-leaks-password, reveal-blocked-without-perm,
 * reveal-with-perm-logs-and-returns-once, update-blank-password-keeps-current,
 * malformed-email-rejected, required-field-empty-rejected.
 */
final class EmailSetupTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $admin;       // manage_communication_mailboxes, NOT reveal
    private User $principal;   // manage + reveal
    private User $target;      // the user whose mailbox is being managed

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        // PermissionService fails OPEN when role_permissions is unseeded (graceful
        // for fresh DBs). Seed the exact grants so the gates enforce as in prod:
        // 'cap_admin' manages mailboxes but cannot reveal; 'cap_principal' can.
        DB::table('role_permissions')->insert([
            ['role' => 'cap_admin',     'permission_key' => 'manage_communication_mailboxes', 'scope' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'cap_principal', 'permission_key' => 'manage_communication_mailboxes', 'scope' => null, 'created_at' => now(), 'updated_at' => now()],
            ['role' => 'cap_principal', 'permission_key' => 'reveal_mailbox_credential',       'scope' => null, 'created_at' => now(), 'updated_at' => now()],
            // AT-395 (2026-09-07) — My Portal self-service surface.
            ['role' => 'cap_selfservice', 'permission_key' => 'access_communication',          'scope' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();

        $this->admin     = $this->makeUser('cap_admin');
        $this->principal = $this->makeUser('cap_principal');
        $this->target    = $this->makeUser('agent');
    }

    /**
     * PermissionService caches `$seeded` in a STATIC that survives the per-test
     * transaction rollback. Seeding role_permissions here flips it to true; if we
     * don't reset it, the next test (which relies on the unseeded fail-open path)
     * inherits seeded=true against a now-empty table and denies every permission.
     * Reset AFTER the rollback so the next test re-evaluates against a clean DB.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
        PermissionService::clearCache();
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email_address'         => 'agent@hfcoastal.co.za',
            'imap_host'             => 'imap.hfcoastal.co.za',
            'imap_port'             => 993,
            'username'              => 'agent@hfcoastal.co.za',
            'password'              => 'Sup3r-Secret-Pw!',
            'poll_inbox'            => 1,
            'poll_sent'             => 1,
            'poll_interval_minutes' => 15,
            'active'                => 1,
        ], $overrides);
    }

    public function test_storing_credentials_links_mailbox_to_user_with_agency_provenance(): void
    {
        $this->actingAs($this->admin)
            ->post(route('settings.email-setup.store', $this->target), $this->validPayload())
            ->assertRedirect();

        $mbx = CommunicationMailbox::where('user_id', $this->target->id)->firstOrFail();
        $this->assertSame($this->agencyId, (int) $mbx->agency_id);
        $this->assertSame('agency', $mbx->set_by, 'agency-provisioned mailbox is stamped set_by=agency');
        $this->assertSame('imap', $mbx->auth_type);
        // Password round-trips through the encrypted cast (stored ciphertext, read plaintext).
        $this->assertSame('Sup3r-Secret-Pw!', $mbx->encrypted_password);
        $this->assertStringNotContainsString('Sup3r-Secret-Pw!', (string) $mbx->getRawOriginal('encrypted_password'), 'password must be ciphertext at rest');
    }

    public function test_stored_password_is_never_returned_by_index_or_serialization(): void
    {
        $secret = 'Leak-Me-If-You-Can-123';
        $mbx = CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->target->id, 'set_by' => 'agency',
            'auth_type' => 'imap', 'email_address' => 'a@hfcoastal.co.za', 'imap_host' => 'imap.x.co.za',
            'imap_port' => 993, 'username' => 'a@hfcoastal.co.za', 'encrypted_password' => $secret,
            'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ]);

        // The management list endpoint renders the user + mailbox but never the password.
        $resp = $this->actingAs($this->admin)->get(route('settings.email-setup.index'));
        $resp->assertOk();
        $resp->assertSee('a@hfcoastal.co.za', false);   // the address IS shown
        $resp->assertDontSee($secret, false);            // the password is NOT

        // Model serialisation hides it too (no API/JSON path can leak it).
        $this->assertArrayNotHasKey('encrypted_password', $mbx->fresh()->toArray());
        $this->assertStringNotContainsString($secret, $mbx->fresh()->toJson());
    }

    public function test_reveal_is_blocked_without_the_reveal_permission(): void
    {
        $mbx = $this->seedMailbox('Secret-Pw-A');

        // admin holds manage_communication_mailboxes but NOT reveal_mailbox_credential.
        $this->actingAs($this->admin)
            ->post(route('settings.email-setup.reveal', $mbx))
            ->assertForbidden();

        $this->assertSame(0, MailboxCredentialReveal::count(), 'a blocked reveal must not write an audit row');
    }

    public function test_reveal_with_permission_logs_an_audit_row_and_returns_the_password_once(): void
    {
        $mbx = $this->seedMailbox('Secret-Pw-B');

        $this->actingAs($this->principal)
            ->post(route('settings.email-setup.reveal', $mbx))
            ->assertRedirect()
            ->assertSessionHas('revealed_password', 'Secret-Pw-B')
            ->assertSessionHas('revealed_mailbox_id', $mbx->id);

        $this->assertDatabaseHas('mailbox_credential_reveals', [
            'agency_id'            => $this->agencyId,
            'mailbox_id'           => $mbx->id,
            'revealed_by'          => $this->principal->id,
            'revealed_for_user_id' => $this->target->id,
        ]);
        $this->assertNotNull(MailboxCredentialReveal::first()->revealed_at);
    }

    public function test_update_with_blank_password_keeps_the_current_one(): void
    {
        $mbx = $this->seedMailbox('Original-Pw');

        $this->actingAs($this->admin)
            ->put(route('settings.email-setup.update', $mbx), $this->validPayload([
                'email_address' => 'renamed@hfcoastal.co.za', 'password' => '',
            ]))
            ->assertRedirect();

        $mbx->refresh();
        $this->assertSame('renamed@hfcoastal.co.za', $mbx->email_address, 'other fields update');
        $this->assertSame('Original-Pw', $mbx->encrypted_password, 'blank password leaves the stored one intact');
    }

    public function test_malformed_email_is_rejected_and_required_fields_enforced(): void
    {
        // Malformed email → rejected, no row created.
        $this->actingAs($this->admin)
            ->post(route('settings.email-setup.store', $this->target), $this->validPayload(['email_address' => 'not-an-email']))
            ->assertSessionHasErrors('email_address');

        // Required-but-empty host → rejected.
        $this->actingAs($this->admin)
            ->post(route('settings.email-setup.store', $this->target), $this->validPayload(['imap_host' => '']))
            ->assertSessionHasErrors('imap_host');

        $this->assertSame(0, CommunicationMailbox::where('user_id', $this->target->id)->count());
    }

    private function seedMailbox(string $password): CommunicationMailbox
    {
        return CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'user_id' => $this->target->id, 'set_by' => 'agency',
            'auth_type' => 'imap', 'email_address' => 'box@hfcoastal.co.za', 'imap_host' => 'imap.x.co.za',
            'imap_port' => 993, 'username' => 'box@hfcoastal.co.za', 'encrypted_password' => $password,
            'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    /**
     * AT-395 (2026-09-07) — the settings screen's own form used to be missing
     * outgoing/SMTP fields entirely (backend already supported them; only the
     * view never rendered them), so a mailbox configured here could never
     * send. Regression guard for that exact drift: the edit form for an
     * existing mailbox must render every outgoing input the compliance
     * "Email Mailboxes (import)" screen has.
     */
    public function test_settings_screen_renders_the_outgoing_smtp_fields(): void
    {
        $this->seedMailbox('Some-Pw');

        $resp = $this->actingAs($this->admin)->get(route('settings.email-setup.index'));

        $resp->assertOk();
        foreach (['outgoing_enabled', 'use_imap_credentials_for_smtp', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_name', 'outgoing_active'] as $field) {
            $resp->assertSee('name="' . $field . '"', false);
        }
    }

    /** AT-395 — a mailbox configured for outgoing mail from THIS screen must actually persist as sendable. */
    public function test_storing_outgoing_settings_via_settings_screen_persists_them(): void
    {
        $this->actingAs($this->admin)->post(route('settings.email-setup.store', $this->target), $this->validPayload([
            'outgoing_enabled' => 1,
            'use_imap_credentials_for_smtp' => 1,
            'smtp_host' => 'mail.hfcoastal.co.za',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'outgoing_active' => 1,
        ]))->assertRedirect();

        $mbx = CommunicationMailbox::where('user_id', $this->target->id)->firstOrFail();
        $this->assertTrue((bool) $mbx->outgoing_enabled);
        $this->assertSame('mail.hfcoastal.co.za', $mbx->smtp_host);
        $this->assertSame(587, $mbx->smtp_port);
        $this->assertTrue($mbx->is(CommunicationMailbox::resolveOutgoingFor($this->target)), 'the resolver a real send uses must pick up this exact row');
    }

    /**
     * AT-395 (2026-09-07) — the THIRD surface (My Portal self-service,
     * MyPortal\CommunicationCaptureController) had no outgoing-field support
     * at all, not even on the backend. A mailbox an agent configures for
     * themselves must be just as capable of sending as one an admin
     * configures for them.
     */
    public function test_my_portal_self_service_also_persists_outgoing_settings(): void
    {
        $selfServiceUser = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => 'cap_selfservice', 'is_active' => true,
        ]);

        $this->actingAs($selfServiceUser)->post(route('my-portal.comm-capture.store'), $this->validPayload([
            'email_address' => $selfServiceUser->email,
            'outgoing_enabled' => 1,
            'smtp_host' => 'mail.hfcoastal.co.za',
            'smtp_port' => 587,
        ]))->assertRedirect();

        $mbx = CommunicationMailbox::where('user_id', $selfServiceUser->id)->firstOrFail();
        $this->assertSame('user', $mbx->set_by, 'self-service mailbox is stamped set_by=user');
        $this->assertTrue((bool) $mbx->outgoing_enabled);
        $this->assertSame('mail.hfcoastal.co.za', $mbx->smtp_host);
        $this->assertTrue($mbx->is(CommunicationMailbox::resolveOutgoingFor($selfServiceUser)));
    }

    /**
     * AT-395 fix (2026-09-07) — onboardingSaveOutgoing() used to always reuse
     * the SMTP host for imap_host on a brand-new mailbox, silently
     * mis-configuring incoming mail for any agency whose provider uses
     * different incoming/outgoing servers. Now: an explicit imap_host input
     * wins; only fall back to smtp_host when the user left it blank.
     */
    public function test_onboarding_saver_prefers_an_explicit_imap_host_over_the_smtp_host(): void
    {
        $agency = \App\Models\Agency::find($this->agencyId);
        $this->actingAs($this->target);

        app(\App\Http\Controllers\Settings\EmailSetupController::class)->onboardingSaveOutgoing(
            \Illuminate\Http\Request::create('/fake', 'POST', [
                'email_address' => $this->target->email,
                'outgoing_enabled' => '1',
                'smtp_host' => 'smtp.provider.example',
                'imap_host' => 'imap.provider.example',
            ]),
            $agency
        );

        $mbx = CommunicationMailbox::where('user_id', $this->target->id)->firstOrFail();
        $this->assertSame('imap.provider.example', $mbx->imap_host, 'an explicit IMAP host must win over the SMTP-host fallback');
        $this->assertSame('smtp.provider.example', $mbx->smtp_host);
    }

    /** AT-395 fix — leaving the (optional) IMAP host blank still falls back to the SMTP host, the sensible single-host default. */
    public function test_onboarding_saver_falls_back_to_smtp_host_when_imap_host_left_blank(): void
    {
        $agency = \App\Models\Agency::find($this->agencyId);
        $this->actingAs($this->target);

        app(\App\Http\Controllers\Settings\EmailSetupController::class)->onboardingSaveOutgoing(
            \Illuminate\Http\Request::create('/fake', 'POST', [
                'email_address' => $this->target->email,
                'outgoing_enabled' => '1',
                'smtp_host' => 'mail.singlehost.example',
            ]),
            $agency
        );

        $mbx = CommunicationMailbox::where('user_id', $this->target->id)->firstOrFail();
        $this->assertSame('mail.singlehost.example', $mbx->imap_host);
        $this->assertSame('mail.singlehost.example', $mbx->smtp_host);
    }

    /** AT-395 fix — re-running the saver on an EXISTING mailbox must never overwrite an imap_host the user already has. */
    public function test_onboarding_saver_never_overwrites_an_existing_imap_host(): void
    {
        $mbx = $this->seedMailbox('Existing-Pw');
        $mbx->update(['user_id' => $this->target->id, 'imap_host' => 'imap.already-set.example']);
        $agency = \App\Models\Agency::find($this->agencyId);
        $this->actingAs($this->target);

        app(\App\Http\Controllers\Settings\EmailSetupController::class)->onboardingSaveOutgoing(
            \Illuminate\Http\Request::create('/fake', 'POST', [
                'email_address' => $mbx->email_address,
                'outgoing_enabled' => '1',
                'smtp_host' => 'smtp.different.example',
            ]),
            $agency
        );

        $mbx->refresh();
        $this->assertSame('imap.already-set.example', $mbx->imap_host, 'existing imap_host must never be silently overwritten');
        $this->assertSame('smtp.different.example', $mbx->smtp_host);
    }
}
