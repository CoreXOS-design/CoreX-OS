<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Http\Controllers\Tools\AdTemplateManagerController;
use App\Models\PropertyAdTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Template Manager (ad-manager.md §19): list / search / sort / filter, archive + restore
 * (soft delete only), creator-or-permission rights, and agency isolation.
 *
 * Calls the controller directly, like AdManagerGeneratedCounterTest — the
 * `tools.ad-manager*` routes sit behind a `feature:ad-manager` flag that a fresh test
 * agency does not have, which is unrelated to what is asserted here.
 */
final class AdTemplateManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_only_the_users_agency_and_never_another_agencys_templates(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        [$b, $ub] = $this->agencyWithUser();
        $mine   = $this->template($a, $ua, 'Alpha Mine');
        $theirs = $this->template($b, $ub, 'Beta Theirs');

        $names = $this->listNames($ua);

        $this->assertSame(['Alpha Mine'], $names);
        // The exact lookup route-model binding does — another agency's id is simply not found (404).
        $this->actingAs($ua);
        $this->assertNull(PropertyAdTemplate::withTrashed()->find($theirs));
        $this->assertNotNull(PropertyAdTemplate::withTrashed()->find($mine));
    }

    public function test_search_matches_template_name_and_creator_name(): void
    {
        [$a, $ua] = $this->agencyWithUser(['name' => 'Zelda Creator']);
        $ub = $this->userIn($a, ['name' => 'Bob Builder']);
        $this->template($a, $ua, 'Sunset Special');
        $this->template($a, $ub, 'Plain Jane');

        $this->assertSame(['Sunset Special'], $this->listNames($ua, ['q' => 'sunset']));
        $this->assertSame(['Plain Jane'], $this->listNames($ua, ['q' => 'Bob']));
        $this->assertSame([], $this->listNames($ua, ['q' => 'no-such-thing']));
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $this->template($a, $ua, 'Summer 100% Off');
        $this->template($a, $ua, 'Summer Sale');

        $this->assertSame(['Summer 100% Off'], $this->listNames($ua, ['q' => '100%']));
    }

    public function test_default_sort_is_last_updated_newest_first_and_name_sort_works(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $this->template($a, $ua, 'Bravo', updatedAt: now()->subDays(3));
        $this->template($a, $ua, 'Alpha', updatedAt: now()->subDays(9));
        $this->template($a, $ua, 'Charlie', updatedAt: now()->subDay());

        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->listNames($ua));
        $this->assertSame(['Alpha', 'Bravo', 'Charlie'], $this->listNames($ua, ['sort' => 'name']));
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->listNames($ua, ['sort' => 'name', 'dir' => 'desc']));
        // Garbage sort input falls back to the default instead of erroring.
        $this->assertSame(['Charlie', 'Bravo', 'Alpha'], $this->listNames($ua, ['sort' => 'DROP TABLE']));
    }

    public function test_status_filter_active_archived_all(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $this->template($a, $ua, 'Live One');
        $this->template($a, $ua, 'Retired One', deleted: true);

        $this->assertSame(['Live One'], $this->listNames($ua));
        $this->assertSame(['Retired One'], $this->listNames($ua, ['status' => 'archived']));
        $this->assertEqualsCanonicalizing(['Live One', 'Retired One'], $this->listNames($ua, ['status' => 'all']));
    }

    public function test_only_mine_and_updated_date_range_filters(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $ub = $this->userIn($a);
        $this->template($a, $ua, 'Mine Old', updatedAt: now()->subDays(30));
        $this->template($a, $ua, 'Mine New', updatedAt: now()->subDay());
        $this->template($a, $ub, 'Theirs New', updatedAt: now()->subDay());

        $this->assertEqualsCanonicalizing(['Mine Old', 'Mine New'], $this->listNames($ua, ['creator' => 'mine']));
        $this->assertEqualsCanonicalizing(
            ['Mine New', 'Theirs New'],
            $this->listNames($ua, ['from' => now()->subDays(5)->toDateString()])
        );
        $this->assertSame(['Mine Old'], $this->listNames($ua, ['to' => now()->subDays(10)->toDateString()]));
        // A malformed date is ignored, not a 500.
        $this->assertCount(3, $this->listNames($ua, ['from' => 'not-a-date']));
    }

    public function test_pagination_is_fifteen_per_page(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        for ($i = 1; $i <= 17; $i++) {
            $this->template($a, $ua, sprintf('T%02d', $i));
        }

        $this->assertCount(15, $this->listNames($ua));
        $this->assertCount(2, $this->listNames($ua, ['page' => 2]));
    }

    public function test_archive_soft_deletes_and_restore_brings_it_back(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $id = $this->template($a, $ua, 'Keep Me');
        $this->actingAs($ua);

        app(AdTemplateManagerController::class)->archive(PropertyAdTemplate::findOrFail($id));

        // Soft delete — the row is still there with deleted_at set; never a hard delete.
        $this->assertNotNull(DB::table('property_ad_templates')->where('id', $id)->value('deleted_at'));
        $this->assertNull(PropertyAdTemplate::find($id));

        app(AdTemplateManagerController::class)->restore(PropertyAdTemplate::withTrashed()->findOrFail($id));

        $this->assertNull(DB::table('property_ad_templates')->where('id', $id)->value('deleted_at'));
        $this->assertNotNull(PropertyAdTemplate::find($id));
    }

    public function test_someone_elses_template_needs_the_manage_permission_to_archive_or_restore(): void
    {
        [$a, $creator] = $this->agencyWithUser();
        $plain = $this->userIn($a, ['role' => 'agent']);
        $id    = $this->template($a, $creator, 'Not Yours');

        $this->actingAs($plain);
        $this->assertFalse($plain->hasPermission('properties.ad_templates.manage'));
        try {
            app(AdTemplateManagerController::class)->archive(PropertyAdTemplate::findOrFail($id));
            $this->fail('An agent without the manage permission archived another member\'s template.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertNull(DB::table('property_ad_templates')->where('id', $id)->value('deleted_at'));

        // The row is flagged view-only in the list for that user.
        $rows = $this->listRows($plain);
        $this->assertFalse($rows->first()->can_manage);

        // The creator can.
        $this->actingAs($creator);
        app(AdTemplateManagerController::class)->archive(PropertyAdTemplate::findOrFail($id));
        $this->assertNotNull(DB::table('property_ad_templates')->where('id', $id)->value('deleted_at'));

        // And the same rule guards restore.
        $this->actingAs($plain);
        try {
            app(AdTemplateManagerController::class)->restore(PropertyAdTemplate::withTrashed()->findOrFail($id));
            $this->fail('An agent without the manage permission restored another member\'s template.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_page_renders_with_rows_filters_and_empty_state(): void
    {
        [$a, $ua] = $this->agencyWithUser();
        $this->template($a, $ua, 'Renders Fine');
        $this->actingAs($ua);

        $html = $this->renderIndex($ua);
        $this->assertStringContainsString('Renders Fine', $html);
        $this->assertStringContainsString('Template Manager', $html);

        $empty = $this->renderIndex($ua, ['q' => 'zzzz-nothing']);
        $this->assertStringContainsString('No templates match these filters', $empty);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function listRows(User $user, array $query = [])
    {
        $this->actingAs($user);
        $request = Request::create('/tools/ad-manager/templates', 'GET', $query);
        $request->setUserResolver(fn () => $user);

        return app(AdTemplateManagerController::class)->index($request)->getData()['templates']->getCollection();
    }

    private function listNames(User $user, array $query = []): array
    {
        return $this->listRows($user, $query)->pluck('name')->all();
    }

    private function renderIndex(User $user, array $query = []): string
    {
        $request = Request::create('/tools/ad-manager/templates', 'GET', $query);
        $request->setUserResolver(fn () => $user);
        $this->app->instance('request', $request);

        return app(AdTemplateManagerController::class)->index($request)->render();
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

    private function template(int $agencyId, User $creator, string $name, $updatedAt = null, bool $deleted = false): int
    {
        $stamp = $updatedAt ?? now();

        return (int) DB::table('property_ad_templates')->insertGetId([
            'agency_id'   => $agencyId,
            'user_id'     => $creator->id,
            'name'        => $name,
            'layout_json' => json_encode(['canvasW' => 1080, 'canvasH' => 1080, 'elements' => []]),
            'is_global'   => 0,
            'created_at'  => $stamp,
            'updated_at'  => $stamp,
            'deleted_at'  => $deleted ? now() : null,
        ]);
    }
}
