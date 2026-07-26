<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\SystemUpdate;

/**
 * Authoring CRUD + the owner_only gate — spec §7, §10, §11.1.
 */
final class SystemUpdateCrudTest extends SystemUpdateTestCase
{
    public function test_an_owner_can_reach_the_index(): void
    {
        $this->actingAs($this->owner)
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee('System Updates');
    }

    public function test_a_plain_agent_is_forbidden(): void
    {
        $this->actingAs($this->agent)->get(route('admin.system-updates.index'))->assertForbidden();
        $this->actingAs($this->agent)->get(route('admin.system-updates.create'))->assertForbidden();
    }

    /** An agency admin is NOT a System Owner — the gate has no delegation path. */
    public function test_an_agency_admin_is_forbidden(): void
    {
        $this->actingAs($this->admin)->get(route('admin.system-updates.index'))->assertForbidden();
    }

    public function test_a_guest_is_redirected(): void
    {
        $this->get(route('admin.system-updates.index'))->assertRedirect();
    }

    public function test_creating_a_draft_shows_it_to_nobody(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());

        $this->actingAs($this->owner)->post(route('admin.system-updates.store'), [
            'title'    => 'Deal Register V2 now tracks settlement dates',
            'body'     => 'Every deal step shows the settlement date it is waiting on.',
            'type'     => 'improvement',
            'audience' => SystemUpdate::AUDIENCE_ALL,
        ])->assertRedirect();

        $update = SystemUpdate::firstOrFail();
        $this->assertTrue($update->isDraft());
        $this->assertNull($update->published_at);
        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    public function test_publish_now_from_the_create_form_goes_live_immediately(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());

        $this->actingAs($this->owner)->post(route('admin.system-updates.store'), [
            'title'       => 'FICA referrals can be sent to the compliance officer',
            'body'        => 'Send a submission straight to your compliance officer for review.',
            'type'        => 'feature',
            'audience'    => SystemUpdate::AUDIENCE_ALL,
            'publish_now' => '1',
        ])->assertRedirect(route('admin.system-updates.index'));

        $this->assertTrue(SystemUpdate::firstOrFail()->isPublished());
        $this->assertCount(1, $this->service()->pendingFor($this->agent));
    }

    public function test_publish_and_unpublish_toggle_visibility(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->draft();

        $this->actingAs($this->owner)->post(route('admin.system-updates.publish', $update->id))->assertRedirect();
        $this->assertCount(1, $this->service()->pendingFor($this->agent));

        $this->actingAs($this->owner)->post(route('admin.system-updates.unpublish', $update->id))->assertRedirect();
        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    /** Idempotent — republishing a live update is a no-op, not an error (spec §9.5). */
    public function test_publishing_an_already_published_update_is_a_no_op(): void
    {
        $update = $this->publish();
        $originalPublishedAt = $update->published_at;

        $this->actingAs($this->owner)->post(route('admin.system-updates.publish', $update->id))
            ->assertRedirect();

        $this->assertEquals(
            $originalPublishedAt->timestamp,
            $update->refresh()->published_at->timestamp,
            'republishing must not move published_at (it would re-show it to everyone)'
        );
    }

    /** Non-negotiable #1 — "delete" archives, and the row survives. */
    public function test_delete_archives_and_restore_brings_it_back(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $this->actingAs($this->owner)->delete(route('admin.system-updates.destroy', $update->id))->assertRedirect();

        $this->assertSoftDeleted('system_updates', ['id' => $update->id]);
        $this->assertDatabaseHas('system_updates', ['id' => $update->id]);   // never hard-deleted
        $this->assertCount(0, $this->service()->pendingFor($this->agent));

        $this->actingAs($this->owner)->post(route('admin.system-updates.restore', $update->id))->assertRedirect();

        $this->assertCount(1, $this->service()->pendingFor($this->agent));
    }

    public function test_editing_does_not_re_show_it_but_renotify_does(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish(['published_at' => now()->subWeek()]);
        $this->service()->dismiss($this->agent, [$update->id]);

        $this->actingAs($this->owner)->put(route('admin.system-updates.update', $update->id), [
            'title'    => 'Bulk-send viewing packs from the property page (typo fixed)',
            'body'     => $update->body,
            'type'     => $update->type,
            'audience' => $update->audience,
        ])->assertRedirect();

        $this->assertCount(0, $this->service()->pendingFor($this->agent), 'a typo fix must not re-interrupt everyone');

        $this->actingAs($this->owner)->post(route('admin.system-updates.renotify', $update->id))->assertRedirect();

        $this->assertCount(1, $this->service()->pendingFor($this->agent));
    }

    public function test_renotify_before_publish_is_refused_gracefully(): void
    {
        $update = $this->draft();

        $this->actingAs($this->owner)->post(route('admin.system-updates.renotify', $update->id))
            ->assertRedirect();

        $this->assertNull($update->refresh()->notify_reset_at);
    }

    public function test_show_edit_and_preview_render(): void
    {
        $update = $this->publish();

        $this->actingAs($this->owner)->get(route('admin.system-updates.show', $update->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.system-updates.edit', $update->id))->assertOk();
        $this->actingAs($this->owner)->get(route('admin.system-updates.preview', $update->id))->assertOk();
    }

    public function test_the_archived_list_renders(): void
    {
        $update = $this->publish();
        $update->delete();

        $this->actingAs($this->owner)
            ->get(route('admin.system-updates.index', ['archived' => 1]))
            ->assertOk()
            ->assertSee($update->title);
    }
}
