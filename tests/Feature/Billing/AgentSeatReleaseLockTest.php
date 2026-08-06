<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Exceptions\SeatReinstatementLockedException;
use App\Models\Agency;
use App\Models\Billing\AgentSeatRelease;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\AgentSeatLockService;
use App\Services\Billing\SubscriptionPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The 30-day seat reinstatement lock — AT-278.
 * Spec: .ai/specs/agent-seat-release-lock.md §9
 *
 * The rule this protects: CoreX bills on the LIVE seat count, so a seat freed
 * today stops being charged today. Without a hold, an agency could archive
 * eight agents on the 28th and restore them on the 1st. The bill must still
 * drop on release (we never charge for people who are gone) — what is blocked
 * is the RETURN.
 *
 * Test data is real KZN South Coast agent shapes, not Test/Test/0000000000.
 */
class AgentSeatReleaseLockTest extends TestCase
{
    use RefreshDatabase;

    private AgentSeatLockService $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock = app(AgentSeatLockService::class);
    }

    private function makeAgency(string $name = 'Home Finders Coastal'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => Str::slug($name).'-'.uniqid()]);
    }

    private function agent(Agency $agency, string $name = 'Thandeka Mkhize', array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'name'      => $name,
            'email'     => Str::slug($name).'.'.uniqid().'@hfcoastal.co.za',
            'cell'      => '082 555 0143',
            'agency_id' => $agency->id,
            'role'      => 'agent',
            'is_active' => 1,
        ], $overrides));
    }

    /**
     * A CoreX System Owner: platform identity, NULL agency, is_owner role.
     *
     * The owner row is a global template (agency_id NULL) that the schema
     * snapshot does not carry, so it is created here. `is_owner` is not
     * fillable — deliberately, it is the one hardcoded authority concept in
     * CoreX — so it is force-filled, and the Role static cache is cleared so
     * isOwnerRole() sees the new row.
     */
    private function systemOwner(): User
    {
        $ownerRole = Role::withoutGlobalScopes()->whereNull('agency_id')->where('is_owner', true)->first();

        if (! $ownerRole) {
            $ownerRole = new Role();
            $ownerRole->forceFill([
                'name'       => 'owner',
                'label'      => 'CoreX System Owner',
                'is_owner'   => true,
                'agency_id'  => null,
                'sort_order' => 0,
            ])->save();
        }

        Role::clearCache();

        return User::factory()->create([
            'name'      => 'CoreX System Owner',
            'agency_id' => null,
            'role'      => $ownerRole->name,
            'is_active' => 1,
        ]);
    }

    // ── Lock lifecycle ───────────────────────────────────────────────────────

    public function test_releasing_a_seat_opens_a_hold_stamped_thirty_days_out(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');

        $agent = $this->agent($this->makeAgency());

        $release = $this->lock->release($agent, AgentSeatRelease::REASON_DELETED, actorId: null);

        $this->assertNotNull($release);
        $this->assertSame(AgentSeatRelease::REASON_DELETED, $release->release_reason);
        $this->assertSame('2026-09-05 09:00:00', $release->reinstatable_at->toDateTimeString());
        $this->assertTrue($this->lock->isLocked($agent));

        Carbon::setTestNow();
    }

    public function test_release_is_idempotent_so_delete_then_deactivate_does_not_restack_the_clock(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agent = $this->agent($this->makeAgency());

        $first = $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        // Five days later, the same agent is also toggled inactive.
        Carbon::setTestNow('2026-08-11 09:00:00');
        $second = $this->lock->release($agent, AgentSeatRelease::REASON_DEACTIVATED);

        $this->assertSame($first->id, $second->id, 'A second release must reuse the open hold, not stack a new one.');
        $this->assertSame(1, AgentSeatRelease::where('user_id', $agent->id)->count());
        // The clock runs from the FIRST release — the moment the seat became free.
        $this->assertSame('2026-09-05 09:00:00', $second->fresh()->reinstatable_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_assistants_and_agency_less_users_are_never_held(): void
    {
        $agency = $this->makeAgency();

        $assistant = $this->agent($agency, 'Lerato Dube', ['is_assistant' => 1]);
        $platform  = $this->agent($agency, 'Platform User', ['agency_id' => null]);

        $this->assertNull($this->lock->release($assistant, AgentSeatRelease::REASON_DELETED));
        $this->assertNull($this->lock->release($platform, AgentSeatRelease::REASON_DELETED));
        $this->assertSame(0, AgentSeatRelease::count());
        $this->assertFalse($this->lock->isLocked($assistant));
    }

    // ── The block ────────────────────────────────────────────────────────────

    public function test_reinstating_inside_the_window_is_refused_with_the_unlock_date(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agent = $this->agent($this->makeAgency(), 'Sipho Ngcobo');
        $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        Carbon::setTestNow('2026-08-20 09:00:00');   // 14 days in

        try {
            $this->lock->assertCanReinstate($agent, actor: null);
            $this->fail('Expected the hold to refuse reinstatement.');
        } catch (SeatReinstatementLockedException $e) {
            $this->assertStringContainsString('Sipho Ngcobo', $e->getMessage());
            $this->assertStringContainsString('5 September 2026', $e->getMessage());
            $this->assertStringContainsString('16 days', $e->getMessage());
        }

        Carbon::setTestNow();
    }

    public function test_reinstating_after_the_window_succeeds_and_closes_the_row_as_elapsed(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agent = $this->agent($this->makeAgency());
        $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        Carbon::setTestNow('2026-09-06 09:00:00');   // 31 days later

        $this->lock->assertCanReinstate($agent, actor: null);   // must not throw
        $this->lock->reinstate($agent, actor: null);

        $row = AgentSeatRelease::where('user_id', $agent->id)->first();
        $this->assertNotNull($row->reinstated_at);
        $this->assertSame(AgentSeatRelease::VIA_ELAPSED, $row->reinstated_via);
        $this->assertFalse($this->lock->isLocked($agent->fresh()));

        Carbon::setTestNow();
    }

    public function test_the_observer_backstop_blocks_a_restore_from_any_caller(): void
    {
        $agent = $this->agent($this->makeAgency());
        AgentSeatLockService::bypass(fn () => $agent->delete());
        $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        // A path that never heard of this rule — a console fix, a future controller.
        $this->expectException(SeatReinstatementLockedException::class);
        User::onlyTrashed()->findOrFail($agent->id)->restore();
    }

    /**
     * Real regression: the generic Admin → Soft Deletes register calls
     * $record->restore() directly with no way to pass an actor or override
     * reason down to UserObserver::restoring() — exactly the "future
     * controller" the previous test anticipates. The backstop correctly
     * refuses (spec §5.1 point 7); the bug was that SeatReinstatementLockedException
     * went uncaught there and surfaced as a raw 500 instead of the same plain
     * CoreX message shown everywhere else.
     */
    public function test_the_generic_soft_deletes_restore_path_surfaces_the_hold_instead_of_crashing(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agency = $this->makeAgency();
        $leaver = $this->agent($agency, 'Barbara Jackson');

        AgentSeatLockService::bypass(fn () => $leaver->delete());
        $this->lock->release($leaver, AgentSeatRelease::REASON_DELETED);

        $owner = $this->systemOwner();

        // RequireAgencyContext (agency.required) needs an active agency for a
        // System Owner (agency_id is NULL) — unrelated to the lock itself.
        $response = $this->withSession(['active_agency_id' => $agency->id])
            ->actingAs($owner)
            ->post(route('admin.soft-deletes.restore', ['User', $leaver->id]));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('cannot be reinstated', session('error'));
        $this->assertSoftDeleted('users', ['id' => $leaver->id]);

        Carbon::setTestNow();
    }

    public function test_the_observer_backstop_blocks_a_raw_is_active_reactivation(): void
    {
        $agent = $this->agent($this->makeAgency());
        AgentSeatLockService::bypass(fn () => $agent->update(['is_active' => false]));
        $this->lock->release($agent, AgentSeatRelease::REASON_DEACTIVATED);

        $this->expectException(SeatReinstatementLockedException::class);
        $agent->fresh()->update(['is_active' => true]);
    }

    // ── The override ─────────────────────────────────────────────────────────

    public function test_a_system_owner_can_lift_the_hold_with_a_reason(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agent = $this->agent($this->makeAgency());
        $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        Carbon::setTestNow('2026-08-08 09:00:00');
        $owner  = $this->systemOwner();
        $reason = 'Archived in error — agent never resigned, confirmed with the principal.';

        $this->lock->assertCanReinstate($agent, $owner, $reason);   // must not throw
        $this->lock->reinstate($agent, $owner, $reason);

        $row = AgentSeatRelease::where('user_id', $agent->id)->first();
        $this->assertSame(AgentSeatRelease::VIA_OWNER_OVERRIDE, $row->reinstated_via);
        $this->assertSame($reason, $row->override_reason);
        $this->assertSame($owner->id, $row->reinstated_by_user_id);

        Carbon::setTestNow();
    }

    public function test_a_system_owner_without_a_real_reason_is_refused(): void
    {
        $agent = $this->agent($this->makeAgency());
        $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        $this->expectException(SeatReinstatementLockedException::class);
        $this->expectExceptionMessageMatches('/at least 10 characters/');
        $this->lock->assertCanReinstate($agent, $this->systemOwner(), 'oops');
    }

    /** The authority is deliberately NOT delegable — manage_users must not buy it. */
    public function test_an_agency_admin_cannot_override_even_holding_manage_users(): void
    {
        $agency = $this->makeAgency();
        $agent  = $this->agent($agency);
        $this->lock->release($agent, AgentSeatRelease::REASON_DELETED);

        $admin = $this->agent($agency, 'Agency Admin', ['role' => 'admin', 'is_admin' => 1]);

        $this->expectException(SeatReinstatementLockedException::class);
        $this->expectExceptionMessageMatches('/Only CoreX Dev/');
        $this->lock->assertCanReinstate($agent, $admin, 'A perfectly long-sounding reason here.');
    }

    // ── The HTTP paths an admin actually uses ────────────────────────────────

    /**
     * The schema snapshot carries no role rows, so `Rule::in(Role::roleNames())`
     * on the create-user form rejects every role until one exists. Seed the
     * global template row the form validates against.
     */
    private function ensureRole(string $name): void
    {
        if (! Role::withoutGlobalScopes()->whereNull('agency_id')->where('name', $name)->exists()) {
            (new Role())->forceFill([
                'name'      => $name,
                'label'     => ucfirst($name),
                'is_owner'  => false,
                'agency_id' => null,
            ])->save();
        }

        Role::clearCache();
    }

    /** An agency admin holding manage_users — the real actor on these screens. */
    private function adminFor(Agency $agency): User
    {
        \App\Models\RolePermission::insert([[
            'role' => 'admin', 'permission_key' => 'manage_users', 'scope' => null,
            'agency_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]]);
        \App\Services\PermissionService::clearCache();

        return $this->agent($agency, 'Nomsa Zulu', ['role' => 'admin', 'is_admin' => 1]);
    }

    /**
     * Regression for the reinstate()-before-save() ordering bug: updateRole()
     * used to close the agent_seat_releases hold BEFORE $user->save() had
     * actually persisted is_active = 1. If save() then failed for any unrelated
     * reason, the hold was left marked closed while the user stayed deactivated
     * in the DB — silently defeating the lock for good, since a later
     * reactivation would find no open hold at all. reinstate() must only run
     * once save() has actually succeeded, exactly as toggle() and restore()
     * already do it.
     */
    public function test_updateRole_reinstate_only_fires_after_a_successful_save(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('admin');

        $target = $this->agent($agency, 'Precious Mthembu', ['is_active' => 0]);
        $this->lock->release($target, AgentSeatRelease::REASON_DEACTIVATED, $admin->id);

        Carbon::setTestNow('2026-09-10 09:00:00');   // 35 days later — the hold has elapsed
        $this->assertFalse($this->lock->isLocked($target->fresh()));

        User::saving(function (User $u) use ($target) {
            if ($u->id === $target->id) {
                throw new \RuntimeException('Simulated save failure for AT-278 regression test');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->post(route('admin.users.role.update', $target), [
                'cell' => '083 555 0123',
                'role' => 'admin',
            ]);
            $this->fail('Expected the simulated save failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated save failure for AT-278 regression test', $e->getMessage());
        }

        $row = AgentSeatRelease::where('user_id', $target->id)->first();
        $this->assertNull(
            $row->reinstated_at,
            'A failed save must not close the hold — reinstate() must run AFTER save() succeeds, not before.'
        );
        $this->assertFalse((bool) $target->fresh()->is_active, 'is_active must not have persisted since save() failed.');

        Carbon::setTestNow();
    }

    /**
     * The main /admin/users Activate toggle is the ONLY path back for an agent
     * who was merely deactivated (is_active = 0) and never soft-deleted —
     * Archived Agents only lists onlyTrashed() rows, so a locked-but-not-deleted
     * agent never appears there, and the override must work here too or a
     * System Owner has no way back in for this class of agent at all.
     */
    public function test_toggle_owner_can_override_a_locked_deactivation_with_a_reason(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agency = $this->makeAgency();
        $agent  = $this->agent($agency, 'Zodwa Khumalo', ['is_active' => 0]);
        $this->lock->release($agent, AgentSeatRelease::REASON_DEACTIVATED);

        Carbon::setTestNow('2026-08-08 09:00:00');   // still well inside the 30-day hold
        $owner  = $this->systemOwner();
        $reason = 'Deactivated in error — confirmed with the principal by phone.';

        $this->actingAs($owner)
            ->post(route('admin.users.toggle', $agent), ['override_reason' => $reason])
            ->assertSessionDoesntHaveErrors();

        $agent->refresh();
        $this->assertTrue((bool) $agent->is_active);
        $this->assertFalse($this->lock->isLocked($agent));

        $row = AgentSeatRelease::where('user_id', $agent->id)->first();
        $this->assertSame(AgentSeatRelease::VIA_OWNER_OVERRIDE, $row->reinstated_via);
        $this->assertSame($reason, $row->override_reason);

        Carbon::setTestNow();
    }

    /** Same control, no reason supplied — even a System Owner is refused. */
    public function test_toggle_owner_without_a_reason_is_still_refused(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agency = $this->makeAgency();
        $agent  = $this->agent($agency, 'Zodwa Khumalo', ['is_active' => 0]);
        $this->lock->release($agent, AgentSeatRelease::REASON_DEACTIVATED);

        Carbon::setTestNow('2026-08-08 09:00:00');
        $owner = $this->systemOwner();

        $this->actingAs($owner)
            ->post(route('admin.users.toggle', $agent))
            ->assertSessionHasErrors();

        $this->assertFalse((bool) $agent->fresh()->is_active);

        Carbon::setTestNow();
    }

    public function test_the_archived_page_renders_and_shows_the_hold_with_its_unlock_date(): void
    {
        Carbon::setTestNow('2026-08-06 09:00:00');
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $leaver = $this->agent($agency, 'Bongani Cele');

        AgentSeatLockService::bypass(fn () => $leaver->delete());
        $this->lock->release($leaver, AgentSeatRelease::REASON_DELETED, $admin->id);

        $this->actingAs($admin)->get(route('admin.users.archived'))
            ->assertOk()
            ->assertSee('Bongani Cele')
            ->assertSee('Held until 5 Sep 2026');

        Carbon::setTestNow();
    }

    public function test_posting_restore_inside_the_window_is_refused_and_the_user_stays_archived(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $leaver = $this->agent($agency, 'Bongani Cele');

        AgentSeatLockService::bypass(fn () => $leaver->delete());
        $this->lock->release($leaver, AgentSeatRelease::REASON_DELETED, $admin->id);

        $this->actingAs($admin)
            ->post(route('admin.users.restore', $leaver->id))
            ->assertSessionHasErrors();

        $this->assertSoftDeleted('users', ['id' => $leaver->id]);
    }

    /**
     * THE regression that matters most: store() used to forceDelete() the
     * archived user with this email — a hard delete (non-negotiable #1) and a
     * one-form bypass of the entire hold.
     */
    public function test_recreating_the_same_email_is_refused_and_never_hard_deletes_the_archived_user(): void
    {
        $agency = $this->makeAgency();
        $admin  = $this->adminFor($agency);
        $this->ensureRole('agent');
        $leaver = $this->agent($agency, 'Bongani Cele', ['email' => 'bongani.cele@hfcoastal.co.za']);

        AgentSeatLockService::bypass(fn () => $leaver->delete());
        $this->lock->release($leaver, AgentSeatRelease::REASON_DELETED, $admin->id);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name'    => 'Bongani',
            'surname' => 'Cele',
            'email'   => 'bongani.cele@hfcoastal.co.za',
            'cell'    => '083 555 0198',
            'role'    => 'agent',
        ])->assertSessionHasErrors('email');

        // The archived record must still be there — soft-deleted, not destroyed.
        $this->assertDatabaseHas('users', ['id' => $leaver->id, 'email' => 'bongani.cele@hfcoastal.co.za']);
        $this->assertSoftDeleted('users', ['id' => $leaver->id]);
        // ...and no second account was created for the same person.
        $this->assertSame(1, User::withTrashed()->where('email', 'bongani.cele@hfcoastal.co.za')->count());
    }

    // ── Billing integration — the point of the ticket ────────────────────────

    public function test_the_bill_drops_immediately_on_release_but_the_seat_cannot_bounce_back(): void
    {
        $pricing = app(SubscriptionPricingService::class);
        $agency  = $this->makeAgency();

        foreach (range(1, 10) as $i) {
            $this->agent($agency, "Agent {$i}");
        }
        $this->assertSame(10, $pricing->billableSeats($agency));

        // Archive one — the agency stops paying for them TODAY (spec D2).
        $leaver = User::where('agency_id', $agency->id)->orderBy('id')->first();
        AgentSeatLockService::bypass(fn () => $leaver->update(['is_active' => false]));
        $this->lock->release($leaver, AgentSeatRelease::REASON_DEACTIVATED);

        $this->assertSame(9, $pricing->billableSeats($agency->fresh()));

        // ...but they cannot be put straight back to game the next billing read.
        $this->assertTrue($this->lock->isLocked($leaver->fresh()));
        $this->expectException(SeatReinstatementLockedException::class);
        $this->lock->assertCanReinstate($leaver->fresh(), actor: null);
    }
}
