<?php

declare(strict_types=1);

namespace Tests\Feature\Permissions;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AT-265 — PermissionService must FAIL CLOSED on an empty `role_permissions` table.
 *
 * THE DEFECT: an empty grants table granted EVERYONE EVERYTHING, platform-wide, silently. Any
 * deploy, seed, migration or reconcile accident that emptied that table removed all permission
 * enforcement — and nothing logged it, so nothing would have noticed. `RolePermission` uses
 * SoftDeletes and `exists()` cannot see trashed rows, so a reconcile that soft-deleted every grant
 * read as "unseeded" and would have tripped exactly this.
 *
 * THE CONTROL IS ISOLATED. The entire test suite is written against an unseeded table and relies on
 * the historic allow-all (tests/TestCase.php documents this, and ~40 test files state it as their
 * premise). The production posture therefore keys off the environment and is unreachable on a
 * server — and these tests opt INTO it explicitly, per test, via forceProductionPosture(). The
 * TestCase::setUp() clearCache() resets the posture, so it cannot leak into any other test.
 *
 * Paths proven: deny on empty table (permission + data scope) · alarm logged with the fix in it ·
 * owner break-glass still gets in · break-glass is audited · break-glass NOT logged on a healthy
 * system · a real grant is still honoured · a real DENIAL is still a denial · a soft-deleted grant
 * set reads as empty and denies (the reconcile accident) · the alarm cannot break the check.
 */
