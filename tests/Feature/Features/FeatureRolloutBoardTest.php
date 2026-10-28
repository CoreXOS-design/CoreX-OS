<?php

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\FeatureRollout\FeatureRolloutWave;
use App\Models\Role;
use App\Models\User;
use App\Services\FeatureRollout\RolloutCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-478 §20 — the wave planner board on Dev Settings → Feature rollout: it renders the data the browser needs,
 * and "Save plan" writes the whole ordered list in one post (spec agency-feature-rollout.md §20.2).
 */
final class FeatureRolloutBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'branch_id' => null, 'is_active' => true]);
    }

    private function wave(string $title, array $keys, string $track = 'general', int $position = 10): FeatureRolloutWave
    {
        return FeatureRolloutWave::create(['title' => $title, 'track' => $track, 'position' => $position, 'feature_keys' => $keys]);
    }

    private function save(User $owner, array $layout)
    {
        return $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.layout'), ['layout' => json_encode($layout)]);
    }

    private function row(?int $id, string $title, array $keys, string $track = 'general', ?string $intro = null): array
    {
        return ['id' => $id, 'title' => $title, 'track' => $track, 'intro_text' => $intro, 'feature_keys' => $keys];
    }

    public function test_the_page_is_the_board_with_everything_the_browser_needs(): void
    {
        $owner = $this->owner();
        $this->wave('Documents first', ['docuperfect']);

        $html = $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="wave-planner"', $html);
        $this->assertStringContainsString(route('admin.feature-rollout-defaults.layout'), $html);
        $this->assertStringContainsString('id="pace-form"', $html);
        $this->assertStringContainsString('Documents first', $html);
        $this->assertStringContainsString('Not placed yet', $html);
        // The old per-wave tick-box form is gone from the page.
        $this->assertStringNotContainsString('name="feature_keys[]"', $html);
    }

    public function test_the_holiday_list_for_the_date_preview_comes_from_the_calendar_the_plans_use(): void
    {
        $map = app(RolloutCalendar::class)->holidayMap('za');

        $this->assertNotEmpty($map);
        $this->assertTrue(collect(array_keys($map))->contains(fn ($d) => str_ends_with($d, '-12-25')), 'Christmas Day is a South African public holiday');
    }

    public function test_saving_the_board_creates_updates_orders_and_archives(): void
    {
        $owner = $this->owner();
        $a = $this->wave('First', ['docuperfect'], 'general', 10);
        $b = $this->wave('Second', ['presentations'], 'general', 20);

        $this->save($owner, [
            $this->row($b->id, 'Second renamed', ['presentations', 'viewing-packs'], 'sales', 'Present better.'),
            $this->row(null, 'Brand new', ['rentals'], 'rentals'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.feature-rollout-defaults.index'));

        $this->assertSoftDeleted('feature_rollout_waves', ['id' => $a->id]);
        $this->assertSame(['Second renamed', 'Brand new'], FeatureRolloutWave::orderBy('position')->pluck('title')->all());
        $this->assertSame([10, 20], FeatureRolloutWave::orderBy('position')->pluck('position')->all());
        $kept = $b->fresh();
        $this->assertSame(['presentations', 'viewing-packs'], $kept->feature_keys);
        $this->assertSame('sales', $kept->track);
        $this->assertSame('Present better.', $kept->intro_text);
        // Same row kept: a running plan's link to its source wave stays valid.
        $this->assertSame($b->id, $kept->id);
    }

    public function test_admin_decides_waves_are_always_saved_last(): void
    {
        $owner = $this->owner();

        $this->save($owner, [
            $this->row(null, 'Office', ['billing'], 'admin'),
            $this->row(null, 'Documents', ['docuperfect']),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Documents', 'Office'], FeatureRolloutWave::orderBy('position')->pluck('title')->all());
    }

    public function test_a_feature_in_two_waves_keeps_its_earliest_place(): void
    {
        $owner = $this->owner();

        $this->save($owner, [
            $this->row(null, 'One', ['docuperfect', 'presentations']),
            $this->row(null, 'Two', ['presentations', 'viewing-packs']),
        ])->assertSessionHasNoErrors();

        $this->assertSame([['docuperfect', 'presentations'], ['viewing-packs']], FeatureRolloutWave::orderBy('position')->get()->map->feature_keys->all());
    }

    public function test_a_bad_plan_is_refused_and_nothing_is_written(): void
    {
        $owner = $this->owner();
        $a = $this->wave('Keep me', ['docuperfect']);
        $bad = [
            'an empty wave'                       => [$this->row($a->id, 'Keep me', ['docuperfect']), $this->row(null, 'Empty', [])],
            'a core feature'                      => [$this->row($a->id, 'Keep me', ['properties'])],
            'an opt-in feature'                   => [$this->row($a->id, 'Keep me', ['payroll'])],
            'a feature with no access setting'    => [$this->row($a->id, 'Keep me', ['commission-management'], 'admin')],
            'a wave that is not there any more'   => [$this->row(999999, 'Ghost', ['docuperfect'])],
            'a wave listed twice'                 => [$this->row($a->id, 'Keep me', ['docuperfect']), $this->row($a->id, 'Again', ['presentations'])],
            'a wave without a name'               => [$this->row($a->id, '  ', ['docuperfect'])],
            'an unknown group'                    => [$this->row($a->id, 'Keep me', ['docuperfect'], 'nonsense')],
        ];

        foreach ($bad as $why => $layout) {
            $this->save($owner, $layout);
            $this->assertTrue(session()->has('errors') && session('errors')->has('layout'), "Not refused: {$why}");
        }

        $this->assertSame(['Keep me'], FeatureRolloutWave::pluck('title')->all());
        $this->assertSame(['docuperfect'], $a->fresh()->feature_keys);
        $this->assertNull(FeatureRolloutWave::onlyTrashed()->first());
    }

    public function test_an_empty_plan_is_refused_unless_confirmed(): void
    {
        $owner = $this->owner();
        $a = $this->wave('Keep me', ['docuperfect']);

        $this->save($owner, [])->assertSessionHasErrors('layout');
        $this->assertNotSoftDeleted('feature_rollout_waves', ['id' => $a->id]);

        $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.layout'), ['layout' => '[]', 'confirm_empty' => '1'])->assertSessionHasNoErrors();
        $this->assertSoftDeleted('feature_rollout_waves', ['id' => $a->id]);
    }

    public function test_a_page_that_never_saw_a_wave_cannot_archive_it(): void
    {
        $owner = $this->owner();
        $seen = $this->wave('Seen', ['docuperfect'], 'general', 10);
        $added = $this->wave('Added by someone else', ['presentations'], 'general', 20); // created after this page loaded

        $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.layout'), [
            'layout' => json_encode([$this->row($seen->id, 'Seen renamed', ['docuperfect'])]),
            'known'  => json_encode([$seen->id]),
        ])->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted('feature_rollout_waves', ['id' => $added->id]);
        $this->assertSame('Seen renamed', $seen->fresh()->title);

        // A wave the page did show and the owner left out is archived.
        $this->actingAs($owner)->put(route('admin.feature-rollout-defaults.layout'), [
            'layout' => json_encode([$this->row($seen->id, 'Seen renamed', ['docuperfect'])]),
            'known'  => json_encode([$seen->id, $added->id]),
        ])->assertSessionHasNoErrors();
        $this->assertSoftDeleted('feature_rollout_waves', ['id' => $added->id]);
    }

    public function test_a_refused_save_brings_the_browsers_edits_back_to_the_board(): void
    {
        $owner = $this->owner();
        $layout = [$this->row(null, 'Typed but empty', [])];

        $this->save($owner, $layout)->assertSessionHasErrors('layout');
        $html = $this->actingAs($owner)->followingRedirects()->get(route('admin.feature-rollout-defaults.index'))->getContent();

        $this->assertStringContainsString('Typed but empty', $html);
    }

    public function test_a_non_owner_cannot_save_the_board(): void
    {
        $agency = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id, 'is_active' => true]);
        $wave = $this->wave('Untouched', ['docuperfect']);

        $this->save($admin, [])->assertForbidden();

        $this->assertSame(['Untouched'], FeatureRolloutWave::pluck('title')->all());
        $this->assertNotSoftDeleted('feature_rollout_waves', ['id' => $wave->id]);
    }

    public function test_archived_waves_keep_the_list_screen_with_filters_and_restore(): void
    {
        $owner = $this->owner();
        $paper = $this->wave('Zebra Paperwork', ['docuperfect'], 'general', 10);
        $letting = $this->wave('Quokka Letting', ['rentals'], 'rentals', 20);
        $paper->delete();
        $letting->delete();
        $restore = fn (FeatureRolloutWave $w) => route('admin.feature-rollout-defaults.restore', $w->id);

        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index', ['status' => 'archived', 'track' => 'rentals']))
            ->assertOk()->assertSee($restore($letting), false)->assertDontSee($restore($paper), false);

        $label = config('corex-features.docuperfect.label');
        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index', ['status' => 'archived', 'q' => $label]))
            ->assertSee($restore($paper), false)->assertDontSee($restore($letting), false);

        // The board is not shown for the archived list.
        $this->actingAs($owner)->get(route('admin.feature-rollout-defaults.index', ['status' => 'archived']))
            ->assertDontSee('id="wave-planner"', false);
    }
}
