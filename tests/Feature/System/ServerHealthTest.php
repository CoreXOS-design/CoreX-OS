<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\User;
use App\Services\System\ServerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Server Health Monitor (System Developer). Locks: the snapshot is well-formed
 * and failure-contained (never throws), the JSON endpoint is reachable + auth-
 * gated, and the read stays CHEAP enough for a 10s poll.
 */
final class ServerHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_is_well_formed_and_never_throws(): void
    {
        $snap = app(ServerHealthService::class)->snapshot();

        foreach (['cpu', 'memory', 'disks', 'corex', 'backups', 'generated_at'] as $key) {
            $this->assertArrayHasKey($key, $snap);
        }
        $this->assertIsArray($snap['disks']);
        $this->assertIsArray($snap['corex']);
        // CoreX vitals always expose the three canonical queues (zero when empty).
        $queueNames = collect($snap['corex']['queues'] ?? [])->pluck('queue')->all();
        foreach (['default', 'matching', 'mail'] as $q) {
            $this->assertContains($q, $queueNames);
        }
    }

    public function test_data_endpoint_returns_json_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson(route('api.v1.system-health'));

        $res->assertOk()
            ->assertJsonStructure(['cpu', 'memory', 'disks', 'corex' => ['queues'], 'backups', 'generated_at']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        // Unauthenticated web request is redirected to login (never 200).
        $this->get(route('api.v1.system-health'))->assertRedirect();
    }

    // Note: full-page render is not asserted here — it extends the app layout,
    // which requires the built Vite manifest (absent in the bare test env). The
    // view's Blade validity is verified in the build (compileString + php -l),
    // and the JSON data endpoint the page consumes is covered above.

    public function test_snapshot_is_cheap(): void
    {
        // A 10s poll must not run expensive SQL. Bound the query count.
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ServerHealthService::class)->snapshot();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(15, $count, "snapshot ran {$count} queries — too many for a 10s poll");
    }
}
