<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-39 — Communication Capture Setup Phase 2 (Profile self-service + dual
 * control). Proves: a user manages only their OWN mailbox, self-set rows are
 * stamped set_by=user, dual-control update flips an agency-set row to user
 * provenance, the password stays write-only (never returned), and there is no
 * reveal on the self surface.
 *
 * Input paths proven: self-store sets user_id+set_by=user; cannot-touch-another-
 * user's-mailbox (403); password-never-returned-by-self-index; dual-control-
 * update-flips-set_by; blank-password-keeps-current; malformed-email rejected.
 */
final class ProfileCommunicationCaptureTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);

        // Seed the gate so access_communication actually enforces (PermissionService
        // fails open when role_permissions is unseeded).
        DB::table('role_permissions')->insert([
            ['role' => 'cap_agent', 'permission_key' => 'access_communication', 'scope' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        PermissionService::clearCache();

        $this->user  = $this->makeUser();
        $this->other = $this->makeUser();
    }

    /** Reset the PermissionService static cache so seeding can't bleed (see AT-37). */
    protected function tearDown(): void
    {
        parent::tearDown();
        PermissionService::clearCache();
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'role' => 'cap_agent', 'is_active' => true,
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'email_address'         => 'me@hfcoastal.co.za',
            'imap_host'             => 'imap.hfcoastal.co.za',
            'imap_port'             => 993,
            'username'              => 'me@hfcoastal.co.za',
            'password'              => 'My-Own-Pw-99',
            'poll_inbox'            => 1,
            'poll_sent'             => 1,
            'poll_interval_minutes' => 15,
            'active'                => 1,
        ], $overrides);
    }

    private function seedMailbox(User $owner, string $setBy, string $password = 'Stored-Pw'): CommunicationMailbox
    {
        return CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'user_id' => $owner->id, 'set_by' => $setBy,
            'auth_type' => 'imap', 'email_address' => 'box@hfcoastal.co.za', 'imap_host' => 'imap.x.co.za',
            'imap_port' => 993, 'username' => 'box@hfcoastal.co.za', 'encrypted_password' => $password,
            'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ]);
    }

    public function test_self_store_links_mailbox_to_self_with_user_provenance(): void
    {
        $this->actingAs($this->user)
            ->post(route('my-portal.comm-capture.store'), $this->validPayload())
            ->assertRedirect();

        $mbx = CommunicationMailbox::where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('user', $mbx->set_by, 'self-set mailbox is stamped set_by=user');
        $this->assertSame('My-Own-Pw-99', $mbx->encrypted_password);
        $this->assertStringNotContainsString('My-Own-Pw-99', (string) $mbx->getRawOriginal('encrypted_password'), 'ciphertext at rest');
    }

    public function test_a_user_cannot_manage_another_users_mailbox(): void
    {
        $foreign = $this->seedMailbox($this->other, 'user');

        // Update someone else's mailbox → forbidden, value unchanged.
        $this->actingAs($this->user)
            ->put(route('my-portal.comm-capture.update', $foreign), $this->validPayload(['email_address' => 'hacked@x.co.za']))
            ->assertForbidden();

        // Archive someone else's mailbox → forbidden, still present.
        $this->actingAs($this->user)
            ->delete(route('my-portal.comm-capture.destroy', $foreign))
            ->assertForbidden();

        $this->assertSame('box@hfcoastal.co.za', $foreign->fresh()->email_address);
        $this->assertNull($foreign->fresh()->deleted_at);
    }

    public function test_self_index_never_returns_the_stored_password(): void
    {
        $secret = 'Leak-Me-Self-77';
        $this->seedMailbox($this->user, 'user', $secret);

        $resp = $this->actingAs($this->user)->get(route('my-portal.comm-capture.index'));
        $resp->assertOk();
        $resp->assertSee('box@hfcoastal.co.za', false);   // address shown
        $resp->assertDontSee($secret, false);              // password NOT
        // And no reveal action exists on the self surface.
        $resp->assertDontSee('comm-capture/' . CommunicationMailbox::first()->id . '/reveal', false);
    }

    public function test_dual_control_user_edit_flips_agency_set_row_to_user(): void
    {
        $mbx = $this->seedMailbox($this->user, 'agency', 'Agency-Set-Pw');

        $this->actingAs($this->user)
            ->put(route('my-portal.comm-capture.update', $mbx), $this->validPayload([
                'email_address' => 'me@hfcoastal.co.za', 'password' => '',
            ]))
            ->assertRedirect();

        $mbx->refresh();
        $this->assertSame('user', $mbx->set_by, 'a user editing an agency-set row re-stamps provenance to user');
        $this->assertSame('me@hfcoastal.co.za', $mbx->email_address);
        $this->assertSame('Agency-Set-Pw', $mbx->encrypted_password, 'blank password keeps the current one');
    }

    public function test_malformed_email_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->post(route('my-portal.comm-capture.store'), $this->validPayload(['email_address' => 'nope']))
            ->assertSessionHasErrors('email_address');

        $this->assertSame(0, CommunicationMailbox::where('user_id', $this->user->id)->count());
    }
}
