<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P24's GET /agencies/{id}/agents takes ~90s. The in-process memo only spans one
 * request, so every fresh manual Refresh paid that cold. This locks the
 * cross-request cache: a second, independent request must reuse the result
 * without a second P24 fetch.
 */
class Property24AgentsCacheTest extends TestCase
{
    use RefreshDatabase;

    /** Reset the static in-process memo so each scenario starts clean. */
    private function clearInProcessMemo(): void
    {
        $prop = new \ReflectionProperty(Property24ApiClient::class, 'agentsCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    public function test_second_request_is_served_from_cross_request_cache(): void
    {
        config(['services.property24_syndication.api_url' => 'https://p24.test']);
        Cache::flush();
        $this->clearInProcessMemo();

        Http::fake([
            '*agencies/29159/agents' => Http::response([
                ['id' => 1, 'sourceReference' => 'CoreX-Agent-10'],
                ['id' => 2, 'sourceReference' => 'CoreX-Agent-11'],
            ], 200),
        ]);

        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);

        // First request: live fetch, warms both the in-process and shared caches.
        $r1 = (new Property24ApiClient($agency))->getAgents('29159');
        $this->assertTrue($r1['success']);

        // Simulate a NEW request — the static memo is gone, but the cross-request
        // cache must still serve the list.
        $this->clearInProcessMemo();

        $r2 = (new Property24ApiClient($agency))->getAgents('29159');

        $this->assertSame($r1['data'], $r2['data']);
        Http::assertSentCount(1); // one P24 fetch despite two independent calls
    }

    public function test_failed_fetch_is_not_cached(): void
    {
        config(['services.property24_syndication.api_url' => 'https://p24.test']);
        Cache::flush();
        $this->clearInProcessMemo();

        Http::fake(['*agencies/29159/agents' => Http::response('boom', 500)]);

        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);

        $r1 = (new Property24ApiClient($agency))->getAgents('29159');
        $this->assertFalse($r1['success']);
        $this->clearInProcessMemo();

        // A transient failure must NOT poison the cache — the next call re-fetches.
        (new Property24ApiClient($agency))->getAgents('29159');
        Http::assertSentCount(2);
    }
}
