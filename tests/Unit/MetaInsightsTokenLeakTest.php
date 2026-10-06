<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AgentSocialAccount;
use App\Models\PropertyMarketingPost;
use App\Services\MetaPublishingService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

/**
 * Audit 2026-10-06: the Facebook insights branch used graphGet() (http_errors
 * off) but the Instagram branch still called Guzzle directly — a 4xx from Meta
 * then threw a RequestException whose message embeds the full request URL,
 * access_token included, and PropertyMarketingController::syncInsights returned
 * that message to the browser and wrote it to the log.
 */
final class MetaInsightsTokenLeakTest extends TestCase
{
    private const TOKEN = 'EAAB-SECRET-PAGE-TOKEN-123';

    private function service(Response $response): MetaPublishingService
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);

        return new MetaPublishingService($client);
    }

    private function marketingPost(string $platform): PropertyMarketingPost
    {
        $account = new AgentSocialAccount();
        $account->access_token = self::TOKEN;

        $post = Mockery::mock(PropertyMarketingPost::class)->makePartial();
        $post->shouldReceive('socialAccount')->andReturn($account);
        $post->platform         = $platform;
        $post->platform_post_id = '17900000000000001';

        return $post;
    }

    public function test_instagram_insights_error_never_carries_the_access_token(): void
    {
        $service = $this->service(new Response(400, [], json_encode(['error' => ['message' => 'Invalid metric']])));

        try {
            $service->fetchPostInsights($this->marketingPost('instagram'));
            $this->fail('A Meta error must surface as an exception.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringContainsString('Instagram: Invalid metric', $e->getMessage());
        }
    }

    public function test_instagram_insights_success_still_maps_metrics(): void
    {
        $body = json_encode(['data' => [
            ['name' => 'impressions', 'values' => [['value' => 50]]],
            ['name' => 'reach',       'values' => [['value' => 40]]],
        ]]);

        $metrics = $this->service(new Response(200, [], $body))->fetchPostInsights($this->marketingPost('instagram'));

        $this->assertSame(50, $metrics['impressions']);
        $this->assertSame(40, $metrics['reach']);
        $this->assertSame(0, $metrics['link_clicks']);
    }
}
