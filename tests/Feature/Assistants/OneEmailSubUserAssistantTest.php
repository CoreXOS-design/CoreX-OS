<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Mail\UserInviteMail;
use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Database\Seeders\AssistantRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-423 — One email (sub-users) on the Assistants screens.
 * Spec: .ai/specs/one-email-sub-users.md §6.2 / §6.5 / §6.7.
 *
 * An assistant can sign in with a username that shares the agency inbox, exactly like
 * any other user — same username rules, same invite-to-the-shared-inbox, same
 * admin-only temporary-password reset.
 */
final class OneEmailSubUserAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('db:seed', ['--class' => AssistantRoleSeeder::class, '--force' => true]);
        Artisan::call('corex:sync-permissions');
        Mail::fake();

        $this->agency = Agency::create([
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(),
            'assistants_enabled' => true, 'assistant_fica_required_default' => true,
        ]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        foreach (['admin', 'agent'] as $r) {
            Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id]);
        }
        foreach (['assistants.view', 'assistants.create'] as $k) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $k, 'agency_id' => $this->agency->id]);
        }

        $this->admin = User::factory()->create([
            'name' => 'Johan Reichel', 'email' => 'johan@hfcoastal.co.za',
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true,
        ]);
        $this->agent = User::factory()->create([
            'name' => 'Sarah Nkosi', 'email' => 'agents@hfcoastal.co.za',
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true,
        ]);

        // Sarah's address is the shared inbox.
        $this->agency->forceFill(['one_email_enabled' => true, 'one_email_user_id' => $this->agent->id])->save();
        Agency::forgetFindMemo();

        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    private function createAssistant(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(route('admin.assistants.store'), array_merge([
            'name' => 'Thandi', 'surname' => 'Mokoena',
            'sign_in_type' => 'username', 'username' => 'Thandi',
            'email' => '', 'cell' => '083 555 0142',
            'agent_user_id' => $this->agent->id,
        ], $overrides));
    }

    public function test_an_assistant_can_be_a_sub_user(): void
    {
        $this->createAssistant()->assertRedirect()->assertSessionHas('invite_username', 'thandi@hfcoastal');

        $thandi = User::where('email', 'thandi@hfcoastal')->firstOrFail();
        $this->assertTrue($thandi->isSubUser());
        $this->assertTrue($thandi->is_assistant);
        $this->assertSame('assistant', $thandi->role);

        Mail::assertSent(UserInviteMail::class, fn (UserInviteMail $m) => $m->hasTo('agents@hfcoastal.co.za'));
    }

    public function test_a_bad_assistant_username_is_rejected_without_creating_anyone(): void
    {
        $this->createAssistant(['username' => 'thandi mokoena!'])->assertSessionHasErrors('username');
        $this->assertSame(0, AssistantAssignment::count());
    }

    public function test_the_assistant_edit_screen_offers_the_reset_and_it_works(): void
    {
        $this->createAssistant();
        $thandi = User::where('email', 'thandi@hfcoastal')->firstOrFail();
        $assignment = AssistantAssignment::where('assistant_user_id', $thandi->id)->firstOrFail();

        $this->actingAs($this->admin)->get(route('admin.assistants.edit', $assignment))
            ->assertOk()->assertSee('A username, sharing an inbox')->assertSee('Reset password');

        $this->actingAs($this->admin)->put(route('admin.assistants.update', $assignment), [
            'name' => 'Thandi', 'surname' => 'Mokoena', 'cell' => '083 555 0142',
            'sign_in_type' => 'username', 'username' => 'thandi',
            'password' => 'Temp-5678', 'password_confirmation' => 'Temp-5678',
        ])->assertSessionHasNoErrors();

        $thandi->refresh();
        $this->assertSame('thandi@hfcoastal', $thandi->email);
        $this->assertTrue((bool) $thandi->must_change_password);
    }

    public function test_an_email_assistant_is_unchanged(): void
    {
        $this->createAssistant([
            'sign_in_type' => 'email', 'username' => '', 'email' => 'lerato.dube@hfcoastal.co.za',
        ])->assertRedirect();

        $lerato = User::where('email', 'lerato.dube@hfcoastal.co.za')->firstOrFail();
        $this->assertFalse($lerato->isSubUser());
        Mail::assertSent(UserInviteMail::class, fn (UserInviteMail $m) => $m->hasTo('lerato.dube@hfcoastal.co.za'));
    }
}
