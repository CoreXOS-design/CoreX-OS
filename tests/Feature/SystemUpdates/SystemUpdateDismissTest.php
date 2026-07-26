<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\SystemUpdate;

/**
 * The dismiss endpoint — spec §11.2, §9.5.
 *
 * Self-scoped, idempotent, and deliberately forgiving: a stale modal must never
 * hand the user an error for closing it.
 */
final class SystemUpdateDismissTest extends SystemUpdateTestCase
{
    public function test_it_dismisses_every_id_the_modal_showed(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $a = $this->publish(['title' => 'Release note A']);
        $b = $this->publish(['title' => 'Release note B']);
        $c = $this->publish(['title' => 'Release note C']);

        $this->actingAs($this->agent)
            ->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$a->id, $b->id, $c->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'recorded' => 3]);

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
        $this->assertDatabaseCount('system_update_views', 3);
    }

    public function test_it_is_idempotent(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $this->actingAs($this->agent)->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$update->id]])->assertOk();
        $this->actingAs($this->agent)->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$update->id]])->assertOk();

        $this->assertDatabaseCount('system_update_views', 1);
    }

    public function test_an_unknown_id_is_ignored_not_rejected(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [999999]])
            ->assertOk()
            ->assertJson(['ok' => true, 'recorded' => 0]);
    }

    /** Archived while the modal was open — closing it must still succeed. */
    public function test_an_archived_id_is_ignored_not_rejected(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();
        $update->delete();

        $this->actingAs($this->agent)
            ->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$update->id]])
            ->assertOk()
            ->assertJson(['recorded' => 0]);
    }

    /** A non-admin cannot acknowledge — or even touch — an admin-only update. */
    public function test_an_out_of_audience_id_is_ignored(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);

        $this->actingAs($this->agent)
            ->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$update->id]])
            ->assertOk()
            ->assertJson(['recorded' => 0]);

        $this->assertDatabaseCount('system_update_views', 0);
    }

    public function test_it_only_ever_writes_for_the_authenticated_user(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->joinedAt($this->admin, now()->subMonth());
        $update = $this->publish();

        $this->actingAs($this->agent)
            ->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$update->id]])
            ->assertOk();

        $this->assertDatabaseHas('system_update_views', [
            'system_update_id' => $update->id, 'user_id' => $this->agent->id,
        ]);
        $this->assertDatabaseMissing('system_update_views', [
            'system_update_id' => $update->id, 'user_id' => $this->admin->id,
        ]);
    }

    public function test_a_guest_cannot_dismiss(): void
    {
        $update = $this->publish();

        $this->postJson(route('api.v1.system-updates.dismiss'), ['ids' => [$update->id]])
            ->assertUnauthorized();
    }

    public function test_a_missing_ids_payload_is_a_validation_error(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('api.v1.system-updates.dismiss'), [])
            ->assertStatus(422);
    }
}
