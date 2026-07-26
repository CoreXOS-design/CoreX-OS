<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 (audit 2026-07-21) — an assistant is ALWAYS role='assistant' and never an admin. ~20
 * authorization sites read users.role / is_admin directly (bypassing the resolver), so a drifted
 * role would silently escalate an assistant to agency-wide admin. User::saving pins it structurally.
 */
final class AssistantRolePinnedTest extends TestCase
{
    use RefreshDatabase;

    public function test_making_a_user_an_assistant_forces_the_assistant_role_and_drops_admin(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        // A co-admin so demoting the one below does not trip last-admin protection.
        User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'admin', 'is_admin' => true, 'is_assistant' => false,
        ]);

        $user = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'admin', 'is_admin' => true, 'is_assistant' => false,
        ]);

        // Someone flips the account to an assistant while leaving the admin role/flag set.
        $user->is_assistant = true;
        $user->save();

        $fresh = $user->fresh();
        $this->assertSame('assistant', $fresh->role, 'An assistant must be pinned to the assistant role.');
        $this->assertFalse((bool) $fresh->is_admin, 'An assistant must never be an admin.');
    }

    public function test_an_existing_assistant_cannot_be_promoted_to_admin(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        $assistant = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'assistant', 'is_admin' => false, 'is_assistant' => true,
        ]);

        // A crafted / careless admin edit tries to escalate the assistant.
        $assistant->role = 'admin';
        $assistant->is_admin = true;
        $assistant->save();

        $fresh = $assistant->fresh();
        $this->assertSame('assistant', $fresh->role);
        $this->assertFalse((bool) $fresh->is_admin);
    }

    public function test_a_normal_user_is_unaffected(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'admin', 'is_admin' => true, 'is_assistant' => false,
        ]);

        $this->assertSame('admin', $admin->fresh()->role);
        $this->assertTrue((bool) $admin->fresh()->is_admin);
    }
}
