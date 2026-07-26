<?php

declare(strict_types=1);

namespace Tests\Feature\SystemUpdates;

use App\Models\SystemUpdate;
use Illuminate\Support\Facades\DB;

/**
 * The cost contract — spec §9.6.
 *
 * This partial runs on EVERY authenticated page in CoreX. A feature that added a
 * query to every page load for the 99.99% case where there is nothing to say would
 * be a bad trade, and a slow page fails no assertion and turns nothing red — so the
 * budget is asserted outright, the same way the P24 refresh-cost contract is.
 */
final class SystemUpdateQueryBudgetTest extends SystemUpdateTestCase
{
    /** @return array<int,string> */
    private function captureQueries(callable $callback): array
    {
        $queries = [];

        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'system_update')) {
                $queries[] = $query->sql;
            }
        });

        $callback();

        return $queries;
    }

    public function test_nothing_pending_costs_zero_queries(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->service()->publishedList();   // warm the cache, as a real request would

        $queries = $this->captureQueries(fn () => $this->service()->pendingFor($this->agent));

        $this->assertSame([], $queries, 'a page load with nothing to say must not touch the database');
    }

    public function test_a_non_admin_costs_zero_queries_when_only_admin_updates_are_live(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish(['audience' => SystemUpdate::AUDIENCE_ADMINS]);
        $this->service()->publishedList();

        $queries = $this->captureQueries(fn () => $this->service()->pendingFor($this->agent));

        $this->assertSame([], $queries, 'the audience filter must run in PHP, before any SQL');
    }

    public function test_a_user_who_joined_after_every_update_costs_zero_queries(): void
    {
        $this->publish(['published_at' => now()->subYear()]);
        $this->joinedAt($this->agent, now()->subMinute());
        $this->service()->publishedList();

        $queries = $this->captureQueries(fn () => $this->service()->pendingFor($this->agent));

        $this->assertSame([], $queries);
    }

    public function test_a_real_candidate_costs_exactly_one_query(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->publish();
        $this->service()->publishedList();

        $queries = $this->captureQueries(fn () => $this->service()->pendingFor($this->agent));

        $this->assertCount(1, $queries, 'the dismissal check must be a correlated NOT EXISTS, not a second round-trip');
    }

    /** The cache must never outlive a publish — bound to model events, not the controller. */
    public function test_publishing_busts_the_cache_automatically(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $this->service()->publishedList();

        $this->assertCount(0, $this->service()->pendingFor($this->agent));

        // Straight through the model, bypassing every controller.
        SystemUpdate::create([
            'title'        => 'Cache coherence check',
            'body'         => 'Published directly through the model.',
            'type'         => 'feature',
            'audience'     => SystemUpdate::AUDIENCE_ALL,
            'status'       => SystemUpdate::STATUS_PUBLISHED,
            'published_at' => now()->subSecond(),
        ]);

        $this->assertCount(1, $this->service()->pendingFor($this->agent), 'a stale cache would hide a live update');
    }

    public function test_archiving_busts_the_cache_automatically(): void
    {
        $this->joinedAt($this->agent, now()->subMonth());
        $update = $this->publish();
        $this->service()->publishedList();

        $update->delete();

        $this->assertCount(0, $this->service()->pendingFor($this->agent), 'a stale cache would keep showing a withdrawn update');
    }
}
