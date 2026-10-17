<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\CoreX\PropertyAdTemplateController;
use App\Models\PropertyAdTemplate;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * AT-456 finding — PropertyAdTemplateController::store()/update() only needed access_properties. Creating or
 * editing a template needs BOTH access_properties and access_ad_manager (ad-manager.md §19, at456 spec §B.6).
 */
final class AdTemplateSaveNeedsAdManagerTest extends TestCase
{
    use RefreshDatabase;

    private const LAYOUT = ['canvasW' => 1080, 'canvasH' => 1080, 'elements' => []];

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    public function test_properties_access_without_the_ad_manager_cannot_create_or_edit_a_template(): void
    {
        [$agency, $branch] = $this->agency();
        $this->grants($agency, ['tpl_props_only' => ['access_properties'], 'tpl_both' => ['access_properties', 'access_ad_manager']]);

        $noAd = User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'tpl_props_only']);
        $both = User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => 'tpl_both']);
        $id   = $this->template($agency, $noAd);

        $this->assertTrue($noAd->hasPermission('access_properties'));
        $this->assertFalse($noAd->hasPermission('access_ad_manager'));

        // Through the routes (middleware).
        $this->actingAs($noAd)
            ->postJson(route('corex.ad-templates.store'), ['name' => 'Sneaky', 'layout_json' => self::LAYOUT])
            ->assertForbidden();
        $this->putJson(route('corex.ad-templates.update', $id), ['name' => 'Renamed', 'layout_json' => self::LAYOUT])
            ->assertForbidden();
        $this->assertSame(0, DB::table('property_ad_templates')->where('name', 'Sneaky')->count());
        $this->assertSame('Mine', DB::table('property_ad_templates')->where('id', $id)->value('name'));

        // And the controller's own re-check (so it holds even if the route middleware were ever dropped).
        foreach (['store', 'update'] as $action) {
            try {
                $controller = app(PropertyAdTemplateController::class);
                $request    = Request::create('/x', 'POST', ['name' => 'Direct', 'layout_json' => self::LAYOUT]);
                $action === 'store'
                    ? $controller->store($request)
                    : $controller->update($request, PropertyAdTemplate::findOrFail($id));
                $this->fail("{$action}() allowed a user without access_ad_manager.");
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('Mine', DB::table('property_ad_templates')->where('id', $id)->value('name'));

        // Holding both keys still works.
        $this->actingAs($both)
            ->postJson(route('corex.ad-templates.store'), ['name' => 'Allowed', 'layout_json' => self::LAYOUT])
            ->assertOk()->assertJsonPath('name', 'Allowed');
        $mine = $this->template($agency, $both, 'Edit me');
        $this->putJson(route('corex.ad-templates.update', $mine), ['name' => 'Edited', 'layout_json' => self::LAYOUT])
            ->assertOk()->assertJsonPath('name', 'Edited');
    }

    /** @return array{0:int,1:int} */
    private function agency(): array
    {
        $agency = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return [$agency, $branch];
    }

    /** Seeded grants switch the permission system out of the test-suite allow-all posture. */
    private function grants(int $agency, array $roles): void
    {
        foreach ($roles as $role => $keys) {
            foreach ($keys as $key) {
                RolePermission::create(['role' => $role, 'permission_key' => $key, 'scope' => null, 'agency_id' => $agency]);
            }
            RolePermission::create(['role' => $role, 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $agency]);
        }
        PermissionService::clearCache();
    }

    private function template(int $agency, User $creator, string $name = 'Mine'): int
    {
        return (int) DB::table('property_ad_templates')->insertGetId([
            'agency_id' => $agency, 'user_id' => $creator->id, 'name' => $name,
            'layout_json' => json_encode(self::LAYOUT), 'is_global' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
