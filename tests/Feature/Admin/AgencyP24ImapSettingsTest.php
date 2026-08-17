<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\AgencyP24ImapSetting;
use App\Models\Branch;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P24 IMAP per-agency (#3) — each agency's own P24 alert-mailbox settings
 * screen. Before this, ingestion read a single global .env mailbox shared
 * by every agency. Proves: the screen is agency-scoped, the password is
 * only overwritten when a new one is supplied, and two agencies' settings
 * never leak into each other.
 *
 * Permission convention (mirrors BackupPageTest): with role_permissions
 * unseeded, PermissionService grants every permission to a factory user.
 */
class AgencyP24ImapSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    private function makeUser(?int $agencyId = null): User
    {
        $agency = $agencyId
            ? Agency::withoutGlobalScopes()->find($agencyId)
            : Agency::create(['name' => 'T ' . uniqid(), 'slug' => 'agy-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
        ]);
    }

    public function test_edit_renders_not_configured_when_no_row_exists(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('admin.p24-imap-settings.edit'))
            ->assertOk()
            ->assertSee('P24 IMAP Settings')
            ->assertSee('Not configured');
    }

    public function test_update_creates_agency_scoped_row_with_encrypted_password(): void
    {
        $user = $this->makeUser();

        $resp = $this->actingAs($user)->put(route('admin.p24-imap-settings.update'), [
            'imap_host'       => 'imap.example.com',
            'imap_port'       => 993,
            'imap_encryption' => 'ssl',
            'imap_folder'     => 'INBOX',
            'username'        => 'alerts@example.com',
            'password'        => 'super-secret',
            'active'          => '1',
        ]);
        $resp->assertRedirect(route('admin.p24-imap-settings.edit'));

        $setting = AgencyP24ImapSetting::forAgency($user->agency_id);
        $this->assertNotNull($setting);
        $this->assertSame('imap.example.com', $setting->imap_host);
        $this->assertSame('alerts@example.com', $setting->username);
        $this->assertSame('super-secret', $setting->encrypted_password); // decrypted transparently by the cast
        $this->assertTrue($setting->active);
        $this->assertTrue($setting->isConfigured());
    }

    public function test_update_without_password_does_not_blank_the_existing_one(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->put(route('admin.p24-imap-settings.update'), [
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_folder' => 'INBOX', 'username' => 'alerts@example.com', 'password' => 'first-secret', 'active' => '1',
        ]);

        // Re-save without a password (the "leave blank to keep current" UX).
        $this->actingAs($user)->put(route('admin.p24-imap-settings.update'), [
            'imap_host' => 'imap.example.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_folder' => 'INBOX', 'username' => 'alerts@example.com', 'active' => '1',
        ]);

        $setting = AgencyP24ImapSetting::forAgency($user->agency_id);
        $this->assertSame('first-secret', $setting->encrypted_password);
    }

    public function test_two_agencies_settings_are_independent(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();

        $this->actingAs($userA)->put(route('admin.p24-imap-settings.update'), [
            'imap_host' => 'imap.agency-a.com', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_folder' => 'INBOX', 'username' => 'a@example.com', 'password' => 'secret-a', 'active' => '1',
        ]);

        // Agency B has never configured anything — its own edit page must show unconfigured,
        // never agency A's data.
        $this->actingAs($userB)
            ->get(route('admin.p24-imap-settings.edit'))
            ->assertOk()
            ->assertSee('Not configured')
            ->assertDontSee('imap.agency-a.com');

        $settingA = AgencyP24ImapSetting::forAgency($userA->agency_id);
        $settingB = AgencyP24ImapSetting::forAgency($userB->agency_id);
        $this->assertNotNull($settingA);
        $this->assertNull($settingB);
    }
}
