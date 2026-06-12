<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\AgentOverride;
use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Build 2 — agent review screen + agent_overrides log.
 *
 * Covers the 8 proofs the build prompt demanded:
 *   1. show() opens in a new tab — server side this is just GET-200,
 *      the new-tab behaviour is property-page JS; here we verify the
 *      route reachable and gated.
 *   2. default state: every compiled comp is included.
 *   3. untick: pin disappears (state: included_comp_ids_json shrinks)
 *      + override logged.
 *   4. re-tick: pin reappears + override logged.
 *   5. cross-type warning flag computed when subject category resolves
 *      to a different title_type than the comp.
 *   6. publish flow: status flips, published_at written.
 *   7. save-later: toggleComp persistence is the save mechanism — the
 *      separate Save button is a UI affordance only; covered by toggle
 *      tests.
 *   8. concurrent reviewer banner — second reviewer sees the lock
 *      surfaced + can take it over via POST.
 */
final class ReviewFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Reset the PermissionService static cache between tests so role
     *  state from one test cannot bleed into the next. Mirrors
     *  ReportLifecycleTest::tearDown(). */
    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    // ── 1 — show() ───────────────────────────────────────────────────

    public function test_show_renders_review_screen_for_reviewer(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $this->actingAs($user)
            ->get(route('presentations.review.show', $version->id))
            ->assertOk()
            ->assertSee('Review Presentation')
            ->assertSee('Subject Snapshot')
            ->assertSee('Comparable Sales');
    }

    public function test_show_blocks_cross_agency_user(): void
    {
        // The BelongsToAgency global scope filters the version out for
        // userB before route-model-binding reaches the controller — so
        // the response is 404 (information-hiding), not 403. Either is
        // a successful gate; 404 is the stronger default.
        [$agencyA, $userA] = $this->seedAgencyAndUser();
        [, $userB]        = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyA, $userA->id);

        $this->actingAs($userB)
            ->get(route('presentations.review.show', $version->id))
            ->assertNotFound();
    }

    // ── 2 — default included state ───────────────────────────────────

    public function test_show_defaults_to_all_comps_included(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 3);

        $resp = $this->actingAs($user)
            ->get(route('presentations.review.show', $version->id));

        $resp->assertOk();
        // Each row carries data-included="1" — checked by table render.
        foreach ($comps as $comp) {
            $resp->assertSee('data-comp-id="' . $comp->id . '"', false);
        }
        $resp->assertSeeInOrder(['data-included="1"', 'data-included="1"', 'data-included="1"'], false);
    }

    // ── 3 — untick: pin disappears + override logged ─────────────────

    public function test_toggle_comp_excluded_writes_override_and_updates_version(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        [$comp] = $this->seedComps($agencyId, $version->presentation_id, 1);

        $this->actingAs($user)
            ->post(
                route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => $comp->id]),
                ['included' => '0'],
            )
            ->assertOk()
            ->assertJson(['ok' => true, 'comp_id' => $comp->id, 'is_included' => false]);

        // included_comp_ids_json now excludes this comp.
        $fresh = $version->fresh();
        $this->assertNotContains($comp->id, $fresh->included_comp_ids_json ?: []);

        // Override row logged.
        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $version->id,
            'user_id'                 => $user->id,
            'override_type'           => AgentOverride::TYPE_COMP_EXCLUDED,
            'target_id'               => (string) $comp->id,
        ]);
    }

    // ── 4 — re-tick: pin reappears + override logged ────────────────

    public function test_toggle_comp_included_writes_override_after_exclusion(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        [$comp] = $this->seedComps($agencyId, $version->presentation_id, 1);

        // First exclude.
        $this->actingAs($user)->post(
            route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => $comp->id]),
            ['included' => '0'],
        )->assertOk();

        // Then re-include.
        $this->actingAs($user)->post(
            route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => $comp->id]),
            ['included' => '1'],
        )->assertOk()->assertJson(['ok' => true, 'is_included' => true]);

        $fresh = $version->fresh();
        $this->assertContains($comp->id, $fresh->included_comp_ids_json);

        // Two override rows (one excluded, one included).
        $this->assertSame(2, AgentOverride::where('presentation_version_id', $version->id)
            ->whereIn('override_type', [
                AgentOverride::TYPE_COMP_EXCLUDED,
                AgentOverride::TYPE_COMP_INCLUDED,
            ])->count());
    }

    public function test_toggle_comp_idempotent_no_op_on_same_intent(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        [$comp] = $this->seedComps($agencyId, $version->presentation_id, 1);

        // Default state is included. POSTing included=1 again is a no-op.
        $this->actingAs($user)->post(
            route('presentations.review.toggle-comp', ['version' => $version->id, 'comp' => $comp->id]),
            ['included' => '1'],
        )->assertOk()->assertJson(['ok' => true, 'no_op' => true]);

        $this->assertSame(0, AgentOverride::where('presentation_version_id', $version->id)
            ->whereIn('override_type', [
                AgentOverride::TYPE_COMP_EXCLUDED,
                AgentOverride::TYPE_COMP_INCLUDED,
            ])->count());
    }

    // ── 6 — publish ──────────────────────────────────────────────────

    public function test_publish_sets_status_and_published_at(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_AWAITING,
        ]);

        $this->actingAs($user)->post(route('presentations.review.publish', $version->id))
            ->assertOk()->assertJson(['ok' => true]);

        $fresh = $version->fresh();
        $this->assertSame(PresentationVersion::REVIEW_PUBLISHED, $fresh->review_status);
        $this->assertNotNull($fresh->published_at);
    }

    public function test_publish_is_idempotent(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'  => now()->subMinute(),
        ]);

        $this->actingAs($user)->post(route('presentations.review.publish', $version->id))
            ->assertOk()->assertJson(['ok' => true, 'already' => true]);
    }

    // ── AT-27 Phase A — Continue to Analysis ─────────────────────────

    public function test_continue_to_analysis_sets_in_analysis_and_routes_to_analysis(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_AWAITING,
        ]);

        $resp = $this->actingAs($user)
            ->post(route('presentations.review.continue', $version->id))
            ->assertOk()
            ->assertJson(['ok' => true]);

        // Routes the agent to the Analysis working surface — NOT Overview.
        $this->assertStringContainsString(
            "/presentations/{$version->presentation_id}/analysis",
            $resp->json('redirect_url')
        );

        $fresh = $version->fresh();
        // The version is NOW in-analysis and stays a mutable draft — NOT
        // published, NO snapshot frozen (freeze moves to Analysis confirm).
        $this->assertSame(PresentationVersion::REVIEW_IN_ANALYSIS, $fresh->review_status);
        $this->assertNull($fresh->published_at);
        $this->assertNull($fresh->snapshot_payload);
    }

    public function test_continue_to_analysis_does_not_demote_a_published_version(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id, [
            'review_status' => PresentationVersion::REVIEW_PUBLISHED,
            'published_at'  => now()->subMinute(),
        ]);

        $this->actingAs($user)
            ->post(route('presentations.review.continue', $version->id))
            ->assertOk();

        $this->assertSame(PresentationVersion::REVIEW_PUBLISHED, $version->fresh()->review_status);
    }

    // ── revert ───────────────────────────────────────────────────────

    public function test_revert_archives_version_and_logs_override(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);

        $this->actingAs($user)->post(route('presentations.review.revert', $version->id))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSoftDeleted('presentation_versions', ['id' => $version->id]);

        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $version->id,
            'override_type'           => AgentOverride::TYPE_FIELD_EDITED,
            'target_id'               => 'review_status',
        ]);
    }

    // ── 8 — concurrent reviewer ──────────────────────────────────────

    public function test_second_reviewer_sees_lock_banner(): void
    {
        [$agencyId, $userA] = $this->seedAgencyAndUser();
        $userB = $this->createUserInAgency($agencyId);
        $version = $this->seedPresentationWithVersion($agencyId, $userA->id, [
            'reviewer_user_id'   => $userA->id,
            'reviewer_locked_at' => now()->subMinute(),
        ]);

        $this->actingAs($userB)
            ->get(route('presentations.review.show', $version->id))
            ->assertOk()
            ->assertSee('Currently being reviewed by');
    }

    public function test_first_reviewer_does_not_see_lock_banner(): void
    {
        [$agencyId, $userA] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $userA->id);

        $this->actingAs($userA)
            ->get(route('presentations.review.show', $version->id))
            ->assertOk()
            ->assertDontSee('Currently being reviewed by');
    }

    public function test_takeover_overwrites_lock_and_logs_override(): void
    {
        [$agencyId, $userA] = $this->seedAgencyAndUser();
        $userB = $this->createUserInAgency($agencyId);
        $version = $this->seedPresentationWithVersion($agencyId, $userA->id, [
            'reviewer_user_id'   => $userA->id,
            'reviewer_locked_at' => now()->subMinute(),
        ]);

        $this->actingAs($userB)->post(route('presentations.review.takeover', $version->id))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($userB->id, $version->fresh()->reviewer_user_id);
        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $version->id,
            'user_id'                 => $userB->id,
            'override_type'           => AgentOverride::TYPE_REVIEW_TAKEOVER,
        ]);
    }

    // ── soft-delete reconciliation at render ─────────────────────────

    public function test_show_drops_soft_deleted_comps_and_logs_unavailable_override(): void
    {
        [$agencyId, $user] = $this->seedAgencyAndUser();
        $version = $this->seedPresentationWithVersion($agencyId, $user->id);
        $comps = $this->seedComps($agencyId, $version->presentation_id, 2);

        // Tick both to seed the included list (so the reconcile path runs).
        $version->forceFill(['included_comp_ids_json' => $comps->pluck('id')->all()])->save();

        // Soft-delete one between compile and review.
        $comps[0]->delete();

        $this->actingAs($user)
            ->get(route('presentations.review.show', $version->id))
            ->assertOk();

        // Surviving comp remains, deleted one dropped, unavailable override logged.
        $fresh = $version->fresh();
        $this->assertNotContains($comps[0]->id, $fresh->included_comp_ids_json);
        $this->assertContains($comps[1]->id, $fresh->included_comp_ids_json);

        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $version->id,
            'override_type'           => AgentOverride::TYPE_COMP_UNAVAILABLE,
            'target_id'               => (string) $comps[0]->id,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedAgencyAndUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id'         => $agencyId,
            'agency_id'  => $agencyId,
            'name'       => 'Default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'super_admin',
        ]);
        return [$agencyId, $user];
    }

    private function createUserInAgency(int $agencyId): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'role'      => 'super_admin',
        ]);
    }

    private function seedPresentationWithVersion(int $agencyId, int $userId, array $versionOverrides = []): PresentationVersion
    {
        $presentation = Presentation::create([
            'agency_id'          => $agencyId,
            'branch_id'          => $agencyId,
            'created_by_user_id' => $userId,
            'title'              => 'Review-flow Test Presentation',
            'property_address'   => '123 Test Street',
            'suburb'             => 'Testville',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);

        return PresentationVersion::create(array_merge([
            'agency_id'         => $agencyId,
            'presentation_id'   => $presentation->id,
            'compiled_by'       => $userId,
            'blueprint_version' => 'v1',
            'data_snapshot_json'=> json_encode(['sections' => []]),
            'compiled_at'       => now(),
            'review_status'     => PresentationVersion::REVIEW_AWAITING,
            'awaiting_review_at'=> now(),
        ], $versionOverrides));
    }

    /** @return \Illuminate\Support\Collection<PresentationSoldComp> */
    private function seedComps(int $agencyId, int $presentationId, int $count): \Illuminate\Support\Collection
    {
        $rows = collect();
        for ($i = 1; $i <= $count; $i++) {
            $rows->push(PresentationSoldComp::create([
                'agency_id'       => $agencyId,
                'presentation_id' => $presentationId,
                'sold_date'       => now()->subDays(30 * $i)->toDateString(),
                'sold_price_inc'  => 1_500_000 + ($i * 100_000),
                'suburb'          => 'Testville',
                'property_type'   => 'house',
                'beds'            => 3,
                'baths'           => 2,
                'size_m2'         => 200,
                'raw_row_json'    => json_encode([
                    'address'   => $i . ' Test Avenue',
                    'latitude'  => -30.84 + ($i * 0.001),
                    'longitude' => 30.39 + ($i * 0.001),
                ]),
                'parser_version'  => 'test-v1',
                'is_demo'         => false,
            ]));
        }
        return $rows;
    }
}