final class PermissionFailClosedTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Port Shepstone']);

        Cache::flush(); // the alarm + break-glass throttles are cache-backed
    }

    private function agent(): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    /**
     * The platform owner — the break-glass operator.
     *
     * `is_owner` is deliberately NOT in Role::$fillable, so a mass-assigned Role::create([...
     * 'is_owner' => true]) silently produces a NON-owner role (the privilege flag is guarded, which
     * is right). It has to be set explicitly, or this fixture quietly tests the wrong thing.
     */
    private function owner(): User
    {
        $role = new Role(['name' => 'super_admin', 'label' => 'Super Admin', 'agency_id' => null]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'super_admin',
        ]);
    }

    // ── The defect itself ────────────────────────────────────────────────────────────────────

    /** THE BUG: an empty grants table used to return TRUE here — for every key, for every user. */
    public function test_an_empty_grants_table_denies_instead_of_granting_everything(): void
    {
        PermissionService::forceProductionPosture();

        $this->assertSame(0, RolePermission::count(), 'precondition: the grants table is empty');

        $agent = $this->agent();

        $this->assertFalse(PermissionService::userHasPermission($agent, 'deals.settle'),
            'An empty grants table must DENY. Pre-AT-265 this returned true and handed out settle_deals.');
        $this->assertFalse(PermissionService::userHasPermission($agent, 'admin.access'));
        $this->assertFalse(PermissionService::userHasAnyPermission($agent, ['deals.settle', 'admin.access']));
    }

    /**
     * The SECOND fail-open, which the ticket did not name: getDataScope() handed 'all' to admins
     * and 'branch' to branch managers off the same empty table — a data-visibility grant nobody made.
     */
    public function test_an_empty_grants_table_denies_the_data_scope_too(): void
    {
        PermissionService::forceProductionPosture();

        $admin = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);

        $this->assertNull(PermissionService::getDataScope($admin, 'properties'),
            "Pre-AT-265 an 'admin' got scope 'all' over every property on an unseeded table.");
        $this->assertNull(PermissionService::getDataScope($admin, 'deals'));
    }

    /**
     * The reconcile accident, concretely. RolePermission is a SoftDeletes model, so a command that
     * soft-deletes every grant leaves the rows in the table but makes exists() return false — which
     * read as "fresh install, allow everything".
     */
    public function test_soft_deleting_every_grant_denies_rather_than_opening_the_platform(): void
    {
        PermissionService::forceProductionPosture();

        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.settle', 'scope' => null, 'agency_id' => null]);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $agent = $this->agent();
        $this->assertTrue(PermissionService::userHasPermission($agent, 'deals.settle'), 'precondition: the grant works');

        // Someone runs a reconcile that soft-deletes the lot.
        RolePermission::query()->delete();
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->assertSame(1, DB::table('role_permissions')->count(), 'the row is still there, just trashed');
        $this->assertFalse(PermissionService::userHasPermission($agent, 'deals.settle'),
            'A soft-deleted grant set reads as empty — and must DENY, not open the platform.');
    }

    // ── The alarm ────────────────────────────────────────────────────────────────────────────

    /**
     * A locked-down platform is never a silent one — and the log says how to fix it.
     *
     * Log is SPIED, not strict-mocked: a strict mock intercepts every Log:: call made anywhere in
     * the request (factories, listeners, the framework), so it fails on calls that have nothing to
     * do with the assertion. A spy records and lets everything through.
     */
    public function test_the_denial_raises_a_critical_alarm_on_the_security_channel(): void
    {
        PermissionService::forceProductionPosture();
        $agent = $this->agent();

        $channel = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->with('security')->andReturn($channel);
        Log::shouldReceive('channel')->andReturn($channel);

        $this->assertFalse(PermissionService::userHasPermission($agent, 'deals.settle'));

        $channel->shouldHaveReceived('critical')
            ->withArgs(fn (string $message, array $ctx = []) => str_contains($message, 'PERMISSION LOCKDOWN')
                // The alarm carries its own remedy — whoever is paged at 22:00 should not have to
                // go and find out how to fix it.
                && str_contains($message, 'deploy:sync-reference-data'));
    }

    // ── Break-glass ──────────────────────────────────────────────────────────────────────────

    /**
     * The owner bypass is PRESERVED on purpose. If permissions vanish on live at 22:00, somebody has
     * to be able to log in and run the reprovision. A system that locks out the one person who can
     * fix it has swapped a security hole for an outage with no exit.
     */
    public function test_the_owner_break_glass_still_gets_in_when_the_table_is_empty(): void
    {
        PermissionService::forceProductionPosture();

        $owner = $this->owner();

        $this->assertTrue(PermissionService::userHasPermission($owner, 'deals.settle'));
        $this->assertSame('all', PermissionService::getDataScope($owner, 'properties'));
    }

    /** Break-glass that leaves no trace is just a back door. */
    public function test_the_owner_break_glass_is_audited(): void
    {
        PermissionService::forceProductionPosture();
        $owner = $this->owner();

        $channel = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($channel);

        $this->assertTrue(PermissionService::userHasPermission($owner, 'deals.settle'));

        $channel->shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $ctx = []) => str_contains($message, 'BREAK-GLASS')
                && ($ctx['user_id'] ?? null) === $owner->id);
    }

    /**
     * An owner on a HEALTHY system is just an owner, not break-glass. Logging every owner request
     * would bury the one line that matters under millions that don't.
     */
    public function test_an_owner_on_a_healthy_system_is_not_logged_as_break_glass(): void
    {
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.view', 'scope' => 'own', 'agency_id' => null]);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $owner = $this->owner();

        $channel = \Mockery::spy(\Psr\Log\LoggerInterface::class);
        Log::shouldReceive('channel')->andReturn($channel);

        $this->assertTrue(PermissionService::userHasPermission($owner, 'deals.settle'));

        $channel->shouldNotHaveReceived('warning');
        $channel->shouldNotHaveReceived('critical');
    }

    // ── Still a permission system ────────────────────────────────────────────────────────────

    /** Fail-closed must not become deny-everything: a real grant is still honoured. */
    public function test_a_real_grant_is_still_honoured_under_the_production_posture(): void
    {
        RolePermission::create(['role' => 'agent', 'permission_key' => 'deals.settle', 'scope' => null, 'agency_id' => null]);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->assertTrue(PermissionService::userHasPermission($this->agent(), 'deals.settle'));
    }

    /** …and a real denial is still a denial (a populated table the role is simply not in). */
    public function test_a_role_without_the_grant_is_still_denied(): void
    {
        RolePermission::create(['role' => 'branch_manager', 'permission_key' => 'deals.settle', 'scope' => null, 'agency_id' => null]);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->assertFalse(PermissionService::userHasPermission($this->agent(), 'deals.settle'));
    }

    // ── The suite's own premise is untouched ─────────────────────────────────────────────────

    /**
     * The isolation itself. WITHOUT forceProductionPosture(), the test-suite posture still allows —
     * which is what the other ~40 test files are built on. If this ever fails, this change has just
     * redlined the suite.
     */
    public function test_the_test_suite_posture_is_unchanged_when_not_forced(): void
    {
        $this->assertSame(0, RolePermission::count());

        $this->assertTrue(PermissionService::userHasPermission($this->agent(), 'deals.settle'),
            'The suite convention (unseeded → allow) must survive, or every other test file breaks.');
        $this->assertSame('own', PermissionService::getDataScope($this->agent(), 'properties'));
    }

    /** The posture must not leak between tests — clearCache() resets it (TestCase calls it in setUp). */
    public function test_the_forced_posture_does_not_leak(): void
    {
        PermissionService::forceProductionPosture();
        $this->assertFalse(PermissionService::userHasPermission($this->agent(), 'deals.settle'));

        PermissionService::clearCache(); // what TestCase::setUp() does before every test

        $this->assertTrue(PermissionService::userHasPermission($this->agent(), 'deals.settle'),
            'clearCache() must restore the environment-derived posture, or one test poisons the next.');
    }
}
