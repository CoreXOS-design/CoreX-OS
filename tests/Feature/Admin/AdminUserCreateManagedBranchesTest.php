<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The "Branches managed" boxes on the user form used to save on EDIT only — on CREATE they showed
 * and did nothing. Create now saves them through the same routine as edit
 * (UserManagementController::applyManagedBranches). Spec admin-multi-branch-manager.md.
 */
final class AdminUserCreateManagedBranchesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $b1;
    private Branch $b2;
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        foreach (['admin', 'agent'] as $name) {
            (new Role())->forceFill(['name' => $name, 'label' => ucfirst($name), 'is_owner' => false, 'agency_id' => null])->save();
        }
        Role::clearCache();
        RolePermission::insert([[
            'role' => 'admin', 'permission_key' => 'manage_users', 'scope' => null,
            'agency_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        PermissionService::clearCache();

        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->b1 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->b2 = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Scottburgh']);
        $this->editor = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->b1->id, 'role' => 'admin', 'is_admin' => 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function form(string $email, string $role, array $extra = []): array
    {
        return array_merge([
            'name' => 'Thandi', 'surname' => 'Mokoena', 'email' => $email, 'cell' => '083 555 0123',
            'role' => $role, 'branch_id' => $this->b1->id,
        ], $extra);
    }

    public function test_creating_an_admin_saves_the_ticked_branches_and_the_default(): void
    {
        $this->actingAs($this->editor)->post(route('admin.users.store'), $this->form('thandi@coastal.test', 'admin', [
            'managed_branches' => [$this->b1->id, $this->b2->id],
            'default_branch_id' => $this->b2->id,
        ]))->assertSessionDoesntHaveErrors();

        $created = User::where('email', 'thandi@coastal.test')->firstOrFail();

        $this->assertTrue($created->isManagerOfBranch($this->b1->id));
        $this->assertTrue($created->isManagerOfBranch($this->b2->id));
        $this->assertSame($this->b2->id, $created->defaultManagedBranchId());
        $this->assertSame(1, DB::table('user_managed_branches')->where('user_id', $created->id)->where('is_default', 1)->count());
    }

    public function test_creating_an_admin_with_no_default_marked_defaults_to_the_first_ticked_branch(): void
    {
        $this->actingAs($this->editor)->post(route('admin.users.store'), $this->form('thandi@coastal.test', 'admin', [
            'managed_branches' => [$this->b2->id],
        ]))->assertSessionDoesntHaveErrors();

        $created = User::where('email', 'thandi@coastal.test')->firstOrFail();

        $this->assertSame($this->b2->id, $created->defaultManagedBranchId());
    }

    public function test_a_branch_from_another_agency_is_dropped_on_create(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rival-' . uniqid()]);
        $foreign = Branch::create(['agency_id' => $rival->id, 'name' => 'Foreign']);

        $this->actingAs($this->editor)->post(route('admin.users.store'), $this->form('thandi@coastal.test', 'admin', [
            'managed_branches' => [$this->b1->id, $foreign->id],
            'default_branch_id' => $foreign->id,
        ]))->assertSessionDoesntHaveErrors();

        $created = User::where('email', 'thandi@coastal.test')->firstOrFail();

        $this->assertDatabaseMissing('user_managed_branches', ['user_id' => $created->id, 'branch_id' => $foreign->id]);
        $this->assertSame($this->b1->id, $created->defaultManagedBranchId());
    }

    public function test_a_non_admin_created_with_ticked_branches_manages_nothing(): void
    {
        $this->actingAs($this->editor)->post(route('admin.users.store'), $this->form('sipho@coastal.test', 'agent', [
            'managed_branches' => [$this->b1->id],
            'default_branch_id' => $this->b1->id,
        ]))->assertSessionDoesntHaveErrors();

        $created = User::where('email', 'sipho@coastal.test')->firstOrFail();

        $this->assertSame(0, DB::table('user_managed_branches')->where('user_id', $created->id)->count());
    }

    public function test_creating_an_admin_with_no_ticks_manages_nothing_and_still_succeeds(): void
    {
        $this->actingAs($this->editor)->post(route('admin.users.store'), $this->form('thandi@coastal.test', 'admin'))
            ->assertSessionDoesntHaveErrors();

        $created = User::where('email', 'thandi@coastal.test')->firstOrFail();

        $this->assertSame(0, DB::table('user_managed_branches')->where('user_id', $created->id)->count());
    }

    public function test_the_create_form_keeps_the_ticks_when_the_save_bounced(): void
    {
        $this->actingAs($this->editor)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $this->form('not-an-email', 'admin', [
                'managed_branches' => [$this->b2->id],
                'default_branch_id' => $this->b2->id,
            ]))
            ->assertSessionHasErrors('email');

        $html = $this->actingAs($this->editor)
            ->withSession(['_old_input' => ['managed_branches' => [(string) $this->b2->id], 'default_branch_id' => (string) $this->b2->id, 'role' => 'admin']])
            ->get(route('admin.users.create'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/name="managed_branches\[\]" value="' . $this->b2->id . '" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="managed_branches\[\]" value="' . $this->b1->id . '" checked/', $html);
        $this->assertMatchesRegularExpression('/name="default_branch_id" value="' . $this->b2->id . '" checked/', $html);
    }
}
