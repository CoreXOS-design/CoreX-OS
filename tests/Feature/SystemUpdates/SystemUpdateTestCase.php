<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\Agency;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\SystemUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared fixtures for the System Updates suite (AT-338).
 *
 * Spec: .ai/specs/system-updates.md §16
 *
 * Test data mirrors real CoreX release copy, not "Test / Test" (BUILD_STANDARD §5).
 */
abstract class SystemUpdateTestCase extends TestCase
{
    use RefreshDatabase;

    protected Agency $agency;
    protected User $owner;
    protected User $admin;
    protected User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);

        // A System Owner role is global (agency_id null) — owners are platform
        // identities, not agency members.
        //
        // forceCreate, NOT create: `is_owner` is not in Role::$fillable, so a mass
        // assignment silently drops it and the "owner" is not an owner — with no
        // error, and every owner-only assertion failing for the wrong reason.
        Role::forceCreate(['name' => 'system_owner', 'label' => 'System Owner', 'is_owner' => true, 'agency_id' => null]);
        Role::create(['name' => 'admin', 'label' => 'Office Manager', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);

        // The grants table must be non-empty or PermissionService treats the whole
        // suite as "unseeded" and allows everything — which would make every
        // audience assertion meaningless.
        RolePermission::create([
            'role'           => 'admin',
            'permission_key' => (string) config('system-updates.admin_permission'),
            'agency_id'      => $this->agency->id,
        ]);
        RolePermission::create([
            'role'           => 'agent',
            'permission_key' => 'access_properties',
            'agency_id'      => $this->agency->id,
        ]);

        $this->owner = User::factory()->create(['agency_id' => null, 'role' => 'system_owner', 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'admin', 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'role' => 'agent', 'is_active' => true]);

        PermissionService::clearCache();
        Role::clearCache();
        PermissionService::forceProductionPosture();
        SystemUpdateService::bustCache();
    }

    protected function service(): SystemUpdateService
    {
        return app(SystemUpdateService::class);
    }

    /**
     * Publish an update. `published_at` is set explicitly so tests can place it
     * before or after a user's created_at without sleeping.
     */
    protected function publish(array $attributes = []): SystemUpdate
    {
        $update = SystemUpdate::create(array_merge([
            'title'              => 'Bulk-send viewing packs from the property page',
            'body'               => "You can now send a viewing pack to several buyers at once.\nOpen any listing, tick the buyers, and press Send.",
            'type'               => 'feature',
            'audience'           => SystemUpdate::AUDIENCE_ALL,
            'status'             => SystemUpdate::STATUS_PUBLISHED,
            'published_at'       => now()->subMinute(),
            'created_by_user_id' => $this->owner->id,
        ], $attributes));

        SystemUpdateService::bustCache();

        return $update;
    }

    protected function draft(array $attributes = []): SystemUpdate
    {
        return $this->publish(array_merge([
            'status'       => SystemUpdate::STATUS_DRAFT,
            'published_at' => null,
        ], $attributes));
    }

    /** Backdate a user's created_at so published-before-I-joined rules can be exercised. */
    protected function joinedAt(User $user, \DateTimeInterface $when): User
    {
        $user->forceFill(['created_at' => $when])->save();

        return $user->refresh();
    }
}
