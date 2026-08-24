<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\SystemUpdate;
use App\Models\SystemUpdateView;

/**
 * The eligibility rule in full — spec §8.1.
 *
 * This is the file that decides whether the feature works at all: everything else
 * is chrome around "should this user see this update right now?".
 */
final class SystemUpdateVisibilityTest extends SystemUpdateTestCase
{
    public function test_a_published_update_is_pending_for_an_existing_user(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $pending = $this->service()->pendingFor($this->agent);

        $this->assertCount(1, $pending);
        $this->assertTrue($pending->first()->is($update));
    }

    public function test_a_draft_is_never_pending(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->draft();

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    public function test_a_future_dated_update_is_not_pending_yet(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['published_at' => now()->addDay()]);

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    /**
     * Spec §8.1 rule 3 — the load-bearing one. Without it a new joiner meets every
     * historical release note on their first morning.
     */
    public function test_a_user_never_sees_updates_published_before_they_joined(): void
    {
        $this->publish(['published_at' => now()->subYear()]);
        $this->joinedAt($this->agent, now()->subDay());

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    public function test_a_dismissed_update_is_not_pending_again(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $this->service()->dismiss($this->agent, [$update->id]);

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    /** Spec §7.4 — re-notify is a watermark, and it does NOT destroy the audit. */
    public function test_renotify_makes_a_dismissed_update_pending_again_without_deleting_the_view(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish(['published_at' => now()->subWeek()]);

        $this->service()->dismiss($this->agent, [$update->id]);
        $this->assertCount(0, $this->service()->pendingFor($this->agent));

        $update->update(['notify_reset_at' => now()]);

        $this->assertCount(1, $this->service()->pendingFor($this->agent), 're-notify must re-show it');
        $this->assertDatabaseCount('system_update_views', 1);
        $this->assertNotNull(
            SystemUpdateView::where('system_update_id', $update->id)->where('user_id', $this->agent->id)->first(),
            'the original view row must survive re-notify — it is the audit'
        );
    }

    public function test_an_archived_update_stops_being_pending_immediately(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $this->assertCount(1, $this->service()->pendingFor($this->agent));

        $update->delete();

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
        $this->assertSoftDeleted('system_updates', ['id' => $update->id]);
    }

    public function test_a_restored_update_becomes_pending_again(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();
        $update->delete();

        SystemUpdate::onlyTrashed()->findOrFail($update->id)->restore();

        $this->assertCount(1, $this->service()->pendingFor($this->agent));
    }

    public function test_unpublishing_stops_it_showing(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();

        $update->update(['status' => SystemUpdate::STATUS_DRAFT, 'published_at' => null]);

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
    }

    /** Spec §8.3 — bounded interruption; the overflow is reported, never swallowed. */
    public function test_the_modal_caps_at_five_and_reports_the_overflow(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());

        for ($i = 0; $i < 7; $i++) {
            $this->publish([
                'title'        => "Release note {$i}",
                'published_at' => now()->subMinutes(10 - $i),
            ]);
        }

        $payload = $this->service()->modalPayloadFor($this->agent);

        $this->assertCount(5, $payload['updates']);
        $this->assertSame(2, $payload['overflow']);
    }

    public function test_dismissals_are_per_user_and_never_leak(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->joinedAt($this->admin, now()->subMonth());
        $update = $this->publish();

        $this->service()->dismiss($this->agent, [$update->id]);

        $this->assertCount(0, $this->service()->pendingFor($this->agent));
        $this->assertCount(1, $this->service()->pendingFor($this->admin), "one user's dismissal must not clear another's");
    }
}
