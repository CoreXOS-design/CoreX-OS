<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Admin\ApiCatalogController;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * CoreX non-negotiable #7 for the AT-456 JSON endpoints: every one lives under /api/v1, is named, appears in the
 * Admin -> API catalogue, and its old web URL is gone. Spec: .ai/specs/at456-ai-tools-scheduler-and-spend-safety.md.
 */
final class AdManagerApiV1RoutesTest extends TestCase
{
    /** name => [method, uri, controller method, permission middleware that must be kept] */
    private const MOVED = [
        'api.v1.ad-manager.templates.ai.generate' => ['POST', 'api/v1/ad-manager/templates/ai/generate', 'generateWithAi', ['permission:access_ad_manager', 'agency.required', 'feature:ad-manager', 'throttle:8,1']],
        'api.v1.ad-manager.templates.ai.save'     => ['POST', 'api/v1/ad-manager/templates/ai/{generation}/save', 'saveAiOption', ['permission:access_ad_manager', 'agency.required', 'feature:ad-manager']],
        'api.v1.ad-manager.scheduled.image'       => ['POST', 'api/v1/ad-manager/scheduled/image', 'uploadImage', ['permission:access_ad_manager', 'permission:ad_manager.schedule_posts', 'agency.required', 'feature:ad-manager', 'throttle:60,1']],
        'api.v1.ad-manager.scheduled.store'       => ['POST', 'api/v1/ad-manager/scheduled', 'store', ['permission:access_ad_manager', 'permission:ad_manager.schedule_posts', 'agency.required', 'feature:ad-manager', 'throttle:20,1']],
        // 2026-10-18 audit - the Ad Builder's company-ad save.
        'api.v1.ad-manager.company-ads.store'     => ['POST', 'api/v1/ad-manager/company-ads', 'store', ['permission:access_ad_manager', 'permission:ad_manager.company_ads', 'agency.required', 'feature:ad-manager']],
        'api.v1.ad-manager.company-ads.update'    => ['PUT', 'api/v1/ad-manager/company-ads/{companyAd}', 'update', ['permission:access_ad_manager', 'permission:ad_manager.company_ads', 'agency.required', 'feature:ad-manager']],
    ];

    public function test_each_moved_endpoint_is_under_api_v1_named_and_keeps_its_gates(): void
    {
        foreach (self::MOVED as $name => [$method, $uri, $action, $gates]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "$name is not registered");
            $this->assertSame($uri, $route->uri());
            $this->assertContains($method, $route->methods());
            $this->assertStringEndsWith('@' . $action, $route->getActionName());
            $this->assertContains('auth', $route->gatherMiddleware(), "$name must be session-authenticated");
            foreach ($gates as $gate) {
                $this->assertContains($gate, $route->gatherMiddleware(), "$name lost its '$gate' middleware");
            }
        }
    }

    public function test_each_moved_endpoint_is_listed_by_the_admin_api_catalogue(): void
    {
        $data   = app(ApiCatalogController::class)->index()->getData();
        $listed = collect($data['groups'])->flatten(1)->pluck('name')->filter()->all();

        foreach (array_keys(self::MOVED) as $name) {
            $this->assertContains($name, $listed, "$name is missing from the Admin -> API catalogue");
        }
    }

    public function test_the_old_web_routes_are_gone(): void
    {
        foreach (['tools.ad-manager.templates.ai.generate', 'tools.ad-manager.templates.ai.save',
                  'tools.ad-manager.scheduled.image', 'tools.ad-manager.scheduled.store',
                  'tools.ad-manager.company-ads.store', 'tools.ad-manager.company-ads.update'] as $oldName) {
            $this->assertNull(Route::getRoutes()->getByName($oldName), "$oldName still registered");
        }

        // No POST route may remain on any of the old URIs (the plain /scheduled URI still has the GET list screen).
        $oldUris = ['tools/ad-manager/templates/ai/generate', 'tools/ad-manager/templates/ai/{generation}/save',
                    'tools/ad-manager/scheduled/image', 'tools/ad-manager/scheduled'];
        $leftovers = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $r) => in_array($r->uri(), $oldUris, true) && in_array('POST', $r->methods(), true));
        $this->assertCount(0, $leftovers);

        // The three URLs with no GET sibling answer 404 (nothing handles them at all).
        foreach (['/tools/ad-manager/templates/ai/generate', '/tools/ad-manager/templates/ai/1/save', '/tools/ad-manager/scheduled/image'] as $url) {
            $this->postJson($url, [])->assertNotFound();
        }
    }
}
