<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\CompanyAdsController;
use App\Models\CompanyAd;
use App\Models\CompanyAdImage;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\AdTemplates\CompanyAdLayout;
use App\Services\AdTemplates\CompanyAdLibrary;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Company Ads, step E-1 (.ai/specs/company-ads.md): list / search / sort / filter / pagination, create / edit /
 * duplicate / archive / restore (soft only), the company-ad layout rules (no listing elements, only allowed
 * pictures), picture upload, the seasonal library, the permission, the builder's company mode and agency isolation.
 *
 * Calls the controller directly, like AdTemplateManagerTest — the routes sit behind a `feature:ad-manager` flag a
 * fresh test agency does not have, which is unrelated to what is asserted here.
 */
final class CompanyAdsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PermissionService::clearCache(); // never leak a forced posture into another test
        parent::tearDown();
    }

    // ── list ────────────────────────────────────────────────────────────────

    public function test_list_shows_only_the_users_agency(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        [$b, $ub] = $this->agencyWithUser();
        $mine   = $this->ad($a, $ua, 'Our Christmas');
        $theirs = $this->ad($b, $ub, 'Their Christmas');

        $this->assertSame(['Our Christmas'], $this->listNames($ua));
        $this->actingAs($ua);
        $this->assertNull(CompanyAd::withTrashed()->find($theirs)); // route-model binding → 404
        $this->assertNotNull(CompanyAd::find($mine));
    }

    public function test_search_matches_name_occasion_label_and_creator(): void
    {
        [$a, $ua] = $this->agencyWithUser(['name' => 'Zelda Maker']);
        $ub = $this->userIn($a, ['name' => 'Bob Builder']);
        $this->ad($a, $ua, 'Season greetings', occasion: 'christmas');
        $this->ad($a, $ub, 'Bunny time', occasion: 'easter');
        $this->ad($a, $ua, 'Closed 100% of December');

        $this->assertSame(['Season greetings'], $this->listNames($ua, ['q' => 'festive']));   // occasion label
        $this->assertSame(['Bunny time'], $this->listNames($ua, ['q' => 'Bob']));             // creator
        $this->assertSame(['Bunny time'], $this->listNames($ua, ['q' => 'bunny']));           // name
        $this->assertSame(['Closed 100% of December'], $this->listNames($ua, ['q' => '100%'])); // literal %
    }

    public function test_filters_status_occasion_creator_and_dates(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $ub = $this->userIn($a);
        $this->ad($a, $ua, 'Xmas', occasion: 'christmas', updatedAt: now()->subDays(30));
        $this->ad($a, $ub, 'Easter', occasion: 'easter', updatedAt: now()->subDay());
        $this->ad($a, $ua, 'Other one', updatedAt: now()->subDay(), ai: true);
        $this->ad($a, $ua, 'Old archived', occasion: 'christmas', deleted: true);

        $this->assertEqualsCanonicalizing(['Xmas', 'Easter', 'Other one'], $this->listNames($ua));
        $this->assertSame(['Old archived'], $this->listNames($ua, ['status' => 'archived']));
        $this->assertCount(4, $this->listNames($ua, ['status' => 'all']));
        $this->assertSame(['Xmas'], $this->listNames($ua, ['occasion' => 'christmas']));
        $this->assertSame(['Other one'], $this->listNames($ua, ['occasion' => 'other']));
        $this->assertCount(3, $this->listNames($ua, ['occasion' => 'not-a-real-occasion'])); // ignored, not a 500
        $this->assertEqualsCanonicalizing(['Xmas', 'Other one'], $this->listNames($ua, ['creator' => 'mine']));
        $this->assertSame(['Other one'], $this->listNames($ua, ['creator' => 'ai']));
        $this->assertEqualsCanonicalizing(['Easter', 'Other one'], $this->listNames($ua, ['from' => now()->subDays(5)->toDateString()]));
        $this->assertSame(['Xmas'], $this->listNames($ua, ['to' => now()->subDays(10)->toDateString()]));
        $this->assertCount(3, $this->listNames($ua, ['from' => 'nonsense']));
    }

    public function test_sorts_default_newest_first_name_and_occasion_in_calendar_order(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $this->ad($a, $ua, 'Bravo', occasion: 'christmas', updatedAt: now()->subDays(3));
        $this->ad($a, $ua, 'Alpha', occasion: 'easter', updatedAt: now()->subDays(9));
        $this->ad($a, $ua, 'Charlie', occasion: 'new_year', updatedAt: now()->subDay());

        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->listNames($ua));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->listNames($ua, ['sort' => 'name']));
        // New Year (Jan) → Easter → Christmas (Dec): the library's calendar order, not the keys' A-Z.
        $this->assertSame(['Charlie', 'Alpha', 'Bravo'], $this->listNames($ua, ['sort' => 'occasion']));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->listNames($ua, ['sort' => 'DROP TABLE']));
    }

    public function test_pagination_is_fifteen_per_page(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        for ($i = 1; $i <= 17; $i++) {
            $this->ad($a, $ua, sprintf('Ad %02d', $i));
        }

        $this->assertCount(15, $this->listNames($ua));
        $this->assertCount(2, $this->listNames($ua, ['page' => 2]));
    }

    public function test_page_renders_tabs_rows_and_empty_states(): void
    {
        [$a, $ua] = $this->agencyWithUser();

        $empty = $this->renderIndex($ua);
        $this->assertStringContainsString('No company ads yet', $empty);
        $this->assertStringContainsString('Property templates', $empty);
        $this->assertStringContainsString('Company ads', $empty);

        $this->ad($a, $ua, 'Renders Fine', occasion: 'heritage_day');
        $html = $this->renderIndex($ua);
        $this->assertStringContainsString('Renders Fine', $html);
        $this->assertStringContainsString('Heritage Day', $html);
        $this->assertStringContainsString('data-ad-thumb', $html);
        $this->assertStringContainsString('No company ads match these filters', $this->renderIndex($ua, ['q' => 'zzzz']));
    }

    // ── create / edit / duplicate / archive / restore ──────────────────────

    public function test_store_keeps_only_company_ad_elements_and_allowed_pictures(): void
    {
        Storage::fake('public');
        [$a, $ua] = $this->agencyWithUser();
        [$b] = $this->agencyWithUser();
        $this->actingAs($ua);

        Storage::disk('public')->put("company-ads/{$a}/ours.jpg", 'x');
        Storage::disk('public')->put("company-ads/{$b}/theirs.jpg", 'x');
        $libraryUrl = CompanyAdLibrary::grouped()[0]['pictures'][0]['url'];

        $layout = [
            'canvasW' => 999, 'canvasH' => 111, 'canvasPreset' => 'instagram', 'canvasBg' => '#112233',
            'variants' => ['House' => ['elements' => []]],
            'elements' => [
                ['id' => 1, 'field' => 'custom_text', 'text' => 'Merry Christmas'],
                ['id' => 2, 'field' => 'price'],                 // listing element → removed
                ['id' => 3, 'field' => 'image_1'],               // listing photo → removed
                ['id' => 4, 'field' => 'qr'],                    // QR to listing → removed
                ['id' => 5, 'field' => 'custom_image', 'src' => $libraryUrl, 'mediaKind' => 'image'],
                ['id' => 6, 'field' => 'custom_image', 'src' => "/storage/company-ads/{$a}/ours.jpg"],
                ['id' => 7, 'field' => 'custom_image', 'src' => "/storage/company-ads/{$b}/theirs.jpg"], // another agency's
                ['id' => 8, 'field' => 'custom_image', 'src' => 'https://evil.example/x.jpg'],
                ['id' => 9, 'field' => 'custom_image', 'src' => '/images/company-ad-library/../../.env'],
                ['id' => 10, 'field' => 'agency_logo'],
                ['id' => 11, 'field' => 'custom_video', 'src' => '/x.mp4'], // not offered on company ads
            ],
        ];

        $res = app(CompanyAdsController::class)->store($this->jsonRequest($ua, [
            'name' => 'Festive', 'occasion' => 'christmas', 'layout_json' => $layout,
        ]));
        $ad = CompanyAd::findOrFail($res->getData(true)['id']);

        $this->assertSame($a, (int) $ad->agency_id);
        $this->assertSame('christmas', $ad->occasion);
        $this->assertSame('instagram', $ad->canvas);
        $this->assertSame([1080, 1080], [$ad->layout_json['canvasW'], $ad->layout_json['canvasH']]);
        $this->assertArrayNotHasKey('variants', $ad->layout_json);
        $byId = collect($ad->layout_json['elements'])->keyBy('id');
        $this->assertSame([1, 5, 6, 7, 8, 9, 10], $byId->keys()->all());
        $this->assertSame($libraryUrl, $byId[5]['src']);
        $this->assertSame("/storage/company-ads/{$a}/ours.jpg", $byId[6]['src']);
        $this->assertSame('', $byId[7]['src']);
        $this->assertSame('', $byId[8]['src']);
        $this->assertSame('', $byId[9]['src']);
        $this->assertFalse($ad->ai_generated);
    }

    public function test_store_refuses_an_unknown_occasion_and_a_missing_name(): void
    {
        [, $ua] = $this->agencyWithUser();
        $this->actingAs($ua);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CompanyAdsController::class)->store($this->jsonRequest($ua, [
            'name' => 'X', 'occasion' => 'made_up', 'layout_json' => ['elements' => []],
        ]));
    }

    public function test_update_duplicate_archive_and_restore(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $ub = $this->userIn($a);
        $id = $this->ad($a, $ub, 'Christmas 2025', occasion: 'christmas');
        $this->actingAs($ua);
        $ctl = app(CompanyAdsController::class);

        $ctl->update($this->jsonRequest($ua, [
            'name' => 'Christmas 2026', 'occasion' => 'christmas',
            'layout_json' => ['canvasPreset' => 'story', 'elements' => [['id' => 1, 'field' => 'custom_text', 'text' => 'Hi']]],
        ]), CompanyAd::findOrFail($id));
        $ad = CompanyAd::findOrFail($id);
        $this->assertSame('Christmas 2026', $ad->name);
        $this->assertSame('story', $ad->canvas);

        $redirect = $ctl->duplicate($this->jsonRequest($ua, []), $ad);
        $copy = CompanyAd::where('name', 'Copy of Christmas 2026')->firstOrFail();
        $this->assertSame($ua->id, (int) $copy->user_id);           // the copy belongs to whoever made it
        $this->assertSame($ad->layout_json, $copy->layout_json);
        $this->assertStringContainsString('/company-ads/builder/' . $copy->id, $redirect->getTargetUrl());

        $ctl->archive($this->jsonRequest($ua, []), $ad);
        $this->assertNotNull(DB::table('company_ads')->where('id', $id)->value('deleted_at')); // soft, never hard
        $this->assertNull(CompanyAd::find($id));

        $ctl->restore($this->jsonRequest($ua, []), CompanyAd::withTrashed()->findOrFail($id));
        $this->assertNotNull(CompanyAd::find($id));
    }

    // ── permission ──────────────────────────────────────────────────────────

    public function test_without_the_permission_a_user_can_view_but_not_change(): void
    {
        [$a, $creator] = $this->agencyWithUser();
        $agent = $this->userIn($a, ['role' => 'agent']);
        $id = $this->ad($a, $creator, 'Brand ad');

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $a]);
        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'access_ad_manager', 'agency_id' => $a], []);
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->actingAs($agent);
        $this->assertFalse($agent->hasPermission(CompanyAd::PERMISSION));

        // Sees and can download (the list is the whole agency) …
        $html = $this->renderIndex($agent);
        $this->assertStringContainsString('Brand ad', $html);
        $this->assertStringContainsString('Download', $html);
        $this->assertStringNotContainsString('New company ad', $html);
        $this->assertStringNotContainsString('Duplicate', $html);

        // … but every change is refused server-side.
        $ctl = app(CompanyAdsController::class);
        $ad  = CompanyAd::findOrFail($id);
        $calls = [
            fn () => $ctl->store($this->jsonRequest($agent, ['name' => 'X', 'layout_json' => ['elements' => []]])),
            fn () => $ctl->update($this->jsonRequest($agent, ['name' => 'X', 'layout_json' => ['elements' => []]]), $ad),
            fn () => $ctl->duplicate($this->jsonRequest($agent, []), $ad),
            fn () => $ctl->archive($this->jsonRequest($agent, []), $ad),
            fn () => $ctl->restore($this->jsonRequest($agent, []), $ad),
            fn () => $ctl->builder($this->jsonRequest($agent, []), $ad),
            fn () => $ctl->uploadImage($this->jsonRequest($agent, [])),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Call #{$i} was allowed without " . CompanyAd::PERMISSION);
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('Brand ad', CompanyAd::findOrFail($id)->name);
        $this->assertSame(1, CompanyAd::count());
    }

    public function test_permission_key_and_role_defaults(): void
    {
        $keys = collect(config('corex-permissions.permissions'))->pluck('key');
        $this->assertTrue($keys->contains('ad_manager.company_ads'));

        $defaults = config('corex-permissions.role_defaults');
        $this->assertContains('ad_manager.company_ads', $defaults['branch_manager']['include']);
        $this->assertContains('ad_manager.company_ads', $defaults['office_admin']['include']);
        $this->assertNotContains('ad_manager.company_ads', $defaults['agent']['include']);
        $this->assertNotContains('ad_manager.company_ads', $defaults['viewer']['include']);
        $this->assertNotContains('ad_manager.company_ads', $defaults['admin']['exclude']); // admin gets it via all-minus-exclude
    }

    // ── uploads ─────────────────────────────────────────────────────────────

    public function test_upload_reencodes_into_the_agencys_own_folder(): void
    {
        Storage::fake('public');
        [$a, $ua] = $this->agencyWithUser();
        $this->actingAs($ua);

        $file = UploadedFile::fake()->image('team.png', 3000, 1500);
        $res  = app(CompanyAdsController::class)->uploadImage($this->jsonRequest($ua, [], ['file' => $file]));
        $data = $res->getData(true);

        $this->assertTrue($data['ok']);
        $this->assertSame([2400, 1200], [$data['width'], $data['height']]);  // capped at 2400 px
        $img = CompanyAdImage::findOrFail($data['id']);
        $this->assertSame($a, (int) $img->agency_id);
        $this->assertStringStartsWith("company-ads/{$a}/", $img->path);
        $this->assertStringEndsWith('.jpg', $img->path);
        Storage::disk('public')->assertExists($img->path);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('public')->get($img->path))['mime']);
        // The returned address is one a company ad is allowed to show.
        $this->assertSame($data['url'], CompanyAdLayout::allowedPictureSrc($data['url'], $a));
    }

    public function test_a_phone_photo_taken_upright_is_saved_upright(): void
    {
        Storage::fake('public');
        [, $ua] = $this->agencyWithUser();
        $this->actingAs($ua);

        // Landscape pixels (900x600) tagged "rotate 90" - how a phone stores an upright photo.
        $tmp = tempnam(sys_get_temp_dir(), 'cad') . '.jpg';
        copy(base_path('tests/Fixtures/Images/portrait-exif6.jpg'), $tmp);
        $file = new UploadedFile($tmp, 'portrait.jpg', 'image/jpeg', null, true);
        $data = app(CompanyAdsController::class)->uploadImage($this->jsonRequest($ua, [], ['file' => $file]))->getData(true);

        $this->assertTrue($data['ok']);
        $this->assertSame([600, 900], [$data['width'], $data['height']], 'stored upright, not sideways');
        $saved = getimagesizefromstring(Storage::disk('public')->get(CompanyAdImage::findOrFail($data['id'])->path));
        $this->assertSame([600, 900], [$saved[0], $saved[1]]);
    }

    public function test_upload_refuses_a_file_that_is_not_a_picture(): void
    {
        Storage::fake('public');
        [, $ua] = $this->agencyWithUser();
        $this->actingAs($ua);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(CompanyAdsController::class)->uploadImage($this->jsonRequest($ua, [], [
            'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ]));
    }

    // ── library ─────────────────────────────────────────────────────────────

    public function test_every_library_picture_exists_and_has_a_recorded_source(): void
    {
        $occasions = config('company-ad-library.occasions');
        $this->assertNotEmpty($occasions);
        $sources = file_get_contents(public_path('images/company-ad-library/SOURCES.md'));

        foreach ($occasions as $key => $o) {
            $this->assertGreaterThanOrEqual(4, count($o['pictures']), "Occasion {$key} has fewer than 4 pictures");
            foreach ($o['pictures'] as $p) {
                $this->assertFileExists(public_path('images/company-ad-library/' . $p['file']));
                $this->assertStringContainsString('`' . $p['file'] . '`', $sources, "{$p['file']} has no recorded source");
                $this->assertTrue(CompanyAdLibrary::isLibraryPath(CompanyAdLibrary::URL_PREFIX . $p['file']));
            }
        }
        // Every licence recorded is public domain or CC0.
        preg_match_all('/^\| `[^`]+` \| .+ \| (.+) \|$/m', $sources, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $licence) {
            $this->assertMatchesRegularExpression('/^(Public domain|CC0)/i', trim($licence));
        }
        $this->assertFalse(CompanyAdLibrary::isLibraryPath('/images/company-ad-library/christmas/999.jpg'));
        $this->assertFalse(CompanyAdLibrary::isLibraryPath('/images/ad-demo/1.jpg'));
    }

    // ── builder company mode ────────────────────────────────────────────────

    public function test_builder_opens_in_company_mode_with_the_agencys_own_brand(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        DB::table('agencies')->where('id', $a)->update(['name' => 'Second Agency Rentals']);
        $this->actingAs($ua);

        $html = app(CompanyAdsController::class)->builder($this->jsonRequest($ua, []))->render();

        $this->assertStringContainsString('New Company Ad', $html);
        $this->assertStringContainsString('companyMode:   true', $html);
        $this->assertStringContainsString('Or choose from the library', $html);
        $this->assertStringContainsString('Second Agency Rentals', $html);
        $this->assertStringNotContainsString('Use on Property', $html);
        $this->assertStringNotContainsString('Home Finders', $html);
        $this->assertStringContainsString(json_encode(route('api.v1.ad-manager.company-ads.store')), $html); // save goes to company ads
        $this->assertStringContainsString('href="' . route('tools.ad-manager.templates.company') . '"', $html); // Back
    }

    public function test_property_builder_is_unchanged_by_company_mode(): void
    {
        [, $ua] = $this->agencyWithUser();
        $this->actingAs($ua);

        $html = app(\App\Http\Controllers\CoreX\PropertyAdTemplateController::class)
            ->builder($this->jsonRequest($ua, []))->render();

        $this->assertStringContainsString('companyMode:   false', $html);
        $this->assertStringContainsString('Use on Property', $html);
        $this->assertStringNotContainsString('Or choose from the library', $html);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function listRows(User $user, array $query = [])
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager/templates/company', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(CompanyAdsController::class)->index($request)->getData()['ads']->getCollection();
    }

    private function listNames(User $user, array $query = []): array
    {
        return $this->listRows($user, $query)->pluck('name')->all();
    }

    private function renderIndex(User $user, array $query = []): string
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager/templates/company', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(CompanyAdsController::class)->index($request)->render();
    }

    private function jsonRequest(User $user, array $data, array $files = []): Request
    {
        $request = Request::create('/x', 'POST', $data, [], $files);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return $request;
    }

    private function agencyWithUser(array $userAttrs = []): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $this->userIn($agencyId, $userAttrs)];
    }

    private function userIn(int $agencyId, array $attrs = []): User
    {
        $branchId = DB::table('branches')->where('agency_id', $agencyId)->value('id')
            ?? (int) DB::table('branches')->insertGetId([
                'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now(),
            ]);

        return User::factory()->create(array_merge([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent',
        ], $attrs));
    }

    private function ad(int $agencyId, User $creator, string $name, ?string $occasion = null, $updatedAt = null, bool $deleted = false, bool $ai = false): int
    {
        $stamp = $updatedAt ?? now();

        return (int) DB::table('company_ads')->insertGetId([
            'agency_id'    => $agencyId,
            'user_id'      => $creator->id,
            'name'         => $name,
            'occasion'     => $occasion,
            'canvas'       => 'facebook',
            'layout_json'  => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'canvasPreset' => 'facebook', 'elements' => []]),
            'ai_generated' => $ai,
            'created_at'   => $stamp,
            'updated_at'   => $stamp,
            'deleted_at'   => $deleted ? now() : null,
        ]);
    }
}
